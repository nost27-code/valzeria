<?php

namespace Tests\Support;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Item;
use App\Models\Material;
use App\Services\StorageCapacityService;
use Illuminate\Support\Str;

trait SharedStorageFixtures
{
    private function reserveMaterialSlots(Character $character, int $free): void
    {
        $summary = app(StorageCapacityService::class)->summary($character);
        $count = $summary['material_limit'] - $summary['material_total'] - $free;
        $this->assertGreaterThanOrEqual(0, $count);
        if ($count === 0) { return; }
        $material = Material::create(['material_code' => 'CAPACITY_'.Str::uuid(), 'name' => '倉庫枠確認材',
            'category' => '素材', 'rarity' => 'N', 'material_type' => 'common_drop', 'is_key_item' => false, 'is_cash_item' => false]);
        CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => $count]);
    }

    private function reserveEquipmentSlots(Character $character, int $free): void
    {
        $summary = app(StorageCapacityService::class)->summary($character);
        $count = $summary['equipment_limit'] - $summary['equipment_total'] - $free;
        $this->assertGreaterThanOrEqual(0, $count);
        if ($count === 0) { return; }
        $item = Item::create(['name' => '倉庫枠確認の剣', 'type' => 'weapon', 'weapon_rank' => 'C', 'sell_price' => 100]);
        CharacterItem::insert(array_fill(0, $count, ['character_id' => $character->id, 'item_id' => $item->id,
            'is_equipped' => false, 'created_at' => now(), 'updated_at' => now()]));
    }
}
