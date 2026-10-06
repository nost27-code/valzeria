<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, array<string, mixed>> */
    private const TRIALS = [
        92 => [
            'area' => [
                'name' => '蒼竜の試練場', 'slug' => 'azure_dragon_hero_trial',
                'description' => '【試練場】 古竜の連撃と防御を貫く猛攻を越える蒼竜の試練。',
                'unlock_order' => 16, 'required_master_job_keys' => ['dragon_crown_lance_general'],
                'sort_order' => 1088,
            ],
            'hero_job_key' => 'azure_dragon_warrior_king', 'hero_job_id' => 73,
            'required_job_key' => 'dragon_crown_lance_general', 'required_job_id' => 62,
            'job_description' => '蒼竜の試練場で蒼穹古竜アズラギオンの武を越えた者に開かれる英雄職。',
        ],
        93 => [
            'area' => [
                'name' => '幻葬の試練場', 'slug' => 'phantom_funeral_hero_trial',
                'description' => '【試練場】 精神を崩す魔法と魂を吸収する術を越える幻葬の試練。',
                'unlock_order' => 17, 'required_master_job_keys' => ['black_crown_magic_knight'],
                'sort_order' => 1089,
            ],
            'hero_job_key' => 'phantom_funeral_demon_king', 'hero_job_id' => 76,
            'required_job_key' => 'black_crown_magic_knight', 'required_job_id' => 61,
            'job_description' => '幻葬の試練場で魂葬王ネクロディアの終鐘を越えた者に開かれる英雄職。',
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

        // 公開準備の巻き戻しでも、取得済み英雄職・試練達成・マスター条件を破壊しない。
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
        if ((int) $heroJobId !== $trial['hero_job_id'] || (int) $requiredJobId !== $trial['required_job_id']) {
            throw new RuntimeException(
                "Hero trial job IDs are missing or inconsistent: {$trial['hero_job_key']} / {$trial['required_job_key']}."
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
