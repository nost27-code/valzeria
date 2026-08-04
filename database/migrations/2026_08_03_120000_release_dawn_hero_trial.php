<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TRIAL_AREA_ID = 84;

    public function up(): void
    {
        if (! Schema::hasTable('areas') || ! Schema::hasTable('job_classes')) {
            return;
        }

        $now = now();
        $area = [
            'name' => '暁の試練場',
            'slug' => 'dawn_hero_trial',
            'description' => '【試練場】 職業を問わず挑める、剣相と術相の二形態連戦。',
            'recommended_level_min' => 255,
            'recommended_level_max' => 255,
            'unlock_order' => 8,
            'unlock_required_area_id' => 70,
            'required_master_job_keys' => json_encode(['crown_sword_knight'], JSON_UNESCAPED_UNICODE),
            'background_image' => 'card_bg/dungeon_10_07.webp',
            'sort_order' => 1080,
            'city_id' => 10,
            'area_kind' => 'hero_trial',
            'clear_condition_type' => 'boss_defeated',
            'development_required_point' => 100,
            'is_route_area' => false,
            'is_published' => false,
            'updated_at' => $now,
        ];

        if (DB::table('areas')->where('id', self::TRIAL_AREA_ID)->exists()) {
            DB::table('areas')->where('id', self::TRIAL_AREA_ID)->update($area);
        } else {
            DB::table('areas')->insert(['id' => self::TRIAL_AREA_ID, 'created_at' => $now] + $area);
        }

        $heroJobId = DB::table('job_classes')->where('key', 'dawn_hero')->value('id');
        $requiredJobId = DB::table('job_classes')->where('key', 'crown_sword_knight')->value('id');

        if ($heroJobId) {
            DB::table('job_classes')->where('id', $heroJobId)->update([
                'is_active' => true,
                'is_hidden' => true,
                'description' => '暁の試練場で剣相と術相を打ち破った者に開かれる英雄職。',
                'updated_at' => $now,
            ]);
        }

        if ($heroJobId && $requiredJobId && Schema::hasTable('job_requirements')) {
            $exists = DB::table('job_requirements')
                ->where('job_id', $heroJobId)
                ->where('requirement_type', 'master_job')
                ->where('required_job_id', $requiredJobId)
                ->exists();

            if (! $exists) {
                DB::table('job_requirements')->insert([
                    'job_id' => $heroJobId,
                    'requirement_type' => 'master_job',
                    'required_job_id' => $requiredJobId,
                    'required_value' => null,
                    'required_key' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('job_classes')) {
            $heroJobId = DB::table('job_classes')->where('key', 'dawn_hero')->value('id');
            $requiredJobId = DB::table('job_classes')->where('key', 'crown_sword_knight')->value('id');

            if ($heroJobId) {
                DB::table('job_classes')->where('id', $heroJobId)->update([
                    'is_active' => false,
                    'is_hidden' => true,
                    'description' => '未公開職業データ。正式解放前の調整用。',
                    'updated_at' => now(),
                ]);
            }

            if ($heroJobId && $requiredJobId && Schema::hasTable('job_requirements')) {
                DB::table('job_requirements')
                    ->where('job_id', $heroJobId)
                    ->where('requirement_type', 'master_job')
                    ->where('required_job_id', $requiredJobId)
                    ->delete();
            }
        }

        if (Schema::hasTable('areas')) {
            DB::table('areas')->where('id', self::TRIAL_AREA_ID)->update([
                'is_published' => false,
                'updated_at' => now(),
            ]);
        }
    }
};
