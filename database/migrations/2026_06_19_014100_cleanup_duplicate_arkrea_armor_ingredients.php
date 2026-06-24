<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ARKREA_RAW = 'MAT_REGION_ARKREA_RAW';

    public function up(): void
    {
        if (!Schema::hasTable('armor_evolution_recipe_ingredients') || !Schema::hasTable('armor_evolution_recipes')) {
            return;
        }

        DB::transaction(function (): void {
            $recipeIds = DB::table('armor_evolution_recipe_ingredients as ingredient')
                ->join('armor_evolution_recipes as recipe', 'recipe.evolution_recipe_id', '=', 'ingredient.evolution_recipe_id')
                ->whereIn('recipe.from_rank', ['G', 'F'])
                ->where('ingredient.material_id', self::ARKREA_RAW)
                ->groupBy('ingredient.evolution_recipe_id')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('ingredient.evolution_recipe_id');

            foreach ($recipeIds as $recipeId) {
                $rows = DB::table('armor_evolution_recipe_ingredients')
                    ->where('evolution_recipe_id', $recipeId)
                    ->where('material_id', self::ARKREA_RAW)
                    ->orderByDesc('required_quantity')
                    ->orderBy('id')
                    ->get();

                $keep = $rows->first();
                if (!$keep) {
                    continue;
                }

                DB::table('armor_evolution_recipe_ingredients')
                    ->whereIn('id', $rows->skip(1)->pluck('id'))
                    ->delete();

                DB::table('armor_evolution_recipe_ingredients')
                    ->where('id', $keep->id)
                    ->update([
                        'material_name' => 'アークレアの粗素材',
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function down(): void
    {
        // Cleanup migration only. Removed duplicate ingredient rows are not restored.
    }
};
