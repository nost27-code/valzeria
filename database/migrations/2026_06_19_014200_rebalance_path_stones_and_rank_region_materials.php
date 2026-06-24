<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ARKREA_RAW = 'MAT_REGION_ARKREA_RAW';
    private const WORLD_TREE = 'MAT_REGION_WORLD_TREE_LEAF';
    private const BLACK_IRON = 'MAT_REGION_BLACK_IRON_PART';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->replaceArkreaRegionalRequirements();
            $this->rebalancePathStoneDrops();
        });
    }

    public function down(): void
    {
        // Balance migration only. Previous higher path-stone rates and region requirements are not restored.
    }

    private function replaceArkreaRegionalRequirements(): void
    {
        $this->replaceWeaponRegional('D', self::WORLD_TREE, '世界樹の葉片');
        $this->replaceWeaponRegional('C', self::WORLD_TREE, '世界樹の葉片');
        $this->replaceWeaponRegional('B', self::BLACK_IRON, '黒鉄の部材');

        $this->replaceArmorRegional('D', self::WORLD_TREE, '世界樹の葉片');
        $this->replaceArmorRegional('C', self::WORLD_TREE, '世界樹の葉片');
        $this->replaceArmorRegional('B', self::BLACK_IRON, '黒鉄の部材');

        $this->replaceAccessoryRegional('D', self::WORLD_TREE, '世界樹の葉片');
        $this->replaceAccessoryRegional('C', self::WORLD_TREE, '世界樹の葉片');
        $this->replaceAccessoryRegional('B', self::BLACK_IRON, '黒鉄の部材');
    }

    private function replaceWeaponRegional(string $fromRank, string $code, string $name): void
    {
        if (!Schema::hasTable('weapon_evolution_recipe_ingredients') || !Schema::hasTable('weapon_evolution_recipes')) {
            return;
        }

        DB::table('weapon_evolution_recipe_ingredients as ingredient')
            ->join('weapon_evolution_recipes as recipe', 'recipe.recipe_id', '=', 'ingredient.recipe_id')
            ->where('recipe.from_rank', $fromRank)
            ->where('ingredient.ingredient_id', self::ARKREA_RAW)
            ->update([
                'ingredient.ingredient_id' => $code,
                'ingredient.ingredient_name' => $name,
                'ingredient.updated_at' => now(),
            ]);
    }

    private function replaceArmorRegional(string $fromRank, string $code, string $name): void
    {
        if (!Schema::hasTable('armor_evolution_recipe_ingredients') || !Schema::hasTable('armor_evolution_recipes')) {
            return;
        }

        DB::table('armor_evolution_recipe_ingredients as ingredient')
            ->join('armor_evolution_recipes as recipe', 'recipe.evolution_recipe_id', '=', 'ingredient.evolution_recipe_id')
            ->where('recipe.from_rank', $fromRank)
            ->where('ingredient.material_id', self::ARKREA_RAW)
            ->update([
                'ingredient.material_id' => $code,
                'ingredient.material_name' => $name,
                'ingredient.updated_at' => now(),
            ]);
    }

    private function replaceAccessoryRegional(string $fromRank, string $code, string $name): void
    {
        if (!Schema::hasTable('accessory_evolution_recipe_ingredients') || !Schema::hasTable('accessory_evolution_recipes')) {
            return;
        }

        DB::table('accessory_evolution_recipe_ingredients as ingredient')
            ->join('accessory_evolution_recipes as recipe', 'recipe.recipe_id', '=', 'ingredient.recipe_id')
            ->where('recipe.from_rank', $fromRank)
            ->where('ingredient.material_code', self::ARKREA_RAW)
            ->update([
                'ingredient.material_code' => $code,
                'ingredient.material_name' => $name,
                'ingredient.updated_at' => now(),
            ]);
    }

    private function rebalancePathStoneDrops(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasTable('material_drops') || !Schema::hasTable('enemies')) {
            return;
        }

        $drops = DB::table('material_drops as drop')
            ->join('materials as material', 'material.id', '=', 'drop.material_id')
            ->join('enemies as enemy', 'enemy.id', '=', 'drop.enemy_id')
            ->leftJoin('areas as area', 'area.id', '=', 'enemy.area_id')
            ->where('material.material_type', 'branch_evolution')
            ->where('material.material_code', 'like', '%_PATH')
            ->select('drop.id', 'area.city_id')
            ->get();

        foreach ($drops as $drop) {
            $cityId = (int) ($drop->city_id ?? 0);
            DB::table('material_drops')
                ->where('id', $drop->id)
                ->update([
                    'drop_rate' => $cityId >= 4 ? 1 : 0,
                    'is_active' => $cityId >= 4,
                    'updated_at' => now(),
                ]);
        }
    }
};
