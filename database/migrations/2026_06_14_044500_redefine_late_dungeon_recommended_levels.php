<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('areas')) {
            return;
        }

        foreach ($this->levels() as $areaId => [$min, $max]) {
            DB::table('areas')
                ->where('id', $areaId)
                ->update([
                    'recommended_level_min' => $min,
                    'recommended_level_max' => $max,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Balance data migration. Keep the current recommended levels.
    }

    private function levels(): array
    {
        return [
            22 => [60, 63],
            23 => [63, 66],
            24 => [66, 69],
            25 => [69, 72],
            26 => [72, 75],
            27 => [75, 78],
            28 => [78, 80],
            29 => [80, 83],
            30 => [83, 86],
            31 => [86, 89],
            32 => [89, 92],
            33 => [92, 95],
            34 => [95, 98],
            35 => [98, 100],
            36 => [100, 103],
            37 => [103, 106],
            38 => [106, 109],
            39 => [109, 112],
            40 => [112, 115],
            41 => [115, 118],
            42 => [118, 120],
            43 => [120, 123],
            44 => [123, 126],
            45 => [126, 129],
            46 => [129, 132],
            47 => [132, 135],
            48 => [135, 138],
            49 => [138, 140],
            50 => [140, 143],
            51 => [143, 146],
            52 => [146, 149],
            53 => [149, 152],
            54 => [152, 155],
            55 => [155, 158],
            56 => [158, 160],
            57 => [160, 163],
            58 => [163, 166],
            59 => [166, 169],
            60 => [169, 172],
            61 => [172, 175],
            62 => [175, 178],
            63 => [178, 180],
            64 => [180, 183],
            65 => [183, 186],
            66 => [186, 189],
            67 => [189, 192],
            68 => [192, 195],
            69 => [195, 198],
            70 => [198, 200],
            71 => [220, 220],
            72 => [220, 220],
            73 => [240, 240],
            74 => [240, 240],
        ];
    }
};
