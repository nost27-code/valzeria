<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SALE_PRICES = [
        'MAT_FERDIA_BLUE_LIFE_LEAF' => 10,
        'MAT_FERDIA_CLEARSTREAM_DROP' => 10,
        'MAT_FERDIA_HEMOSTATIC_MOSS' => 10,
        'MAT_FERDIA_GUARDTREE_RESIN' => 20,
        'MAT_FERDIA_DETOX_GALL' => 20,
        'MAT_FERDIA_LIFEROOT' => 30,
        'WEV0023' => 10,
        'WEV0024' => 10,
        'WEV0025' => 10,
        'WEV0026' => 10,
        'WEV0027' => 10,
        'WEV0028' => 10,
        'WEV0031' => 10,
        'WEV0032' => 10,
    ];

    private const ANCIENT_CODES = [
        'MAT_BR_WPN_HOLY_ANCIENT',
        'MAT_BR_WPN_GALE_ANCIENT',
        'MAT_BR_WPN_DARK_ANCIENT',
        'MAT_BR_ARM_HEAVY_ANCIENT',
        'MAT_BR_ARM_ARCANE_ANCIENT',
        'MAT_BR_ARM_LIGHT_ANCIENT',
        'MAT_BR_ARM_TRAVELER_ANCIENT',
        'ACC0004',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->setSalePrices(false);
            $this->setAncientDropRates(false);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->setAncientDropRates(true);
            $this->setSalePrices(true);
        });
    }

    private function setSalePrices(bool $reverse): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        foreach (self::SALE_PRICES as $code => $price) {
            DB::table('materials')
                ->where('material_code', $code)
                ->where('npc_sale_price', $reverse ? $price : 0)
                ->update(['npc_sale_price' => $reverse ? 0 : $price, 'updated_at' => now()]);
        }
    }

    private function setAncientDropRates(bool $reverse): void
    {
        if (!Schema::hasTable('enemies') || !Schema::hasTable('materials') || !Schema::hasTable('material_drops')) {
            return;
        }

        $materialIds = DB::table('materials')->whereIn('material_code', self::ANCIENT_CODES)->pluck('id');
        foreach ([['人型', 0.76, 0.86], ['巨人', 0.60, 0.68]] as [$enemyType, $oldRate, $newRate]) {
            $enemyIds = DB::table('enemies')
                ->whereBetween('area_id', [1001, 1013])
                ->where('is_boss', false)
                ->where('type_name', $enemyType)
                ->pluck('id');

            DB::table('material_drops')
                ->whereIn('enemy_id', $enemyIds)
                ->whereIn('material_id', $materialIds)
                ->where('is_active', true)
                ->where('drop_first_clear_only', false)
                ->where('drop_rate', $reverse ? $newRate : $oldRate)
                ->update(['drop_rate' => $reverse ? $oldRate : $newRate, 'updated_at' => now()]);
        }
    }
};
