<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;

class StorageCapacityService
{
    public function summary(Character $character): array
    {
        $materialTotal = CharacterMaterial::query()
            ->where('character_id', $character->id)
            ->where('quantity', '>', 0)
            ->whereHas('material')
            ->with('material')
            ->get()
            ->reject(fn (CharacterMaterial $row) => $this->isKeyMaterial($row->material))
            ->sum('quantity');

        $equipmentTotal = CharacterItem::query()
            ->where('character_id', $character->id)
            ->whereHas('item', fn ($query) => $query->whereIn('type', ['weapon', 'armor', 'accessory']))
            ->with('item')
            ->get()
            ->reject(fn (CharacterItem $row) => $this->isKeyItem($row))
            ->count();

        $materialLimit = (int) ($character->material_storage_limit ?? 500);
        $equipmentLimit = (int) ($character->equipment_storage_limit ?? 200);

        return [
            'material_total' => (int) $materialTotal,
            'material_limit' => $materialLimit,
            'equipment_total' => (int) $equipmentTotal,
            'equipment_limit' => $equipmentLimit,
            'material_full' => $materialLimit > 0 && (int) $materialTotal >= $materialLimit,
            'equipment_full' => $equipmentLimit > 0 && (int) $equipmentTotal >= $equipmentLimit,
        ];
    }

    public function isFull(Character $character): bool
    {
        $summary = $this->summary($character);

        return $summary['material_full'] || $summary['equipment_full'];
    }

    public function fullMessageHtml(Character $character): string
    {
        $summary = $this->summary($character);
        $lines = [];

        if ($summary['material_full']) {
            $lines[] = '素材倉庫: ' . number_format($summary['material_total']) . ' / ' . number_format($summary['material_limit']);
        }

        if ($summary['equipment_full']) {
            $lines[] = '装備倉庫: ' . number_format($summary['equipment_total']) . ' / ' . number_format($summary['equipment_limit']);
        }

        $details = $lines ? '<br><span class="text-xs">' . e(implode('　', $lines)) . '</span>' : '';
        $inventoryUrl = route('inventory.index');
        $supportUrl = route('kiseki.support');

        return '倉庫がいっぱいです。探索する前に'
            . '<a href="' . e($inventoryUrl) . '" class="underline underline-offset-2 font-extrabold">倉庫の整理</a>'
            . 'をしてください。倉庫の拡張は'
            . '<a href="' . e($supportUrl) . '" class="underline underline-offset-2 font-extrabold">こちら</a>'
            . 'で行えます。'
            . $details;
    }

    private function isKeyMaterial(?object $material): bool
    {
        if (!$material) {
            return false;
        }

        $name = (string) ($material->name ?? '');
        $category = (string) ($material->category ?? '');
        $mainUse = (string) ($material->main_use ?? '');
        $materialType = (string) ($material->material_type ?? '');
        $categoryId = (string) ($material->category_id ?? '');

        return $materialType === 'boss_unique'
            || $categoryId === 'boss_unique'
            || str_contains($category, '討伐証')
            || str_contains($category, 'ボス特異素材')
            || str_contains($mainUse, 'レシピ解放キー')
            || str_contains($mainUse, '解放キー')
            || str_ends_with($name, 'の刻印')
            || str_ends_with($name, 'の王印')
            || str_ends_with($name, 'の神印');
    }

    private function isKeyItem(CharacterItem $characterItem): bool
    {
        $item = $characterItem->item;
        if (!$item) {
            return false;
        }

        $name = (string) ($item->name ?? '');
        $subType = (string) ($item->sub_type ?? '');

        return in_array($subType, ['刻印', '王印', '神印'], true)
            || str_ends_with($name, 'の刻印')
            || str_ends_with($name, 'の王印')
            || str_ends_with($name, 'の神印');
    }
}
