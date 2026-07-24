<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentHpPerformanceMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reduces_only_equipment_and_legacy_affix_hp_from_eightfold_to_fourfold(): void
    {
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'HP移行テスト',
            'explore_stamina' => 0,
            'hp_base' => 100, 'mp_base' => 0,
            'attack_base' => 0, 'defense_base' => 0,
            'speed_base' => 0, 'magic_base' => 0,
            'spirit_base' => 0, 'luck_base' => 0,
        ]);
        $weapon = Item::query()->create(['name' => '8倍武器', 'type' => 'weapon', 'hp_bonus' => 80, 'is_active' => true]);
        $armor = Item::query()->create(['name' => '8倍防具', 'type' => 'armor', 'hp_bonus' => 81, 'is_active' => true]);
        $accessory = Item::query()->create(['name' => '8倍装飾品', 'type' => 'accessory', 'hp_bonus' => 82, 'is_active' => true]);
        $nonEquipment = Item::query()->create(['name' => '対象外', 'type' => 'consumable', 'hp_bonus' => 80, 'is_active' => true]);

        $weaponInstance = CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $weapon->id, 'affix_hp_bonus' => 80]);
        $armorInstance = CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $armor->id, 'affix_hp_bonus' => 81]);
        $accessoryInstance = CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $accessory->id, 'affix_hp_bonus' => 82]);
        $nonEquipmentInstance = CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $nonEquipment->id, 'affix_hp_bonus' => 80]);

        $migration = require database_path('migrations/2026_07_24_040000_reduce_equipment_hp_performance_to_fourfold.php');
        $migration->up();

        $this->assertSame(40, $weapon->refresh()->hp_bonus);
        $this->assertSame(41, $armor->refresh()->hp_bonus);
        $this->assertSame(41, $accessory->refresh()->hp_bonus);
        $this->assertSame(80, $nonEquipment->refresh()->hp_bonus);
        $this->assertSame(40, $weaponInstance->refresh()->affix_hp_bonus);
        $this->assertSame(41, $armorInstance->refresh()->affix_hp_bonus);
        $this->assertSame(41, $accessoryInstance->refresh()->affix_hp_bonus);
        $this->assertSame(80, $nonEquipmentInstance->refresh()->affix_hp_bonus);
    }
}
