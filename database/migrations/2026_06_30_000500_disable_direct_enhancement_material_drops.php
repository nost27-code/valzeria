<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DISABLED_CODES = [
        'MAT_ENHANCE_STONE',
        '5008',
        'ACC0008',
        'MAT_ENHANCE_HIGH_STONE',
        '5009',
        'ACC0009',
        'MAT_REFINING_CORE_LOW_A',
        'MAT_REFINING_CORE_LOW_B',
        'MAT_REFINING_CORE_LOW',
        'MAT_REFINING_CORE_PART_A',
        'MAT_REFINING_CORE_PART_B',
        'MAT_REFINING_CORE_PART_C',
        'MAT_REFINING_CORE',
    ];

    private const DISABLED_NAMES = [
        '強化石',
        '守護石',
        '装飾強化石',
        '調律石',
        '高純度強化石',
        '高純度守護石',
        '高純度装飾強化石',
        '高純度調律石',
        '織成核殻',
        '晶糸核芯',
        '粗精錬核',
        '覇王黒晶',
        '蒼炉魔晶',
        '星樹氷晶',
        '精錬核',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $materialIds = DB::table('materials')
            ->whereIn('material_code', self::DISABLED_CODES)
            ->orWhereIn('name', self::DISABLED_NAMES)
            ->pluck('id');

        if ($materialIds->isEmpty()) {
            return;
        }

        $now = now();

        if (Schema::hasTable('material_drops')) {
            DB::table('material_drops')
                ->whereIn('material_id', $materialIds)
                ->update([
                    'is_active' => false,
                    'updated_at' => $now,
                ]);
        }

        DB::table('materials')
            ->whereIn('id', $materialIds)
            ->update([
                'source_enemy_id' => null,
                'drop_rate' => 0,
                'drop_first_clear_only' => false,
                'drop_timing' => null,
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        // Data-only safety migration: do not re-enable direct enhancement material drops.
    }
};
