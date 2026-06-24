<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNUSED_MATERIAL_CODES = ['CITY_08_MATERIAL', 'WEV0030'];

    private const REGIONAL_MATERIALS = [
        1 => ['MAT_REGION_ARKREA_RAW', 'アークレアの粗素材'],
        2 => ['MAT_REGION_TIDAL_PIECE', '潮騒の素材片'],
        3 => ['MAT_REGION_WORLD_TREE_LEAF', '世界樹の葉片'],
        4 => ['MAT_REGION_BLACK_IRON_PART', '黒鉄の部材'],
        5 => ['MAT_REGION_ICE_CRYSTAL', '氷晶片'],
        6 => ['MAT_REGION_ANCIENT_SAND', '古代砂晶'],
        7 => ['MAT_REGION_MAGIC_CRYSTAL', '魔導結晶'],
        8 => ['MAT_REGION_ABYSS_FRAGMENT', '深淵の欠片'],
        9 => ['MAT_REGION_HEAVEN_FEATHER', '天界の羽根'],
        10 => ['MAT_REGION_HEAVEN_FEATHER', '天界の羽根'],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->removeUnusedMaterialDrops();
            $this->addRegionalRequirementsToBranchRecipes();
        });
    }

    public function down(): void
    {
        // Balance-data migration only. Removed drops and added regional requirements are not reverted.
    }

    private function removeUnusedMaterialDrops(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $materialIds = DB::table('materials')
            ->whereIn('material_code', self::UNUSED_MATERIAL_CODES)
            ->pluck('id');

        if ($materialIds->isNotEmpty() && Schema::hasTable('material_drops')) {
            DB::table('material_drops')
                ->whereIn('material_id', $materialIds)
                ->delete();
        }

        if ($materialIds->isNotEmpty()) {
            DB::table('materials')
                ->whereIn('id', $materialIds)
                ->update([
                    'source_enemy_id' => null,
                    'drop_rate' => 0,
                    'drop_first_clear_only' => false,
                    'drop_timing' => null,
                    'updated_at' => now(),
                ]);
        }
    }

    private function addRegionalRequirementsToBranchRecipes(): void
    {
        $materialMap = $this->branchStageRegionalMaterialMap();
        if ($materialMap === []) {
            return;
        }

        $this->addWeaponRequirements($materialMap);
        $this->addArmorRequirements($materialMap);
        $this->addAccessoryRequirements($materialMap);
    }

    private function branchStageRegionalMaterialMap(): array
    {
        $path = database_path('data/branch_material_drop_design.json');
        if (!is_file($path)) {
            return [];
        }

        $payload = json_decode((string) file_get_contents($path), true);
        $rows = is_array($payload) ? ($payload['rows'] ?? []) : [];
        $pathCitiesByMaterial = [];
        foreach ($rows as $row) {
            if (($row['stage'] ?? '') !== 'A→S') {
                continue;
            }

            $materialCode = (string) ($row['material_code'] ?? '');
            $cityId = (int) ($row['primary_city_id'] ?? 0);
            if ($materialCode !== '' && $cityId > 0) {
                $pathCitiesByMaterial[$materialCode][$cityId] = true;
            }
        }

        $map = [];

        foreach ($rows as $row) {
            $stage = (string) ($row['stage'] ?? '');
            if (!in_array($stage, ['A→S', 'S→SS'], true)) {
                continue;
            }

            $materialCode = (string) ($row['material_code'] ?? '');
            if ($stage === 'A→S' && count($pathCitiesByMaterial[$materialCode] ?? []) > 1) {
                continue;
            }

            $cityId = (int) ($row['primary_city_id'] ?? 0);
            if (!isset(self::REGIONAL_MATERIALS[$cityId])) {
                continue;
            }

            [$code, $name] = self::REGIONAL_MATERIALS[$cityId];
            $map[$materialCode] = [
                'code' => $code,
                'name' => $name,
                'quantity' => $stage === 'A→S' ? 1 : 3,
            ];
        }

        return array_filter($map, fn (array $row, string $code): bool => $code !== '', ARRAY_FILTER_USE_BOTH);
    }

    private function addWeaponRequirements(array $materialMap): void
    {
        if (!Schema::hasTable('weapon_evolution_recipes') || !Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            return;
        }

        $rows = DB::table('weapon_evolution_recipes as recipe')
            ->join('weapon_evolution_recipe_ingredients as ingredient', 'ingredient.recipe_id', '=', 'recipe.recipe_id')
            ->where('recipe.recipe_id', 'like', 'BR_%')
            ->whereIn('ingredient.ingredient_id', array_keys($materialMap))
            ->select('recipe.recipe_id', 'ingredient.ingredient_id')
            ->get();

        foreach ($rows as $row) {
            $material = $materialMap[(string) $row->ingredient_id] ?? null;
            if (!$material) {
                continue;
            }

            DB::table('weapon_evolution_recipe_ingredients')->updateOrInsert(
                [
                    'recipe_id' => $row->recipe_id,
                    'ingredient_id' => $material['code'],
                ],
                [
                    'ingredient_type' => 'regional_material',
                    'ingredient_name' => $material['name'],
                    'quantity' => $material['quantity'],
                    'resolve_rule' => 'branch_stage_region',
                    'is_consumed' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function addArmorRequirements(array $materialMap): void
    {
        if (!Schema::hasTable('armor_evolution_recipes') || !Schema::hasTable('armor_evolution_recipe_ingredients')) {
            return;
        }

        $rows = DB::table('armor_evolution_recipes as recipe')
            ->join('armor_evolution_recipe_ingredients as ingredient', 'ingredient.evolution_recipe_id', '=', 'recipe.evolution_recipe_id')
            ->where('recipe.evolution_recipe_id', 'like', 'BR_%')
            ->whereIn('ingredient.material_id', array_keys($materialMap))
            ->select('recipe.evolution_recipe_id', 'ingredient.material_id')
            ->get();

        foreach ($rows as $row) {
            $material = $materialMap[(string) $row->material_id] ?? null;
            if (!$material) {
                continue;
            }

            DB::table('armor_evolution_recipe_ingredients')->updateOrInsert(
                [
                    'evolution_recipe_id' => $row->evolution_recipe_id,
                    'material_id' => $material['code'],
                ],
                [
                    'ingredient_id' => 'REG_' . $row->evolution_recipe_id . '_' . $material['code'],
                    'ingredient_type' => 'regional_material',
                    'material_name' => $material['name'],
                    'required_quantity' => $material['quantity'],
                    'resolve_rule' => 'branch_stage_region',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function addAccessoryRequirements(array $materialMap): void
    {
        if (!Schema::hasTable('accessory_evolution_recipes') || !Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            return;
        }

        $rows = DB::table('accessory_evolution_recipes as recipe')
            ->join('accessory_evolution_recipe_ingredients as ingredient', 'ingredient.recipe_id', '=', 'recipe.recipe_id')
            ->where('recipe.recipe_id', 'like', 'BR_%')
            ->whereIn('ingredient.material_code', array_keys($materialMap))
            ->select('recipe.recipe_id', 'ingredient.material_code')
            ->get();

        foreach ($rows as $row) {
            $material = $materialMap[(string) $row->material_code] ?? null;
            if (!$material) {
                continue;
            }

            DB::table('accessory_evolution_recipe_ingredients')->updateOrInsert(
                [
                    'recipe_id' => $row->recipe_id,
                    'material_code' => $material['code'],
                ],
                [
                    'ingredient_type' => 'regional_material',
                    'material_name' => $material['name'],
                    'required_quantity' => $material['quantity'],
                    'is_consumed' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
};
