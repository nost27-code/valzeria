<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('skills')
            ->where('skill_type', 'job_art')
            ->where('job_id', 15)
            ->where('learn_rank', 5)
            ->where('name', 'ガーディアンブロウ')
            ->update([
                'effect_template' => 'DAMAGE_GUARD_BARRIER',
                'damage_type' => 'physical',
                'power_multiplier' => 1.65,
                'hit_count' => 1,
                'updated_at' => now(),
            ]);

        DB::table('skills')
            ->where('skill_type', 'job_art')
            ->where('job_id', 31)
            ->where('learn_rank', 5)
            ->where('name', 'ゴールドラッシュ')
            ->update([
                'effect_template' => 'PHYSICAL_DAMAGE_GOLD_REWARD',
                'damage_type' => 'physical',
                'power_multiplier' => 1.85,
                'hit_count' => 2,
                'luk_power_rate' => 0.5,
                'gold_bonus_percent' => 2,
                'drop_bonus_percent' => 0,
                'updated_at' => now(),
            ]);

        DB::table('skills')
            ->where('skill_type', 'job_art')
            ->where('job_id', 21)
            ->where('learn_rank', 9)
            ->where('name', '金剛不壊')
            ->update([
                'damage_reduction_percent' => 25,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('skills')
            ->where('skill_type', 'job_art')
            ->where('job_id', 15)
            ->where('learn_rank', 5)
            ->where('name', 'ガーディアンブロウ')
            ->update([
                'effect_template' => 'GUARD_BARRIER',
                'damage_type' => 'support',
                'power_multiplier' => 1.65,
                'hit_count' => 0,
                'updated_at' => now(),
            ]);

        DB::table('skills')
            ->where('skill_type', 'job_art')
            ->where('job_id', 31)
            ->where('learn_rank', 5)
            ->where('name', 'ゴールドラッシュ')
            ->update([
                'effect_template' => 'REWARD_GOLD',
                'damage_type' => 'gold',
                'power_multiplier' => 1.85,
                'hit_count' => 0,
                'luk_power_rate' => 0,
                'gold_bonus_percent' => 2,
                'drop_bonus_percent' => 0,
                'updated_at' => now(),
            ]);
    }
};
