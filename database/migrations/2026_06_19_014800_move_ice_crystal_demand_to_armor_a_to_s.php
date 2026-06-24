<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ICE_CRYSTAL = ['MAT_REGION_ICE_CRYSTAL', '氷晶片'];

    public function up(): void
    {
        if (!Schema::hasTable('armor_evolution_recipes') || !Schema::hasTable('armor_evolution_recipe_ingredients')) {
            return;
        }

        DB::transaction(function (): void {
            $earlyRecipeIds = DB::table('armor_evolution_recipes')
                ->where('is_active', true)
                ->where('evolution_recipe_id', 'not like', 'BR_%')
                ->where('from_rank', 'B')
                ->pluck('evolution_recipe_id');

            if ($earlyRecipeIds->isNotEmpty()) {
                DB::table('armor_evolution_recipe_ingredients')
                    ->whereIn('evolution_recipe_id', $earlyRecipeIds)
                    ->where('material_id', self::ICE_CRYSTAL[0])
                    ->delete();
            }

            $lateRecipes = DB::table('armor_evolution_recipes')
                ->where('is_active', true)
                ->where('from_rank', 'A')
                ->whereIn('armor_family_id', ['BR_HEAVY_ARMOR', 'BR_ARCANE_ARMOR'])
                ->get();

            foreach ($lateRecipes as $recipe) {
                $recipeId = (string) $recipe->evolution_recipe_id;
                if (DB::table('armor_evolution_recipe_ingredients')->where('evolution_recipe_id', $recipeId)->where('material_id', self::ICE_CRYSTAL[0])->exists()) {
                    continue;
                }

                DB::table('armor_evolution_recipe_ingredients')->insert([
                    'ingredient_id' => 'SUPP_' . $recipeId . '_' . substr(md5(self::ICE_CRYSTAL[0]), 0, 8),
                    'evolution_recipe_id' => $recipeId,
                    'ingredient_type' => 'specific_material',
                    'material_id' => self::ICE_CRYSTAL[0],
                    'material_name' => self::ICE_CRYSTAL[1],
                    'required_quantity' => 3,
                    'resolve_rule' => 'regional_drop_material',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Balance-data migration only. Added/removed supplemental requirements are not restored.
    }
};
