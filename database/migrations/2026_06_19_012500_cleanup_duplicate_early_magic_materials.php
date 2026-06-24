<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ARKREA_CODE = 'MAT_REGION_ARKREA_RAW';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->cleanupWeaponDuplicates();
            $this->cleanupAccessoryDuplicates();
        });
    }

    public function down(): void
    {
        // Duplicate cleanup only. Removed redundant rows are not restored.
    }

    private function cleanupWeaponDuplicates(): void
    {
        if (!Schema::hasTable('weapon_evolution_recipe_ingredients') || !Schema::hasTable('weapon_evolution_recipes')) {
            return;
        }

        $recipeIds = DB::table('weapon_evolution_recipe_ingredients as ingredient')
            ->join('weapon_evolution_recipes as recipe', 'recipe.recipe_id', '=', 'ingredient.recipe_id')
            ->where('recipe.category_id', 'MAGIC')
            ->where('recipe.from_rank', 'F')
            ->where('ingredient.ingredient_id', self::ARKREA_CODE)
            ->groupBy('ingredient.recipe_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ingredient.recipe_id')
            ->all();

        foreach ($recipeIds as $recipeId) {
            $rows = DB::table('weapon_evolution_recipe_ingredients')
                ->where('recipe_id', $recipeId)
                ->where('ingredient_id', self::ARKREA_CODE)
                ->orderByDesc('quantity')
                ->orderBy('id')
                ->get(['id']);

            DB::table('weapon_evolution_recipe_ingredients')
                ->whereIn('id', $rows->skip(1)->pluck('id')->all())
                ->delete();
        }
    }

    private function cleanupAccessoryDuplicates(): void
    {
        if (!Schema::hasTable('accessory_evolution_recipe_ingredients') || !Schema::hasTable('accessory_evolution_recipes')) {
            return;
        }

        $recipeIds = DB::table('accessory_evolution_recipe_ingredients as ingredient')
            ->join('accessory_evolution_recipes as recipe', 'recipe.recipe_id', '=', 'ingredient.recipe_id')
            ->join('items as source', 'source.external_item_id', '=', 'recipe.from_accessory_id')
            ->whereIn('source.accessory_category_id', ['magic', 'mind'])
            ->where('recipe.from_rank', 'F')
            ->where('ingredient.material_code', self::ARKREA_CODE)
            ->groupBy('ingredient.recipe_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ingredient.recipe_id')
            ->all();

        foreach ($recipeIds as $recipeId) {
            $rows = DB::table('accessory_evolution_recipe_ingredients')
                ->where('recipe_id', $recipeId)
                ->where('material_code', self::ARKREA_CODE)
                ->orderByDesc('required_quantity')
                ->orderBy('id')
                ->get(['id']);

            DB::table('accessory_evolution_recipe_ingredients')
                ->whereIn('id', $rows->skip(1)->pluck('id')->all())
                ->delete();
        }
    }
};
