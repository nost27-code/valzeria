<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EARLY_RANKS = ['G', 'F', 'E', 'D'];

    private const CATEGORY_CODES = [
        '5010', '5011', '5012',
        '5013', '5014', '5015',
        '5016', '5017', '5018',
        '5019', '5020', '5021',
        '5022', '5023', '5024',
    ];

    private const CATEGORY_FRAGMENT_TO_CRYSTAL = [
        '5010' => ['5011', '軽装の結晶'],
        '5013' => ['5014', '重装の結晶'],
        '5016' => ['5017', '魔布の結晶'],
        '5019' => ['5020', '聖布の結晶'],
        '5022' => ['5023', '闘具の結晶'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('armor_evolution_recipes') || !Schema::hasTable('armor_evolution_recipe_ingredients')) {
            return;
        }

        $now = now();

        $earlyRecipeIds = DB::table('armor_evolution_recipes')
            ->whereIn('from_rank', self::EARLY_RANKS)
            ->pluck('evolution_recipe_id')
            ->all();

        if ($earlyRecipeIds !== []) {
            DB::table('armor_evolution_recipe_ingredients')
                ->whereIn('evolution_recipe_id', $earlyRecipeIds)
                ->whereIn('material_id', self::CATEGORY_CODES)
                ->delete();
        }

        $fragmentRows = DB::table('armor_evolution_recipe_ingredients as ingredient')
            ->join('armor_evolution_recipes as recipe', 'recipe.evolution_recipe_id', '=', 'ingredient.evolution_recipe_id')
            ->whereNotIn('recipe.from_rank', self::EARLY_RANKS)
            ->whereIn('ingredient.material_id', array_keys(self::CATEGORY_FRAGMENT_TO_CRYSTAL))
            ->select('ingredient.id', 'ingredient.material_id', 'ingredient.required_quantity')
            ->get();

        foreach ($fragmentRows as $row) {
            [$crystalCode, $crystalName] = self::CATEGORY_FRAGMENT_TO_CRYSTAL[(string) $row->material_id];

            DB::table('armor_evolution_recipe_ingredients')
                ->where('id', $row->id)
                ->update([
                    'ingredient_type' => 'category_mid',
                    'material_id' => $crystalCode,
                    'material_name' => $crystalName,
                    'required_quantity' => max(1, (int) ceil(((int) $row->required_quantity) / 10)),
                    'resolve_rule' => 'material_idをそのまま使用',
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        // Balance-data migrations are intentionally not reverted automatically.
    }
};
