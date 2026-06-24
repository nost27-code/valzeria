<?php

use App\Support\NormalDropMaterialConsolidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FAIRY_DUST = 'MAT_COMMON_FAIRY_DUST';
    private const ARKREA_RAW = 'MAT_REGION_ARKREA_RAW';
    private const ICE_CRYSTAL = 'MAT_REGION_ICE_CRYSTAL';
    private const BLACK_IRON = 'MAT_REGION_BLACK_IRON_PART';

    private const SUPPLEMENTAL_DROPS = [
        'MAT_REGION_WORLD_TREE_LEAF' => [
            ['area_id' => 15, 'enemy_name' => '若葉ツリースピリット', 'drop_rate' => 20],
            ['area_id' => 16, 'enemy_name' => '妖精森ツリースピリット', 'drop_rate' => 20],
            ['area_id' => 17, 'enemy_name' => '絡み草', 'drop_rate' => 20],
            ['area_id' => 18, 'enemy_name' => '中層世界樹精', 'drop_rate' => 20],
            ['area_id' => 19, 'enemy_name' => '上層世界樹精', 'drop_rate' => 20],
            ['area_id' => 20, 'enemy_name' => '精霊封印守', 'drop_rate' => 15],
            ['area_id' => 21, 'enemy_name' => '月光星草スライム', 'drop_rate' => 20],
        ],
        'MAT_REGION_BLACK_IRON_PART' => [
            ['area_id' => 22, 'enemy_name' => '鉄殻虫', 'drop_rate' => 25],
            ['area_id' => 22, 'enemy_name' => '鉄鉱ゴーレム', 'drop_rate' => 20],
            ['area_id' => 22, 'enemy_name' => '鉱山トロル', 'drop_rate' => 15],
        ],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->replaceEarlyArmorFairyDust();
            $this->deduplicateEarlyArkreaArmorIngredients();
            $this->replaceArmorIceCrystalWithBlackIron();
            $this->addSupplementalDrops();
        });
    }

    public function down(): void
    {
        // Balance migration only. Previous bottlenecked recipes/drops are not restored.
    }

    private function replaceEarlyArmorFairyDust(): void
    {
        if (!Schema::hasTable('armor_evolution_recipe_ingredients') || !Schema::hasTable('armor_evolution_recipes')) {
            return;
        }

        DB::table('armor_evolution_recipe_ingredients as ingredient')
            ->join('armor_evolution_recipes as recipe', 'recipe.evolution_recipe_id', '=', 'ingredient.evolution_recipe_id')
            ->whereIn('recipe.from_rank', ['G', 'F'])
            ->where('ingredient.material_id', self::FAIRY_DUST)
            ->update([
                'ingredient.material_id' => self::ARKREA_RAW,
                'ingredient.material_name' => 'アークレアの粗素材',
                'ingredient.updated_at' => now(),
            ]);
    }

    private function deduplicateEarlyArkreaArmorIngredients(): void
    {
        if (!Schema::hasTable('armor_evolution_recipe_ingredients') || !Schema::hasTable('armor_evolution_recipes')) {
            return;
        }

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
    }

    private function replaceArmorIceCrystalWithBlackIron(): void
    {
        if (!Schema::hasTable('armor_evolution_recipe_ingredients') || !Schema::hasTable('armor_evolution_recipes')) {
            return;
        }

        DB::table('armor_evolution_recipe_ingredients as ingredient')
            ->join('armor_evolution_recipes as recipe', 'recipe.evolution_recipe_id', '=', 'ingredient.evolution_recipe_id')
            ->where('recipe.from_rank', 'B')
            ->where('ingredient.material_id', self::ICE_CRYSTAL)
            ->update([
                'ingredient.material_id' => self::BLACK_IRON,
                'ingredient.material_name' => '黒鉄の部材',
                'ingredient.updated_at' => now(),
            ]);
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
                'city_id' => $this->cityIdForMaterial($materialCode),
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

    private function cityIdForMaterial(string $materialCode): ?int
    {
        return match ($materialCode) {
            'MAT_REGION_WORLD_TREE_LEAF' => 3,
            'MAT_REGION_BLACK_IRON_PART' => 4,
            default => null,
        };
    }
};
