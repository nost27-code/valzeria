<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('skills')
            ->where('skill_type', 'job_art')
            ->where('job_id', 6)
            ->where('learn_rank', 1)
            ->where('name', '魔力の火種')
            ->update([
                'effect_template' => 'MAGICAL_DAMAGE_BUFF',
                'damage_type' => 'magical',
                'power_multiplier' => 0.90,
                'hit_count' => 1,
                'description' => '単体小魔法＋自身MAG小上昇（累積上限あり）',
                'memo' => '単体小魔法＋自身MAG小上昇（累積上限あり）',
            ]);
    }

    public function down(): void
    {
        DB::table('skills')
            ->where('skill_type', 'job_art')
            ->where('job_id', 6)
            ->where('learn_rank', 1)
            ->where('name', '魔力の火種')
            ->update([
                'effect_template' => 'SELF_BUFF',
                'damage_type' => 'support',
                'power_multiplier' => 0.90,
                'hit_count' => 0,
            ]);
    }
};
