<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CATEGORY_CRYSTALS = [
        'power' => ['ACC0011', '腕力の結晶'],
        'guard' => ['ACC0014', '守護の結晶'],
        'magic' => ['ACC0017', '魔力の結晶'],
        'prayer' => ['ACC0020', '祈祷の結晶'],
        'wind' => ['ACC0023', '疾風の結晶'],
        'luck' => ['ACC0026', '幸運の結晶'],
        'life' => ['ACC0029', '生命の結晶'],
        'mind' => ['ACC0032', '精神の結晶'],
        'balance' => ['ACC0035', '均衡の結晶'],
        'adventure' => ['ACC0038', '冒険の結晶'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('accessory_evolution_recipes') || !Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            return;
        }

        $recipes = DB::table('accessory_evolution_recipes as recipe')
            ->leftJoin('items as item', 'item.external_item_id', '=', 'recipe.from_accessory_id')
            ->where('recipe.recipe_id', 'not like', 'BR_%')
            ->where('recipe.from_rank', 'A')
            ->where('recipe.to_rank', 'S')
            ->select('recipe.recipe_id', 'item.accessory_category_id')
            ->get();

        $recipeIds = $recipes->pluck('recipe_id')->all();
        if ($recipeIds === []) {
            return;
        }

        DB::table('accessory_evolution_recipes')
            ->whereIn('recipe_id', $recipeIds)
            ->update([
                'requires_city7_boss_cleared' => false,
                'updated_at' => now(),
            ]);

        DB::table('accessory_evolution_recipe_ingredients')
            ->whereIn('recipe_id', $recipeIds)
            ->delete();

        foreach ($recipes as $recipe) {
            $this->insertIngredient((string) $recipe->recipe_id, 'ACC0003', '装飾の核', 3);

            $category = (string) ($recipe->accessory_category_id ?? '');
            if (isset(self::CATEGORY_CRYSTALS[$category])) {
                [$code, $name] = self::CATEGORY_CRYSTALS[$category];
                $this->insertIngredient((string) $recipe->recipe_id, $code, $name, 8);
            }
        }

        if (Schema::hasTable('materials')) {
            DB::table('materials')
                ->whereIn('material_code', array_merge(['ACC0003'], array_column(self::CATEGORY_CRYSTALS, 0)))
                ->update([
                    'main_use' => '装飾品進化',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('accessory_evolution_recipes') || !Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            return;
        }

        $recipeIds = DB::table('accessory_evolution_recipes')
            ->where('recipe_id', 'not like', 'BR_%')
            ->where('from_rank', 'A')
            ->where('to_rank', 'S')
            ->pluck('recipe_id')
            ->all();

        if ($recipeIds === []) {
            return;
        }

        DB::table('accessory_evolution_recipe_ingredients')
            ->whereIn('recipe_id', $recipeIds)
            ->delete();

        DB::table('accessory_evolution_recipes')
            ->whereIn('recipe_id', $recipeIds)
            ->update([
                'requires_city7_boss_cleared' => true,
                'updated_at' => now(),
            ]);
    }

    private function insertIngredient(string $recipeId, string $code, string $name, int $quantity): void
    {
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
