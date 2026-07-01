<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COMPOSITES = [
        'MAT_BR_WPN_HOLY_COMPOSITE' => [
            'name' => '聖天織晶',
            'obtain_method' => '素材交換所で精霊王の絹糸、天界の羽根、天空の羽布、天空竜の織布、王都の守護布から錬成します。',
        ],
        'MAT_BR_WPN_DARK_COMPOSITE' => [
            'name' => '冥黒織晶',
            'obtain_method' => '素材交換所で深魔の黒布、深淵の欠片、魔王城の黒布、魔王の黒装片、瘴気の革片から錬成します。',
        ],
        'MAT_BR_WPN_GALE_COMPOSITE' => [
            'name' => '翠嵐織晶',
            'obtain_method' => '素材交換所で精霊樹の繊維、精霊王の絹糸、天空の羽布、天界の羽根、天空竜の織布から錬成します。',
        ],
        'MAT_BR_ARM_HEAVY_COMPOSITE' => [
            'name' => '鋼氷護晶',
            'obtain_method' => '素材交換所で氷帝の守護布、氷晶片、黒鉄の部材、炉心の耐熱布から錬成します。',
        ],
        'MAT_BR_ARM_ARCANE_COMPOSITE' => [
            'name' => '星導魔晶',
            'obtain_method' => '素材交換所で魔導繊維、大魔導の星布、魔導結晶、炉心の耐熱布から錬成します。',
        ],
        'MAT_BR_ARM_LIGHT_COMPOSITE' => [
            'name' => '風精織晶',
            'obtain_method' => '素材交換所で精霊王の絹糸、天界の羽根、精霊樹の繊維、天空の羽布から錬成します。',
        ],
        'MAT_BR_ARM_TRAVELER_COMPOSITE' => [
            'name' => '砂海旅晶',
            'obtain_method' => '素材交換所で砂王の宝布、古代砂晶、潮風の布片、海守りの織布から錬成します。',
        ],
    ];

    private const WEAPON_RECIPES = [
        '_HOLY_S_TO_' => ['MAT_BR_WPN_HOLY_ANCIENT', '聖剣の古代片', 'MAT_BR_WPN_HOLY_COMPOSITE', '聖天織晶'],
        '_DARK_S_TO_' => ['MAT_BR_WPN_DARK_ANCIENT', '魔剣の古代片', 'MAT_BR_WPN_DARK_COMPOSITE', '冥黒織晶'],
        '_GALE_S_TO_' => ['MAT_BR_WPN_GALE_ANCIENT', '迅刃の古代片', 'MAT_BR_WPN_GALE_COMPOSITE', '翠嵐織晶'],
    ];

    private const ARMOR_RECIPES = [
        'BR_ARM_4001_TO_4002' => ['MAT_BR_ARM_HEAVY_ANCIENT', '重装の古代片', 'MAT_BR_ARM_HEAVY_COMPOSITE', '鋼氷護晶'],
        'BR_ARM_4005_TO_4006' => ['MAT_BR_ARM_ARCANE_ANCIENT', '魔装の古代片', 'MAT_BR_ARM_ARCANE_COMPOSITE', '星導魔晶'],
        'BR_ARM_4009_TO_4010' => ['MAT_BR_ARM_LIGHT_ANCIENT', '軽装の古代片', 'MAT_BR_ARM_LIGHT_COMPOSITE', '風精織晶'],
        'BR_ARM_4013_TO_4014' => ['MAT_BR_ARM_TRAVELER_ANCIENT', '旅装の古代片', 'MAT_BR_ARM_TRAVELER_COMPOSITE', '砂海旅晶'],
    ];

    private const OLD_WEAPON_RECIPES = [
        '_HOLY_S_TO_' => [
            ['MAT_BR_WPN_HOLY_ANCIENT', '聖剣の古代片', 5],
            ['MAT_REGION_HEAVEN_FEATHER', '天界の羽根', 3],
            ['5030', '精霊王の絹糸', 3],
        ],
        '_DARK_S_TO_' => [
            ['MAT_BR_WPN_DARK_ANCIENT', '魔剣の古代片', 5],
            ['MAT_REGION_ABYSS_FRAGMENT', '深淵の欠片', 3],
            ['5040', '深魔の黒布', 3],
        ],
        '_GALE_S_TO_' => [
            ['MAT_BR_WPN_GALE_ANCIENT', '迅刃の古代片', 5],
            ['MAT_REGION_HEAVEN_FEATHER', '天界の羽根', 3],
            ['5029', '精霊樹の繊維', 4],
        ],
    ];

    private const OLD_ARMOR_RECIPES = [
        'BR_ARM_4001_TO_4002' => [
            ['MAT_BR_ARM_HEAVY_ANCIENT', '重装の古代片', 5],
            ['5034', '氷帝の守護布', 3],
            ['MAT_REGION_ICE_CRYSTAL', '氷晶片', 4],
            ['MAT_REGION_BLACK_IRON_PART', '黒鉄の部材', 3],
        ],
        'BR_ARM_4005_TO_4006' => [
            ['MAT_BR_ARM_ARCANE_ANCIENT', '魔装の古代片', 5],
            ['5037', '魔導繊維', 4],
            ['CITY_07_MATERIAL', '魔導結晶', 3],
        ],
        'BR_ARM_4009_TO_4010' => [
            ['MAT_BR_ARM_LIGHT_ANCIENT', '軽装の古代片', 5],
            ['MAT_REGION_HEAVEN_FEATHER', '天界の羽根', 3],
            ['5030', '精霊王の絹糸', 3],
        ],
        'BR_ARM_4013_TO_4014' => [
            ['MAT_BR_ARM_TRAVELER_ANCIENT', '旅装の古代片', 5],
            ['5036', '砂王の宝布', 3],
            ['MAT_REGION_ANCIENT_SAND', '古代砂晶', 3],
        ],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $this->upsertCompositeMaterials();
        $this->replaceWeaponIngredients(self::WEAPON_RECIPES);
        $this->replaceArmorIngredients(self::ARMOR_RECIPES);
    }

    public function down(): void
    {
        $this->restoreWeaponIngredients(self::OLD_WEAPON_RECIPES);
        $this->restoreArmorIngredients(self::OLD_ARMOR_RECIPES);

        if (Schema::hasTable('materials')) {
            DB::table('materials')->whereIn('material_code', array_keys(self::COMPOSITES))->delete();
        }
    }

    private function upsertCompositeMaterials(): void
    {
        $now = now();

        foreach (self::COMPOSITES as $code => $payload) {
            DB::table('materials')->updateOrInsert(
                ['material_code' => $code],
                [
                    'name' => $payload['name'],
                    'category' => '分岐進化素材',
                    'rarity' => 'SSR',
                    'element' => null,
                    'main_use' => '装備進化',
                    'npc_sale_price' => 0,
                    'is_tradable' => false,
                    'city_id' => null,
                    'dungeon_id' => null,
                    'source_enemy_id' => null,
                    'drop_rate' => 0,
                    'drop_first_clear_only' => false,
                    'drop_timing' => null,
                    'material_type' => 'branch_evolution',
                    'category_id' => null,
                    'rank_tier' => 4,
                    'is_consumable' => true,
                    'obtain_method' => $payload['obtain_method'],
                    'market_category' => 'normal',
                    'trade_policy' => 'unmarketable',
                    'npc_sell_price' => 0,
                    'usage_tags' => json_encode(['合成', '交換所'], JSON_UNESCAPED_UNICODE),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    private function replaceWeaponIngredients(array $definitions): void
    {
        if (!Schema::hasTable('weapon_evolution_recipes') || !Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            return;
        }

        foreach ($definitions as $pattern => [$ancientCode, $ancientName, $compositeCode, $compositeName]) {
            $recipeIds = DB::table('weapon_evolution_recipes')
                ->where('from_rank', 'S')
                ->where('to_rank', 'SS')
                ->where('recipe_id', 'like', '%' . $pattern . '%')
                ->pluck('recipe_id');

            foreach ($recipeIds as $recipeId) {
                DB::table('weapon_evolution_recipe_ingredients')->where('recipe_id', $recipeId)->delete();
                $this->insertWeaponIngredient((string) $recipeId, $ancientCode, $ancientName, 5);
                $this->insertWeaponIngredient((string) $recipeId, $compositeCode, $compositeName, 3);
            }
        }
    }

    private function restoreWeaponIngredients(array $definitions): void
    {
        if (!Schema::hasTable('weapon_evolution_recipes') || !Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            return;
        }

        foreach ($definitions as $pattern => $ingredients) {
            $recipeIds = DB::table('weapon_evolution_recipes')
                ->where('from_rank', 'S')
                ->where('to_rank', 'SS')
                ->where('recipe_id', 'like', '%' . $pattern . '%')
                ->pluck('recipe_id');

            foreach ($recipeIds as $recipeId) {
                DB::table('weapon_evolution_recipe_ingredients')->where('recipe_id', $recipeId)->delete();
                foreach ($ingredients as [$code, $name, $quantity]) {
                    $this->insertWeaponIngredient((string) $recipeId, $code, $name, $quantity);
                }
            }
        }
    }

    private function insertWeaponIngredient(string $recipeId, string $code, string $name, int $quantity): void
    {
        DB::table('weapon_evolution_recipe_ingredients')->insert([
            'recipe_id' => $recipeId,
            'ingredient_type' => 'specific_material',
            'ingredient_id' => $code,
            'ingredient_name' => $name,
            'quantity' => $quantity,
            'resolve_rule' => 'material_codeをそのまま使用',
            'is_consumed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function replaceArmorIngredients(array $definitions): void
    {
        if (!Schema::hasTable('armor_evolution_recipes') || !Schema::hasTable('armor_evolution_recipe_ingredients')) {
            return;
        }

        foreach ($definitions as $recipeId => [$ancientCode, $ancientName, $compositeCode, $compositeName]) {
            if (!DB::table('armor_evolution_recipes')->where('evolution_recipe_id', $recipeId)->exists()) {
                continue;
            }

            DB::table('armor_evolution_recipe_ingredients')->where('evolution_recipe_id', $recipeId)->delete();
            $this->insertArmorIngredient($recipeId, $ancientCode, $ancientName, 5);
            $this->insertArmorIngredient($recipeId, $compositeCode, $compositeName, 3);
        }
    }

    private function restoreArmorIngredients(array $definitions): void
    {
        if (!Schema::hasTable('armor_evolution_recipes') || !Schema::hasTable('armor_evolution_recipe_ingredients')) {
            return;
        }

        foreach ($definitions as $recipeId => $ingredients) {
            if (!DB::table('armor_evolution_recipes')->where('evolution_recipe_id', $recipeId)->exists()) {
                continue;
            }

            DB::table('armor_evolution_recipe_ingredients')->where('evolution_recipe_id', $recipeId)->delete();
            foreach ($ingredients as [$code, $name, $quantity]) {
                $this->insertArmorIngredient($recipeId, $code, $name, $quantity);
            }
        }
    }

    private function insertArmorIngredient(string $recipeId, string $code, string $name, int $quantity): void
    {
        DB::table('armor_evolution_recipe_ingredients')->insert([
            'ingredient_id' => $recipeId . ':' . $code,
            'evolution_recipe_id' => $recipeId,
            'ingredient_type' => 'specific_material',
            'material_id' => $code,
            'material_name' => $name,
            'required_quantity' => $quantity,
            'resolve_rule' => 'material_codeをそのまま使用',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
