<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FERDIA_AREA_IDS = [
        1001, 1002, 1003, 1004, 1005, 1006, 1007, 1008, 1009, 1010, 1011, 1012, 1013,
        1025, 1026, 1027, 1028, 1029,
    ];

    public function up(): void
    {
        $now = now();

        DB::table('enemies')
            ->whereIn('area_id', self::FERDIA_AREA_IDS)
            ->where('is_boss', false)
            ->update([
                'job_exp_reward' => DB::raw("CASE WHEN role_key = 'strong' THEN 3 ELSE 2 END"),
                'updated_at' => $now,
            ]);

        DB::table('enemies')
            ->whereIn('area_id', [1003, 1007, 1009])
            ->where('is_boss', true)
            ->update(['job_exp_reward' => 4, 'updated_at' => $now]);

        DB::table('enemies')
            ->whereIn('area_id', [1013, 1029])
            ->where('is_boss', true)
            ->update(['job_exp_reward' => 5, 'updated_at' => $now]);
    }

    public function down(): void
    {
        $now = now();

        DB::table('enemies')
            ->whereIn('area_id', self::FERDIA_AREA_IDS)
            ->where('is_boss', false)
            ->update([
                'job_exp_reward' => DB::raw("CASE WHEN role_key = 'strong' THEN 2 ELSE 1 END"),
                'updated_at' => $now,
            ]);

        DB::table('enemies')
            ->whereIn('area_id', [1003, 1007, 1009, 1013, 1029])
            ->where('is_boss', true)
            ->update(['job_exp_reward' => 7, 'updated_at' => $now]);
    }
};
