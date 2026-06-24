<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FRAGMENT_CODES = [
        'MAT_EQUIPMENT_FRAGMENT',
        'MAT_FINE_EQUIPMENT_FRAGMENT',
        'MAT_STRONG_EQUIPMENT_FRAGMENT',
    ];

    private const LEGACY_FRAGMENT_CODES = [
        'WEV0001',
        'WEV0002',
        'WEV0003',
        '5001',
        '5002',
        '5003',
        'ACC0001',
        'ACC0002',
        'ACC0003',
        'MAT_WEAPON_FRAGMENT',
        'MAT_WEAPON_CRYSTAL',
        'MAT_WEAPON_CORE',
    ];

    private const FRAGMENT_NAMES = [
        '装備の欠片',
        '上質な装備の欠片',
        '強装備の欠片',
        '武器の欠片',
        '武器の結晶',
        '武器の核',
        '防具の欠片',
        '防具の結晶',
        '防具の核',
        '装飾の欠片',
        '装飾の結晶',
        '装飾の核',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $materialIds = DB::table('materials')
            ->whereIn('material_code', array_merge(self::FRAGMENT_CODES, self::LEGACY_FRAGMENT_CODES))
            ->orWhereIn('name', self::FRAGMENT_NAMES)
            ->pluck('id');

        if ($materialIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('material_drops')) {
            DB::table('material_drops')
                ->whereIn('material_id', $materialIds)
                ->delete();
        }

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
        // Balance-data cleanup only. Equipment fragment reward sources are not restored.
    }
};
