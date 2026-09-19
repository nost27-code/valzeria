<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\Material;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\StorageCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryUnlockKeyStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_evolution_unlock_keys_are_kept_outside_material_capacity_and_protected_from_sale(): void
    {
        $user = User::factory()->create();
        $character = Character::create(['user_id' => $user->id, 'name' => '進化証の倉庫確認者']);
        $valmonMaster = ValmonMaster::create([
            'valmon_key' => 'inventory-unlock-key',
            'name' => '倉庫確認モン',
            'rarity' => 'normal',
            'is_active' => true,
        ]);
        PlayerValmon::create([
            'character_id' => $character->id,
            'valmon_master_id' => $valmonMaster->id,
            'is_partner' => true,
            'obtained_at' => now(),
        ]);

        $typedKey = Material::create([
            'material_code' => 'TEST_WEAPON_UNLOCK_KEY',
            'name' => '試験の進化証',
            'category' => '進化素材',
            'material_type' => 'weapon_unlock_key',
            'rarity' => 'KEY',
            'npc_sale_price' => 100,
        ]);
        $categoryKey = Material::create([
            'material_code' => 'TEST_CATEGORY_UNLOCK_KEY',
            'name' => '別系統の進化証',
            'category' => '進化解放キー',
            'material_type' => 'weapon_synthesis',
            'rarity' => 'KEY',
            'npc_sale_price' => 100,
        ]);
        $genericKey = Material::create([
            'material_code' => 'TEST_GENERIC_KEY_ITEM',
            'name' => '試験の進行証',
            'category' => '進行素材',
            'material_type' => 'key_item',
            'rarity' => 'KEY',
        ]);
        $ordinaryMaterial = Material::create([
            'material_code' => 'TEST_ORDINARY_PROTECTED_FLAG',
            'name' => '試験の通常素材',
            'category' => '都市素材',
            'material_type' => 'weapon_city',
            'rarity' => 'R',
            'is_key_item' => true,
        ]);

        $typedRow = CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $typedKey->id, 'quantity' => 2]);
        CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $categoryKey->id, 'quantity' => 3]);
        CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $genericKey->id, 'quantity' => 1]);
        CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $ordinaryMaterial->id, 'quantity' => 499]);

        $this->assertSame(499, app(StorageCapacityService::class)->summary($character)['material_total']);
        $this->assertFalse(app(StorageCapacityService::class)->isFull($character));

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertViewHas('storageSummary', fn (array $summary): bool => $summary['material_storage_total'] === 499)
            ->assertViewHas('keyItems', fn ($items): bool => $items->pluck('name')->contains('試験の進化証')
                && $items->pluck('name')->contains('別系統の進化証')
                && $items->pluck('name')->contains('試験の進行証'));

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('inventory.sell'), ['character_material_id' => $typedRow->id, 'quantity' => 1])
            ->assertRedirect(route('inventory.index'))
            ->assertSessionHas('error', '大事なものは売却できません。');

        $this->assertSame(2, (int) $typedRow->fresh()->quantity);
    }
}
