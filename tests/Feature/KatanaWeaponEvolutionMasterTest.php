<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KatanaWeaponEvolutionMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_katana_has_a_complete_base_chain_and_three_high_rank_branches(): void
    {
        $this->assertSame('images/icon/icon_224.webp', Item::weaponIconPathForCategory('katana'));

        $katanaItems = DB::table('items')
            ->where('weapon_category', 'katana')
            ->where(function ($query): void {
                $query->where('external_item_id', 'like', 'WPN_011%')
                    ->orWhere('external_item_id', 'like', 'WPN_BR_KATANA_%');
            })
            ->get();

        $this->assertCount(19, $katanaItems);
        $this->assertSame(7, $katanaItems->whereIn('weapon_rank', ['G', 'F', 'E', 'D', 'C', 'B', 'A'])->count());
        $this->assertSame(12, $katanaItems->whereIn('weapon_rank', ['S', 'SS', 'SSS', 'EPIC'])->count());

        $this->assertDatabaseHas('weapon_families', [
            'weapon_family_id' => 'KATANA',
            'base_atk' => 11,
            'base_spd' => 2,
            'base_luk' => 1,
        ]);

        $this->assertDatabaseHas('items', [
            'external_item_id' => 'WPN_0117',
            'name' => '英傑の太刀',
            'weapon_category' => 'katana',
            'weapon_rank' => 'A',
            'str_bonus' => 536,
            'agi_bonus' => 96,
            'luk_bonus' => 48,
            'weapon_offense_scale_version' => 2,
            'is_drop_enabled' => 1,
        ]);
        $this->assertDatabaseHas('items', [
            'external_item_id' => 'WPN_BR_KATANA_HOLY_S',
            'str_bonus' => 680,
            'def_bonus' => 104,
            'agi_bonus' => 64,
            'spr_bonus' => 176,
            'luk_bonus' => 24,
        ]);
        $this->assertDatabaseHas('items', [
            'external_item_id' => 'WPN_BR_KATANA_DARK_S',
            'str_bonus' => 824,
            'def_bonus' => -72,
            'mag_bonus' => 248,
        ]);
        $this->assertDatabaseHas('items', [
            'external_item_id' => 'WPN_BR_KATANA_GALE_S',
            'str_bonus' => 648,
            'agi_bonus' => 320,
            'luk_bonus' => 248,
        ]);
        $this->assertDatabaseHas('items', [
            'external_item_id' => 'WPN_BR_KATANA_HOLY_EPIC',
            'name' => '天津日継正宗',
        ]);
        $this->assertDatabaseHas('items', [
            'external_item_id' => 'WPN_BR_KATANA_DARK_EPIC',
            'name' => '黄泉津獄門',
        ]);
        $this->assertDatabaseHas('items', [
            'external_item_id' => 'WPN_BR_KATANA_GALE_EPIC',
            'name' => '天羽々斬景光',
        ]);
        $this->assertDatabaseMissing('items', ['name' => '星冠聖刀セレスティル']);
        $this->assertDatabaseMissing('items', ['name' => '深淵魔刀ネクロディア']);
        $this->assertDatabaseMissing('items', ['name' => '天翔迅刀エルフィード']);
        $this->assertDatabaseHas('weapon_evolution_recipes', [
            'to_weapon_id' => 'WPN_BR_KATANA_HOLY_EPIC',
            'to_weapon_name' => '天津日継正宗',
        ]);
        $this->assertDatabaseHas('weapon_evolution_recipes', [
            'to_weapon_id' => 'WPN_BR_KATANA_DARK_EPIC',
            'to_weapon_name' => '黄泉津獄門',
        ]);
        $this->assertDatabaseHas('weapon_evolution_recipes', [
            'to_weapon_id' => 'WPN_BR_KATANA_GALE_EPIC',
            'to_weapon_name' => '天羽々斬景光',
        ]);

        $expectedBranchNames = [
            'WPN_BR_KATANA_HOLY_S' => '白鷺丸国光',
            'WPN_BR_KATANA_HOLY_SS' => '星影宗近',
            'WPN_BR_KATANA_HOLY_SSS' => '天照一文字',
            'WPN_BR_KATANA_HOLY_EPIC' => '天津日継正宗',
            'WPN_BR_KATANA_DARK_S' => '黒炎鬼丸',
            'WPN_BR_KATANA_DARK_SS' => '夜叉丸国綱',
            'WPN_BR_KATANA_DARK_SSS' => '羅刹村正',
            'WPN_BR_KATANA_DARK_EPIC' => '黄泉津獄門',
            'WPN_BR_KATANA_GALE_S' => '青嵐兼光',
            'WPN_BR_KATANA_GALE_SS' => '風伯長光',
            'WPN_BR_KATANA_GALE_SSS' => '疾風一文字',
            'WPN_BR_KATANA_GALE_EPIC' => '天羽々斬景光',
        ];
        $actualBranchNames = DB::table('items')
            ->whereIn('external_item_id', array_keys($expectedBranchNames))
            ->pluck('name', 'external_item_id')
            ->all();
        ksort($expectedBranchNames);
        ksort($actualBranchNames);
        $this->assertSame($expectedBranchNames, $actualBranchNames);

        $actualDescriptions = DB::table('items')
            ->whereIn('external_item_id', array_keys($expectedBranchNames))
            ->pluck('description', 'external_item_id');
        foreach ($expectedBranchNames as $externalItemId => $expectedName) {
            $this->assertStringStartsWith("{$expectedName}。", $actualDescriptions->get($externalItemId));
        }

        $recipeIds = $this->masterRecipeIds();
        $this->assertSame(
            18,
            DB::table('weapon_evolution_recipes')
                ->whereIn('recipe_id', $recipeIds)
                ->where('is_active', true)
                ->count()
        );
        $this->assertSame(
            3,
            DB::table('weapon_evolution_recipes')
                ->where('from_weapon_id', 'WPN_0117')
                ->where('is_active', true)
                ->count()
        );
    }

    public function test_katana_recipes_reuse_current_slash_and_branch_material_rules(): void
    {
        $this->assertDatabaseHas('weapon_evolution_recipe_ingredients', [
            'recipe_id' => 'EVOL_KATANA_0001',
            'ingredient_type' => 'material',
            'ingredient_id' => 'MAT_COMMON_MAGIC_ORE',
            'ingredient_name' => '魔鉱片',
            'quantity' => 5,
        ]);
        $this->assertDatabaseHas('weapon_evolution_recipe_ingredients', [
            'recipe_id' => 'BR_WPN_WPN_BR_KATANA_HOLY_S_TO_WPN_BR_KATANA_HOLY_SS',
            'ingredient_id' => 'MAT_BR_WPN_HOLY_ANCIENT',
            'quantity' => 5,
        ]);
        $this->assertDatabaseHas('weapon_evolution_recipe_ingredients', [
            'recipe_id' => 'BR_WPN_WPN_BR_KATANA_HOLY_S_TO_WPN_BR_KATANA_HOLY_SS',
            'ingredient_id' => 'MAT_BR_WPN_HOLY_COMPOSITE',
            'quantity' => 3,
        ]);
        $this->assertDatabaseHas('weapon_evolution_recipe_ingredients', [
            'recipe_id' => 'BR_WPN_WPN_BR_KATANA_DARK_SS_TO_WPN_BR_KATANA_DARK_SSS',
            'ingredient_id' => 'MAT_BR_WPN_DARK_SECRET',
            'quantity' => 20,
        ]);
        $this->assertDatabaseHas('weapon_evolution_recipe_ingredients', [
            'recipe_id' => 'BR_WPN_WPN_BR_KATANA_GALE_SSS_TO_WPN_BR_KATANA_GALE_EPIC',
            'ingredient_id' => 'MAT_BR_WPN_GALE_CREST',
            'quantity' => 1,
        ]);
    }

    private function masterRecipeIds(): array
    {
        $path = base_path('database/data/katana_weapon_evolution_master.json');
        $master = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return array_column($master['recipes'], 'recipe_id');
    }
}
