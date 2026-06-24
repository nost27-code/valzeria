<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasTable('material_drops') || !Schema::hasTable('enemies')) {
            return;
        }

        $materialId = DB::table('materials')
            ->where('material_code', 'MAT_COMMON_HOLY_FRAGMENT')
            ->value('id');

        if (!$materialId) {
            return;
        }

        $enemyIds = DB::table('enemies')
            ->whereIn('area_id', [1, 3])
            ->whereIn('name', ['見習い盗賊', '洞窟トロル'])
            ->pluck('id');

        if ($enemyIds->isEmpty()) {
            return;
        }

        DB::table('material_drops')
            ->where('material_id', $materialId)
            ->whereIn('enemy_id', $enemyIds)
            ->delete();
    }

    public function down(): void
    {
        // Removed because these early-area holy fragment drops were legacy supplemental data.
    }
};
