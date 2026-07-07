<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BRANCH_PATH_OBTAIN_METHODS = [
        'MAT_BR_WPN_GALE_PATH' => '素材交換所、または精霊の森エルフィアの目安戦力1,631〜2,411帯表層探索のレア報酬枠で入手します。',
        'MAT_BR_WPN_DARK_PATH' => '素材交換所、または鍛冶街グランベルグ周辺の目安戦力1,631〜2,411帯表層探索のレア報酬枠で入手します。',
        'MAT_BR_ARM_HEAVY_ARCANE_PATH' => '素材交換所、または鍛冶街グランベルグ周辺の目安戦力1,631〜2,411帯表層探索のレア報酬枠で入手します。',
        'MAT_BR_ARM_LIGHT_TRAVELER_PATH' => '素材交換所、または精霊の森エルフィアの目安戦力1,631〜2,411帯表層探索のレア報酬枠で入手します。',
        'MAT_BR_ACC_MAGIC_PRAYER_PATH' => '素材交換所、または王都アークレアの最深層・精霊の森エルフィアの目安戦力1,631〜2,411帯表層探索のレア報酬枠で入手します。',
        'MAT_BR_ACC_WIND_LUCK_PATH' => '素材交換所、または精霊の森エルフィアの目安戦力1,631〜2,411帯表層探索のレア報酬枠で入手します。',
        'MAT_BR_ACC_BALANCE_PATH' => '素材交換所、または鍛冶街グランベルグ周辺の目安戦力1,631〜2,411帯表層探索のレア報酬枠で入手します。',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('materials')) {
            return;
        }

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
        // Corrected player-facing obtain text should stay on the current display axis.
    }
};
