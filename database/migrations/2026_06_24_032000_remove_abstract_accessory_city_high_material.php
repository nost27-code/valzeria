<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            DB::table('accessory_evolution_recipe_ingredients')
                ->where('material_code', 'ACC_CITY_HIGH_MATERIAL')
                ->delete();
        }

        if (Schema::hasTable('materials')) {
            DB::table('materials')
                ->where('material_type', 'accessory_city_high')
                ->update([
                    'main_use' => '廃止済み',
                    'obtain_method' => '現在の装飾品進化では使用しません。',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('materials')) {
            DB::table('materials')
                ->where('material_type', 'accessory_city_high')
                ->update([
                    'main_use' => '装飾品進化',
                    'obtain_method' => '装飾品進化用の都市高位素材。',
                    'updated_at' => now(),
                ]);
        }
    }
};
