<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const VERSION = 'enemy_curve_v1_4_2026_06';

    public function up(): void
    {
        $cityRanges = [
            1 => [1, 15],
            2 => [15, 29],
            3 => [29, 43],
            4 => [43, 57],
            5 => [57, 71],
            6 => [71, 85],
            7 => [85, 99],
            8 => [99, 113],
            9 => [113, 127],
            10 => [127, 141],
        ];

        foreach ($cityRanges as $cityIndex => [$min, $max]) {
            DB::table('cities')
                ->where('sort_order', $cityIndex * 10)
                ->update([
                    'recommended_level_min' => $min,
                    'recommended_level_max' => $max,
                    'updated_at' => now(),
                ]);
        }

        for ($areaId = 1; $areaId <= 70; $areaId++) {
            $cityIndex = (int) ceil($areaId / 7);
            $localIndex = (($areaId - 1) % 7) + 1;
            $cityStart = 1 + 14 * ($cityIndex - 1);
            $cityEnd = $cityStart + 14;
            $min = $cityStart + 2 * ($localIndex - 1);
            $max = min($cityStart + 2 * $localIndex, $cityEnd);

            DB::table('areas')
                ->where('id', $areaId)
                ->update([
                    'recommended_level_min' => $min,
                    'recommended_level_max' => $max,
                    'is_recommended_level_locked' => false,
                    'layer_key' => 'surface',
                    'level_generation_version' => self::VERSION,
                    'updated_at' => now(),
                ]);
        }

        foreach ([71 => 220, 72 => 220, 73 => 240, 74 => 240] as $areaId => $level) {
            DB::table('areas')
                ->where('id', $areaId)
                ->update([
                    'recommended_level_min' => $level,
                    'recommended_level_max' => $level,
                    'is_recommended_level_locked' => true,
                    'level_generation_version' => self::VERSION,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Balance data migration: keep the current tuned recommended levels.
    }
};
