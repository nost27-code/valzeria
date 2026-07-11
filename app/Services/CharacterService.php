<?php

namespace App\Services;

use App\Models\User;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\JobClass;
use App\Services\CharacterStatusService;
use App\Services\NewcomerRegistrationCampaignService;
use Illuminate\Support\Facades\DB;

class CharacterService
{
    /**
     * 新規キャラクターを作成する
     */
    public function createCharacter(User $user, array $data)
    {
        return DB::transaction(function () use ($user, $data) {
            // 基礎ステータス（共通初期値。補正は後で計算される）
            $hp_base = 100;
            $attack_base = 10;
            $defense_base = 8;
            $speed_base = 8;
            $magic_base = 8;
            $luck_base = 5;

            $jobId = (int) $data['job_id'];

            // 初期街を取得
            $initialCity = \App\Models\City::where('is_initial', true)->first();
            $initialCityId = $initialCity ? $initialCity->id : null;

            // キャラクター作成
            $character = $user->characters()->create([
                'name' => $data['name'],
                'gender' => $data['gender'],
                'icon_path' => $data['icon_path'] ?? null,
                'level' => 1,
                'exp' => 0,
                'money' => 100,
                'current_job_id' => $jobId,
                'current_hp' => $hp_base,
                'hp_base' => $hp_base,
                'attack_base' => $attack_base,
                'defense_base' => $defense_base,
                'speed_base' => $speed_base,
                'magic_base' => $magic_base,
                'luck_base' => $luck_base,
                'current_city_id' => $initialCityId,
                'highest_city_id' => $initialCityId,
            ]);

            // character_jobs（ジョブ経験値テーブル）へ初期登録
            DB::table('character_jobs')->insert([
                'character_id' => $character->id,
                'job_class_id' => $jobId,
                'job_level' => 1,
                'job_exp' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->grantInitialEquippedGear($character);

            // 職業補正を含んだ最大HP/SPを計算し、current_hpとcurrent_mpを全回復状態にする
            $statusService = new CharacterStatusService();
            $finalStats = $statusService->getFinalStats($character);
            
            $character->current_hp = $finalStats['max_hp'] ?? $hp_base;
            $character->current_mp = $finalStats['max_mp'] ?? 0;
            $character->save();

            app(PlayerLifecycleEventService::class)->recordCharacterCreated($character);
            if ($initialCity) {
                app(PlayerLifecycleEventService::class)->recordCityReached($character, $initialCity);
            }

            app(NewcomerRegistrationCampaignService::class)->grantIfEligible($character);
            app(PublicLogService::class)->addLog(
                'newcomer',
                "新しい冒険者「{$character->name}」がヴァルゼリアの地に降り立ちました。",
                $character,
                1
            );

            return $character;
        });
    }

    private function grantInitialEquippedGear(Character $character): void
    {
        foreach (['weapon', 'armor'] as $type) {
            $item = $this->weakestEquippableItem($character, $type);

            if (!$item) {
                continue;
            }

            CharacterItem::create([
                'character_id' => $character->id,
                'item_id' => $item->id,
                'is_equipped' => false,
                'is_stored' => false,
                'is_locked' => false,
                'enhance_level' => 0,
                'equipped_slot' => null,
                'acquired_from' => 'initial',
            ]);
        }
    }

    private function weakestEquippableItem(Character $character, string $type): ?Item
    {
        $permissionService = app(EquipmentPermissionService::class);
        $categories = $this->starterCategories($character, $type);

        foreach ($categories as $category) {
            $items = $this->starterItemQuery($type)
                ->where($type === 'weapon' ? 'weapon_category' : 'armor_category', $category)
                ->get();

            $item = $items->first(fn (Item $item): bool => $permissionService->canEquip($character, $item))
                ?? $items->first();

            if ($item) {
                return $item;
            }
        }

        $items = $this->starterItemQuery($type)->get();

        return $items->first(fn (Item $item): bool => $permissionService->canEquip($character, $item))
            ?? $items->first();
    }

    private function starterCategories(Character $character, string $type): array
    {
        $job = $character->currentJob ?: JobClass::find((int) $character->current_job_id);
        $jobKey = (string) ($job?->key ?? '');
        $jobName = (string) ($job?->name ?? '');

        $weaponCategories = [
            'warrior' => ['sword', 'axe', 'spear', 'fist'],
            'mage' => ['staff', 'magic_device', 'dagger'],
            'priest' => ['staff', 'sword', 'magic_device'],
            'thief' => ['dagger', 'sword', 'gun'],
            '戦士' => ['sword', 'axe', 'spear', 'fist'],
            '魔法使い' => ['staff', 'magic_device', 'dagger'],
            '僧侶' => ['staff', 'sword', 'magic_device'],
            '盗賊' => ['dagger', 'sword', 'gun'],
        ];

        $armorCategories = [
            'warrior' => ['heavy_armor', 'light_armor', 'clothes'],
            'mage' => ['robe', 'cloak', 'clothes'],
            'priest' => ['robe', 'clothes', 'cloak', 'light_armor'],
            'thief' => ['light_armor', 'cloak', 'clothes'],
            '戦士' => ['heavy_armor', 'light_armor', 'clothes'],
            '魔法使い' => ['robe', 'cloak', 'clothes'],
            '僧侶' => ['robe', 'clothes', 'cloak', 'light_armor'],
            '盗賊' => ['light_armor', 'cloak', 'clothes'],
        ];

        $map = $type === 'weapon' ? $weaponCategories : $armorCategories;

        return $map[$jobKey] ?? $map[$jobName] ?? [];
    }

    private function starterItemQuery(string $type)
    {
        return Item::query()
            ->where('type', $type)
            ->where('is_active', true)
            ->orderByDesc('is_supply_enabled')
            ->orderBy($type === 'weapon' ? 'weapon_rank_sort' : 'armor_rank_sort')
            ->orderBy('required_level')
            ->orderBy('price')
            ->orderByRaw('COALESCE(hp_bonus, 0) + COALESCE(mp_bonus, 0) + COALESCE(str_bonus, 0) + COALESCE(def_bonus, 0) + COALESCE(agi_bonus, 0) + COALESCE(mag_bonus, 0) + COALESCE(spr_bonus, 0) + COALESCE(luk_bonus, 0)')
            ->orderBy('id');
    }
}
