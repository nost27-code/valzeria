<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $now = now();
        DB::table('materials')->updateOrInsert(
            ['material_code' => 'MAT_ENHANCE_HIGH_STONE'],
            [
                'name' => '高純度強化石',
                'category' => '強化素材',
                'rarity' => 'T3',
                'element' => null,
                'material_type' => 'enhance',
                'main_use' => '武器・装飾品の鍛冶強化',
                'npc_sale_price' => 0,
                'is_tradable' => false,
                'city_id' => null,
                'dungeon_id' => null,
                'source_enemy_id' => null,
                'drop_rate' => 0,
                'drop_first_clear_only' => false,
                'drop_timing' => null,
                'rank_tier' => 3,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        if (Schema::hasTable('weapon_enhancement_recipes')) {
            $recipes = [
                1 => [
                    ['material_id' => 'MAT_ENHANCE_FRAGMENT', 'material_name' => '強化石の欠片', 'quantity' => 3],
                ],
                2 => [
                    ['material_id' => 'MAT_ENHANCE_FRAGMENT', 'material_name' => '強化石の欠片', 'quantity' => 10],
                    ['material_id' => 'MAT_ENHANCE_STONE', 'material_name' => '強化石', 'quantity' => 1],
                ],
                3 => [
                    ['material_id' => 'MAT_ENHANCE_STONE', 'material_name' => '強化石', 'quantity' => 3],
                    ['material_id' => 'MAT_ENHANCE_HIGH_STONE', 'material_name' => '高純度強化石', 'quantity' => 1],
                ],
            ];

            foreach ($recipes as $level => $materials) {
                DB::table('weapon_enhancement_recipes')->updateOrInsert(
                    ['enhance_level' => $level],
                    [
                        'materials' => json_encode($materials, JSON_UNESCAPED_UNICODE),
                        'success_rate' => 100,
                        'effect' => '基礎性能+' . ($level * 3) . '%',
                        'note' => '装備の欠片系を使わない強化石系レシピ',
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }

        if (Schema::hasTable('armor_enhancement_recipes')) {
            DB::table('armor_enhancement_recipes')
                ->whereIn('required_material_id', ['5001', '5002', 'MAT_EQUIPMENT_FRAGMENT', 'MAT_FINE_EQUIPMENT_FRAGMENT'])
                ->delete();

            $recipes = [
                ['armor_enhance_1_guard_fragment', 1, '5007', '守護石の欠片', 3],
                ['armor_enhance_2_guard_fragment', 2, '5007', '守護石の欠片', 10],
                ['armor_enhance_2_guard_stone', 2, '5008', '守護石', 1],
                ['armor_enhance_3_guard_stone', 3, '5008', '守護石', 3],
                ['armor_enhance_3_guard_high_stone', 3, '5009', '高純度守護石', 1],
            ];

            foreach ($recipes as [$recipeId, $level, $materialId, $materialName, $quantity]) {
                DB::table('armor_enhancement_recipes')->updateOrInsert(
                    ['enhancement_recipe_id' => $recipeId],
                    [
                        'target_equipment_type' => 'armor',
                        'enhancement_level' => $level,
                        'required_material_id' => $materialId,
                        'required_material_name' => $materialName,
                        'required_quantity' => $quantity,
                        'success_rate' => 100,
                        'required_gold' => 0,
                        'required_kiseki' => 0,
                        'note' => '装備の欠片系を使わない守護石系レシピ',
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        // Balance-data redefinition only. The previous fragment-based recipes are not restored.
    }
};
