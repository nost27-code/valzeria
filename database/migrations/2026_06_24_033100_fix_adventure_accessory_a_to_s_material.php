<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('accessory_evolution_recipes') || !Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            return;
        }

        $recipeId = DB::table('accessory_evolution_recipes')
            ->where('recipe_id', 'ACC_EVO_ADVENTURER_PROOF_A_TO_S')
            ->value('recipe_id');

        if (!$recipeId) {
            return;
        }

        DB::table('accessory_evolution_recipes')
            ->where('recipe_id', $recipeId)
            ->update([
                'requires_city7_boss_cleared' => false,
                'updated_at' => now(),
            ]);

        if (!DB::table('accessory_evolution_recipe_ingredients')->where('recipe_id', $recipeId)->where('material_code', 'ACC0038')->exists()) {
            DB::table('accessory_evolution_recipe_ingredients')->insert([
                'recipe_id' => $recipeId,
                'ingredient_type' => 'material',
                'material_code' => 'ACC0038',
                'material_name' => '冒険の結晶',
                'required_quantity' => 8,
                'is_consumed' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('materials')) {
            DB::table('materials')
                ->where('material_code', 'ACC0038')
                ->update([
                    'main_use' => '装飾品進化',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            return;
        }

        DB::table('accessory_evolution_recipe_ingredients')
            ->where('recipe_id', 'ACC_EVO_ADVENTURER_PROOF_A_TO_S')
            ->where('material_code', 'ACC0038')
            ->delete();
    }
};
