<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Material;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EquipmentEnhancementService
{
    public const MAX_EQUIPMENT_ENHANCE = 3;

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
            ->map(fn (CharacterItem $characterItem) => $this->candidateRow($characterItem, $materials))
            ->values()
            ->all();
    }

    public function enhance(Character $character, CharacterItem $characterItem): array
    {
        return DB::transaction(function () use ($character, $characterItem) {
            $locked = CharacterItem::with('item')
                ->where('id', $characterItem->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->validateEnhanceTarget($character, $locked);

            $nextLevel = ((int) ($locked->enhance_level ?? 0)) + 1;
            $displayName = $locked->displayName();
            $type = (string) ($locked->item?->type ?? '');
            $recipe = $this->recipeForLevel($nextLevel, $type);

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

            $locked->enhance_level = $nextLevel;
            $locked->save();

            return [
                'message' => "{$displayName} を +{$nextLevel} に強化しました。",
                'enhance_level' => $nextLevel,
            ];
        });
    }

    public static function bonusWithEnhancement(int $base, int $enhanceLevel): int
    {
        if ($base <= 0 || $enhanceLevel <= 0) {
            return $base;
        }

        $level = min(self::MAX_EQUIPMENT_ENHANCE, $enhanceLevel);
        $rateBonus = (int) floor($base * 0.03 * $level);

        return $base + max($level, $rateBonus);
    }

    public static function enhancedStatsFor(CharacterItem $characterItem): array
    {
        $item = $characterItem->item;
        if (!$item) {
            return [];
        }

        $level = (int) ($characterItem->enhance_level ?? 0);
        $stats = [];

        foreach (self::STAT_FIELDS as $key => $field) {
            $base = (int) ($item->{$field} ?? 0);
            if ($base === 0) {
                continue;
            }

            $stats[$key] = [
                'base' => $base,
                'current' => self::bonusWithEnhancement($base, $level),
                'next' => self::bonusWithEnhancement($base, $level + 1),
            ];
        }

        return $stats;
    }

    private function candidateRow(CharacterItem $characterItem, array $materials): array
    {
        $item = $characterItem->item;
        $currentLevel = (int) ($characterItem->enhance_level ?? 0);
        $maxLevel = min(self::MAX_EQUIPMENT_ENHANCE, (int) ($item?->max_enhance ?: self::MAX_EQUIPMENT_ENHANCE));
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
            $recipe = $this->recipeForLevel($nextLevel, (string) $item->type);
            foreach ($recipe['materials'] as $materialRequirement) {
                $material = $this->resolveMaterial($materialRequirement['material_id'], $materialRequirement['material_name']);
                $owned = $materials[(string) $material->material_code] ?? 0;
                $required = (int) $materialRequirement['quantity'];
                $missing = max(0, $required - $owned);
                $requirements[] = [
                    'material_code' => (string) $material->material_code,
                    'name' => $material->name,
                    'owned' => $owned,
                    'required' => $required,
                    'missing' => $missing,
                ];
                if ($missing > 0) {
                    $canEnhance = false;
                }
            }

            if (!$canEnhance) {
                $reason = '素材が不足しています。';
            }
        }

        return [
            'character_item' => $characterItem,
            'character_item_id' => $characterItem->id,
            'type' => $item?->type ?? 'equipment',
            'type_label' => $this->typeLabel((string) ($item?->type ?? '')),
            'name' => $characterItem->displayName(),
            'rank' => $this->rankLabel($item),
            'category' => $this->categoryLabel($item),
            'is_equipped' => (bool) $characterItem->is_equipped,
            'is_locked' => (bool) $characterItem->is_locked,
            'is_stored' => false,
            'current_level' => $currentLevel,
            'next_level' => $nextLevel,
            'max_level' => $maxLevel,
            'requirements' => $requirements,
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

        if (!in_array(($characterItem->item?->type ?? null), ['weapon', 'armor', 'accessory'], true)) {
            throw new RuntimeException('強化できるのは武器・防具・装飾品のみです。');
        }

        $maxLevel = min(self::MAX_EQUIPMENT_ENHANCE, (int) ($characterItem->item?->max_enhance ?: self::MAX_EQUIPMENT_ENHANCE));
        if ((int) ($characterItem->enhance_level ?? 0) >= $maxLevel) {
            throw new RuntimeException('これ以上強化できません。');
        }
    }

    private function recipeForLevel(int $level, string $type = 'weapon'): array
    {
        if ($type === 'armor' && DB::getSchemaBuilder()->hasTable('armor_enhancement_recipes')) {
            $rows = DB::table('armor_enhancement_recipes')
                ->where('target_equipment_type', 'armor')
                ->where('enhancement_level', $level)
                ->orderBy('id')
                ->get();

            if ($rows->isEmpty()) {
                throw new RuntimeException("+{$level} の防具強化レシピが見つかりません。");
            }

            return [
                'materials' => $rows->map(fn ($row): array => [
                    'material_id' => $row->required_material_id,
                    'material_name' => $row->required_material_name,
                    'quantity' => (int) $row->required_quantity,
                ])->all(),
                'success_rate' => (int) ($rows->min('success_rate') ?? 100),
                'effect' => '基礎性能+' . ($level * 3) . '%',
            ];
        }

        $recipe = DB::table('weapon_enhancement_recipes')
            ->where('enhance_level', $level)
            ->first();

        if (!$recipe) {
            throw new RuntimeException("+{$level} の装備強化レシピが見つかりません。");
        }

        return [
            'materials' => json_decode((string) $recipe->materials, true) ?: [],
            'success_rate' => (int) ($recipe->success_rate ?? 100),
            'effect' => $recipe->effect,
        ];
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

    private function rankLabel(?object $item): string
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
