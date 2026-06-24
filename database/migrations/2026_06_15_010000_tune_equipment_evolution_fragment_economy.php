<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COMMON_FRAGMENT_CODES = [
        'MAT_EQUIPMENT_FRAGMENT',
        'MAT_FINE_EQUIPMENT_FRAGMENT',
        'MAT_STRONG_EQUIPMENT_FRAGMENT',
    ];

    public function up(): void
    {
        $now = now();

        $this->tuneWeaponRecipes($now);
        $this->tuneArmorRecipes($now);
        $this->tuneAccessoryRecipes($now);
    }

    public function down(): void
    {
        // Balance-data migrations are intentionally not reverted automatically.
    }

    private function tuneWeaponRecipes($now): void
    {
        if (!Schema::hasTable('weapon_evolution_recipes') || !Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            return;
        }

        DB::table('weapon_evolution_recipe_ingredients')
            ->whereIn('ingredient_id', self::COMMON_FRAGMENT_CODES)
            ->delete();

        $recipes = DB::table('weapon_evolution_recipes')
            ->where('is_active', true)
            ->whereIn('from_rank', ['A', 'S', 'SS', 'SSS'])
            ->get();

        foreach ($recipes as $recipe) {
            $material = match ((string) $recipe->from_rank) {
                'A' => ['TOKEN_CITY_HIGH_MATERIAL', '都市高位素材', 3, 'city_high_token'],
                'S' => ['WEV0004', '古代武具片', 3, 'fixed'],
                'SS' => ['WEV0006', '秘境の星砂', 3, 'fixed'],
                'SSS' => ['WEV0007', '伝説の武具紋章', 1, 'fixed'],
                default => null,
            };

            if (!$material) {
                continue;
            }

            DB::table('weapon_evolution_recipe_ingredients')->updateOrInsert(
                [
                    'recipe_id' => $recipe->recipe_id,
                    'ingredient_id' => $material[0],
                ],
                [
                    'ingredient_type' => 'material',
                    'ingredient_name' => $material[1],
                    'quantity' => $material[2],
                    'resolve_rule' => $material[3],
                    'is_consumed' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function tuneArmorRecipes($now): void
    {
        if (!Schema::hasTable('armor_evolution_recipes') || !Schema::hasTable('armor_evolution_recipe_ingredients')) {
            return;
        }

        DB::table('armor_evolution_recipe_ingredients')
            ->whereIn('material_id', self::COMMON_FRAGMENT_CODES)
            ->delete();

        $recipes = DB::table('armor_evolution_recipes')
            ->where('is_active', true)
            ->whereIn('from_rank', ['A', 'S', 'SS', 'SSS'])
            ->get();

        foreach ($recipes as $recipe) {
            $material = match ((string) $recipe->from_rank) {
                'A' => ['5052', '都市高位素材（進化対象街）', 3, 'unlock_city_idや現在街に応じて具体素材へ解決', 'abstract_resolved_material'],
                'S' => ['5004', '古代防具片', 3, 'material_idをそのまま使用', 'specific_material'],
                'SS' => ['5050', '秘境の守護繊維', 3, 'material_idをそのまま使用', 'specific_material'],
                'SSS' => ['5006', '伝説の縫魂', 1, 'material_idをそのまま使用', 'specific_material'],
                default => null,
            };

            if (!$material) {
                continue;
            }

            DB::table('armor_evolution_recipe_ingredients')->updateOrInsert(
                [
                    'ingredient_id' => 'TUNED_' . $recipe->evolution_recipe_id . '_' . $material[0],
                ],
                [
                    'evolution_recipe_id' => $recipe->evolution_recipe_id,
                    'ingredient_type' => $material[4],
                    'material_id' => $material[0],
                    'material_name' => $material[1],
                    'required_quantity' => $material[2],
                    'resolve_rule' => $material[3],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function tuneAccessoryRecipes($now): void
    {
        if (!Schema::hasTable('accessory_evolution_recipes') || !Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            return;
        }

        DB::table('accessory_evolution_recipe_ingredients')
            ->whereIn('material_code', self::COMMON_FRAGMENT_CODES)
            ->delete();

        $recipes = DB::table('accessory_evolution_recipes')
            ->where('is_active', true)
            ->whereIn('from_rank', ['A', 'S', 'SS', 'SSS'])
            ->get();

        foreach ($recipes as $recipe) {
            $material = match ((string) $recipe->from_rank) {
                'A' => ['ACC_CITY_HIGH_MATERIAL', '装飾都市高位素材', 3],
                'S' => ['ACC0004', '古代装飾片', 3],
                'SS' => ['ACC0006', '秘境素材の欠片', 3],
                'SSS' => ['ACC0005', '星屑の宝材', 1],
                default => null,
            };

            if (!$material) {
                continue;
            }

            DB::table('accessory_evolution_recipe_ingredients')->updateOrInsert(
                [
                    'recipe_id' => $recipe->recipe_id,
                    'material_code' => $material[0],
                ],
                [
                    'ingredient_type' => 'material',
                    'material_name' => $material[1],
                    'required_quantity' => $material[2],
                    'is_consumed' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
};
