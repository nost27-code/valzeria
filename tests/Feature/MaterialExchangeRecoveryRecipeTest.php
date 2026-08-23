<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Item;
use App\Models\Material;
use App\Models\User;
use App\Services\MaterialExchangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_large_recovery_brewing_does_not_insert_each_item_separately(): void
    {
        $character = $this->createCharacter();
        $worldTreeLeaf = $this->createMaterial('MAT_REGION_WORLD_TREE_LEAF', '世界樹の葉片');
        $fairyDust = $this->createMaterial('MAT_COMMON_FAIRY_DUST', '妖精粉');
        $herb = Item::query()->firstOrCreate(['name' => '薬草', 'type' => 'consumable']);

        CharacterMaterial::query()->create([
            'character_id' => $character->id,
            'material_id' => $worldTreeLeaf->id,
            'quantity' => 500,
        ]);
        CharacterMaterial::query()->create([
            'character_id' => $character->id,
            'material_id' => $fairyDust->id,
            'quantity' => 1500,
        ]);

        $service = app(MaterialExchangeService::class);
        $recipe = collect($service->recipes($character))->first(
            fn (array $recipe): bool => collect($recipe['source_materials'] ?? [])
                ->contains('material_code', 'MAT_REGION_WORLD_TREE_LEAF')
        );
        $this->assertNotNull($recipe);

        $characterItemInsertCount = 0;
        DB::listen(function ($query) use (&$characterItemInsertCount): void {
            if (str_contains(strtolower($query->sql), 'insert into "character_items"')) {
                $characterItemInsertCount++;
            }
        });

        $service->exchange($character, $recipe['id'], 500);

        $this->assertSame(1000, CharacterItem::query()
            ->where('character_id', $character->id)
            ->where('item_id', $herb->id)
            ->count());
        $this->assertLessThanOrEqual(20, $characterItemInsertCount);
        $this->assertDatabaseMissing('character_materials', [
            'character_id' => $character->id,
            'material_id' => $worldTreeLeaf->id,
        ]);
        $this->assertDatabaseMissing('character_materials', [
            'character_id' => $character->id,
            'material_id' => $fairyDust->id,
        ]);
    }

    public function test_material_exchange_page_uses_its_async_lock_and_timeout_guidance(): void
    {
        $character = $this->createCharacter();
        $this->withoutMiddleware(CheckCharacterSelected::class);

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('material-exchange.index'))
            ->assertOk()
            ->assertSee('data-submit-lock="off"', false)
            ->assertSee('materialExchangeRequestTimeoutMs = 30000', false)
            ->assertSee('materialExchangeSubmitting', false)
            ->assertSee('画面を再読み込みし、所持品を確認してからもう一度お試しください。', false);
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
