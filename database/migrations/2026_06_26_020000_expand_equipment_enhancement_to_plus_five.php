<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('items') && Schema::hasColumn('items', 'max_enhance')) {
            DB::table('items')
                ->whereIn('type', ['weapon', 'armor', 'accessory'])
                ->where('max_enhance', '>', 0)
                ->where('max_enhance', '<', 5)
                ->update([
                    'max_enhance' => 5,
                    'updated_at' => $now,
                ]);
        }

        if (Schema::hasTable('weapon_enhancement_recipes')) {
            $stoneCosts = [1 => 1, 2 => 3, 3 => 5, 4 => 7, 5 => 9];

            foreach ($stoneCosts as $level => $quantity) {
                DB::table('weapon_enhancement_recipes')->updateOrInsert(
                    ['enhance_level' => $level],
                    [
                        'materials' => json_encode([[
                            'material_id' => 'MAT_ENHANCE_STONE',
                            'material_name' => '強化石',
                            'quantity' => $quantity,
                        ]], JSON_UNESCAPED_UNICODE),
                        'success_rate' => 100,
                        'effect' => '基礎性能+' . ($level * 3) . '%',
                        'note' => '強化石だけを使う+5対応レシピ',
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }

        if (Schema::hasTable('armor_enhancement_recipes')) {
            DB::table('armor_enhancement_recipes')
                ->where('target_equipment_type', 'armor')
                ->delete();

            $stoneCosts = [1 => 1, 2 => 3, 3 => 5, 4 => 7, 5 => 9];
            foreach ($stoneCosts as $level => $quantity) {
                DB::table('armor_enhancement_recipes')->insert([
                    'enhancement_recipe_id' => 'armor_enhance_' . $level . '_guard_stone',
                    'target_equipment_type' => 'armor',
                    'enhancement_level' => $level,
                    'required_material_id' => '5008',
                    'required_material_name' => '守護石',
                    'required_quantity' => $quantity,
                    'success_rate' => 100,
                    'required_gold' => 0,
                    'required_kiseki' => 0,
                    'note' => '守護石だけを使う+5対応レシピ',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Balance-data redefinition only. The previous +3 recipe set is not restored.
    }
};
