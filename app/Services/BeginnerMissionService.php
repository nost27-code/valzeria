<?php

namespace App\Services;

use App\Models\Area;
use App\Models\BattleLog;
use App\Models\Character;
use App\Models\CharacterAreaProgress;
use App\Models\CharacterItem;
use App\Models\CharacterItemDailySupply;
use App\Models\CharacterMaterial;
use App\Models\ExplorationItemCarry;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BeginnerMissionService
{
    private const REWARD_EXTERNAL_ID = 'BEGINNER_MISSION_PROOF';
    private const ARKREA_CITY_ID = 1;

    public function summary(Character $character): array
    {
        $completedKeys = $this->completedKeys($character);
        $missions = $this->firstMissions($character, $completedKeys);
        $completedKeys = $this->syncCompletedKeys($character, $missions, $completedKeys);
        $character = $character->fresh() ?? $character;
        $missions = $this->firstMissions($character, $completedKeys);
        $completed = collect($missions)->where('completed', true)->count();
        $total = count($missions);
        $allPersisted = empty(array_diff($this->missionKeys($missions), $completedKeys));
        $rewardGranted = $allPersisted ? $this->grantCompletionReward($character) : false;

        if (!$allPersisted || $rewardGranted) {
            return [
                'label' => '初心者ミッション',
                'should_show' => ($completed < $total && (int) $character->level <= 20) || $rewardGranted,
                'completed' => $completed,
                'total' => $total,
                'percent' => $total > 0 ? (int) floor(($completed / $total) * 100) : 0,
                'current' => collect($missions)->firstWhere('completed', false),
                'missions' => $missions,
                'reward_granted' => $rewardGranted,
                'reward_title' => '初心者ミッション達成',
                'reward_message' => '全能力+1の記念装飾品です。装備変更から身につけられます。',
                'reward_name' => '初心者の証',
            ];
        }

        $missions = $this->arkreaMissions($character, $completedKeys);
        $completedKeys = $this->syncCompletedKeys($character, $missions, $completedKeys);
        $character = $character->fresh() ?? $character;
        $missions = $this->arkreaMissions($character, $completedKeys);
        $completed = collect($missions)->where('completed', true)->count();
        $total = count($missions);

        return [
            'label' => '第二ミッション',
            'should_show' => $completed < $total,
            'completed' => $completed,
            'total' => $total,
            'percent' => $total > 0 ? (int) floor(($completed / $total) * 100) : 0,
            'current' => collect($missions)->firstWhere('completed', false),
            'missions' => $missions,
            'reward_granted' => false,
            'reward_title' => null,
            'reward_message' => null,
            'reward_name' => null,
        ];
    }

    private function firstMissions(Character $character, array $completedKeys): array
    {
        $hasSupplies = ExplorationItemCarry::where('character_id', $character->id)
            ->whereRaw('(carried_count + used_count) > 0')
            ->exists()
            || CharacterItemDailySupply::where('character_id', $character->id)
                ->exists()
            || CharacterItem::where('character_id', $character->id)
                ->where('acquired_from', 'daily_supply')
            ->exists();

        $hasEquipment = CharacterItem::where('character_id', $character->id)
            ->whereHas('item', fn ($query) => $query->whereIn('type', ['weapon', 'armor', 'accessory']))
            ->exists();

        $hasEquipped = CharacterItem::where('character_id', $character->id)
            ->where('is_equipped', true)
            ->exists();

        $hasExplored = BattleLog::where('character_id', $character->id)
            ->where('battle_type', 'normal')
            ->exists();

        $hasLoot = CharacterMaterial::where('character_id', $character->id)
            ->where('quantity', '>', 0)
            ->exists()
            || CharacterItem::where('character_id', $character->id)
                ->whereIn('acquired_from', ['drop', 'treasure', 'boss'])
                ->exists();

        $hasBpToSpend = (int) ($character->bonus_points ?? 0) > 0;
        $hasHandledBp = in_array('bonus_points', $completedKeys, true)
            || ((int) $character->level >= 2 && !$hasBpToSpend);

        $hasBossWin = BattleLog::where('character_id', $character->id)
            ->where('battle_type', 'boss')
            ->where('result', 'win')
            ->exists();

        return [
            [
                'key' => 'supply',
                'title' => '補給所で回復アイテムを受け取る',
                'desc' => '探索中にHP/SPを立て直せるようになります。',
                'completed' => in_array('supply', $completedKeys, true) || $hasSupplies,
                'action_label' => '補給所へ',
                'route' => 'shop.items',
                'persist_completion' => true,
            ],
            [
                'key' => 'starter_equipment',
                'title' => '装備を確認する',
                'desc' => '手に入れた装備を確認し、戦闘に備えましょう。',
                'completed' => in_array('starter_equipment', $completedKeys, true) || $hasEquipment,
                'action_label' => '装備変更へ',
                'route' => 'equipment.index',
                'persist_completion' => true,
            ],
            [
                'key' => 'equip',
                'title' => '装備変更で装備を身につける',
                'desc' => '入手した装備は身につけて初めて強くなります。',
                'completed' => in_array('equip', $completedKeys, true) || $hasEquipped,
                'action_label' => '装備変更へ',
                'route' => 'equipment.index',
                'persist_completion' => true,
            ],
            [
                'key' => 'explore',
                'title' => '最初のダンジョンを探索する',
                'desc' => '経験値、素材、装備、印を集めましょう。',
                'completed' => in_array('explore', $completedKeys, true) || $hasExplored,
                'action_label' => '探索へ',
                'tab' => 'dungeon',
                'persist_completion' => true,
            ],
            [
                'key' => 'loot',
                'title' => '戦利品を1つ持ち帰る',
                'desc' => '拾った素材や装備が、次の強化につながります。',
                'completed' => in_array('loot', $completedKeys, true) || $hasLoot,
                'action_label' => 'もう一度探索',
                'tab' => 'dungeon',
                'persist_completion' => true,
            ],
            [
                'key' => 'bonus_points',
                'title' => 'BPを振ろう',
                'desc' => 'レベルアップ後は、好きな能力を永続強化できます。',
                'completed' => $hasHandledBp,
                'action_label' => '能力割振りへ',
                'route' => 'bonus-points.index',
                'persist_completion' => (int) $character->level >= 2,
            ],
            [
                'key' => 'first_boss',
                'title' => '最初のボス撃破を目指す',
                'desc' => 'ボス撃破で次の冒険先が開けます。',
                'completed' => in_array('first_boss', $completedKeys, true) || $hasBossWin,
                'action_label' => '探索へ',
                'tab' => 'dungeon',
                'persist_completion' => true,
            ],
        ];
    }

    private function arkreaMissions(Character $character, array $completedKeys): array
    {
        $areas = Area::query()
            ->where('city_id', self::ARKREA_CITY_ID)
            ->where('id', '<=', 70)
            ->orderBy('sort_order')
            ->get();

        if ($areas->isEmpty()) {
            return [];
        }

        $progresses = CharacterAreaProgress::where('character_id', $character->id)
            ->whereIn('area_id', $areas->pluck('id'))
            ->get()
            ->keyBy('area_id');

        $highestCityId = (int) ($character->highest_city_id ?? $character->current_city_id ?? 0);
        $hasReachedNextCity = $highestCityId > self::ARKREA_CITY_ID;
        $lastAreaId = (int) $areas->last()->id;

        return $areas
            ->skip(1)
            ->values()
            ->map(function (Area $area) use ($completedKeys, $progresses, $hasReachedNextCity, $lastAreaId) {
                $key = 'arkrea_area_' . $area->id;
                $isLastArea = (int) $area->id === $lastAreaId;
                $progress = $progresses->get($area->id);
                $isCleared = (bool) ($progress?->boss_defeated) || ($isLastArea && $hasReachedNextCity);

                return [
                    'key' => $key,
                    'title' => $isLastArea ? 'アークレアをクリアしよう' : "{$area->name}をクリアしよう",
                    'desc' => $isLastArea
                        ? '王都アークレア最後のボスを倒し、次の街への道を開きましょう。'
                        : "{$area->name}のボスを倒して、次の冒険先を解放しましょう。",
                    'completed' => in_array($key, $completedKeys, true) || $isCleared,
                    'action_label' => '探索へ',
                    'tab' => 'dungeon',
                    'persist_completion' => true,
                ];
            })
            ->all();
    }

    private function completedKeys(Character $character): array
    {
        $keys = $character->beginner_mission_completed_keys ?? [];

        if (!is_array($keys)) {
            return [];
        }

        return array_values(array_unique(array_filter($keys, 'is_string')));
    }

    private function syncCompletedKeys(Character $character, array $missions, array $completedKeys): array
    {
        $newKeys = collect($missions)
            ->filter(fn (array $mission) => ($mission['completed'] ?? false) && ($mission['persist_completion'] ?? false))
            ->pluck('key')
            ->all();

        $merged = array_values(array_unique(array_merge($completedKeys, $newKeys)));
        sort($merged);

        if ($merged !== $completedKeys && Schema::hasColumn('characters', 'beginner_mission_completed_keys')) {
            $character->beginner_mission_completed_keys = $merged;
            $character->save();
        }

        return $merged;
    }

    private function missionKeys(array $missions): array
    {
        return collect($missions)->pluck('key')->all();
    }

    private function grantCompletionReward(Character $character): bool
    {
        if ((bool) ($character->beginner_mission_reward_claimed ?? false)) {
            return false;
        }

        if (!Schema::hasColumn('characters', 'beginner_mission_reward_claimed')) {
            return false;
        }

        return DB::transaction(function () use ($character) {
            $locked = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
            if ((bool) ($locked->beginner_mission_reward_claimed ?? false)) {
                return false;
            }

            if (Schema::hasColumn('items', 'external_item_id')) {
                $reward = Item::query()
                    ->where('external_item_id', self::REWARD_EXTERNAL_ID)
                    ->first();

                if (!$reward) {
                    $reward = Item::query()
                        ->where('name', '初心者の証')
                        ->where('type', 'accessory')
                        ->orderByDesc('is_active')
                        ->orderByDesc('updated_at')
                        ->first();
                }
            } else {
                $reward = Item::query()
                    ->where('name', '初心者の証')
                    ->where('type', 'accessory')
                    ->orderByDesc('is_active')
                    ->orderByDesc('updated_at')
                    ->first();
            }

            if (!$reward) {
                return false;
            }

            CharacterItem::firstOrCreate(
                [
                    'character_id' => $locked->id,
                    'item_id' => $reward->id,
                    'acquired_from' => 'beginner_mission',
                ],
                [
                    'is_equipped' => false,
                    'equipped_slot' => null,
                ]
            );

            $locked->beginner_mission_reward_claimed = true;
            $locked->save();

            return true;
        });
    }
}
