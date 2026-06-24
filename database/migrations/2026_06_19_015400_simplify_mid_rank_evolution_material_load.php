<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->removeCityMaterialFromMidRankRecipes();
            $this->removeOverlappingFlavorMaterials();
        });
    }

    public function down(): void
    {
        // Balance simplification only. Removed supplemental requirements are not restored.
    }

    private function removeCityMaterialFromMidRankRecipes(): void
    {
        if (Schema::hasTable('weapon_evolution_recipes') && Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            $recipeIds = DB::table('weapon_evolution_recipes')
                ->where('is_active', true)
                ->where('recipe_id', 'not like', 'BR_%')
                ->whereIn('from_rank', ['C', 'B'])
                ->pluck('recipe_id');

            DB::table('weapon_evolution_recipe_ingredients')
                ->whereIn('recipe_id', $recipeIds)
                ->where('ingredient_id', 'TOKEN_CITY_MATERIAL')
                ->delete();
        }

        if (Schema::hasTable('armor_evolution_recipes') && Schema::hasTable('armor_evolution_recipe_ingredients')) {
            $recipeIds = DB::table('armor_evolution_recipes')
                ->where('is_active', true)
                ->where('evolution_recipe_id', 'not like', 'BR_%')
                ->whereIn('from_rank', ['C', 'B'])
                ->pluck('evolution_recipe_id');

            DB::table('armor_evolution_recipe_ingredients')
                ->whereIn('evolution_recipe_id', $recipeIds)
                ->where('material_id', '5051')
                ->delete();
        }
    }

    private function removeOverlappingFlavorMaterials(): void
    {
        if (Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            DB::table('weapon_evolution_recipe_ingredients')
                ->whereIn('recipe_id', ['EVOL_0064'])
                ->where('ingredient_id', 'MAT_COMMON_NATURAL_FRAGMENT')
                ->delete();

            DB::table('weapon_evolution_recipe_ingredients')
                ->whereIn('recipe_id', ['EVOL_0065', 'EVOL_0075'])
                ->where('ingredient_id', 'MAT_COMMON_THUNDER_STONE')
                ->delete();
        }

        if (Schema::hasTable('armor_evolution_recipe_ingredients')) {
            DB::table('armor_evolution_recipe_ingredients')
                ->whereIn('evolution_recipe_id', ['7024'])
                ->where('material_id', 'MAT_COMMON_NATURAL_FRAGMENT')
                ->delete();

            DB::table('armor_evolution_recipe_ingredients')
                ->whereIn('evolution_recipe_id', ['7055', '7056'])
                ->where('material_id', 'MAT_COMMON_THUNDER_STONE')
                ->delete();

            DB::table('armor_evolution_recipe_ingredients')
                ->whereIn('evolution_recipe_id', ['7016', '7046'])
                ->where('material_id', 'MAT_COMMON_FIRE_SEED')
                ->delete();
        }

        if (Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            DB::table('accessory_evolution_recipe_ingredients')
                ->whereIn('recipe_id', [
                    'ACC_EVO_MAGIC_RING_C_TO_B',
                    'ACC_EVO_MIND_EARRING_C_TO_B',
                    'ACC_EVO_MAGIC_RING_B_TO_A',
                ])
                ->where('material_code', 'MAT_COMMON_THUNDER_STONE')
                ->delete();

            DB::table('accessory_evolution_recipe_ingredients')
                ->where('recipe_id', 'ACC_EVO_POWER_RING_B_TO_A')
                ->where('material_code', 'MAT_COMMON_FIRE_SEED')
                ->delete();
        }
    }
};
