<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('job_classes')) {
            return;
        }

        $now = now();
        $jobs = $this->jobs();

        foreach ($jobs as $job) {
            DB::table('job_classes')->updateOrInsert(
                ['id' => $job['id']],
                array_merge($job['data'], [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }

        if (Schema::hasTable('job_requirements')) {
            foreach ($jobs as $job) {
                foreach ($job['requirements'] as $requiredJobName) {
                    $requiredJobId = DB::table('job_classes')->where('name', $requiredJobName)->value('id');
                    if (! $requiredJobId) {
                        continue;
                    }

                    DB::table('job_requirements')->updateOrInsert(
                        [
                            'job_id' => $job['id'],
                            'requirement_type' => 'master_job',
                            'required_job_id' => $requiredJobId,
                        ],
                        [
                            'required_value' => null,
                            'required_key' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]
                    );
                }
            }
        }

        $this->seedEquipmentPermissions($jobs, $now);
    }

    public function down(): void
    {
        // Master-data addition only. Keep potentially referenced jobs intact on rollback.
    }

    private function jobs(): array
    {
        return [
            [
                'id' => 44,
                'requirements' => ['守護騎士', '聖騎士'],
                'data' => [
                    'key' => 'shield_saint',
                    'name' => '盾聖',
                    'rank' => 'advanced',
                    'category' => '上級職',
                    'description' => '聖なる盾で味方の未来を守る防御の極致。硬さと祈りが、裁きの光へ変わる。',
                    'max_job_level' => 10,
                    'hp_rate' => 170,
                    'mp_rate' => 140,
                    'atk_rate' => 120,
                    'def_rate' => 230,
                    'mag_rate' => 100,
                    'spr_rate' => 220,
                    'spd_rate' => 80,
                    'luck_rate' => 140,
                    'is_hidden' => true,
                    'is_active' => false,
                    'sort_order' => 440,
                    'bonus_hp' => 0,
                    'bonus_mp' => 0,
                    'bonus_str' => 0,
                    'bonus_def' => 0,
                    'bonus_mag' => 0,
                    'bonus_spr' => 0,
                    'bonus_spd' => 0,
                    'bonus_luk' => 0,
                    'bonus_gold_rate' => 0,
                    'bonus_drop_rate' => 0,
                    'bonus_critical_rate' => 0,
                    'special_skill_rate' => 0,
                    'affinity_physical' => 0.30,
                    'affinity_speed' => 0.00,
                    'affinity_magical' => 0.70,
                    'normal_attack_type' => 'physical',
                ],
            ],
            [
                'id' => 45,
                'requirements' => ['魔弓士', '狙撃手'],
                'data' => [
                    'key' => 'magic_bow_general',
                    'name' => '魔弓将',
                    'rank' => 'advanced',
                    'category' => '上級職',
                    'description' => '魔力を矢に束ね、星の軌道すら射抜く弓の将。狙いは遠く、威力は鋭い。',
                    'max_job_level' => 10,
                    'hp_rate' => 130,
                    'mp_rate' => 150,
                    'atk_rate' => 180,
                    'def_rate' => 110,
                    'mag_rate' => 180,
                    'spr_rate' => 120,
                    'spd_rate' => 180,
                    'luck_rate' => 150,
                    'is_hidden' => true,
                    'is_active' => false,
                    'sort_order' => 450,
                    'bonus_hp' => 0,
                    'bonus_mp' => 0,
                    'bonus_str' => 0,
                    'bonus_def' => 0,
                    'bonus_mag' => 0,
                    'bonus_spr' => 0,
                    'bonus_spd' => 0,
                    'bonus_luk' => 0,
                    'bonus_gold_rate' => 0,
                    'bonus_drop_rate' => 0,
                    'bonus_critical_rate' => 0,
                    'special_skill_rate' => 0,
                    'affinity_physical' => 0.20,
                    'affinity_speed' => 0.40,
                    'affinity_magical' => 0.40,
                    'normal_attack_type' => 'magical',
                ],
            ],
            [
                'id' => 46,
                'requirements' => ['吟遊詩人', '司祭'],
                'data' => [
                    'key' => 'song_saint',
                    'name' => '詩聖',
                    'rank' => 'advanced',
                    'category' => '上級職',
                    'description' => '祝詞と旋律で戦場を整える聖なる詩人。言葉は祈りとなり、祈りは力になる。',
                    'max_job_level' => 10,
                    'hp_rate' => 120,
                    'mp_rate' => 180,
                    'atk_rate' => 100,
                    'def_rate' => 110,
                    'mag_rate' => 150,
                    'spr_rate' => 190,
                    'spd_rate' => 140,
                    'luck_rate' => 210,
                    'is_hidden' => true,
                    'is_active' => false,
                    'sort_order' => 460,
                    'bonus_hp' => 0,
                    'bonus_mp' => 0,
                    'bonus_str' => 0,
                    'bonus_def' => 0,
                    'bonus_mag' => 0,
                    'bonus_spr' => 0,
                    'bonus_spd' => 0,
                    'bonus_luk' => 0,
                    'bonus_gold_rate' => 0,
                    'bonus_drop_rate' => 0,
                    'bonus_critical_rate' => 0,
                    'special_skill_rate' => 0,
                    'affinity_physical' => 0.00,
                    'affinity_speed' => 0.30,
                    'affinity_magical' => 0.70,
                    'normal_attack_type' => 'magical',
                ],
            ],
            [
                'id' => 47,
                'requirements' => ['薬師', '錬金術師'],
                'data' => [
                    'key' => 'medicine_saint',
                    'name' => '薬聖',
                    'rank' => 'advanced',
                    'category' => '上級職',
                    'description' => '薬理と霊薬を極めた支援の聖者。小瓶ひとつで戦況を静かに変えていく。',
                    'max_job_level' => 10,
                    'hp_rate' => 140,
                    'mp_rate' => 170,
                    'atk_rate' => 90,
                    'def_rate' => 130,
                    'mag_rate' => 150,
                    'spr_rate' => 220,
                    'spd_rate' => 110,
                    'luck_rate' => 190,
                    'is_hidden' => true,
                    'is_active' => false,
                    'sort_order' => 470,
                    'bonus_hp' => 0,
                    'bonus_mp' => 0,
                    'bonus_str' => 0,
                    'bonus_def' => 0,
                    'bonus_mag' => 0,
                    'bonus_spr' => 0,
                    'bonus_spd' => 0,
                    'bonus_luk' => 0,
                    'bonus_gold_rate' => 0,
                    'bonus_drop_rate' => 0,
                    'bonus_critical_rate' => 0,
                    'special_skill_rate' => 0,
                    'affinity_physical' => 0.00,
                    'affinity_speed' => 0.00,
                    'affinity_magical' => 1.00,
                    'normal_attack_type' => 'magical',
                ],
            ],
            [
                'id' => 48,
                'requirements' => ['軍師', '傭兵'],
                'data' => [
                    'key' => 'strategy_king',
                    'name' => '戦略王',
                    'rank' => 'advanced',
                    'category' => '上級職',
                    'description' => '布陣と号令で勝負を決める戦術の王。盤面を読み、勝機を逃さない。',
                    'max_job_level' => 10,
                    'hp_rate' => 150,
                    'mp_rate' => 150,
                    'atk_rate' => 150,
                    'def_rate' => 160,
                    'mag_rate' => 130,
                    'spr_rate' => 160,
                    'spd_rate' => 130,
                    'luck_rate' => 170,
                    'is_hidden' => true,
                    'is_active' => false,
                    'sort_order' => 480,
                    'bonus_hp' => 0,
                    'bonus_mp' => 0,
                    'bonus_str' => 0,
                    'bonus_def' => 0,
                    'bonus_mag' => 0,
                    'bonus_spr' => 0,
                    'bonus_spd' => 0,
                    'bonus_luk' => 0,
                    'bonus_gold_rate' => 0,
                    'bonus_drop_rate' => 0,
                    'bonus_critical_rate' => 0,
                    'special_skill_rate' => 0,
                    'affinity_physical' => 0.70,
                    'affinity_speed' => 0.00,
                    'affinity_magical' => 0.30,
                    'normal_attack_type' => 'physical',
                ],
            ],
        ];
    }

    private function seedEquipmentPermissions(array $jobs, mixed $now): void
    {
        if (! Schema::hasTable('job_weapon_permissions') || ! Schema::hasTable('job_armor_permissions')) {
            return;
        }

        $weaponPermissions = [
            44 => ['sword', 'spear', 'staff'],
            45 => ['bow', 'staff', 'magic_device', 'gun'],
            46 => ['staff', 'bow', 'magic_device'],
            47 => ['staff', 'dagger', 'magic_device'],
            48 => ['sword', 'staff', 'magic_device', 'gun'],
        ];

        $armorPermissions = [
            44 => ['robe', 'light_armor', 'heavy_armor'],
            45 => ['clothes', 'robe', 'cloak', 'light_armor'],
            46 => ['clothes', 'robe', 'cloak'],
            47 => ['clothes', 'robe', 'cloak', 'light_armor'],
            48 => ['clothes', 'cloak', 'light_armor', 'heavy_armor'],
        ];

        foreach ($jobs as $job) {
            foreach ($weaponPermissions[$job['id']] ?? [] as $category) {
                DB::table('job_weapon_permissions')->updateOrInsert(
                    ['job_id' => $job['id'], 'weapon_category' => $category],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }

            foreach ($armorPermissions[$job['id']] ?? [] as $category) {
                DB::table('job_armor_permissions')->updateOrInsert(
                    ['job_id' => $job['id'], 'armor_category' => $category],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }
};
