<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterExplorationState;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Item;
use App\Models\Material;
use App\Models\PlayerValmon;
use App\Models\PlayerValmonEgg;
use App\Models\ValmonFeedLog;
use App\Models\ValmonMaterialFindLog;
use App\Models\ValmonMaster;
use App\Models\ValmonSpawnRegion;
use Illuminate\Support\Facades\DB;

class ValmonService
{
    public const MAX_LEVEL = 100;
    private const BASE_EGG_RATE = 0.02;

    public function starters()
    {
        return ValmonMaster::where('is_starter', true)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    public function needsStarter(Character $character): bool
    {
        return !PlayerValmon::where('character_id', $character->id)->exists();
    }

    public function chooseStarter(Character $character, int $valmonMasterId): array
    {
        return DB::transaction(function () use ($character, $valmonMasterId) {
            if (!$this->needsStarter($character)) {
                return ['success' => false, 'message' => 'すでに最初のヴァルモンを選択済みです。'];
            }

            $master = ValmonMaster::whereKey($valmonMasterId)
                ->where('is_starter', true)
                ->where('is_active', true)
                ->first();

            if (!$master) {
                return ['success' => false, 'message' => '選択できないヴァルモンです。'];
            }

            PlayerValmon::create([
                'character_id' => $character->id,
                'valmon_master_id' => $master->id,
                'level' => 1,
                'exp' => 0,
                'affection' => 0,
                'evolution_stage' => 'child',
                'is_partner' => true,
                'obtained_source' => 'starter',
                'obtained_at' => now(),
            ]);

            return ['success' => true, 'message' => "{$master->name}が最初の相棒になりました。"];
        });
    }

    public function setPartner(Character $character, PlayerValmon $valmon): array
    {
        if ((int) $valmon->character_id !== (int) $character->id) {
            return ['success' => false, 'message' => 'このヴァルモンは所持していません。'];
        }

        DB::transaction(function () use ($character, $valmon) {
            PlayerValmon::where('character_id', $character->id)->update(['is_partner' => false]);
            $valmon->forceFill(['is_partner' => true])->save();
        });

        return ['success' => true, 'message' => "{$valmon->displayName()}を相棒にしました。"];
    }

    public function tryFindEgg(Character $character, Area $area, ?CharacterExplorationState $state): ?array
    {
        if (!$state || $this->hasActiveEgg($character) || $this->foundEggToday($character)) {
            return null;
        }

        $rate = $this->eggRate((int) ($state->exploration_point ?? 0));
        if (!$this->rollPercent($rate)) {
            return null;
        }

        $spawn = $this->weightedSpawnForArea($area, $character);
        if (!$spawn?->valmonMaster) {
            return null;
        }

        $egg = PlayerValmonEgg::create([
            'character_id' => $character->id,
            'valmon_master_id' => $spawn->valmonMaster->id,
            'found_city_id' => $area->city_id,
            'found_area_id' => $area->id,
            'found_exploration_state_id' => $state->id,
            'found_at' => now(),
        ]);

        app(PublicLogService::class)->addLog(
            'valmon',
            "【ヴァルモンの卵】{$character->name}さんが{$area->name}でふしぎな卵を見つけました！",
            $character,
            2
        );

        return [
            'egg_id' => $egg->id,
            'rarity' => $spawn->valmonMaster->rarity,
            'message' => '草むらの奥で、ふしぎな卵を見つけた！ ヴァルモンの卵を手に入れた！ 街へ帰還すると孵化します。',
        ];
    }

    public function tryPartnerFindMaterial(Character $character, Area $area, ?CharacterExplorationState $state, DropService $dropService, ?\App\Models\Enemy $enemy = null): ?array
    {
        if (!$state || (bool) ($state->valmon_material_found ?? false)) {
            return null;
        }

        $partner = PlayerValmon::with('master')
            ->where('character_id', $character->id)
            ->where('is_partner', true)
            ->first();

        if (!$partner || !$this->rollPercent(3.0)) {
            return null;
        }

        $material = $this->selectFindMaterial($partner, $area);
        if (!$material) {
            return null;
        }

        $drop = $dropService->grantMaterialReward($character, $material, 'valmon_find', $enemy);
        $state->forceFill(['valmon_material_found' => true])->save();

        ValmonMaterialFindLog::create([
            'character_id' => $character->id,
            'player_valmon_id' => $partner->id,
            'character_exploration_state_id' => $state->id,
            'material_id' => $material->id,
            'quantity' => 1,
        ]);

        return [
            'valmon_id' => $partner->id,
            'valmon_name' => $partner->displayName(),
            'valmon_image_path' => $partner->master?->imageUrl(),
            'valmon_rarity' => $partner->master?->rarity ?? 'normal',
            'material_name' => $drop['name'] ?? $material->displayName(),
            'quantity' => 1,
        ];
    }

    public function hatchActiveEggs(Character $character): array
    {
        $hatched = [];

        DB::transaction(function () use ($character, &$hatched) {
            $eggs = PlayerValmonEgg::with('master')
                ->where('character_id', $character->id)
                ->where('is_hatched', false)
                ->where('is_lost', false)
                ->lockForUpdate()
                ->get();

            foreach ($eggs as $egg) {
                $alreadyHad = PlayerValmon::where('character_id', $character->id)
                    ->where('valmon_master_id', $egg->valmon_master_id)
                    ->exists();

                $valmon = null;
                if (!$alreadyHad) {
                    $valmon = PlayerValmon::create([
                        'character_id' => $character->id,
                        'valmon_master_id' => $egg->valmon_master_id,
                        'level' => 1,
                        'exp' => 0,
                        'affection' => 0,
                        'evolution_stage' => 'child',
                        'is_partner' => false,
                        'obtained_source' => 'egg',
                        'obtained_at' => now(),
                    ]);

                    if (!PlayerValmon::where('character_id', $character->id)->where('is_partner', true)->where('id', '!=', $valmon->id)->exists()) {
                        $valmon->forceFill(['is_partner' => true])->save();
                    }
                }

                $egg->forceFill(['is_hatched' => true, 'hatched_at' => now()])->save();

                $hatched[] = [
                    'name' => $egg->master?->name ?? 'ヴァルモン',
                    'rarity' => $egg->master?->rarity ?? 'normal',
                    'already_had' => $alreadyHad,
                ];

                $valmonName = $egg->master?->name ?? 'ヴァルモン';
                $rarity = (string) ($egg->master?->rarity ?? 'normal');
                $message = $alreadyHad
                    ? "【ヴァルモンの卵】{$character->name}さんの卵は「{$valmonName}」の気配を宿していました。すでに仲間にいるため牧場には追加されませんでした。"
                    : "【ヴァルモン誕生】{$character->name}さんの卵から新しい相棒「{$valmonName}」が生まれました！";

                app(PublicLogService::class)->addLog(
                    'valmon',
                    $message,
                    $character,
                    in_array($rarity, ['rare', 'super_rare'], true) ? 3 : 2
                );
            }
        });

        return $hatched;
    }

    public function loseActiveEggs(Character $character): array
    {
        $lost = [];

        PlayerValmonEgg::with('master')
            ->where('character_id', $character->id)
            ->where('is_hatched', false)
            ->where('is_lost', false)
            ->get()
            ->each(function (PlayerValmonEgg $egg) use (&$lost) {
                $lost[] = ['name' => $egg->master?->name ?? 'ヴァルモンの卵'];
                $egg->forceFill(['is_lost' => true, 'lost_at' => now()])->save();
            });

        return $lost;
    }

    public function feedMaterial(Character $character, PlayerValmon $valmon, CharacterMaterial $characterMaterial, int $quantity): array
    {
        if ((int) $valmon->character_id !== (int) $character->id || (int) $characterMaterial->character_id !== (int) $character->id) {
            return ['success' => false, 'message' => '対象が見つかりません。'];
        }

        $quantity = max(1, min($quantity, (int) $characterMaterial->quantity));
        $expPer = $this->materialFeedExp($characterMaterial->material);
        if ($expPer <= 0) {
            return ['success' => false, 'message' => 'この素材はヴァルモンの餌にできません。'];
        }

        return DB::transaction(function () use ($valmon, $characterMaterial, $quantity, $expPer) {
            $characterMaterial->decrement('quantity', $quantity);
            if ((int) $characterMaterial->fresh()?->quantity <= 0) {
                $characterMaterial->delete();
            }

            return $this->grantFeedExp($valmon, 'material', (int) $characterMaterial->material_id, $quantity, $expPer * $quantity);
        });
    }

    public function feedEquipment(Character $character, PlayerValmon $valmon, CharacterItem $characterItem): array
    {
        if ((int) $valmon->character_id !== (int) $character->id || (int) $characterItem->character_id !== (int) $character->id) {
            return ['success' => false, 'message' => '対象が見つかりません。'];
        }

        $exp = $this->equipmentFeedExp($characterItem);
        if ($exp <= 0) {
            return ['success' => false, 'message' => 'この装備はヴァルモンの餌にできません。'];
        }

        return DB::transaction(function () use ($valmon, $characterItem, $exp) {
            $feedId = (int) $characterItem->id;
            $characterItem->delete();

            return $this->grantFeedExp($valmon, 'equipment', $feedId, 1, $exp);
        });
    }

    public function feedEquipmentBulk(Character $character, PlayerValmon $valmon, array $characterItemIds): array
    {
        if ((int) $valmon->character_id !== (int) $character->id) {
            return ['success' => false, 'message' => '対象が見つかりません。'];
        }

        $ids = collect($characterItemIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return ['success' => false, 'message' => '餌にする装備を選んでください。'];
        }

        $items = CharacterItem::with('item')
            ->where('character_id', $character->id)
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy('id');

        if ($items->count() !== $ids->count()) {
            return ['success' => false, 'message' => '対象外の装備が含まれています。'];
        }

        $totalExp = 0;
        foreach ($ids as $id) {
            $exp = $this->equipmentFeedExp($items[$id]);
            if ($exp <= 0) {
                return ['success' => false, 'message' => '餌にできない装備が含まれています。'];
            }
            $totalExp += $exp;
        }

        return DB::transaction(function () use ($valmon, $items, $ids, $totalExp) {
            CharacterItem::where('character_id', $valmon->character_id)
                ->whereIn('id', $ids->all())
                ->delete();

            return $this->grantFeedExp($valmon, 'equipment_bulk', (int) $ids->first(), $items->count(), $totalExp);
        });
    }

    public function materialFeedExp(?Material $material): int
    {
        if (!$material || $this->isBlockedMaterial($material)) {
            return 0;
        }

        return match ((string) $material->name) {
            '装備の欠片' => 2,
            '上質な装備の欠片' => 8,
            '強装備の欠片' => 20,
            default => match (strtoupper((string) $material->rarity)) {
                'N', 'NORMAL' => 1,
                'N+' => 3,
                'R' => 10,
                default => 0,
            },
        };
    }

    public function equipmentFeedExp(CharacterItem $characterItem): int
    {
        $item = $characterItem->item;
        if (!$item || $characterItem->is_equipped || $characterItem->is_locked) {
            return 0;
        }

        $rank = strtoupper((string) ($item->weapon_rank ?? $item->armor_rank ?? $item->accessory_rank ?? $item->rarity ?? ''));
        if (in_array($rank, ['S', 'SS', 'SSS', 'EPIC'], true)) {
            return 0;
        }

        return match ($rank) {
            'G', 'F' => 2,
            'E', 'D' => 5,
            'C', 'B' => 15,
            'A' => 40,
            default => 0,
        };
    }

    public function nextLevelExp(int $level): int
    {
        $level = max(1, min(self::MAX_LEVEL, $level));

        return (int) round(20 + (5 * $level) + (0.68 * $level * $level));
    }

    public function nextLevelRemaining(PlayerValmon $valmon): ?int
    {
        if ((int) $valmon->level >= self::MAX_LEVEL) {
            return null;
        }

        return max(0, $this->nextLevelExp((int) $valmon->level) - (int) $valmon->exp);
    }

    private function grantFeedExp(PlayerValmon $valmon, string $feedType, int $feedId, int $quantity, int $gainedExp): array
    {
        $levelUps = 0;
        $valmon->refresh();
        $valmon->exp += $gainedExp;
        $valmon->affection = min(100, (int) $valmon->affection + max(1, $quantity));

        while ((int) $valmon->level < self::MAX_LEVEL && (int) $valmon->exp >= $this->nextLevelExp((int) $valmon->level)) {
            $valmon->exp -= $this->nextLevelExp((int) $valmon->level);
            $valmon->level += 1;
            $levelUps++;
        }

        $valmon->save();

        ValmonFeedLog::create([
            'character_id' => $valmon->character_id,
            'player_valmon_id' => $valmon->id,
            'feed_type' => $feedType,
            'feed_id' => $feedId,
            'quantity' => $quantity,
            'gained_exp' => $gainedExp,
            'gained_affection' => max(1, $quantity),
        ]);

        return [
            'success' => true,
            'message' => $levelUps > 0
                ? "{$valmon->displayName()}に餌を与えました。EXP +{$gainedExp}、Lvが{$valmon->level}になりました！"
                : "{$valmon->displayName()}に餌を与えました。EXP +{$gainedExp}。",
        ];
    }

    private function selectFindMaterial(PlayerValmon $partner, Area $area): ?Material
    {
        $query = Material::query()
            ->whereIn('rarity', ['N', 'N+', 'R'])
            ->where(function ($query) {
                $query->whereNotNull('usage_tags')
                    ->where('usage_tags', '<>', '[]');
            })
            ->where(function ($query) {
                $query->whereNull('material_type')
                    ->orWhereNotIn('material_type', ['legacy_normal_drop']);
            })
            ->where(function ($query) {
                $query->whereNull('category_id')
                    ->orWhere('category_id', '<>', 'legacy_normal_drop');
            })
            ->where(function ($query) {
                $query->whereNull('category')
                    ->orWhere(function ($query) {
                        $query->where('category', 'not like', '%旧通常素材%')
                            ->where('category', 'not like', '%討伐証%');
                    });
            })
            ->where('name', 'not like', '%の刻印')
            ->where('name', 'not like', '%の王印')
            ->where('name', 'not like', '%の神印')
            ->where(function ($query) {
                $query->whereNull('drop_first_clear_only')->orWhere('drop_first_clear_only', false);
            })
            ->where(function ($query) {
                $query->whereNull('main_use')
                    ->orWhere(function ($query) {
                        $query->where('main_use', 'not like', '%秘境晶%')
                            ->where('main_use', 'not like', '%極印%')
                            ->where('main_use', 'not like', '%EPIC%')
                            ->where('main_use', 'not like', '%統合済%')
                            ->where('main_use', 'not like', '%廃止%')
                            ->where('main_use', 'not like', '%未使用%');
                    });
            })
            ->where(function ($query) {
                $query->whereNull('obtain_method')
                    ->orWhere(function ($query) {
                        $query->where('obtain_method', 'not like', '%統合済%')
                            ->where('obtain_method', 'not like', '%廃止%')
                            ->where('obtain_method', 'not like', '%未使用%');
                    });
            });

        $categoryHint = (string) ($partner->master?->base_find_material_category ?? '');
        if ($categoryHint !== '') {
            $query->orderByRaw('CASE WHEN category LIKE ? OR name LIKE ? OR main_use LIKE ? THEN 0 ELSE 1 END', [
                '%' . $categoryHint . '%',
                '%' . $categoryHint . '%',
                '%' . $categoryHint . '%',
            ]);
        }

        return $query
            ->where(function ($query) use ($area) {
                $query->whereNull('city_id')->orWhere('city_id', $area->city_id);
            })
            ->inRandomOrder()
            ->limit(30)
            ->get()
            ->first(fn (Material $material) => $this->hasUsablePurpose($material) && !$this->isBlockedMaterial($material));
    }

    private function hasActiveEgg(Character $character): bool
    {
        return PlayerValmonEgg::where('character_id', $character->id)
            ->where('is_hatched', false)
            ->where('is_lost', false)
            ->exists();
    }

    private function foundEggToday(Character $character): bool
    {
        return PlayerValmonEgg::where('character_id', $character->id)
            ->whereDate('found_at', today('Asia/Tokyo')->toDateString())
            ->exists();
    }

    private function eggRate(int $explorationPoint): float
    {
        $multiplier = max(0, min(5, app(GameSettingService::class)->getFloat('valmon.egg_rate_multiplier', 1.0)));

        return min(100, self::BASE_EGG_RATE * $multiplier);
    }

    private function weightedSpawnForArea(Area $area, ?Character $character = null): ?ValmonSpawnRegion
    {
        $ownedMasterIds = $character
            ? PlayerValmon::where('character_id', $character->id)->pluck('valmon_master_id')->map(fn ($id) => (int) $id)->all()
            : [];

        $spawns = ValmonSpawnRegion::with('valmonMaster')
            ->where('city_id', $area->city_id)
            ->where('is_active', true)
            ->get()
            ->filter(fn ($spawn) => $spawn->valmonMaster
                && $spawn->valmonMaster->is_active
                && !in_array((int) $spawn->valmon_master_id, $ownedMasterIds, true));

        $total = (int) $spawns->sum('spawn_weight');
        if ($total <= 0) {
            return $spawns->first();
        }

        $roll = random_int(1, $total);
        $cursor = 0;
        foreach ($spawns as $spawn) {
            $cursor += (int) $spawn->spawn_weight;
            if ($roll <= $cursor) {
                return $spawn;
            }
        }

        return $spawns->first();
    }

    private function isBlockedMaterial(Material $material): bool
    {
        $name = (string) $material->name;
        $mainUse = (string) $material->main_use;
        $category = (string) $material->category;
        $categoryId = (string) $material->category_id;
        $materialType = (string) $material->material_type;
        $obtainMethod = (string) $material->obtain_method;

        return str_contains($name, '秘境晶')
            || str_contains($name, '極印')
            || str_contains($mainUse, '秘境晶')
            || str_contains($mainUse, '極印')
            || str_contains($mainUse, 'EPIC')
            || str_contains($mainUse, '統合済')
            || str_contains($mainUse, '廃止')
            || str_contains($mainUse, '未使用')
            || str_contains($obtainMethod, '統合済')
            || str_contains($obtainMethod, '廃止')
            || str_contains($obtainMethod, '未使用')
            || str_contains($category, '討伐証')
            || str_contains($category, '旧通常素材')
            || $categoryId === 'legacy_normal_drop'
            || $materialType === 'legacy_normal_drop'
            || str_ends_with($name, 'の刻印')
            || str_ends_with($name, 'の王印')
            || str_ends_with($name, 'の神印');
    }

    private function hasUsablePurpose(Material $material): bool
    {
        $usageTags = $material->usage_tags ?? [];
        if (is_string($usageTags)) {
            $decoded = json_decode($usageTags, true);
            $usageTags = is_array($decoded) ? $decoded : [];
        }

        $usageTags = array_filter((array) $usageTags, fn ($tag) => trim((string) $tag) !== '');
        if ($usageTags !== []) {
            return true;
        }

        $usageText = trim((string) ($material->usage_summary ?? ''));
        if ($usageText !== '') {
            return true;
        }

        $mainUse = trim((string) ($material->main_use ?? ''));
        if ($mainUse === '') {
            return false;
        }

        foreach (['統合済', '廃止', '未使用'] as $blocked) {
            if (str_contains($mainUse, $blocked)) {
                return false;
            }
        }

        return true;
    }

    private function rollPercent(float $percent): bool
    {
        if ($percent <= 0) {
            return false;
        }

        return random_int(1, 10000) <= (int) round($percent * 100);
    }
}
