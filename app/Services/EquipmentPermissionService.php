<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

class EquipmentPermissionService
{
    private array $canEquipCache = [];
    private array $representativeJobNamesCache = [];

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
