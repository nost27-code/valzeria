<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const D_RANK_REGION = ['MAT_REGION_ARKREA_RAW', 'アークレアの粗素材'];
    private const C_RANK_REGION = ['MAT_REGION_TIDAL_PIECE', '潮騒の素材片'];
    private const PREVIOUS_REGION = ['MAT_REGION_WORLD_TREE_LEAF', '世界樹の葉片'];

    public function up(): void
    {
        $this->replaceRegional('D', self::D_RANK_REGION);
        $this->replaceRegional('C', self::C_RANK_REGION);
    }

    public function down(): void
    {
        $this->replaceRegional('D', self::PREVIOUS_REGION);
        $this->replaceRegional('C', self::PREVIOUS_REGION);
    }

    private function replaceRegional(string $fromRank, array $material): void
    {
        [$code, $name] = $material;

        $weaponRecipeIds = DB::table('weapon_evolution_recipes')
            ->where('from_rank', $fromRank)
            ->pluck('recipe_id');
        DB::table('weapon_evolution_recipe_ingredients')
            ->whereIn('recipe_id', $weaponRecipeIds)
            ->where('ingredient_id', self::PREVIOUS_REGION[0])
            ->update([
                'ingredient_id' => $code,
                'ingredient_name' => $name,
                'updated_at' => now(),
            ]);

        $armorRecipeIds = DB::table('armor_evolution_recipes')
            ->where('from_rank', $fromRank)
            ->pluck('evolution_recipe_id');
        DB::table('armor_evolution_recipe_ingredients')
            ->whereIn('evolution_recipe_id', $armorRecipeIds)
            ->where('material_id', self::PREVIOUS_REGION[0])
            ->update([
                'material_id' => $code,
                'material_name' => $name,
                'updated_at' => now(),
            ]);

        $accessoryRecipeIds = DB::table('accessory_evolution_recipes')
            ->where('from_rank', $fromRank)
            ->pluck('recipe_id');
        DB::table('accessory_evolution_recipe_ingredients')
            ->whereIn('recipe_id', $accessoryRecipeIds)
            ->where('material_code', self::PREVIOUS_REGION[0])
            ->update([
                'material_code' => $code,
                'material_name' => $name,
                'updated_at' => now(),
            ]);
    }
};
