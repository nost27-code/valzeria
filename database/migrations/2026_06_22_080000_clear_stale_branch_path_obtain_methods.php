<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STALE_PATH_CODES = [
        'MAT_BR_WPN_HOLY_PATH',
        'MAT_BR_WPN_DARK_PATH',
        'MAT_BR_WPN_GALE_PATH',
        'MAT_BR_ARM_HEAVY_PATH',
        'MAT_BR_ARM_ARCANE_PATH',
        'MAT_BR_ARM_LIGHT_PATH',
        'MAT_BR_ARM_TRAVELER_PATH',
        'MAT_BR_ACC_POWER_PATH',
        'MAT_BR_ACC_GUARD_PATH',
        'MAT_BR_ACC_MAGIC_PATH',
        'MAT_BR_ACC_PRAYER_PATH',
        'MAT_BR_ACC_WIND_PATH',
        'MAT_BR_ACC_LUCK_PATH',
        'MAT_BR_ACC_BALANCE_PATH',
    ];

    private const BRANCH_PATH_OBTAIN_METHODS = [
        'MAT_BR_WPN_HOLY_PATH' => '素材交換所、または王都アークレアの最深層のレア報酬枠で入手します。',
        'MAT_BR_WPN_GALE_PATH' => '素材交換所、または精霊の森エルフィアのLv40〜50帯表層探索のレア報酬枠で入手します。',
        'MAT_BR_WPN_DARK_PATH' => '素材交換所、または鍛冶街グランベルグ周辺のLv40〜50帯表層探索のレア報酬枠で入手します。',
        'MAT_BR_ARM_HEAVY_ARCANE_PATH' => '素材交換所、または鍛冶街グランベルグ周辺のLv40〜50帯表層探索のレア報酬枠で入手します。',
        'MAT_BR_ARM_LIGHT_TRAVELER_PATH' => '素材交換所、または精霊の森エルフィアのLv40〜50帯表層探索のレア報酬枠で入手します。',
        'MAT_BR_ACC_POWER_GUARD_PATH' => '素材交換所、または王都アークレアの最深層のレア報酬枠で入手します。',
        'MAT_BR_ACC_MAGIC_PRAYER_PATH' => '素材交換所、または王都アークレア最深層・精霊の森エルフィアLv40〜50帯表層探索のレア報酬枠で入手します。',
        'MAT_BR_ACC_WIND_LUCK_PATH' => '素材交換所、または精霊の森エルフィアのLv40〜50帯表層探索のレア報酬枠で入手します。',
        'MAT_BR_ACC_BALANCE_PATH' => '素材交換所、または鍛冶街グランベルグ周辺のLv40〜50帯表層探索のレア報酬枠で入手します。',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $payload = [
            'obtain_method' => '分岐進化用の導石。素材交換所や、対応地域の深層・最深層のレア報酬枠で入手します。',
            'updated_at' => now(),
        ];

        foreach (['city_id', 'dungeon_id', 'source_area_id', 'source_enemy_id'] as $column) {
            if (Schema::hasColumn('materials', $column)) {
                $payload[$column] = null;
            }
        }

        DB::table('materials')
            ->whereIn('material_code', self::STALE_PATH_CODES)
            ->where('material_type', 'branch_evolution')
            ->update($payload);

        DB::table('materials')
            ->where('material_code', 'MAT_BR_WPN_HOLY_PATH')
            ->where('material_type', 'branch_evolution')
            ->update([
                'obtain_method' => '素材交換所、または王都アークレアの最深層のレア報酬枠で入手します。',
                'updated_at' => now(),
            ]);

        foreach (self::BRANCH_PATH_OBTAIN_METHODS as $code => $method) {
            DB::table('materials')
                ->where('material_code', $code)
                ->where('material_type', 'branch_evolution')
                ->update([
                    'obtain_method' => $method,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // User-facing obtain text is corrected master data; old city-specific text was stale.
    }
};
