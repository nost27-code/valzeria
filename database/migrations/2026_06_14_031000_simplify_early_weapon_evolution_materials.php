<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EARLY_RANKS = ['G', 'F', 'E', 'D'];

    private const CATEGORY_CODES = [
        'WEV0008', 'WEV0009', 'WEV0010',
        'WEV0011', 'WEV0012', 'WEV0013',
        'WEV0014', 'WEV0015', 'WEV0016',
        'WEV0017', 'WEV0018', 'WEV0019',
        'WEV0020', 'WEV0021', 'WEV0022',
    ];

    private const CATEGORY_FRAGMENT_TO_CRYSTAL = [
        'WEV0008' => ['WEV0009', '斬撃の結晶'],
        'WEV0011' => ['WEV0012', '刺突の結晶'],
        'WEV0014' => ['WEV0015', '打撃の結晶'],
        'WEV0017' => ['WEV0018', '射撃の結晶'],
        'WEV0020' => ['WEV0021', '魔導の結晶'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('weapon_evolution_recipes') || !Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            return;
        }

        $now = now();

        $earlyRecipeIds = DB::table('weapon_evolution_recipes')
            ->whereIn('from_rank', self::EARLY_RANKS)
            ->pluck('recipe_id')
            ->all();

        if ($earlyRecipeIds !== []) {
            DB::table('weapon_evolution_recipe_ingredients')
                ->whereIn('recipe_id', $earlyRecipeIds)
                ->whereIn('ingredient_id', self::CATEGORY_CODES)
                ->delete();
        }

        $fragmentRows = DB::table('weapon_evolution_recipe_ingredients as ingredient')
            ->join('weapon_evolution_recipes as recipe', 'recipe.recipe_id', '=', 'ingredient.recipe_id')
            ->whereNotIn('recipe.from_rank', self::EARLY_RANKS)
            ->whereIn('ingredient.ingredient_id', array_keys(self::CATEGORY_FRAGMENT_TO_CRYSTAL))
            ->select('ingredient.id', 'ingredient.ingredient_id', 'ingredient.quantity')
            ->get();

        foreach ($fragmentRows as $row) {
            [$crystalCode, $crystalName] = self::CATEGORY_FRAGMENT_TO_CRYSTAL[(string) $row->ingredient_id];

            DB::table('weapon_evolution_recipe_ingredients')
                ->where('id', $row->id)
                ->update([
                    'ingredient_id' => $crystalCode,
                    'ingredient_name' => $crystalName,
                    'quantity' => max(1, (int) ceil(((int) $row->quantity) / 10)),
                    'resolve_rule' => 'by_weapon_category',
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        // Balance-data migrations are intentionally not reverted automatically.
    }
};
