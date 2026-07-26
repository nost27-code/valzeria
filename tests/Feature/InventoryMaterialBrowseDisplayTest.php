<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\Material;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryMaterialBrowseDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_material_cards_show_item_book_category_and_rarity_filters(): void
    {
        $user = User::factory()->create();
        $character = Character::create([
            'user_id' => $user->id,
            'name' => '素材倉庫表示テスト',
        ]);
        $valmonMaster = ValmonMaster::create([
            'valmon_key' => 'inventory-material-display',
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
        $material = Material::create([
            'material_code' => 'TEST_INVENTORY_CITY_MATERIAL',
            'name' => '倉庫表示試験素材',
            'category' => '地域素材',
            'material_type' => 'city_material',
            'rarity' => 'CITY_HIGH',
        ]);
        CharacterMaterial::create([
            'character_id' => $character->id,
            'material_id' => $material->id,
            'quantity' => 5,
        ]);

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('倉庫表示試験素材')
            ->assertSee('都市素材')
            ->assertSee('レアリティが高い順')
            ->assertSee('data-material-rarity="R"', false);
    }
}
