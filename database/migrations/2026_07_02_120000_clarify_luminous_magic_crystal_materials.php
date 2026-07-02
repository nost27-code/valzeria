<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('materials')
            ->where('material_code', 'CITY_07_MATERIAL')
            ->update([
                'name' => '旧・魔導結晶',
                'updated_at' => now(),
            ]);

        DB::table('materials')
            ->where('material_code', 'WEV0029')
            ->update([
                'name' => 'ルミナス魔導晶',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('materials')
            ->where('material_code', 'CITY_07_MATERIAL')
            ->update([
                'name' => '魔導結晶',
                'updated_at' => now(),
            ]);

        DB::table('materials')
            ->where('material_code', 'WEV0029')
            ->update([
                'name' => '魔導結晶',
                'updated_at' => now(),
            ]);
    }
};
