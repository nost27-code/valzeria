<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('armor_evolution_recipes') || !Schema::hasTable('armor_evolution_recipe_ingredients')) {
            return;
        }

        DB::transaction(function (): void {
            $recipes = DB::table('armor_evolution_recipes')
                ->where('is_active', true)
                ->where('evolution_recipe_id', 'like', 'BR_%')
                ->where('from_rank', 'S')
                ->where('to_rank', 'SS')
                ->where('armor_family_id', 'BR_HEAVY_ARMOR')
                ->get();

            foreach ($recipes as $recipe) {
                $recipeId = (string) $recipe->evolution_recipe_id;
                if (DB::table('armor_evolution_recipe_ingredients')->where('evolution_recipe_id', $recipeId)->where('material_id', 'MAT_REGION_ICE_CRYSTAL')->exists()) {
                    continue;
                }

                DB::table('armor_evolution_recipe_ingredients')->insert([
                    'ingredient_id' => 'SERIES_' . $recipeId . '_' . substr(md5('MAT_REGION_ICE_CRYSTAL'), 0, 8),
                    'evolution_recipe_id' => $recipeId,
                    'ingredient_type' => 'specific_material',
                    'material_id' => 'MAT_REGION_ICE_CRYSTAL',
                    'material_name' => '氷晶片',
                    'required_quantity' => 4,
                    'resolve_rule' => 'branch_series_flavor_material',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Balance-data migration only. Added supplemental requirements are not restored.
    }
};
