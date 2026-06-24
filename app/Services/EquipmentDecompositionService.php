<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use Illuminate\Support\Collection;
use RuntimeException;

class EquipmentDecompositionService
{
    private const DISABLED_MESSAGE = '装備分解は現在停止中です。不要な装備は売却してください。';

    public function candidates(Character $character): Collection
    {
        return collect();
    }

    public function candidate(CharacterItem $characterItem): array
    {
        $item = $characterItem->item;

        return [
            'character_item' => $characterItem,
            'equipment_instance_id' => $characterItem->id,
            'equipment_master_id' => $item?->id,
            'equipment_name' => $characterItem->displayName(),
            'equipment_type' => $item?->type,
            'equipment_type_label' => $this->typeLabel($item?->type),
            'rank' => $this->rank($item),
            'rank_sort' => $this->rankSort($this->rank($item)),
            'enhancement_level' => (int) ($characterItem->enhance_level ?? 0),
            'category' => $this->categoryName($item),
            'is_equipped' => (bool) $characterItem->is_equipped,
            'is_locked' => (bool) ($characterItem->is_locked ?? false),
            'is_stored' => (bool) ($characterItem->is_stored ?? false),
            'can_disassemble' => false,
            'unavailable_reason' => self::DISABLED_MESSAGE,
            'expected_materials' => [],
        ];
    }

    public function disassemble(Character $character, CharacterItem $characterItem): array
    {
        throw new RuntimeException(self::DISABLED_MESSAGE);
    }

    private function categoryName($item): string
    {
        if (!$item) {
            return '装備';
        }

        return match ($item->type) {
            'weapon' => (string) ($item->weapon_family_name ?? $item->sub_type ?? '武器'),
            'armor' => (string) ($item->armor_family_name ?? $item->sub_type ?? '防具'),
            'accessory' => (string) ($item->accessory_family_name ?? $item->sub_type ?? '装飾品'),
            default => '装備',
        };
    }

    private function rank($item): ?string
    {
        if (!$item) {
            return null;
        }

        return strtoupper((string) match ($item->type) {
            'weapon' => $item->weapon_rank ?? $item->rarity,
            'armor' => $item->armor_rank ?? $item->rarity,
            'accessory' => $item->accessory_rank ?? $item->rarity,
            default => $item->rarity,
        });
    }

    private function typeLabel(?string $type): string
    {
        return match ($type) {
            'weapon' => '武器',
            'armor' => '防具',
            'accessory' => '装飾品',
            default => '装備',
        };
    }

    private function rankSort(?string $rank): int
    {
        return ['G' => 1, 'F' => 2, 'E' => 3, 'D' => 4, 'C' => 5, 'B' => 6, 'A' => 7, 'S' => 8, 'SS' => 9, 'SSS' => 10, 'EPIC' => 11][$rank] ?? 99;
    }
}
