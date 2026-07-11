<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('skills')
            ->where('skill_type', 'job_art')
            ->where('job_id', 34)
            ->where('learn_rank', 9)
            ->where('name', '百影夜行')
            ->update([
                'effect_template' => 'DAMAGE_GUARD_BARRIER',
                'damage_type' => 'physical',
                'hit_count' => 4,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('skills')
            ->where('skill_type', 'job_art')
            ->where('job_id', 34)
            ->where('learn_rank', 9)
            ->where('name', '百影夜行')
            ->update([
                'effect_template' => 'GUARD_BARRIER',
                'damage_type' => 'support',
                'hit_count' => 0,
                'updated_at' => now(),
            ]);
    }
};
