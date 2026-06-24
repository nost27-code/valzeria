<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const WEAPON_CITY_MATERIAL = ['TOKEN_CITY_MATERIAL', '都市素材（進化対象街）'];
    private const ARMOR_CITY_MATERIAL = ['5051', '都市素材（進化対象街）'];
    private const MAGIC_CRYSTAL = ['MAT_COMMON_MAGIC_CRYSTAL', '魔力水晶'];
    private const DRAGON_SCALE = ['MAT_COMMON_DRAGON_SCALE', '竜鱗'];
    private const ICE_CRYSTAL = ['MAT_REGION_ICE_CRYSTAL', '氷晶片'];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->addWeaponDemand();
            $this->addArmorDemand();
            $this->addAccessoryDemand();
        });
    }

    public function down(): void
    {
        // Balance-data migration only. Added supplemental recipe requirements are not restored.
    }

    private function addWeaponDemand(): void
    {
        if (!Schema::hasTable('weapon_evolution_recipes') || !Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            return;
        }

        $recipes = DB::table('weapon_evolution_recipes')
            ->where('is_active', true)
            ->where('recipe_id', 'not like', 'BR_%')
            ->whereIn('from_rank', ['D', 'C', 'B'])
            ->get();

        foreach ($recipes as $recipe) {
            $rank = (string) $recipe->from_rank;
            $category = (string) ($recipe->category_id ?? '');

            if (in_array($rank, ['C', 'B'], true)) {
                $this->insertWeaponIngredient(
                    (string) $recipe->recipe_id,
                    self::WEAPON_CITY_MATERIAL[0],
                    self::WEAPON_CITY_MATERIAL[1],
                    $rank === 'C' ? 4 : 6,
                    'city_material_pool'
                );
            }

            if ($category === 'MAGIC') {
                $this->insertWeaponIngredient(
                    (string) $recipe->recipe_id,
                    self::MAGIC_CRYSTAL[0],
                    self::MAGIC_CRYSTAL[1],
                    ['D' => 4, 'C' => 6, 'B' => 8][$rank] ?? 4,
                    'common_drop_material'
                );
            }

            if ($rank === 'B' && in_array($category, ['SLASH', 'PIERCE', 'BLUNT'], true)) {
                $this->insertWeaponIngredient(
                    (string) $recipe->recipe_id,
                    self::DRAGON_SCALE[0],
                    self::DRAGON_SCALE[1],
                    6,
                    'common_drop_material'
                );
            }
        }
    }

    private function addArmorDemand(): void
    {
        if (!Schema::hasTable('armor_evolution_recipes') || !Schema::hasTable('armor_evolution_recipe_ingredients')) {
            return;
        }

        $recipes = DB::table('armor_evolution_recipes')
            ->where('is_active', true)
            ->where('evolution_recipe_id', 'not like', 'BR_%')
            ->whereIn('from_rank', ['D', 'C', 'B'])
            ->get();

        foreach ($recipes as $recipe) {
            $rank = (string) $recipe->from_rank;
            $family = (string) ($recipe->armor_family_id ?? '');
            $recipeId = (string) $recipe->evolution_recipe_id;

            if (in_array($rank, ['C', 'B'], true)) {
                $this->insertArmorIngredient(
                    $recipeId,
                    self::ARMOR_CITY_MATERIAL[0],
                    self::ARMOR_CITY_MATERIAL[1],
                    $rank === 'C' ? 4 : 6,
                    'city_material_pool'
                );
            }

            if (in_array($family, ['robe', 'arcane_armor'], true)) {
                $this->insertArmorIngredient(
                    $recipeId,
                    self::MAGIC_CRYSTAL[0],
                    self::MAGIC_CRYSTAL[1],
                    ['D' => 4, 'C' => 6, 'B' => 8][$rank] ?? 4,
                    'common_drop_material'
                );
            }

            if ($rank === 'B' && in_array($family, ['heavy_armor', 'martial_garb'], true)) {
                $this->insertArmorIngredient(
                    $recipeId,
                    self::DRAGON_SCALE[0],
                    self::DRAGON_SCALE[1],
                    6,
                    'common_drop_material'
                );
            }

        }
    }

    private function addAccessoryDemand(): void
    {
        if (!Schema::hasTable('accessory_evolution_recipes') || !Schema::hasTable('accessory_evolution_recipe_ingredients') || !Schema::hasTable('items')) {
            return;
        }

        $recipes = DB::table('accessory_evolution_recipes as recipe')
            ->leftJoin('items as item', 'item.external_item_id', '=', 'recipe.from_accessory_id')
            ->where('recipe.is_active', true)
            ->where('recipe.recipe_id', 'not like', 'BR_%')
            ->whereIn('recipe.from_rank', ['D', 'C', 'B'])
            ->select('recipe.*', 'item.accessory_category_id')
            ->get();

        foreach ($recipes as $recipe) {
            $rank = (string) $recipe->from_rank;
            $category = (string) ($recipe->accessory_category_id ?? '');
            $recipeId = (string) $recipe->recipe_id;

            if (in_array($category, ['magic', 'mind'], true)) {
                $this->insertAccessoryIngredient(
                    $recipeId,
                    self::MAGIC_CRYSTAL[0],
                    self::MAGIC_CRYSTAL[1],
                    ['D' => 4, 'C' => 6, 'B' => 8][$rank] ?? 4
                );
            }

            if ($rank === 'B' && in_array($category, ['power', 'guard', 'life'], true)) {
                $this->insertAccessoryIngredient(
                    $recipeId,
                    self::DRAGON_SCALE[0],
                    self::DRAGON_SCALE[1],
                    6
                );
            }
        }
    }

    private function insertWeaponIngredient(string $recipeId, string $code, string $name, int $quantity, string $resolveRule): void
    {
        if (DB::table('weapon_evolution_recipe_ingredients')->where('recipe_id', $recipeId)->where('ingredient_id', $code)->exists()) {
            return;
        }

        DB::table('weapon_evolution_recipe_ingredients')->insert([
            'recipe_id' => $recipeId,
            'ingredient_type' => 'material',
            'ingredient_id' => $code,
            'ingredient_name' => $name,
            'quantity' => $quantity,
            'resolve_rule' => $resolveRule,
            'is_consumed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertArmorIngredient(string $recipeId, string $code, string $name, int $quantity, string $resolveRule): void
    {
        if (DB::table('armor_evolution_recipe_ingredients')->where('evolution_recipe_id', $recipeId)->where('material_id', $code)->exists()) {
            return;
        }

        DB::table('armor_evolution_recipe_ingredients')->insert([
            'ingredient_id' => 'SUPP_' . $recipeId . '_' . substr(md5($code), 0, 8),
            'evolution_recipe_id' => $recipeId,
            'ingredient_type' => 'specific_material',
            'material_id' => $code,
            'material_name' => $name,
            'required_quantity' => $quantity,
            'resolve_rule' => $resolveRule,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAccessoryIngredient(string $recipeId, string $code, string $name, int $quantity): void
    {
        if (DB::table('accessory_evolution_recipe_ingredients')->where('recipe_id', $recipeId)->where('material_code', $code)->exists()) {
            return;
        }

        DB::table('accessory_evolution_recipe_ingredients')->insert([
            'recipe_id' => $recipeId,
            'ingredient_type' => 'material',
            'material_code' => $code,
            'material_name' => $name,
            'required_quantity' => $quantity,
            'is_consumed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
