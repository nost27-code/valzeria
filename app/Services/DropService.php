<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Enemy;
use App\Models\EnemyDrop;
use App\Models\Item;
use App\Models\Material;
use App\Models\MaterialDrop;
use Illuminate\Support\Collection;

class DropService
{
    private const RANKS_BLOCKED_FROM_NORMAL_DROP = ['S', 'SS', 'SSS', 'EPIC'];
    private const EQUIPMENT_FRAGMENT_CODE = 'MAT_EQUIPMENT_FRAGMENT';
    private const FINE_EQUIPMENT_FRAGMENT_CODE = 'MAT_FINE_EQUIPMENT_FRAGMENT';
    private const STRONG_EQUIPMENT_FRAGMENT_CODE = 'MAT_STRONG_EQUIPMENT_FRAGMENT';
    private const COMMON_MONSTER_FRAGMENT_CODE = 'MAT_COMMON_MONSTER_FRAGMENT';
    private const DISABLED_EQUIPMENT_FRAGMENT_CODES = [
        self::EQUIPMENT_FRAGMENT_CODE,
        self::FINE_EQUIPMENT_FRAGMENT_CODE,
        self::STRONG_EQUIPMENT_FRAGMENT_CODE,
    ];
    private const LEGACY_COMMON_FRAGMENT_CODES = ['WEV0001', '5001', 'ACC0001', 'MAT_WEAPON_FRAGMENT'];
    private const LEGACY_COMMON_FRAGMENT_NAMES = ['武器の欠片', '防具の欠片', '装飾の欠片'];

    // drops未登録の敵へのフォールバック用汎用素材コード
    private const GENERIC_FALLBACK_CODES = ['MAT_COMMON_MONSTER_FRAGMENT', 'MAT_COMMON_OLD_BADGE'];

    private const DEPTH_BRANCH_MATERIAL_DROPS = [
        1 => [
            'deepest' => [
                ['code' => 'MAT_BR_WPN_HOLY_PATH', 'rate' => 2.5],
            ],
        ],
        3 => [
            'surface' => [
                ['code' => 'MAT_BR_WPN_GALE_PATH', 'rate' => 0.8, 'min_area_level' => 39, 'max_area_level' => 50],
                ['code' => 'MAT_BR_ARM_LIGHT_TRAVELER_PATH', 'rate' => 0.8, 'min_area_level' => 39, 'max_area_level' => 50],
            ],
        ],
        4 => [
            'surface' => [
                ['code' => 'MAT_BR_WPN_DARK_PATH', 'rate' => 0.8, 'min_area_level' => 40, 'max_area_level' => 50],
                ['code' => 'MAT_BR_ARM_HEAVY_ARCANE_PATH', 'rate' => 0.8, 'min_area_level' => 40, 'max_area_level' => 50],
            ],
        ],
    ];

    /**
     * 仕様書ベースの独立枠ドロップ抽選を行う。
     */
    public function rollBattleDrops(Character $character, Enemy $enemy, int $dropBonusPercent = 0, int $rareBonusPercent = 0, bool $trackExplorationLoot = true): array
    {
        $result = [
            'materials' => [],
            'equipment' => [],
            'monster_mark' => null,
            'by_slot' => [
                'material' => [],
                'weapon' => null,
                'armor' => null,
                'accessory' => null,
                'monster_mark' => null,
            ],
        ];

        if ($this->isBossEnemy($enemy)) {
            $result['materials'] = $this->rollFirstClearMaterials($character, $enemy);
            $result['by_slot']['material'] = $result['materials'];
            return $result;
        }

        $rates = $this->withSkillRewardBonus(
            $this->withDangerRewardBonus($this->dropRates($enemy), $character),
            $dropBonusPercent,
            $rareBonusPercent
        );

        foreach ($this->rollEnemySpecificEquipmentDrops($character, $enemy, $trackExplorationLoot) as $drop) {
            $result['equipment'][] = $drop;
            $slot = (string) ($drop['slot'] ?? '');
            if ($slot !== '' && array_key_exists($slot, $result['by_slot']) && $result['by_slot'][$slot] === null) {
                $result['by_slot'][$slot] = $drop;
            }
        }

        $branchMaterial = $this->rollConfiguredBranchEvolutionMaterial($character, $enemy, $trackExplorationLoot);
        if ($branchMaterial) {
            $result['materials'][] = $branchMaterial;
        }

        $depthBranchMaterial = $this->rollDepthBranchEvolutionMaterial($character, $enemy, $trackExplorationLoot);
        if ($depthBranchMaterial) {
            $result['materials'][] = $depthBranchMaterial;
        }

        if ($this->rollPercent($rates['material'])) {
            $material = $this->rollMaterialBySpec($character, $enemy, $trackExplorationLoot);
            if ($material) {
                $result['materials'][] = $material;
            }
        }

        foreach (['weapon', 'armor', 'accessory'] as $slot) {
            if ($result['by_slot'][$slot] !== null) {
                continue;
            }

            if (!$this->rollPercent($rates[$slot])) {
                continue;
            }

            $drop = $this->rollEquipmentBySpec($character, $enemy, $slot, $rareBonusPercent, $trackExplorationLoot);
            if ($drop) {
                $result['equipment'][] = $drop;
                $result['by_slot'][$slot] = $drop;
            }
        }

        $monsterMark = app(MonsterMarkService::class)->rollAndGrant($character, $enemy);
        if ($monsterMark) {
            $result['monster_mark'] = $monsterMark;
            $result['by_slot']['monster_mark'] = $monsterMark;
        }

        $result['by_slot']['material'] = $result['materials'];

        return $result;
    }

    /**
     * 旧呼び出し互換: 最初に当たった装備だけ返す。
     */
    public function rollDrop(Character $character, Enemy $enemy): ?array
    {
        $drops = $this->rollBattleDrops($character, $enemy);

        return $drops['equipment'][0] ?? null;
    }

    /**
     * 旧呼び出し互換: 素材枠だけ抽選して返す。
     */
    public function rollMaterialDrop(Character $character, Enemy $enemy): array
    {
        if ($this->isBossEnemy($enemy)) {
            return $this->rollFirstClearMaterials($character, $enemy);
        }

        $drops = [];
        $branchMaterial = $this->rollConfiguredBranchEvolutionMaterial($character, $enemy);
        if ($branchMaterial) {
            $drops[] = $branchMaterial;
        }

        if (!$this->rollPercent($this->dropRates($enemy)['material'])) {
            return $drops;
        }

        $material = $this->rollMaterialBySpec($character, $enemy);

        if ($material) {
            $drops[] = $material;
        }

        return $drops;
    }

    public function grantMaterialReward(Character $character, Material $material, string $kind = 'special_event', ?Enemy $enemy = null): array
    {
        return $this->grantMaterial($character, $material, $kind, $enemy);
    }

    public function dropRates(Enemy $enemy): array
    {
        $cityTier = $this->enemyCityTier($enemy);

        if ($this->isBackDungeon($enemy)) {
            return $this->applyOperationalDropRateSettings(['material' => 65, 'weapon' => 3, 'armor' => 2, 'accessory' => 1]);
        }

        if ($this->isGrassland($enemy)) {
            return $this->applyOperationalDropRateSettings(['material' => 40, 'weapon' => 2, 'armor' => 1.5, 'accessory' => 0.5]);
        }

        $rates = match ($cityTier) {
            1, 2, 3, 4 => ['material' => 50, 'weapon' => 3, 'armor' => 2, 'accessory' => 1],
            5, 6, 7 => ['material' => 55, 'weapon' => 3, 'armor' => 2, 'accessory' => 1],
            default => ['material' => 60, 'weapon' => 3, 'armor' => 2, 'accessory' => 1],
        };

        return $this->applyOperationalDropRateSettings($rates);
    }

    private function applyOperationalDropRateSettings(array $rates): array
    {
        $settings = app(GameSettingService::class);
        $materialMultiplier = max(0, min(3, $settings->getFloat('drop.material_rate_multiplier', 1.0)));
        $equipmentMultiplier = max(0, min(3, $settings->getFloat('drop.equipment_rate_multiplier', 1.0)));

        $rates['material'] = min(95, round(($rates['material'] ?? 0) * $materialMultiplier, 2));
        foreach (['weapon', 'armor'] as $slot) {
            $rates[$slot] = min(30, round(($rates[$slot] ?? 0) * $equipmentMultiplier, 2));
        }
        $rates['accessory'] = min(20, round(($rates['accessory'] ?? 0) * $equipmentMultiplier, 2));

        return $rates;
    }

    private function withDangerRewardBonus(array $rates, Character $character): array
    {
        $state = app(ExplorationStateService::class)->currentFor($character);
        $dangerRate = (int) ($state?->danger_rate ?? 0);

        if ($dangerRate >= 100) {
            $rates['material'] = min(95, ($rates['material'] ?? 0) + 10);
            $rates['weapon'] = min(30, ($rates['weapon'] ?? 0) + 2);
            $rates['armor'] = min(30, ($rates['armor'] ?? 0) + 2);
            $rates['accessory'] = min(20, ($rates['accessory'] ?? 0) + 1);
        } elseif ($dangerRate >= 75) {
            $rates['material'] = min(90, ($rates['material'] ?? 0) + 5);
            $rates['weapon'] = min(25, ($rates['weapon'] ?? 0) + 1);
            $rates['armor'] = min(25, ($rates['armor'] ?? 0) + 1);
        }

        return $rates;
    }

    private function withSkillRewardBonus(array $rates, int $dropBonusPercent, int $rareBonusPercent): array
    {
        $dropBonusPercent = max(0, $dropBonusPercent);
        $rareBonusPercent = max(0, $rareBonusPercent);

        if ($dropBonusPercent > 0) {
            $rates['material'] = min(95, ($rates['material'] ?? 0) + $dropBonusPercent);
        }

        if ($rareBonusPercent > 0) {
            $equipmentBonus = max(1, (int) ceil($rareBonusPercent / 2));
            $rates['weapon'] = min(30, ($rates['weapon'] ?? 0) + $equipmentBonus);
            $rates['armor'] = min(30, ($rates['armor'] ?? 0) + $equipmentBonus);
            $rates['accessory'] = min(20, ($rates['accessory'] ?? 0) + max(1, (int) floor($equipmentBonus / 2)));
        }

        return $rates;
    }

    private function rollEquipmentBySpec(Character $character, Enemy $enemy, string $type, int $rareBonusPercent = 0, bool $trackExplorationLoot = true): ?array
    {
        $rankWeights = $this->equipmentRankWeights($enemy, $rareBonusPercent);

        while (!empty($rankWeights)) {
            $rank = $this->weightedKey($rankWeights);
            if ($rank === null || in_array($rank, self::RANKS_BLOCKED_FROM_NORMAL_DROP, true)) {
                unset($rankWeights[$rank]);
                continue;
            }

            $items = $this->equipmentCandidates($type, $rank, $enemy);
            if ($items->isNotEmpty()) {
                return $this->grantItemDrop($character, $items->random(), $type, $enemy, $trackExplorationLoot);
            }

            unset($rankWeights[$rank]);
        }

        return null;
    }

    /**
     * Enemy-specific equipment drops are kept separate from the rank-based
     * generic equipment pool so rare named gear can come from a specific enemy.
     */
    private function rollEnemySpecificEquipmentDrops(Character $character, Enemy $enemy, bool $trackExplorationLoot = true): array
    {
        $drops = EnemyDrop::query()
            ->where('enemy_id', $enemy->id)
            ->where('is_active', true)
            ->where('drop_rate', '>', 0)
            ->with('item')
            ->get()
            ->filter(fn (EnemyDrop $drop) => $this->isEnemySpecificDropEquipment($drop->item))
            ->values();

        if ($drops->isEmpty()) {
            return [];
        }

        $granted = [];
        $usedSlots = [];
        foreach ($drops as $drop) {
            $item = $drop->item;
            if (!$item || isset($usedSlots[$item->type]) || !$this->rollPercent((float) $drop->drop_rate)) {
                continue;
            }

            $grantedDrop = $this->grantItemDrop($character, $item, (string) $item->type, $enemy, $trackExplorationLoot);
            $granted[] = $grantedDrop;
            $usedSlots[$item->type] = true;
        }

        return $granted;
    }

    private function isEnemySpecificDropEquipment(?Item $item): bool
    {
        if (!$item || !$item->is_active || !in_array((string) $item->type, ['weapon', 'armor', 'accessory'], true)) {
            return false;
        }

        $externalId = (string) ($item->external_item_id ?? '');

        return str_starts_with($externalId, 'DROP_WPN_')
            || str_starts_with($externalId, 'DROP_ARM_')
            || str_starts_with($externalId, 'DROP_ACC_');
    }

    private function equipmentCandidates(string $type, string $rank, Enemy $enemy): Collection
    {
        $cityTier = $this->enemyCityTier($enemy);

        return Item::query()
            ->where('type', $type)
            ->where('is_active', true)
            ->where('is_drop_enabled', true)
            ->whereNotIn('rarity', self::RANKS_BLOCKED_FROM_NORMAL_DROP)
            ->where(function ($query) use ($cityTier) {
                $query->whereNull('unlock_city_id')
                    ->orWhere('unlock_city_id', '<=', $cityTier);
            })
            ->where(function ($query) use ($type, $rank) {
                match ($type) {
                    'weapon' => $query->where('weapon_rank', $rank),
                    'armor' => $query->where('armor_rank', $rank),
                    'accessory' => $query->where('accessory_rank', $rank),
                    default => $query->where('rarity', $rank),
                };
            })
            ->get();
    }

    private function equipmentRankWeights(Enemy $enemy, int $rareBonusPercent = 0): array
    {
        if ($this->isGrassland($enemy)) {
            return ['G' => 100];
        }

        if ($this->isBackDungeon($enemy)) {
            return ['A' => 100];
        }

        $weights = match ($this->enemyCityTier($enemy)) {
            1 => ['G' => 75, 'F' => 22, 'E' => 3],
            2 => ['G' => 35, 'F' => 50, 'E' => 15],
            3 => ['F' => 40, 'E' => 45, 'D' => 15],
            4 => ['E' => 40, 'D' => 45, 'C' => 15],
            5 => ['D' => 40, 'C' => 45, 'B' => 15],
            6 => ['C' => 50, 'B' => 48, 'A' => 2],
            7 => ['C' => 30, 'B' => 60, 'A' => 10],
            8 => ['B' => 75, 'A' => 25],
            9 => ['B' => 50, 'A' => 50],
            10 => ['A' => 100],
            default => ['G' => 100],
        };

        return $this->withRareRankBonus($weights, $rareBonusPercent);
    }

    private function withRareRankBonus(array $weights, int $rareBonusPercent): array
    {
        if ($rareBonusPercent <= 0 || empty($weights)) {
            return $weights;
        }

        $rankOrder = ['G', 'F', 'E', 'D', 'C', 'B', 'A'];
        $availableRanks = array_values(array_intersect($rankOrder, array_keys($weights)));
        $bestRank = end($availableRanks);

        if ($bestRank) {
            $weights[$bestRank] = ($weights[$bestRank] ?? 0) + max(1, $rareBonusPercent);
        }

        return $weights;
    }

    private function rollMaterialBySpec(Character $character, Enemy $enemy, bool $trackExplorationLoot = true): ?array
    {
        $drops = MaterialDrop::where('enemy_id', $enemy->id)
            ->where('is_active', true)
            ->where('drop_first_clear_only', false)
            ->where('drop_rate', '>', 0)
            ->with('material')
            ->get()
            ->filter(function (MaterialDrop $drop) {
                $material = $drop->material;
                if (!$material) {
                    return false;
                }
                // branch_evolution は rollConfiguredBranchEvolutionMaterial で別途処理
                if ((string) ($material->material_type ?? '') === 'branch_evolution') {
                    return false;
                }
                // 廃止済み欠片コードは除外
                if ($this->isDisabledEquipmentFragmentCode((string) $material->material_code)) {
                    return false;
                }
                return true;
            })
            ->values();

        if ($drops->isNotEmpty()) {
            $material = $this->weightedMaterialDrop($drops)?->material;
            if ($material) {
                return $this->grantMaterial($character, $material, 'drop', $enemy, $trackExplorationLoot);
            }
        }

        // drops未登録の敵への最終フォールバック（汎用素材）
        $fallback = Material::whereIn('material_code', self::GENERIC_FALLBACK_CODES)
            ->inRandomOrder()
            ->first();
        if ($fallback) {
            return $this->grantMaterial($character, $fallback, 'generic', $enemy, $trackExplorationLoot);
        }

        return null;
    }

    private function rollConfiguredBranchEvolutionMaterial(Character $character, Enemy $enemy, bool $trackExplorationLoot = true): ?array
    {
        $drops = MaterialDrop::where('enemy_id', $enemy->id)
            ->where('is_active', true)
            ->where('drop_first_clear_only', false)
            ->where('drop_rate', '>', 0)
            ->with('material')
            ->get()
            ->filter(fn (MaterialDrop $drop) => (string) ($drop->material?->material_type ?? '') === 'branch_evolution')
            ->values();

        if ($drops->isEmpty()) {
            return null;
        }

        $wins = [];
        foreach ($drops as $drop) {
            if ($this->rollPercent((float) $drop->drop_rate)) {
                $wins[] = $drop;
            }
        }

        if (empty($wins)) {
            return null;
        }

        $drop = $wins[array_rand($wins)];

        return $this->grantMaterial($character, $drop->material, 'branch_evolution', $enemy, $trackExplorationLoot);
    }

    private function rollDepthBranchEvolutionMaterial(Character $character, Enemy $enemy, bool $trackExplorationLoot = true): ?array
    {
        $area = $enemy->area;
        if (!$area) {
            return null;
        }

        $cityId = (int) ($area->city_id ?? 0);
        if (!isset(self::DEPTH_BRANCH_MATERIAL_DROPS[$cityId])) {
            return null;
        }

        $state = app(ExplorationStateService::class)->currentFor($character);
        if (!$state || (int) $state->area_id !== (int) $area->id) {
            return null;
        }

        $tier = app(ExplorationDepthService::class)->activeTierFor(
            $character,
            $area,
            (int) ($state->exploration_point ?? 0),
            (int) ($state->danger_rate ?? 0)
        );
        $tierKey = (string) ($tier['key'] ?? 'surface');
        $dropConfigs = self::DEPTH_BRANCH_MATERIAL_DROPS[$cityId][$tierKey] ?? [];
        if ($dropConfigs === []) {
            return null;
        }

        $wins = [];
        foreach ($dropConfigs as $config) {
            if (!$this->matchesDepthBranchDropConfig($area, $config)) {
                continue;
            }

            if ($this->rollPercent((float) ($config['rate'] ?? 0))) {
                $wins[] = $config;
            }
        }

        if ($wins === []) {
            return null;
        }

        $win = $wins[array_rand($wins)];
        $material = Material::where('material_code', (string) $win['code'])->first();
        if (!$material) {
            return null;
        }

        return $this->grantMaterial($character, $material, 'depth_branch_evolution', $enemy, $trackExplorationLoot);
    }

    private function matchesDepthBranchDropConfig($area, array $config): bool
    {
        $areaMinLevel = (int) ($area->recommended_level_min ?? $area->recommended_level ?? 1);
        $areaMaxLevel = (int) ($area->recommended_level_max ?? $areaMinLevel);
        $min = isset($config['min_area_level']) ? (int) $config['min_area_level'] : null;
        $max = isset($config['max_area_level']) ? (int) $config['max_area_level'] : null;

        if ($min !== null && $areaMaxLevel < $min) {
            return false;
        }

        if ($max !== null && $areaMinLevel > $max) {
            return false;
        }

        return true;
    }

    private function weightedMaterialDrop(Collection $drops): ?MaterialDrop
    {
        $totalWeight = $drops->sum(fn (MaterialDrop $drop) => max(0.01, (float) $drop->drop_rate));
        if ($totalWeight <= 0) {
            return null;
        }

        $roll = mt_rand() / mt_getrandmax() * $totalWeight;
        $running = 0.0;

        foreach ($drops as $drop) {
            $running += max(0.01, (float) $drop->drop_rate);
            if ($roll <= $running) {
                return $drop;
            }
        }

        return $drops->last();
    }

    private function rollFirstClearMaterials(Character $character, Enemy $enemy): array
    {
        $droppedMaterials = [];
        $materialDrops = MaterialDrop::where('enemy_id', $enemy->id)
            ->where('is_active', true)
            ->where('drop_first_clear_only', true)
            ->where('drop_rate', '>', 0)
            ->with('material')
            ->get();

        foreach ($materialDrops as $materialDrop) {
            $material = $materialDrop->material;
            if (!$material) {
                continue;
            }

            $alreadyHas = CharacterMaterial::where('character_id', $character->id)
                ->where('material_id', $material->id)
                ->exists();
            if ($alreadyHas || !$this->rollPercent((float) $materialDrop->drop_rate)) {
                continue;
            }

            $droppedMaterials[] = $this->grantMaterial($character, $material, 'first_clear');
        }

        return $droppedMaterials;
    }

    private function isDisabledEquipmentFragmentCode(string $code): bool
    {
        return in_array($code, self::DISABLED_EQUIPMENT_FRAGMENT_CODES, true);
    }

    private function grantItemDrop(Character $character, Item $item, string $slot, ?Enemy $enemy = null, bool $trackExplorationLoot = true): array
    {
        $characterItem = CharacterItem::create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'is_equipped' => false,
            'is_stored' => false,
            'is_locked' => false,
            'enhance_level' => 0,
            'equipped_slot' => null,
            'acquired_from' => 'drop',
        ]);
        $characterItem = app(EquipmentAffixService::class)
            ->applyRandomAffixToDroppedItem($characterItem);

        if ($enemy && $trackExplorationLoot) {
            app(ExplorationStateService::class)->recordItemLoot($character, $enemy, $characterItem);
        }

        $affixLines = $characterItem->affixEffectLines();

        return [
            'slot' => $slot,
            'slot_label' => $this->slotLabel($slot),
            'item_id' => $item->id,
            'item_name' => $characterItem->displayName(),
            'base_item_name' => $item->name,
            'rarity' => $item->rarity,
            'rank' => $this->itemRank($item),
            'hp_bonus' => (int) $item->hp_bonus + (int) ($characterItem->affix_hp_bonus ?? 0),
            'mp_bonus' => (int) $item->mp_bonus,
            'str_bonus' => (int) $item->str_bonus + (int) ($characterItem->affix_str_bonus ?? 0),
            'def_bonus' => (int) $item->def_bonus + (int) ($characterItem->affix_def_bonus ?? 0),
            'agi_bonus' => (int) $item->agi_bonus + (int) ($characterItem->affix_agi_bonus ?? 0),
            'mag_bonus' => (int) $item->mag_bonus + (int) ($characterItem->affix_mag_bonus ?? 0),
            'spr_bonus' => (int) $item->spr_bonus + (int) ($characterItem->affix_spr_bonus ?? 0),
            'luk_bonus' => (int) $item->luk_bonus + (int) ($characterItem->affix_luk_bonus ?? 0),
            'has_affix' => $characterItem->hasAffix(),
            'affix_quality' => $characterItem->affix_quality,
            'affix_effect_lines' => $affixLines,
            'killer_species_key' => $characterItem->killer_species_key,
            'killer_damage_rate' => (float) ($characterItem->killer_damage_rate ?? 0),
            'character_item_id' => $characterItem->id,
        ];
    }

    private function grantMaterial(Character $character, Material $material, string $kind, ?Enemy $enemy = null, bool $trackExplorationLoot = true): array
    {
        $material = $this->normalizeGrantedMaterial($material);

        $charMat = CharacterMaterial::firstOrCreate(
            ['character_id' => $character->id, 'material_id' => $material->id],
            ['quantity' => 0]
        );
        $charMat->increment('quantity', 1);

        if ($enemy && $trackExplorationLoot && $kind !== 'first_clear') {
            app(ExplorationStateService::class)->recordMaterialLoot($character, $enemy, $material);
        }

        return [
            'material_id' => $material->id,
            'material_code' => $material->material_code,
            'name' => $material->displayName(),
            'rarity' => $material->rarity,
            'kind' => $kind,
        ];
    }

    private function normalizeGrantedMaterial(Material $material): Material
    {
        $code = (string) $material->material_code;
        $name = (string) $material->name;

        if (!$this->isDisabledEquipmentFragmentCode($code)
            && !in_array($code, self::LEGACY_COMMON_FRAGMENT_CODES, true)
            && !in_array($name, self::LEGACY_COMMON_FRAGMENT_NAMES, true)) {
            return $material;
        }

        return Material::where('material_code', self::COMMON_MONSTER_FRAGMENT_CODE)->first() ?: $material;
    }

    private function enemyArea(Enemy $enemy)
    {
        return $enemy->relationLoaded('area')
            ? $enemy->area
            : $enemy->area()->with('city')->first();
    }

    private function enemyCityTier(Enemy $enemy): int
    {
        $area = $this->enemyArea($enemy);
        $city = $area?->relationLoaded('city') ? $area->city : $area?->city;

        if ($city) {
            $cityId = (int) $city->id;
            if ($cityId >= 1 && $cityId <= 10) {
                return $cityId;
            }

            $cityOrder = (int) ($city->sort_order ?: 0);
            if ($cityOrder >= 1 && $cityOrder <= 10) {
                return $cityOrder;
            }

            return max(1, min(10, (int) ceil(max(1, $cityOrder) / 10)));
        }

        $areaOrder = (int) ($area?->sort_order ?: $area?->id ?: 1);
        return max(1, min(10, (int) ceil($areaOrder / 7)));
    }

    private function isBackDungeon(Enemy $enemy): bool
    {
        $area = $this->enemyArea($enemy);
        if ((bool) ($area?->is_route_area ?? false)) {
            return false;
        }

        return (int) ($area?->id ?? 0) >= 71
            || str_contains((string) ($area?->name ?? ''), '裏');
    }

    private function isGrassland(Enemy $enemy): bool
    {
        $area = $this->enemyArea($enemy);

        return (int) ($area?->id ?? 0) === 1
            || str_contains((string) ($area?->name ?? ''), 'はじまりの草原');
    }

    private function isBossEnemy(Enemy $enemy): bool
    {
        $role = (string) ($enemy->role ?? '');

        return (bool) $enemy->is_boss || str_contains($role, 'ボス');
    }

    private function weightedKey(array $weights): ?string
    {
        $totalWeight = array_sum(array_map(fn ($weight) => max(0, (float) $weight), $weights));
        if ($totalWeight <= 0) {
            return null;
        }

        $roll = mt_rand() / mt_getrandmax() * $totalWeight;
        $running = 0.0;
        foreach ($weights as $key => $weight) {
            $running += max(0, (float) $weight);
            if ($roll <= $running) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }

    private function rollPercent(float $rate): bool
    {
        if ($rate <= 0) {
            return false;
        }

        return rand(1, 10000) <= $rate * 100;
    }

    private function itemRank(Item $item): string
    {
        return strtoupper((string) match ($item->type) {
            'weapon' => $item->weapon_rank ?? $item->rarity,
            'armor' => $item->armor_rank ?? $item->rarity,
            'accessory' => $item->accessory_rank ?? $item->rarity,
            default => $item->rarity,
        });
    }

    private function slotLabel(string $slot): string
    {
        return match ($slot) {
            'weapon' => '武器',
            'armor' => '防具',
            'accessory' => '装飾品',
            default => '装備',
        };
    }
}
