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
            1 => [1, 3],
            2 => [3, 6],
            3 => [6, 9],
            4 => [9, 12],
            5 => [12, 15],
            6 => [15, 18],
            7 => [18, 20],
            8 => [20, 23],
            9 => [23, 26],
            10 => [26, 29],
            11 => [29, 32],
            12 => [32, 35],
            13 => [35, 38],
            14 => [38, 40],
            15 => [40, 43],
            16 => [43, 46],
            17 => [46, 49],
            18 => [49, 52],
            19 => [52, 55],
            20 => [55, 58],
            21 => [58, 60],
        ];
    }
};
