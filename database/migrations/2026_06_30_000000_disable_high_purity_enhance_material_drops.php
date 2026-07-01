<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const HIGH_PURITY_CODES = ['MAT_ENHANCE_HIGH_STONE', '5009', 'ACC0009'];
    private const HIGH_PURITY_NAMES = ['高純度強化石', '高純度守護石', '高純度装飾強化石', '高純度調律石'];

    public function up(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasTable('material_drops')) {
            return;
        }

        $materialIds = DB::table('materials')
            ->whereIn('material_code', self::HIGH_PURITY_CODES)
            ->orWhereIn('name', self::HIGH_PURITY_NAMES)
            ->pluck('id');

        if ($materialIds->isEmpty()) {
            return;
        }

        DB::table('material_drops')
            ->whereIn('material_id', $materialIds)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        DB::table('materials')
            ->whereIn('id', $materialIds)
            ->update([
                'source_enemy_id' => null,
                'drop_rate' => 0,
                'drop_first_clear_only' => false,
                'drop_timing' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Data-only safety migration: do not re-enable removed high-purity drops.
    }
};
