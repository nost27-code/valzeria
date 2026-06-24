<?php

use App\Support\NormalDropMaterialConsolidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TIDAL_CODE = 'MAT_REGION_TIDAL_PIECE';
    private const ARKREA_CODE = 'MAT_REGION_ARKREA_RAW';

    private const SUPPLEMENTAL_DROPS = [
        'MAT_COMMON_DARK_CRYSTAL' => [
            ['area_id' => 2, 'enemy_name' => '小鬼シャーマン', 'drop_rate' => 15],
            ['area_id' => 3, 'enemy_name' => 'スケルトン見習い', 'drop_rate' => 15],
            ['area_id' => 5, 'enemy_name' => 'ゾンビ', 'drop_rate' => 20],
            ['area_id' => 5, 'enemy_name' => '呪いガラス', 'drop_rate' => 22],
        ],
        'MAT_COMMON_MONSTER_CORE' => [
            ['area_id' => 2, 'enemy_name' => '小鬼シャーマン', 'drop_rate' => 15],
            ['area_id' => 6, 'enemy_name' => 'いたずらピクシー', 'drop_rate' => 12],
            ['area_id' => 6, 'enemy_name' => '泉の番人', 'drop_rate' => 15],
        ],
        'MAT_COMMON_HOLY_FRAGMENT' => [
            ['area_id' => 1, 'enemy_name' => '見習い盗賊', 'drop_rate' => 15],
            ['area_id' => 3, 'enemy_name' => '洞窟トロル', 'drop_rate' => 25],
        ],
        'MAT_COMMON_OLD_BADGE' => [
            ['area_id' => 1, 'enemy_name' => '草原コウモリ', 'drop_rate' => 18],
            ['area_id' => 1, 'enemy_name' => '見習い盗賊', 'drop_rate' => 20],
        ],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->replaceDefaultCityTwoRegionMaterials();
            $this->addSupplementalDrops();
        });
    }

    public function down(): void
    {
        // Balance migration only. The previous city-2 default and weaker drops are not restored.
    }

    private function replaceDefaultCityTwoRegionMaterials(): void
    {
        if (Schema::hasTable('weapon_evolution_recipe_ingredients') && Schema::hasTable('weapon_evolution_recipes')) {
            DB::table('weapon_evolution_recipe_ingredients as ingredient')
                ->join('weapon_evolution_recipes as recipe', 'recipe.recipe_id', '=', 'ingredient.recipe_id')
                ->whereIn('recipe.from_rank', ['C', 'B'])
                ->where('ingredient.ingredient_id', self::TIDAL_CODE)
                ->update([
                    'ingredient.ingredient_id' => self::ARKREA_CODE,
                    'ingredient.ingredient_name' => 'アークレアの粗素材',
                    'ingredient.updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('armor_evolution_recipe_ingredients') && Schema::hasTable('armor_evolution_recipes')) {
            DB::table('armor_evolution_recipe_ingredients as ingredient')
                ->join('armor_evolution_recipes as recipe', 'recipe.evolution_recipe_id', '=', 'ingredient.evolution_recipe_id')
                ->whereIn('recipe.from_rank', ['C', 'B'])
                ->where('ingredient.material_id', self::TIDAL_CODE)
                ->update([
                    'ingredient.material_id' => self::ARKREA_CODE,
                    'ingredient.material_name' => 'アークレアの粗素材',
                    'ingredient.updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('accessory_evolution_recipe_ingredients') && Schema::hasTable('accessory_evolution_recipes')) {
            DB::table('accessory_evolution_recipe_ingredients as ingredient')
                ->join('accessory_evolution_recipes as recipe', 'recipe.recipe_id', '=', 'ingredient.recipe_id')
                ->whereIn('recipe.from_rank', ['C', 'B'])
                ->where('ingredient.material_code', self::TIDAL_CODE)
                ->update([
                    'ingredient.material_code' => self::ARKREA_CODE,
                    'ingredient.material_name' => 'アークレアの粗素材',
                    'ingredient.updated_at' => now(),
                ]);
        }
    }

    private function addSupplementalDrops(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasTable('material_drops') || !Schema::hasTable('enemies')) {
            return;
        }

        foreach (self::SUPPLEMENTAL_DROPS as $materialCode => $drops) {
            $materialId = $this->ensureMaterial($materialCode);

            foreach ($drops as $drop) {
                $enemy = DB::table('enemies')
                    ->where('area_id', $drop['area_id'])
                    ->where('name', $drop['enemy_name'])
                    ->where('is_boss', false)
                    ->first();

                if (!$enemy) {
                    continue;
                }

                $existing = DB::table('material_drops')
                    ->where('enemy_id', $enemy->id)
                    ->where('material_id', $materialId)
                    ->first();

                DB::table('material_drops')->updateOrInsert(
                    ['enemy_id' => $enemy->id, 'material_id' => $materialId],
                    [
                        'drop_rate' => $existing ? max((float) $existing->drop_rate, (float) $drop['drop_rate']) : (float) $drop['drop_rate'],
                        'drop_first_clear_only' => false,
                        'drop_timing' => null,
                        'is_active' => true,
                        'updated_at' => now(),
                        'created_at' => $existing->created_at ?? now(),
                    ]
                );
            }
        }
    }

    private function ensureMaterial(string $materialCode): int
    {
        DB::table('materials')->updateOrInsert(
            ['material_code' => $materialCode],
            array_merge(NormalDropMaterialConsolidator::payload($materialCode), [
                'city_id' => str_starts_with($materialCode, 'MAT_REGION_') ? 1 : null,
                'dungeon_id' => null,
                'source_enemy_id' => null,
                'drop_rate' => 0,
                'drop_first_clear_only' => false,
                'drop_timing' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ])
        );

        return (int) DB::table('materials')
            ->where('material_code', $materialCode)
            ->value('id');
    }
};
