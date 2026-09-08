<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, array<string, mixed>> */
    private const TRIALS = [
        86 => [
            'area' => [
                'name' => '星天の試練場',
                'slug' => 'star_heaven_hero_trial',
                'description' => '【試練場】 職業を問わず挑める、精神低下と予兆大魔法を軸にした星天の試練。',
                'unlock_order' => 10,
                'required_master_job_keys' => ['star_crown_sage'],
                'sort_order' => 1082,
            ],
            'hero_job_key' => 'star_heaven_sage',
            'required_job_key' => 'star_crown_sage',
            'job_description' => '星天の試練場で天象魔導核アステリオンの星図を打ち破った者に開かれる英雄職。',
        ],
        87 => [
            'area' => [
                'name' => '時環の試練場',
                'slug' => 'time_reader_hero_trial',
                'description' => '【試練場】 職業を問わず挑める、魔法連撃と敏捷操作を軸にした時環の試練。',
                'unlock_order' => 11,
                'required_master_job_keys' => ['gold_crown_alchemist'],
                'sort_order' => 1083,
            ],
            'hero_job_key' => 'time_reader_traveler',
            'required_job_key' => 'gold_crown_alchemist',
            'job_description' => '時環の試練場で時環の観測者エオンの刻を越えた者に開かれる英雄職。',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('areas') || ! Schema::hasTable('job_classes')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (self::TRIALS as $areaId => $trial) {
                $this->releaseTrial((int) $areaId, $trial);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('job_classes')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (self::TRIALS as $areaId => $trial) {
                $heroJobId = DB::table('job_classes')->where('key', $trial['hero_job_key'])->value('id');
                $requiredJobId = DB::table('job_classes')->where('key', $trial['required_job_key'])->value('id');

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

                if (Schema::hasTable('areas')) {
                    DB::table('areas')->where('id', $areaId)->update([
                        'is_published' => false,
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    /** @param array<string, mixed> $trial */
    private function releaseTrial(int $areaId, array $trial): void
    {
        $area = (array) $trial['area'];
        $existingById = DB::table('areas')->where('id', $areaId)->first();
        if ($existingById && (string) $existingById->slug !== (string) $area['slug']) {
            throw new RuntimeException("Hero trial area ID {$areaId} is already used by {$existingById->slug}.");
        }

        $existingBySlug = DB::table('areas')->where('slug', $area['slug'])->first();
        if ($existingBySlug && (int) $existingBySlug->id !== $areaId) {
            throw new RuntimeException("Hero trial slug {$area['slug']} is already used by area {$existingBySlug->id}.");
        }

        $now = now();
        $areaPayload = [
            'name' => $area['name'],
            'slug' => $area['slug'],
            'description' => $area['description'],
            'recommended_level_min' => 255,
            'recommended_level_max' => 255,
            'unlock_order' => $area['unlock_order'],
            'unlock_required_area_id' => 70,
            'required_master_job_keys' => json_encode($area['required_master_job_keys'], JSON_UNESCAPED_UNICODE),
            'background_image' => 'card_bg/dungeon_10_07.webp',
            'sort_order' => $area['sort_order'],
            'city_id' => 10,
            'area_kind' => 'hero_trial',
            'clear_condition_type' => 'boss_defeated',
            'development_required_point' => 100,
            'is_route_area' => false,
            'is_published' => false,
            'updated_at' => $now,
        ];

        if ($existingById) {
            DB::table('areas')->where('id', $areaId)->update($areaPayload);
        } else {
            DB::table('areas')->insert(['id' => $areaId, 'created_at' => $now] + $areaPayload);
        }

        $heroJobId = DB::table('job_classes')->where('key', $trial['hero_job_key'])->value('id');
        $requiredJobId = DB::table('job_classes')->where('key', $trial['required_job_key'])->value('id');
        if (! $heroJobId || ! $requiredJobId) {
            throw new RuntimeException(
                "Hero trial jobs are missing: {$trial['hero_job_key']} / {$trial['required_job_key']}."
            );
        }

        DB::table('job_classes')->where('id', $heroJobId)->update([
            'is_active' => true,
            'is_hidden' => true,
            'description' => $trial['job_description'],
            'updated_at' => $now,
        ]);

        if (Schema::hasTable('job_requirements')) {
            $requirement = [
                'job_id' => $heroJobId,
                'requirement_type' => 'master_job',
                'required_job_id' => $requiredJobId,
            ];
            $existingRequirement = DB::table('job_requirements')->where($requirement)->exists();

            if ($existingRequirement) {
                DB::table('job_requirements')->where($requirement)->update([
                    'required_value' => null,
                    'required_key' => null,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('job_requirements')->insert($requirement + [
                    'required_value' => null,
                    'required_key' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]);
            }
        }
    }
};
