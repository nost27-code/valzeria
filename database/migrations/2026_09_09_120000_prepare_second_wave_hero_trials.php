<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, array<string, mixed>> */
    private const TRIALS = [
        88 => [
            'area' => [
                'name' => '天機の試練場',
                'slug' => 'heavenly_machina_hero_trial',
                'description' => '【試練場】 HP/SPを引き継ぎ、偵察・重装・演算の三機構を攻略する天機の試練。',
                'unlock_order' => 12,
                'required_master_job_keys' => ['steel_crown_machina_sage'],
                'sort_order' => 1084,
            ],
            'hero_job_key' => 'heavenly_machina_chancellor',
            'required_job_key' => 'steel_crown_machina_sage',
            'job_description' => '天機の試練場で三つの機構を読み解いた者に開かれる英雄職。',
        ],
        89 => [
            'area' => [
                'name' => '聖域の試練場',
                'slug' => 'sanctuary_hero_trial',
                'description' => '【試練場】 精神低下・回復阻害・審判の大魔法を耐え抜く聖域の試練。',
                'unlock_order' => 13,
                'required_master_job_keys' => ['holy_crown_guardian'],
                'sort_order' => 1085,
            ],
            'hero_job_key' => 'sanctuary_judge',
            'required_job_key' => 'holy_crown_guardian',
            'job_description' => '聖域の試練場で天秤聖獣ユスティアの審判を越えた者に開かれる英雄職。',
        ],
        90 => [
            'area' => [
                'name' => '荒天の試練場',
                'slug' => 'storm_hero_trial',
                'description' => '【試練場】 高速連撃と急所攻撃を打ち破る荒天の試練。',
                'unlock_order' => 14,
                'required_master_job_keys' => ['thunder_crown_fist_saint'],
                'sort_order' => 1086,
            ],
            'hero_job_key' => 'storm_overlord',
            'required_job_key' => 'thunder_crown_fist_saint',
            'job_description' => '荒天の試練場で雷嵐神獣テンペスタの猛攻を制した者に開かれる英雄職。',
        ],
        91 => [
            'area' => [
                'name' => '白銀の試練場',
                'slug' => 'silver_guardian_hero_trial',
                'description' => '【試練場】 不落の守りと防御を貫く反撃を攻略する白銀の試練。',
                'unlock_order' => 15,
                'required_master_job_keys' => ['war_crown_commander'],
                'sort_order' => 1087,
            ],
            'hero_job_key' => 'silver_guardian_king',
            'required_job_key' => 'war_crown_commander',
            'job_description' => '白銀の試練場で白銀城塞アルジェオンを陥落させた者に開かれる英雄職。',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('areas') || ! Schema::hasTable('job_classes')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (self::TRIALS as $areaId => $trial) {
                $this->prepareTrial((int) $areaId, $trial);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('areas')) {
            return;
        }
        // 取得済み職業・条件・プレイヤーの達成記録は巻き戻しでも削除しない。
        DB::transaction(function (): void {
            foreach (self::TRIALS as $areaId => $trial) {
                DB::table('areas')->where('id', $areaId)->where('slug', $trial['area']['slug'])->update([
                    'is_published' => false, 'updated_at' => now(),
                ]);
            }
        });
    }

    /** @param array<string, mixed> $trial */
    private function prepareTrial(int $areaId, array $trial): void
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

        if (! Schema::hasTable('job_requirements')) {
            return;
        }

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
};
