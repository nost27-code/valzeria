<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $codes = ['MAT_FERDIA_BLUE_LIFE_LEAF', 'MAT_FERDIA_CLEARSTREAM_DROP', 'MAT_FERDIA_GUARDTREE_RESIN', 'MAT_FERDIA_HEMOSTATIC_MOSS', 'MAT_FERDIA_DETOX_GALL', 'MAT_FERDIA_LIFEROOT'];
        $materialIds = DB::table('materials')->whereIn('material_code', $codes)->pluck('id');
        $enemyIds = DB::table('enemies')->whereBetween('area_id', [1001, 1013])->whereIn('type_name', ['人型', '巨人'])->pluck('id');
        DB::table('material_drops')->whereIn('material_id', $materialIds)->whereIn('enemy_id', $enemyIds)->delete();

        $lifeRootId = DB::table('materials')->where('material_code', 'MAT_FERDIA_LIFEROOT')->value('id');
        if (!$lifeRootId) return;
        $rates = [1011 => 2, 1012 => 2.5, 1013 => 2.5];
        $now = now();
        foreach ($rates as $areaId => $rate) {
            DB::table('enemies')->where('area_id', $areaId)->where('is_boss', false)->where('type_name', '巨人')->get()->each(function ($enemy) use ($lifeRootId, $rate, $now) {
                DB::table('material_drops')->updateOrInsert(['enemy_id' => $enemy->id, 'material_id' => $lifeRootId], ['drop_rate' => $rate, 'drop_first_clear_only' => false, 'drop_timing' => null, 'is_active' => true, 'updated_at' => $now, 'created_at' => $now]);
            });
        }
    }

    public function down(): void {}
};
