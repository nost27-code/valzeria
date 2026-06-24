<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('armor_enhancement_recipes')) {
            return;
        }

        $now = now();
        DB::table('armor_enhancement_recipes')->delete();

        foreach ([
            ['armor_enhance_1_guard_fragment', 1, '5007', '守護石の欠片', 3],
            ['armor_enhance_2_guard_fragment', 2, '5007', '守護石の欠片', 10],
            ['armor_enhance_2_guard_stone', 2, '5008', '守護石', 1],
            ['armor_enhance_3_guard_stone', 3, '5008', '守護石', 3],
            ['armor_enhance_3_guard_high_stone', 3, '5009', '高純度守護石', 1],
        ] as [$recipeId, $level, $materialId, $materialName, $quantity]) {
            DB::table('armor_enhancement_recipes')->insert([
                'enhancement_recipe_id' => $recipeId,
                'target_equipment_type' => 'armor',
                'enhancement_level' => $level,
                'required_material_id' => $materialId,
                'required_material_name' => $materialName,
                'required_quantity' => $quantity,
                'success_rate' => 100,
                'required_gold' => 0,
                'required_kiseki' => 0,
                'note' => '守護石系だけを使う防具強化レシピ',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Balance-data normalization only. Previous duplicate rows are not restored.
    }
};
