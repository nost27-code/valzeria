<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

class EquipmentPermissionService
{
    private array $canEquipCache = [];
    private array $representativeJobNamesCache = [];
    private array $nativeWeaponCategoryLabelsCache = [];
    private array $nativeCategoryKeysCache = [];

    private const WEAPON_LABELS = [
        'sword' => '剣',
        'axe' => '斧',
        'dagger' => '短剣',
        'bow' => '弓',
        'staff' => '杖',
        'magic_device' => '魔導具',
        'gun' => '銃',
        'spear' => '槍',
        'fist' => '拳甲',
        'katana' => '刀',
    ];

    private const WEAPON_PROFICIENCY_GROUP_LABELS = [
        'sword' => '剣',
        'axe' => '重量武器',
        'dagger' => '短剣',
        'bow' => '弓',
        'staff' => '杖',
        'magic_device' => '魔法具',
        'gun' => '銃器',
        'spear' => '槍',
        'fist' => '格闘武器',
        'katana' => '刀',
    ];

    private const WEAPON_ROLE_LABELS = [
        '斧' => '一撃型',
        '棍棒' => '堅守型',
        '銃' => '先手型',
        '機工銃' => '物魔両用型',
    ];

    private const ARMOR_LABELS = [
        'clothes' => '服・旅装',
        'robe' => 'ローブ・法衣',
        'cloak' => '外套・マント',
        'light_armor' => '革鎧・軽鎧',
        'heavy_armor' => '鎧・重鎧',
    ];

    public function canEquip(Character $character, Item $item): bool
    {
        if (!in_array($item->type, ['weapon', 'armor'], true)) {
            return true;
        }

        if ($this->isNonProficientPenaltyEnabled()) {
            return true;
        }

        return $this->hasNativeProficiency($character, $item);
    }

    public function hasNativeProficiency(Character $character, Item $item): bool
    {
        if (!in_array($item->type, ['weapon', 'armor'], true)) {
            return true;
        }

        $category = $this->categoryKey($item);
        if (!$category || !$character->current_job_id) {
            return true;
        }

        $table = $item->type === 'weapon' ? 'job_weapon_permissions' : 'job_armor_permissions';
        $column = $item->type === 'weapon' ? 'weapon_category' : 'armor_category';
        $cacheKey = implode(':', [
            (int) $character->current_job_id,
            $item->type,
            $category,
        ]);

        if (array_key_exists($cacheKey, $this->canEquipCache)) {
            return $this->canEquipCache[$cacheKey];
        }

        return $this->canEquipCache[$cacheKey] = DB::table($table)
            ->where('job_id', $character->current_job_id)
            ->where($column, $category)
            ->exists();
    }

    public function performanceRate(Character $character, Item $item): float
    {
        if (!$this->isNonProficientPenaltyEnabled() || $this->hasNativeProficiency($character, $item)) {
            return 1.0;
        }

        $fallbackRate = (float) config('equipment_proficiency.non_proficient.effect_rate', 0.65);
        $rate = $item->type === 'weapon'
            ? (float) config("equipment_proficiency.non_proficient.weapon_effect_rates.{$item->weapon_category}", $fallbackRate)
            : $fallbackRate;

        return max(0.0, min(1.0, $rate));
    }

    public function hasPerformancePenalty(Character $character, Item $item): bool
    {
        return $this->performanceRate($character, $item) < 1.0;
    }

    public function effectiveKillerDamageRate(Character $character, \App\Models\CharacterItem $characterItem): float
    {
        return $characterItem->effectiveKillerDamageRate()
            * $this->performanceRate($character, $characterItem->item);
    }

    public function effectiveSpeciesDamageReductionRate(Character $character, \App\Models\CharacterItem $characterItem): float
    {
        return $characterItem->effectiveSpeciesDamageReductionRate()
            * $this->performanceRate($character, $characterItem->item);
    }

    public function isNonProficientPenaltyEnabled(): bool
    {
        return (bool) config('equipment_proficiency.non_proficient.enabled', false);
    }

    public function categoryKey(Item $item): ?string
    {
        return match ($item->type) {
            'weapon' => $item->weapon_category ?: null,
            'armor' => $item->armor_category ?: null,
            default => null,
        };
    }

    public function categoryLabel(Item $item): ?string
    {
        $category = $this->categoryKey($item);
        if (!$category) {
            return null;
        }

        return match ($item->type) {
            'weapon' => self::WEAPON_LABELS[$category] ?? $category,
            'armor' => self::ARMOR_LABELS[$category] ?? $category,
            default => null,
        };
    }

    public function proficiencyGroupLabel(Item $item): ?string
    {
        if ($item->type !== 'weapon' || !$item->weapon_category) {
            return null;
        }

        return self::WEAPON_PROFICIENCY_GROUP_LABELS[$item->weapon_category]
            ?? self::WEAPON_LABELS[$item->weapon_category]
            ?? $item->weapon_category;
    }

    public function weaponRoleLabel(Item $item): ?string
    {
        if ($item->type !== 'weapon') {
            return null;
        }

        return self::WEAPON_ROLE_LABELS[(string) $item->sub_type] ?? null;
    }

    /**
     * @return list<string>
     */
    public function nativeCategoryKeys(?int $jobId, string $type): array
    {
        if (!$jobId || !in_array($type, ['weapon', 'armor'], true)) {
            return [];
        }

        $cacheKey = "{$jobId}:{$type}";
        if (array_key_exists($cacheKey, $this->nativeCategoryKeysCache)) {
            return $this->nativeCategoryKeysCache[$cacheKey];
        }

        $table = $type === 'weapon' ? 'job_weapon_permissions' : 'job_armor_permissions';
        $column = $type === 'weapon' ? 'weapon_category' : 'armor_category';

        return $this->nativeCategoryKeysCache[$cacheKey] = DB::table($table)
            ->where('job_id', $jobId)
            ->pluck($column)
            ->map(fn ($category) => (string) $category)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function nativeWeaponCategoryLabels(?int $jobId): array
    {
        if (!$jobId) {
            return [];
        }

        if (array_key_exists($jobId, $this->nativeWeaponCategoryLabelsCache)) {
            return $this->nativeWeaponCategoryLabelsCache[$jobId];
        }

        $categories = $this->nativeCategoryKeys($jobId, 'weapon');

        $categorySet = array_fill_keys($categories, true);
        $orderedCategories = array_values(array_filter(
            array_keys(self::WEAPON_LABELS),
            fn (string $category): bool => isset($categorySet[$category]),
        ));
        $orderedCategories = array_merge(
            $orderedCategories,
            array_values(array_diff($categories, $orderedCategories)),
        );

        return $this->nativeWeaponCategoryLabelsCache[$jobId] = array_map(
            fn (string $category): string => self::WEAPON_LABELS[$category] ?? $category,
            $orderedCategories,
        );
    }

    public function representativeJobNames(Item $item, int $limit = 4): array
    {
        $category = $this->categoryKey($item);
        if (!$category) {
            return [];
        }

        $table = $item->type === 'weapon' ? 'job_weapon_permissions' : 'job_armor_permissions';
        $column = $item->type === 'weapon' ? 'weapon_category' : 'armor_category';
        $cacheKey = implode(':', [$item->type, $category, $limit]);

        if (array_key_exists($cacheKey, $this->representativeJobNamesCache)) {
            return $this->representativeJobNamesCache[$cacheKey];
        }

        return $this->representativeJobNamesCache[$cacheKey] = DB::table($table)
            ->join('job_classes', 'job_classes.id', '=', "{$table}.job_id")
            ->where("{$table}.{$column}", $category)
            ->where('job_classes.is_active', true)
            ->orderBy('job_classes.sort_order')
            ->limit($limit)
            ->pluck('job_classes.name')
            ->all();
    }

    public function restrictionMessage(Character $character, Item $item): ?string
    {
        if ($this->canEquip($character, $item)) {
            return null;
        }

        return $item->type === 'weapon'
            ? '現在の職業ではこの武器を装備できません。'
            : '現在の職業ではこの防具を装備できません。';
    }
}
