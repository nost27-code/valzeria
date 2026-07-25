<?php

namespace Tests\Unit;

use App\Services\ExplorationMapRewardProfileService;
use Tests\TestCase;

class ExplorationMapRewardProfileTest extends TestCase
{
    public function test_new_maps_choose_from_the_eight_player_facing_reward_profiles(): void
    {
        $profiles = config('exploration_maps.reward_profiles');

        $this->assertSame([
            'experience', 'wealth', 'training', 'material', 'equipment', 'windfall', 'vitality', 'ancient_fragment',
        ], array_keys($profiles));
        $this->assertSame(1.5, $profiles['wealth']['modifiers']['gold_multiplier']);
        $this->assertSame(1 / 3, $profiles['training']['exploration_limit_multiplier']);
        $this->assertSame(2.0, $profiles['training']['modifiers']['job_exp_multiplier']);
        $this->assertSame(6, $profiles['training']['modifiers']['job_exp_cap']);
        $this->assertSame(3, $profiles['windfall']['modifiers']['gold_drop_rate_bonus_points']);
        $this->assertSame(142, $profiles['ancient_fragment']['minimum_enemy_level']);
        $this->assertSame([], $profiles['ancient_fragment']['modifiers']);
        $this->assertSame(100, array_sum(array_column($profiles, 'weight')));
        $this->assertSame([13, 13, 13, 13, 13, 13, 13], array_column(array_slice($profiles, 0, 7), 'weight'));
        $this->assertSame(9, $profiles['ancient_fragment']['weight']);
    }

    public function test_hero_and_legend_profiles_apply_the_agreed_grade_bonuses(): void
    {
        $profiles = app(ExplorationMapRewardProfileService::class);

        $this->assertSame(1.25, $profiles->modifiers('experience', 'hero')['exp_multiplier']);
        $this->assertSame(1.30, $profiles->modifiers('experience', 'legend')['exp_multiplier']);
        $this->assertSame(1.65, $profiles->modifiers('wealth', 'hero')['gold_multiplier']);
        $this->assertSame(1.80, $profiles->modifiers('wealth', 'legend')['gold_multiplier']);
        $this->assertSame(8, $profiles->modifiers('material', 'hero')['material_drop_bonus_points']);
        $this->assertSame(10, $profiles->modifiers('material', 'legend')['material_drop_bonus_points']);
        $this->assertSame(
            ['weapon' => 0.20, 'armor' => 0.20, 'accessory' => 0.08],
            $profiles->modifiers('equipment', 'hero')['equipment_drop_bonus_points'],
        );
        $this->assertSame(
            ['weapon' => 0.30, 'armor' => 0.30, 'accessory' => 0.12],
            $profiles->modifiers('equipment', 'legend')['equipment_drop_bonus_points'],
        );
        $this->assertSame(2 / 7, $profiles->explorationLimitMultiplier('training', 'hero'));
        $this->assertSame(1 / 4, $profiles->explorationLimitMultiplier('training', 'legend'));
        $this->assertSame([], $profiles->modifiers('ancient_fragment', 'hero'));
    }

    public function test_training_job_exp_is_six_seven_and_eight_at_the_normal_cap(): void
    {
        $profiles = app(ExplorationMapRewardProfileService::class);

        $this->assertSame(['amount' => 6, 'cap' => 6], $profiles->jobExpReward(3, $profiles->modifiers('training', 'normal')));
        $this->assertSame(['amount' => 7, 'cap' => 7], $profiles->jobExpReward(3, $profiles->modifiers('training', 'hero')));
        $this->assertSame(['amount' => 8, 'cap' => 8], $profiles->jobExpReward(3, $profiles->modifiers('training', 'legend')));
    }
}
