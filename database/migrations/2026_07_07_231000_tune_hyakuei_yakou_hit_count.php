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
                'description' => '分身による3回攻撃＋分身バリア。1戦1回',
                'memo' => '分身による3回攻撃＋分身バリア。1戦1回',
                'hit_count' => 3,
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
                'description' => '分身による複数Hit＋分身バリア。1戦1回',
                'memo' => '分身による複数Hit＋分身バリア。1戦1回',
                'hit_count' => 4,
                'updated_at' => now(),
            ]);
    }
};
