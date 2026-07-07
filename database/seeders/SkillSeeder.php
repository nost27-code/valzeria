<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Skill;
use App\Models\JobClass;

class SkillSeeder extends Seeder
{
    public function run(): void
    {
        $skills = require base_path('database/data/job_special_skills.php');

        foreach ($skills as $masterRow) {
            $jobKey = $masterRow['job_key'];

            $job = JobClass::where('key', $jobKey)->first();
            if ($job) {
                $skillData = [
                    'job_id' => $job->id,
                    'skill_type' => 'special',
                    'mp_cost' => 0,
                    'activation_rate' => (int) $masterRow['activation_rate'],
                    'sp_cost_base' => (int) $masterRow['sp_cost_base'],
                    'sp_cost_rate' => (float) $masterRow['sp_cost_rate'],
                    'name' => $masterRow['special_name'],
                    'trigger_rate' => (int) $masterRow['activation_rate'],
                    'damage_type' => $masterRow['damage_type'],
                    'power_multiplier' => (float) $masterRow['power_multiplier'],
                    'hit_count' => (int) $masterRow['hit_count'],
                    'extra_hit_chance_percent' => (int) ($masterRow['extra_hit_chance_percent'] ?? 0),
                    'luk_power_rate' => (float) ($masterRow['luk_power_rate'] ?? 0),
                    'hybrid_scaling' => (string) ($masterRow['hybrid_scaling'] ?? 'average'),
                    'heal_percent' => (int) ($masterRow['heal_percent'] ?? 0),
                    'self_damage_percent' => (int) ($masterRow['self_damage_percent'] ?? 0),
                    'gold_bonus_percent' => 0,
                    'drop_bonus_percent' => (int) ($masterRow['drop_bonus_percent'] ?? 0),
                    'rare_bonus_percent' => (int) ($masterRow['rare_bonus_percent'] ?? 0),
                    'def_ignore_percent' => (int) ($masterRow['def_ignore_percent'] ?? 0),
                    'damage_reduction_percent' => (int) ($masterRow['damage_reduction_percent'] ?? 0),
                    'self_buff_percent' => (int) ($masterRow['self_buff_percent'] ?? 0),
                    'enemy_atk_down_percent' => (int) ($masterRow['enemy_atk_down_percent'] ?? 0),
                    'enemy_mag_down_percent' => (int) ($masterRow['enemy_mag_down_percent'] ?? 0),
                    'enemy_def_down_percent' => (int) ($masterRow['enemy_def_down_percent'] ?? 0),
                    'enemy_spr_down_percent' => (int) ($masterRow['enemy_spr_down_percent'] ?? 0),
                    'enemy_spd_down_percent' => (int) ($masterRow['enemy_spd_down_percent'] ?? 0),
                    'drain_hp_rate' => (float) ($masterRow['drain_hp_rate'] ?? 0),
                    'mp_recover_percent' => (int) ($masterRow['mp_recover_percent'] ?? 0),
                    'description' => $masterRow['description'],
                ];

                Skill::updateOrCreate(
                    ['job_id' => $job->id, 'skill_type' => 'special'],
                    $skillData
                );
            }
        }
    }
}
