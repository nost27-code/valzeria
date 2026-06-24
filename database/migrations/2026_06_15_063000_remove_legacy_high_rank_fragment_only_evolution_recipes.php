<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const HIGH_RANKS = ['A', 'S', 'SS', 'SSS'];

    private const COMMON_FRAGMENT_CODES = [
        'MAT_EQUIPMENT_FRAGMENT',
        'MAT_FINE_EQUIPMENT_FRAGMENT',
        'MAT_STRONG_EQUIPMENT_FRAGMENT',
    ];

    public function up(): void
    {
        $this->cleanWeaponRecipes();
        $this->cleanArmorRecipes();
        $this->cleanAccessoryRecipes();
    }

    public function down(): void
    {
        // Master-data cleanup only. Legacy fragment-only high-rank recipes are not restored.
    }

    private function cleanWeaponRecipes(): void
    {
        if (!Schema::hasTable('weapon_evolution_recipes') || !Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            return;
        }

        $recipeIds = DB::table('weapon_evolution_recipes')
            ->whereIn('from_rank', self::HIGH_RANKS)
            ->pluck('recipe_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if ($recipeIds === []) {
            return;
        }

        DB::table('weapon_evolution_recipe_ingredients')
            ->whereIn('recipe_id', $recipeIds)
            ->whereIn('ingredient_id', self::COMMON_FRAGMENT_CODES)
            ->delete();

        $validRecipeIds = DB::table('weapon_evolution_recipe_ingredients')
            ->whereIn('recipe_id', $recipeIds)
            ->where('ingredient_type', '!=', 'same_weapon')
            ->pluck('recipe_id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->all();

        DB::table('weapon_evolution_recipes')
            ->whereIn('recipe_id', array_values(array_diff($recipeIds, $validRecipeIds)))
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    private function cleanArmorRecipes(): void
    {
        if (!Schema::hasTable('armor_evolution_recipes') || !Schema::hasTable('armor_evolution_recipe_ingredients')) {
            return;
        }

        $recipeIds = DB::table('armor_evolution_recipes')
            ->whereIn('from_rank', self::HIGH_RANKS)
            ->pluck('evolution_recipe_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if ($recipeIds === []) {
            return;
        }

        DB::table('armor_evolution_recipe_ingredients')
            ->whereIn('evolution_recipe_id', $recipeIds)
            ->whereIn('material_id', self::COMMON_FRAGMENT_CODES)
            ->delete();

        $validRecipeIds = DB::table('armor_evolution_recipe_ingredients')
            ->whereIn('evolution_recipe_id', $recipeIds)
            ->pluck('evolution_recipe_id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->all();

        DB::table('armor_evolution_recipes')
            ->whereIn('evolution_recipe_id', array_values(array_diff($recipeIds, $validRecipeIds)))
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    private function cleanAccessoryRecipes(): void
    {
        if (!Schema::hasTable('accessory_evolution_recipes') || !Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            return;
        }

        $recipeIds = DB::table('accessory_evolution_recipes')
            ->whereIn('from_rank', self::HIGH_RANKS)
            ->pluck('recipe_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if ($recipeIds === []) {
            return;
        }

        DB::table('accessory_evolution_recipe_ingredients')
            ->whereIn('recipe_id', $recipeIds)
            ->whereIn('material_code', self::COMMON_FRAGMENT_CODES)
            ->delete();

        $validRecipeIds = DB::table('accessory_evolution_recipe_ingredients')
            ->whereIn('recipe_id', $recipeIds)
            ->where('ingredient_type', '!=', 'same_accessory')
            ->pluck('recipe_id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->all();

        DB::table('accessory_evolution_recipes')
            ->whereIn('recipe_id', array_values(array_diff($recipeIds, $validRecipeIds)))
            ->update(['is_active' => false, 'updated_at' => now()]);
    }
};
