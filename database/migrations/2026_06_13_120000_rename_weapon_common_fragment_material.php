<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('materials')) {
            DB::table('materials')
                ->where('material_code', 'WEV0001')
                ->update([
                    'name' => '武器の欠片',
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            DB::table('weapon_evolution_recipe_ingredients')
                ->where('ingredient_id', 'WEV0001')
                ->update([
                    'ingredient_name' => '武器の欠片',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('materials')) {
            DB::table('materials')
                ->where('material_code', 'WEV0001')
                ->update([
                    'name' => '武具の欠片',
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            DB::table('weapon_evolution_recipe_ingredients')
                ->where('ingredient_id', 'WEV0001')
                ->update([
                    'ingredient_name' => '武具の欠片',
                    'updated_at' => now(),
                ]);
        }
    }
};
