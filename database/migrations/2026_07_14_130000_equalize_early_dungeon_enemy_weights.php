<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EARLY_AREA_IDS = [1, 2, 3, 4, 5, 6, 7];

    private const PREVIOUS_WEIGHTS = [
        1 => 50, 2 => 30, 3 => 15, 4 => 4, 5 => 1,
        7 => 50, 8 => 30, 9 => 15, 10 => 4, 11 => 1,
        13 => 50, 14 => 30, 15 => 15, 16 => 4, 17 => 1,
        19 => 50, 20 => 30, 21 => 15, 22 => 4, 23 => 1,
        25 => 50, 26 => 30, 27 => 15, 28 => 4, 29 => 1,
        31 => 50, 32 => 30, 33 => 15, 34 => 4, 35 => 1,
        37 => 50, 38 => 30, 39 => 15, 40 => 4, 41 => 1,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('enemies')) {
            return;
        }

        DB::table('enemies')
            ->whereIn('area_id', self::EARLY_AREA_IDS)
            ->where('is_boss', false)
            ->update([
                'appearance_weight' => 20,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('enemies')) {
            return;
        }

        foreach (self::PREVIOUS_WEIGHTS as $enemyId => $weight) {
            DB::table('enemies')
                ->where('id', $enemyId)
                ->update([
                    'appearance_weight' => $weight,
                    'updated_at' => now(),
                ]);
        }
    }
};
