<?php

use App\Support\NormalDropMaterialConsolidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ARKREA_CODE = 'MAT_REGION_ARKREA_RAW';
    private const FAIRY_CODE = 'MAT_COMMON_FAIRY_DUST';
    private const CORE_CODE = 'MAT_COMMON_MONSTER_CORE';

    private const ARKREA_DROPS = [
        ['area_id' => 1, 'enemy_name' => 'スライム', 'drop_rate' => 20],
        ['area_id' => 1, 'enemy_name' => '野うさぎ', 'drop_rate' => 20],
        ['area_id' => 1, 'enemy_name' => '見習い盗賊', 'drop_rate' => 20],
        ['area_id' => 2, 'enemy_name' => 'ゴブリン', 'drop_rate' => 25],
        ['area_id' => 2, 'enemy_name' => 'ゴブリン弓兵', 'drop_rate' => 20],
        ['area_id' => 2, 'enemy_name' => 'ゴブリン隊長', 'drop_rate' => 20],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->replaceEarlyMagicWeaponMaterials();
            $this->replaceEarlyMagicAccessoryMaterials();
            $this->addArkreaSupplementalDrops();
        });
    }

    public function down(): void
    {
        // Balance migration only. Old early magic-core requirements are not restored.
    }

    private function replaceEarlyMagicWeaponMaterials(): void
    {
        if (!Schema::hasTable('weapon_evolution_recipe_ingredients') || !Schema::hasTable('weapon_evolution_recipes')) {
            return;
        }

        $rows = DB::table('weapon_evolution_recipe_ingredients as ingredient')
            ->join('weapon_evolution_recipes as recipe', 'recipe.recipe_id', '=', 'ingredient.recipe_id')
            ->where('recipe.category_id', 'MAGIC')
            ->whereIn('recipe.from_rank', ['G', 'F', 'E'])
            ->where('ingredient.ingredient_id', self::CORE_CODE)
            ->get(['ingredient.id', 'recipe.from_rank']);

        foreach ($rows as $row) {
            [$code, $name] = $row->from_rank === 'E'
                ? [self::FAIRY_CODE, '妖精粉']
                : [self::ARKREA_CODE, 'アークレアの粗素材'];

            DB::table('weapon_evolution_recipe_ingredients')
                ->where('id', $row->id)
                ->update([
                    'ingredient_id' => $code,
                    'ingredient_name' => $name,
                    'updated_at' => now(),
                ]);
        }
    }

    private function replaceEarlyMagicAccessoryMaterials(): void
    {
        if (!Schema::hasTable('accessory_evolution_recipe_ingredients') || !Schema::hasTable('accessory_evolution_recipes')) {
            return;
        }

        $rows = DB::table('accessory_evolution_recipe_ingredients as ingredient')
            ->join('accessory_evolution_recipes as recipe', 'recipe.recipe_id', '=', 'ingredient.recipe_id')
            ->join('items as source', 'source.external_item_id', '=', 'recipe.from_accessory_id')
            ->whereIn('source.accessory_category_id', ['magic', 'mind'])
            ->whereIn('recipe.from_rank', ['G', 'F', 'E'])
            ->where('ingredient.material_code', self::CORE_CODE)
            ->get(['ingredient.id', 'recipe.from_rank']);

        foreach ($rows as $row) {
            [$code, $name] = $row->from_rank === 'E'
                ? [self::FAIRY_CODE, '妖精粉']
                : [self::ARKREA_CODE, 'アークレアの粗素材'];

            DB::table('accessory_evolution_recipe_ingredients')
                ->where('id', $row->id)
                ->update([
                    'material_code' => $code,
                    'material_name' => $name,
                    'updated_at' => now(),
                ]);
        }
    }

    private function addArkreaSupplementalDrops(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasTable('material_drops') || !Schema::hasTable('enemies')) {
            return;
        }

        $materialId = $this->ensureMaterial(self::ARKREA_CODE);

        foreach (self::ARKREA_DROPS as $drop) {
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

    private function ensureMaterial(string $materialCode): int
    {
        DB::table('materials')->updateOrInsert(
            ['material_code' => $materialCode],
            array_merge(NormalDropMaterialConsolidator::payload($materialCode), [
                'city_id' => $materialCode === self::ARKREA_CODE ? 1 : null,
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
