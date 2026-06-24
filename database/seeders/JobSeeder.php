<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class JobSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $jobs = [
            [
                'id' => 1,
                'job_key' => 'warrior',
                'name' => '戦士',
                'description' => 'HP、STR、DEFが伸びやすい前衛職',
                'hp_growth_min' => 3, 'hp_growth_max' => 5,
                'attack_growth_min' => 2, 'attack_growth_max' => 4,
                'defense_growth_min' => 2, 'defense_growth_max' => 4,
                'speed_growth_min' => 0, 'speed_growth_max' => 2,
                'magic_growth_min' => 0, 'magic_growth_max' => 1,
                'luck_growth_min' => 0, 'luck_growth_max' => 1,
                'skill_name' => '会心斬り',
                'skill_description' => '一定確率で通常攻撃の1.8倍ダメージ',
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'id' => 2,
                'job_key' => 'mage',
                'name' => '魔法使い',
                'description' => 'MAGが大きく伸びる魔法職',
                'hp_growth_min' => 2, 'hp_growth_max' => 3,
                'attack_growth_min' => 0, 'attack_growth_max' => 1,
                'defense_growth_min' => 0, 'defense_growth_max' => 2,
                'speed_growth_min' => 0, 'speed_growth_max' => 2,
                'magic_growth_min' => 3, 'magic_growth_max' => 5,
                'luck_growth_min' => 0, 'luck_growth_max' => 1,
                'skill_name' => 'ファイア',
                'skill_description' => '魔力依存の追加ダメージ',
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'id' => 3,
                'job_key' => 'priest',
                'name' => '僧侶',
                'description' => 'MAG、DEF、LUKが伸びやすい安定職',
                'hp_growth_min' => 2, 'hp_growth_max' => 4,
                'attack_growth_min' => 0, 'attack_growth_max' => 2,
                'defense_growth_min' => 1, 'defense_growth_max' => 3,
                'speed_growth_min' => 0, 'speed_growth_max' => 2,
                'magic_growth_min' => 2, 'magic_growth_max' => 4,
                'luck_growth_min' => 1, 'luck_growth_max' => 2,
                'skill_name' => 'ヒール',
                'skill_description' => '戦闘中に一度だけHPを回復する可能性',
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'id' => 4,
                'job_key' => 'thief',
                'name' => '盗賊',
                'description' => 'AGI、LUKが伸びやすい素早い職',
                'hp_growth_min' => 2, 'hp_growth_max' => 3,
                'attack_growth_min' => 1, 'attack_growth_max' => 3,
                'defense_growth_min' => 0, 'defense_growth_max' => 2,
                'speed_growth_min' => 2, 'speed_growth_max' => 5,
                'magic_growth_min' => 0, 'magic_growth_max' => 1,
                'luck_growth_min' => 1, 'luck_growth_max' => 3,
                'skill_name' => '急所突き',
                'skill_description' => '一定確率で防御を一部無視して攻撃',
                'is_active' => true,
                'sort_order' => 4,
            ]
        ];

        foreach ($jobs as $job) {
            \App\Models\JobClass::updateOrCreate(
                ['id' => $job['id']],
                $job
            );
        }

        // 既存キャラクター対応（job_id未設定なら僧侶=3を設定）
        \App\Models\Character::whereNull('current_job_id')->update(['current_job_id' => 3]);
    }
}
