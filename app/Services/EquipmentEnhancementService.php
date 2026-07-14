<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Item;
use App\Models\Material;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EquipmentEnhancementService
{
    public const MAX_EQUIPMENT_ENHANCE = 30;

    private const STAT_FIELDS = [
        'hp' => 'hp_bonus',
        'mp' => 'mp_bonus',
        'str' => 'str_bonus',
        'def' => 'def_bonus',
        'agi' => 'agi_bonus',
        'mag' => 'mag_bonus',
        'spr' => 'spr_bonus',
        'luk' => 'luk_bonus',
    ];

    private const ACCESSORY_FULL_STAT_KEYS = ['str', 'def', 'agi', 'mag', 'spr', 'luk'];

    private const MATERIAL_CODE_ALIASES = [
        'MAT_WEAPON_FRAGMENT' => 'MAT_EQUIPMENT_FRAGMENT',
        'WEV0001' => 'MAT_EQUIPMENT_FRAGMENT',
        '5001' => 'MAT_EQUIPMENT_FRAGMENT',
        'ACC0001' => 'MAT_EQUIPMENT_FRAGMENT',
        'MAT_WEAPON_CRYSTAL' => 'MAT_FINE_EQUIPMENT_FRAGMENT',
        'WEV0002' => 'MAT_FINE_EQUIPMENT_FRAGMENT',
        '5002' => 'MAT_FINE_EQUIPMENT_FRAGMENT',
        'ACC0002' => 'MAT_FINE_EQUIPMENT_FRAGMENT',
        'MAT_WEAPON_CORE' => 'MAT_STRONG_EQUIPMENT_FRAGMENT',
        'WEV0003' => 'MAT_STRONG_EQUIPMENT_FRAGMENT',
        '5003' => 'MAT_STRONG_EQUIPMENT_FRAGMENT',
        'ACC0003' => 'MAT_STRONG_EQUIPMENT_FRAGMENT',
    ];

    private const ACCESSORY_ENHANCEMENT_MATERIALS = [
        1 => [['material_id' => 'ACC0007', 'material_name' => '調律石の欠片', 'quantity' => 3]],
        2 => [['material_id' => 'ACC0007', 'material_name' => '調律石の欠片', 'quantity' => 8], ['material_id' => 'MAT_COMMON_FAIRY_DUST', 'material_name' => '妖精粉', 'quantity' => 3]],
        3 => [['material_id' => 'ACC0008', 'material_name' => '調律石', 'quantity' => 1], ['material_id' => 'ACC0007', 'material_name' => '調律石の欠片', 'quantity' => 5], ['material_id' => 'MAT_COMMON_MONSTER_CORE', 'material_name' => '魔物の魔核', 'quantity' => 6]],
        4 => [['material_id' => 'ACC0009', 'material_name' => '高純度調律石', 'quantity' => 1], ['material_id' => 'ACC0008', 'material_name' => '調律石', 'quantity' => 2], ['material_id' => 'MAT_COMMON_MAGIC_ORE', 'material_name' => '魔鉱片', 'quantity' => 6], ['material_id' => 'MAT_REFINING_CORE_LOW', 'material_name' => '粗精錬核', 'quantity' => 1]],
        5 => [['material_id' => 'ACC0009', 'material_name' => '高純度調律石', 'quantity' => 2], ['material_id' => 'ACC0008', 'material_name' => '調律石', 'quantity' => 4], ['material_id' => 'MAT_COMMON_MAGIC_ORE', 'material_name' => '魔鉱片', 'quantity' => 10], ['material_id' => 'MAT_REFINING_CORE', 'material_name' => '精錬核', 'quantity' => 1]],
    ];

    private const ENHANCEMENT_GOLD_COSTS = [
        1 => 100,
        2 => 500,
        3 => 1500,
        4 => 5000,
        5 => 15000,
    ];

    private const ENHANCEMENT_MATERIALS = [
        'armor' => [
            1 => [['material_id' => '5007', 'material_name' => '守護石の欠片', 'quantity' => 3]],
            2 => [['material_id' => '5007', 'material_name' => '守護石の欠片', 'quantity' => 8], ['material_id' => 'MAT_COMMON_MONSTER_SHELL', 'material_name' => '魔物の外殻', 'quantity' => 3]],
            3 => [['material_id' => '5008', 'material_name' => '守護石', 'quantity' => 1], ['material_id' => '5007', 'material_name' => '守護石の欠片', 'quantity' => 5], ['material_id' => 'MAT_COMMON_BEAST_FUR', 'material_name' => '獣の毛皮', 'quantity' => 6]],
            4 => [['material_id' => '5009', 'material_name' => '高純度守護石', 'quantity' => 1], ['material_id' => '5008', 'material_name' => '守護石', 'quantity' => 2], ['material_id' => 'MAT_COMMON_MONSTER_CORE', 'material_name' => '魔物の魔核', 'quantity' => 6], ['material_id' => 'MAT_REFINING_CORE_LOW', 'material_name' => '粗精錬核', 'quantity' => 1]],
            5 => [['material_id' => '5009', 'material_name' => '高純度守護石', 'quantity' => 2], ['material_id' => '5008', 'material_name' => '守護石', 'quantity' => 4], ['material_id' => 'MAT_COMMON_MONSTER_CORE', 'material_name' => '魔物の魔核', 'quantity' => 10], ['material_id' => 'MAT_REFINING_CORE', 'material_name' => '精錬核', 'quantity' => 1]],
        ],
    ];

    private const EXTENDED_MATERIALS = [
        'armor' => [
            'fragment' => ['material_id' => '5007', 'material_name' => '守護石の欠片'],
            'stone' => ['material_id' => '5008', 'material_name' => '守護石'],
            'high_purity' => ['material_id' => '5009', 'material_name' => '高純度守護石'],
            'common' => ['material_id' => 'MAT_COMMON_MONSTER_CORE', 'material_name' => '魔物の魔核'],
            'city_low' => ['material_id' => 'ARMOR_CITY_MATERIAL', 'material_name' => '街の装甲材'],
            'city_high' => ['material_id' => 'ARMOR_CITY_HIGH_MATERIAL', 'material_name' => '高位の街装甲材'],
        ],
        'accessory' => [
            'fragment' => ['material_id' => 'ACC0007', 'material_name' => '調律石の欠片'],
            'stone' => ['material_id' => 'ACC0008', 'material_name' => '調律石'],
            'high_purity' => ['material_id' => 'ACC0009', 'material_name' => '高純度調律石'],
            'common' => ['material_id' => 'MAT_COMMON_MONSTER_CORE', 'material_name' => '魔物の魔核'],
            'city_low' => ['material_id' => 'ACCESSORY_CITY_MATERIAL', 'material_name' => '街の調律材'],
            'city_high' => ['material_id' => 'ACCESSORY_CITY_HIGH_MATERIAL', 'material_name' => '高位の街調律材'],
        ],
        'shared' => [
            'low_core' => ['material_id' => 'MAT_REFINING_CORE_LOW', 'material_name' => '粗精錬核'],
            'core' => ['material_id' => 'MAT_REFINING_CORE', 'material_name' => '精錬核'],
        ],
    ];

    public function candidates(Character $character): array
    {
        $materials = $this->ownedMaterials($character);

        return CharacterItem::with('item')
            ->where('character_id', $character->id)
            ->whereHas('item', fn ($query) => $query->whereIn('type', ['weapon', 'armor', 'accessory']))
            ->orderByDesc('is_equipped')
            ->orderByRaw("CASE equipped_slot WHEN 'weapon' THEN 0 WHEN 'armor' THEN 1 WHEN 'accessory' THEN 2 ELSE 3 END")
            ->orderBy('enhance_level')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CharacterItem $characterItem) => $this->candidateRow($characterItem, $materials, $character))
            ->values()
            ->all();
    }

    public function enhance(Character $character, CharacterItem $characterItem): array
    {
        $executionLock = Cache::lock("equipment-enhancement:{$character->id}:{$characterItem->id}", 30);
        if (!$executionLock->get()) {
            throw new RuntimeException('この装備は強化処理中です。少し待ってからもう一度お試しください。');
        }

        try {
            return DB::transaction(function () use ($character, $characterItem) {
                $locked = CharacterItem::with('item')
                    ->where('id', $characterItem->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->validateEnhanceTarget($character, $locked);

                $nextLevel = ((int) ($locked->enhance_level ?? 0)) + 1;
                $displayName = $locked->displayName();
                $type = (string) ($locked->item?->type ?? '');
                $recipe = $this->recipeForLevel($nextLevel, $type, $character, $locked->item);
                $goldCost = (int) ($recipe['gold_cost'] ?? 0);
                if ($goldCost > 0 && (int) ($character->money ?? 0) < $goldCost) {
                    throw new RuntimeException(number_format($goldCost) . 'G必要です。');
                }

                foreach ($recipe['materials'] as $materialRequirement) {
                    $material = $this->resolveMaterial($materialRequirement['material_id'], $materialRequirement['material_name']);
                    $owned = CharacterMaterial::where('character_id', $character->id)
                        ->where('material_id', $material->id)
                        ->lockForUpdate()
                        ->first();

                    $quantity = (int) ($owned->quantity ?? 0);
                    $required = (int) $materialRequirement['quantity'];
                    if ($quantity < $required) {
                        throw new RuntimeException("{$material->name}が{$required}個必要です。");
                    }

                    $owned->quantity = $quantity - $required;
                    $owned->save();
                }

                if ($goldCost > 0) {
                    app(GoldService::class)->spend(
                        $character,
                        $goldCost,
                        'equipment_enhancement',
                        "{$displayName} +{$nextLevel} 強化"
                    );
                }

                $locked->enhance_level = $nextLevel;
                $locked->save();

                app(PlayerLifecycleEventService::class)->recordFirstEnhancement($character);

                return [
                    'message' => "{$displayName} を +{$nextLevel} に強化しました。",
                    'enhance_level' => $nextLevel,
                ];
            });
        } finally {
            optional($executionLock)->release();
        }
    }

    public static function bonusWithEnhancement(int $base, int $enhanceLevel): int
    {
        if ($base <= 0 || $enhanceLevel <= 0) {
            return $base;
        }

        $level = min(self::MAX_EQUIPMENT_ENHANCE, $enhanceLevel);
        $rateBonus = (int) floor($base * self::enhancementRateBps($level) / 10000);

        return $base + max(self::minimumStatBonus($level), $rateBonus);
    }

    /**
     * @return array<string, int>
     */
    public static function enhancedStatTotalsForItem(?object $item, int $enhanceLevel): array
    {
        if (!$item) {
            return [];
        }

        $baseStats = [];
        foreach (self::STAT_FIELDS as $key => $field) {
            $base = (int) ($item->{$field} ?? 0);
            if ($base === 0 && $key === 'str') {
                $base = (int) ($item->attack_bonus ?? 0);
            }
            if ($base === 0 && $key === 'agi') {
                $base = (int) ($item->speed_bonus ?? 0);
            }
            if ($base !== 0) {
                $baseStats[$key] = $base;
            }
        }

        if ((string) ($item->type ?? '') === 'accessory') {
            return self::accessoryStatsWithEnhancement($baseStats, $enhanceLevel, $item);
        }

        $stats = [];
        foreach ($baseStats as $key => $base) {
            $stats[$key] = self::bonusWithEnhancement($base, $enhanceLevel);
        }

        return $stats;
    }

    public static function enhancedStatsFor(CharacterItem $characterItem): array
    {
        $item = $characterItem->item;
        if (!$item) {
            return [];
        }

        $level = (int) ($characterItem->enhance_level ?? 0);
        $currentStats = self::enhancedStatTotalsForItem($item, $level);
        $nextStats = self::enhancedStatTotalsForItem($item, $level + 1);
        $stats = [];

        foreach (self::STAT_FIELDS as $key => $field) {
            $base = (int) ($item->{$field} ?? 0);
            if ($base === 0 && $key === 'str') {
                $base = (int) ($item->attack_bonus ?? 0);
            }
            if ($base === 0 && $key === 'agi') {
                $base = (int) ($item->speed_bonus ?? 0);
            }
            if ($base === 0) {
                continue;
            }

            $stats[$key] = [
                'base' => $base,
                'current' => $currentStats[$key] ?? $base,
                'next' => $nextStats[$key] ?? $base,
            ];
        }

        return $stats;
    }

    /**
     * @param  array<string, int>  $baseStats
     * @return array<string, int>
     */
    private static function accessoryStatsWithEnhancement(array $baseStats, int $enhanceLevel, ?object $item = null): array
    {
        if ($baseStats === [] || $enhanceLevel <= 0) {
            return $baseStats;
        }

        $level = min(self::MAX_EQUIPMENT_ENHANCE, $enhanceLevel);
        $positiveStats = array_filter($baseStats, fn (int $base): bool => $base > 0);
        $totalBase = array_sum($positiveStats);

        if ($totalBase <= 0) {
            return $baseStats;
        }

        $extraTotal = self::accessoryExtraTotalForItem($totalBase, $positiveStats, $level, $item);

        $extras = array_fill_keys(array_keys($baseStats), 0);
        $remainders = [];
        $allocated = 0;

        foreach ($positiveStats as $key => $base) {
            $rawExtra = ($extraTotal * $base) / $totalBase;
            $extra = (int) floor($rawExtra);
            $extras[$key] = $extra;
            $remainders[$key] = $rawExtra - $extra;
            $allocated += $extra;
        }

        $remaining = $extraTotal - $allocated;
        if ($remaining > 0) {
            $keys = array_keys($positiveStats);
            $fieldOrder = array_flip(array_keys(self::STAT_FIELDS));
            usort($keys, function (string $a, string $b) use ($remainders, $positiveStats, $fieldOrder): int {
                $remainderCompare = ($remainders[$b] ?? 0.0) <=> ($remainders[$a] ?? 0.0);
                if ($remainderCompare !== 0) {
                    return $remainderCompare;
                }

                $baseCompare = ($positiveStats[$b] ?? 0) <=> ($positiveStats[$a] ?? 0);
                if ($baseCompare !== 0) {
                    return $baseCompare;
                }

                return ($fieldOrder[$a] ?? PHP_INT_MAX) <=> ($fieldOrder[$b] ?? PHP_INT_MAX);
            });

            foreach ($keys as $key) {
                if ($remaining <= 0) {
                    break;
                }

                $extras[$key]++;
                $remaining--;
            }
        }

        $stats = $baseStats;
        foreach ($extras as $key => $extra) {
            $stats[$key] = ($stats[$key] ?? 0) + $extra;
        }

        return $stats;
    }

    private function candidateRow(CharacterItem $characterItem, array $materials, Character $character): array
    {
        $item = $characterItem->item;
        $currentLevel = (int) ($characterItem->enhance_level ?? 0);
        $maxLevel = $this->maxEnhanceFor($item);
        $nextLevel = $currentLevel + 1;
        $requirements = [];
        $canEnhance = $item && $currentLevel < $maxLevel;
        $reason = null;
        $recipe = null;

        if (!$item) {
            $canEnhance = false;
            $reason = '装備データが見つかりません。';
        } elseif ($currentLevel >= $maxLevel) {
            $canEnhance = false;
            $reason = '最大強化済みです。';
        } else {
            try {
                $recipe = $this->recipeForLevel($nextLevel, (string) $item->type, $character, $item);
            } catch (RuntimeException $e) {
                $canEnhance = false;
                $reason = $e->getMessage();
            }

            if ($recipe !== null) {
                foreach ($recipe['materials'] as $materialRequirement) {
                    $material = $this->resolveMaterial($materialRequirement['material_id'], $materialRequirement['material_name']);
                    $owned = $materials[(string) $material->material_code] ?? 0;
                    $required = (int) $materialRequirement['quantity'];
                    $missing = max(0, $required - $owned);
                    $requirements[] = [
                        'material_code' => (string) $material->material_code,
                        'name' => $material->name,
                        'icon_image' => $material->iconImagePath(),
                        'owned' => $owned,
                        'required' => $required,
                        'missing' => $missing,
                        'source' => $materialRequirement['source'] ?? null,
                    ];
                    if ($missing > 0) {
                        $canEnhance = false;
                    }
                }

                if (($recipe['gold_cost'] ?? 0) > (int) ($character->money ?? 0)) {
                    $canEnhance = false;
                }

                if (!$canEnhance) {
                    $reason = '素材が不足しています。';
                    if (($recipe['gold_cost'] ?? 0) > (int) ($character->money ?? 0)) {
                        $reason = '素材またはGoldが不足しています。';
                    }
                }
            }
        }

        return [
            'character_item' => $characterItem,
            'character_item_id' => $characterItem->id,
            'type' => $item?->type ?? 'equipment',
            'type_label' => $this->typeLabel((string) ($item?->type ?? '')),
            'name' => $characterItem->displayName(),
            'display_name_without_rank' => $characterItem->displayName(false),
            'rank' => $this->rankDisplayLabel($item),
            'category' => $this->categoryLabel($item),
            'is_equipped' => (bool) $characterItem->is_equipped,
            'is_locked' => (bool) $characterItem->is_locked,
            'is_stored' => false,
            'current_level' => $currentLevel,
            'next_level' => $nextLevel,
            'max_level' => $maxLevel,
            'requirements' => $requirements,
            'gold_cost' => (int) ($recipe['gold_cost'] ?? 0),
            'owned_gold' => (int) ($character->money ?? 0),
            'missing_gold' => max(0, (int) ($recipe['gold_cost'] ?? 0) - (int) ($character->money ?? 0)),
            'effect' => $recipe['effect'] ?? '+3%',
            'stats' => self::enhancedStatsFor($characterItem),
            'can_enhance' => $canEnhance,
            'unavailable_reason' => $reason,
        ];
    }

    private function validateEnhanceTarget(Character $character, CharacterItem $characterItem): void
    {
        if ((int) $characterItem->character_id !== (int) $character->id) {
            throw new RuntimeException('この装備は強化できません。');
        }
        if ($characterItem->isMarketListed()) {
            throw new RuntimeException('この武器は冒険者市場へ出品中です。操作するには先に出品を取り消してください。');
        }

        if (!in_array(($characterItem->item?->type ?? null), ['weapon', 'armor', 'accessory'], true)) {
            throw new RuntimeException('強化できるのは武器・防具・装飾品のみです。');
        }

        $maxLevel = $this->maxEnhanceFor($characterItem->item);
        if ((int) ($characterItem->enhance_level ?? 0) >= $maxLevel) {
            throw new RuntimeException('これ以上強化できません。');
        }
    }

    private function recipeForLevel(int $level, string $type = 'weapon', ?Character $character = null, ?object $item = null): array
    {
        if (in_array($type, ['weapon', 'accessory'], true)) {
            return [
                'materials' => $type === 'weapon'
                    ? $this->weaponMaterialsFor($level)
                    : $this->accessoryMaterialsFor($level),
                'gold_cost' => $this->goldCostForLevel($level, $type, $item),
                'success_rate' => 100,
                'effect' => $this->effectDescription($level, $type, $item),
            ];
        }

        if ($level > 5) {
            $materials = $this->extendedMaterialsFor($level, $type, $character, $item);
            if (!$materials) {
                throw new RuntimeException("+{$level} の装備強化レシピが見つかりません。");
            }

            return [
                'materials' => $materials,
                'gold_cost' => $this->goldCostForLevel($level, $type, $item),
                'success_rate' => 100,
                'effect' => $this->effectDescription($level, $type, $item),
            ];
        }

        $materials = $this->resolveDynamicMaterials(self::ENHANCEMENT_MATERIALS[$type][$level] ?? null, $type, $character, $item);
        if (!$materials) {
            throw new RuntimeException("+{$level} の装備強化レシピが見つかりません。");
        }

        return [
            'materials' => $materials,
            'gold_cost' => $this->goldCostForLevel($level, $type, $item),
            'success_rate' => 100,
            'effect' => $this->effectDescription($level, $type, $item),
        ];
    }

    private function goldCostForLevel(int $level, string $type, ?object $item): int
    {
        if ($type === 'weapon') {
            return $level * $level * (int) config('equipment_enhancement.weapon_gold_per_level_squared', 300);
        }

        $rank = strtoupper(trim((string) $this->rankRawLabel($item)));
        $rankOverride = config('equipment_enhancement.non_weapon_rank_gold_cost_overrides.' . $rank . '.' . $level);
        if ($rankOverride !== null) {
            return max(0, (int) $rankOverride);
        }

        $baseCost = (int) config('equipment_enhancement.base_gold_costs.' . $level, self::ENHANCEMENT_GOLD_COSTS[$level] ?? 0);
        if ($baseCost <= 0) {
            return 0;
        }

        if ($level <= 5) {
            return (int) ceil($baseCost * (float) config('equipment_enhancement.non_weapon_rank_gold_multipliers.' . $rank, 1.0));
        }

        return (int) ceil($baseCost * (int) config('equipment_enhancement.non_weapon_rank_gold_multipliers_bps.' . $rank, 10000) / 10000);
    }

    public function maxEnhanceFor(?object $item): int
    {
        if (!$item) {
            return 0;
        }

        $rank = strtoupper(trim($this->rankRawLabel($item)));
        $rankCap = config('equipment_enhancement.rank_caps.' . $rank);
        if ($rankCap !== null) {
            return min(self::MAX_EQUIPMENT_ENHANCE, (int) $rankCap);
        }

        return min(self::MAX_EQUIPMENT_ENHANCE, (int) ($item->max_enhance ?: self::MAX_EQUIPMENT_ENHANCE));
    }

    private static function enhancementRateBps(int $level): int
    {
        $total = 0;
        foreach (config('equipment_enhancement.performance_bands', []) as $band) {
            $from = (int) ($band['from'] ?? 1);
            $to = (int) ($band['to'] ?? 0);
            $appliedLevels = max(0, min($level, $to) - $from + 1);
            $total += $appliedLevels * (int) ($band['rate_bps_per_level'] ?? 0);
        }

        return $total;
    }

    private static function minimumStatBonus(int $level): int
    {
        if ($level <= 5) {
            return $level;
        }

        return 5 + intdiv($level - 5, 2);
    }

    private static function accessoryExtraTotal(int $level): int
    {
        if ($level <= 5) {
            return $level * 2;
        }

        return 10 + min($level - 5, 10) + intdiv(max(0, $level - 15), 2);
    }

    /**
     * @param  array<string, int>  $positiveStats
     */
    private static function accessoryExtraTotalForItem(int $baseTotal, array $positiveStats, int $level, ?object $item): int
    {
        $rank = strtoupper(trim((string) (
            $item?->accessory_rank
            ?? $item?->rarity
            ?? ''
        )));
        $targetTotal = self::isFullAbilityAccessory($positiveStats)
            ? config('equipment_enhancement.accessory_full_stat_target_per_stat_at_max.' . $rank)
            : config('equipment_enhancement.accessory_total_stat_targets_at_max.' . $rank);

        if ($targetTotal === null) {
            return self::accessoryExtraTotal($level);
        }

        if (self::isFullAbilityAccessory($positiveStats)) {
            $targetTotal *= count(self::ACCESSORY_FULL_STAT_KEYS);
        }

        $maxLevel = min(
            self::MAX_EQUIPMENT_ENHANCE,
            (int) config('equipment_enhancement.rank_caps.' . $rank, self::MAX_EQUIPMENT_ENHANCE)
        );
        $targetTotal = max($baseTotal, (int) $targetTotal);
        $totalAtLevel = $baseTotal + intdiv(($targetTotal - $baseTotal) * $level, max(1, $maxLevel));

        return $totalAtLevel - $baseTotal;
    }

    /**
     * @param  array<string, int>  $positiveStats
     */
    private static function isFullAbilityAccessory(array $positiveStats): bool
    {
        return array_keys($positiveStats) === self::ACCESSORY_FULL_STAT_KEYS;
    }

    private function extendedMaterialsFor(int $level, string $type, ?Character $character, ?object $item): ?array
    {
        $band = collect(config('equipment_enhancement.extended_material_bands', []))
            ->first(fn (array $candidate): bool => $level >= (int) $candidate['from'] && $level <= (int) $candidate['to']);
        if (!$band || !isset(self::EXTENDED_MATERIALS[$type])) {
            return null;
        }

        $quantities = $band['materials'] ?? [];
        if ($level === (int) $band['to']) {
            foreach (($band['milestone'] ?? []) as $key => $quantity) {
                $quantities[$key] = (int) ($quantities[$key] ?? 0) + (int) $quantity;
            }
        }

        $requirements = [];
        foreach ($quantities as $key => $quantity) {
            $definition = self::EXTENDED_MATERIALS[$type][$key] ?? self::EXTENDED_MATERIALS['shared'][$key] ?? null;
            if (!$definition || $quantity <= 0) {
                continue;
            }

            $requirements[] = $definition + ['quantity' => (int) $quantity];
        }

        return $this->resolveDynamicMaterials($requirements, $type, $character, $item);
    }

    private function weaponMaterialsFor(int $level): array
    {
        $band = collect(config('equipment_enhancement.weapon_material_recipes', []))
            ->first(fn (array $candidate): bool => $level >= (int) $candidate['from'] && $level <= (int) $candidate['to']);

        if (!$band) {
            throw new RuntimeException("+{$level} の武器強化レシピが見つかりません。");
        }

        return $band['materials'] ?? [];
    }

    private function accessoryMaterialsFor(int $level): array
    {
        return array_map(function (array $material): array {
            return match ($material['material_id']) {
                'MAT_ENHANCE_FRAGMENT' => [
                    'material_id' => 'ACC0007',
                    'material_name' => '調律石の欠片',
                    'quantity' => $material['quantity'],
                ],
                'MAT_ENHANCE_STONE' => [
                    'material_id' => 'ACC0008',
                    'material_name' => '調律石',
                    'quantity' => $material['quantity'],
                ],
                'MAT_ENHANCE_HIGH_STONE' => [
                    'material_id' => 'ACC0009',
                    'material_name' => '高純度調律石',
                    'quantity' => $material['quantity'],
                ],
                default => $material,
            };
        }, $this->weaponMaterialsFor($level));
    }

    private function effectDescription(int $level, string $type, ?object $item): string
    {
        if ($type === 'accessory') {
            $total = array_sum(array_filter(
                self::enhancedStatTotalsForItem($item, $level),
                fn (int $value): bool => $value > 0
            ));

            return '装飾品の能力値合計 ' . $total;
        }

        $bps = self::enhancementRateBps($level);
        $percent = $bps % 100 === 0 ? (string) intdiv($bps, 100) : number_format($bps / 100, 1, '.', '');

        return '基礎性能+' . $percent . '%';
    }

    private function resolveDynamicMaterials(?array $requirements, string $type, ?Character $character, ?object $item): ?array
    {
        if (!$requirements) {
            return null;
        }

        return array_map(function (array $requirement) use ($type, $character, $item): array {
            [$code, $name] = $this->resolveDynamicMaterialCode(
                (string) $requirement['material_id'],
                (string) $requirement['material_name'],
                $type,
                $character,
                $item
            );

            return [
                'material_id' => $code,
                'material_name' => $name,
                'quantity' => (int) $requirement['quantity'],
                'source' => $requirement['source'] ?? null,
            ];
        }, $requirements);
    }

    private function resolveDynamicMaterialCode(string $code, string $name, string $type, ?Character $character, ?object $item): array
    {
        return match ($code) {
            'ARMOR_CITY_MATERIAL' => $this->resolveCityMaterial('city_material', $this->enhancementCityId($character, $item), $code, $name, false),
            'ARMOR_CITY_HIGH_MATERIAL' => $this->resolveCityMaterial('city_material', $this->enhancementCityId($character, $item), $code, $name, true),
            'ACCESSORY_CITY_MATERIAL' => $this->resolveCityMaterial('city_material', $this->enhancementCityId($character, $item), $code, $name, false),
            'ACCESSORY_CITY_HIGH_MATERIAL' => $this->resolveCityMaterial('city_material', $this->enhancementCityId($character, $item), $code, $name, true),
            default => [$code, $name],
        };
    }

    private function resolveCityMaterial(string $materialType, int $cityId, string $fallbackCode, string $fallbackName, ?bool $high = null): array
    {
        $query = Material::where('material_type', $materialType)->where('city_id', $cityId);
        if ($high !== null) {
            $query->where('rarity', $high ? 'city_high' : 'city_low');
        }

        $material = $query->first();
        if (!$material) {
            $fallbackQuery = Material::where('material_type', $materialType)->orderBy('city_id');
            if ($high !== null) {
                $fallbackQuery->where('rarity', $high ? 'city_high' : 'city_low');
            }
            $material = $fallbackQuery->first();
        }

        return $material
            ? [(string) $material->material_code, $material->name]
            : [$fallbackCode, $fallbackName];
    }

    private function enhancementCityId(?Character $character, ?object $item): int
    {
        $cityId = (int) ($item?->unlock_city_id ?? 0);
        if ($cityId <= 0) {
            $cityId = (int) ($character?->current_city_id ?? $character?->highest_city_id ?? 1);
        }

        return max(1, min(10, $cityId));
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'weapon' => '武器',
            'armor' => '防具',
            'accessory' => '装飾品',
            default => '装備',
        };
    }

    private function rankDisplayLabel(?object $item): string
    {
        $rank = $this->rankRawLabel($item);

        if (strtoupper($rank) === 'SPECIAL' && (string) ($item?->source_type ?? '') === 'star_tree_tower_reward') {
            return '星樹';
        }

        return $rank;
    }

    private function rankRawLabel(?object $item): string
    {
        if (!$item) {
            return '-';
        }

        return $item->weapon_rank
            ?? $item->armor_rank
            ?? $item->accessory_rank
            ?? $item->rarity
            ?? '-';
    }

    private function categoryLabel(?object $item): string
    {
        if (!$item) {
            return '装備';
        }

        if ($item instanceof Item) {
            return app(EquipmentPermissionService::class)->categoryLabel($item)
                ?? $this->typeLabel((string) $item->type);
        }

        return $item->weapon_category
            ?? $item->armor_category_name
            ?? $item->armor_category
            ?? $item->accessory_category_name
            ?? $item->accessory_category_id
            ?? $item->sub_type
            ?? $this->typeLabel((string) $item->type);
    }

    private function ownedMaterials(Character $character): array
    {
        return CharacterMaterial::with('material')
            ->where('character_id', $character->id)
            ->get()
            ->filter(fn (CharacterMaterial $row) => $row->material)
            ->mapWithKeys(fn (CharacterMaterial $row) => [
                (string) $row->material->material_code => (int) $row->quantity,
            ])
            ->all();
    }

    private function resolveMaterial(?string $code, ?string $name): Material
    {
        if ($code && isset(self::MATERIAL_CODE_ALIASES[$code])) {
            $code = self::MATERIAL_CODE_ALIASES[$code];
        }

        $material = null;
        if ($code) {
            $material = Material::where('material_code', $code)->first();
        }

        if (!$material && $name) {
            $material = Material::where('name', $name)->first();
        }

        if (!$material) {
            throw new RuntimeException('強化素材のマスタが見つかりません。');
        }

        return $material;
    }
}
