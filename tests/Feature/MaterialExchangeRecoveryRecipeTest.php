<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Item;
use App\Models\Material;
use App\Models\User;
use App\Services\MaterialExchangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialExchangeRecoveryRecipeTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_potion_and_mana_water_use_the_approved_common_material_recipes(): void
    {
        $character = $this->createCharacter();
        $beastFang = $this->createMaterial('MAT_COMMON_BEAST_FANG', '獣牙');
        $monsterFragment = $this->createMaterial('MAT_COMMON_MONSTER_FRAGMENT', '魔物の欠片');
        $magicOre = $this->createMaterial('MAT_COMMON_MAGIC_ORE', '魔鉱片');
        $recoveryPotion = Item::query()->firstOrCreate(['name' => '回復薬', 'type' => 'consumable']);
        $manaWater = Item::query()->firstOrCreate(['name' => '魔力水', 'type' => 'consumable']);

        CharacterMaterial::query()->create(['character_id' => $character->id, 'material_id' => $beastFang->id, 'quantity' => 3]);
        CharacterMaterial::query()->create(['character_id' => $character->id, 'material_id' => $monsterFragment->id, 'quantity' => 4]);
        CharacterMaterial::query()->create(['character_id' => $character->id, 'material_id' => $magicOre->id, 'quantity' => 3]);

        $service = app(MaterialExchangeService::class);
        $recipes = collect($service->recipes($character));
        $recoveryRecipe = $recipes->firstWhere('target_code', '回復薬');
        $manaRecipe = $recipes->firstWhere('target_code', '魔力水');

        $this->assertSame([
            'MAT_COMMON_BEAST_FANG' => 3,
            'MAT_COMMON_MONSTER_FRAGMENT' => 2,
        ], collect($recoveryRecipe['source_materials'])->pluck('required', 'material_code')->all());
        $this->assertSame('探索中にHPを60%回復', $recoveryRecipe['target_usage']);
        $this->assertSame([
            'MAT_COMMON_MAGIC_ORE' => 3,
            'MAT_COMMON_MONSTER_FRAGMENT' => 2,
        ], collect($manaRecipe['source_materials'])->pluck('required', 'material_code')->all());
        $this->assertSame('探索中にSPを30%回復', $manaRecipe['target_usage']);

        $service->exchange($character, $recoveryRecipe['id']);
        $service->exchange($character, $manaRecipe['id']);

        $this->assertSame(1, CharacterItem::query()->where('character_id', $character->id)->where('item_id', $recoveryPotion->id)->count());
        $this->assertSame(1, CharacterItem::query()->where('character_id', $character->id)->where('item_id', $manaWater->id)->count());
        $this->assertDatabaseMissing('character_materials', ['character_id' => $character->id, 'material_id' => $beastFang->id]);
        $this->assertDatabaseMissing('character_materials', ['character_id' => $character->id, 'material_id' => $monsterFragment->id]);
        $this->assertDatabaseMissing('character_materials', ['character_id' => $character->id, 'material_id' => $magicOre->id]);
    }

    private function createCharacter(): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => '調合テスト',
            'money' => 0,
            'explore_stamina' => 0,
        ]);
    }

    private function createMaterial(string $code, string $name): Material
    {
        return Material::query()->updateOrCreate([
            'material_code' => $code,
        ], [
            'name' => $name,
            'category' => '共通素材',
            'rarity' => 'N',
            'npc_sale_price' => 0,
            'is_tradable' => false,
        ]);
    }
}
