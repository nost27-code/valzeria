<?php

namespace Tests\Unit;

use Tests\TestCase;

class ExplorationMapRewardProfileTest extends TestCase
{
    public function test_new_maps_choose_from_the_seven_player_facing_reward_profiles(): void
    {
        $profiles = config('exploration_maps.reward_profiles');

        $this->assertSame([
            'experience', 'wealth', 'training', 'material', 'equipment', 'windfall', 'vitality',
        ], array_keys($profiles));
        $this->assertSame(1.5, $profiles['wealth']['modifiers']['gold_multiplier']);
        $this->assertSame(1 / 3, $profiles['training']['exploration_limit_multiplier']);
        $this->assertSame(2.0, $profiles['training']['modifiers']['job_exp_multiplier']);
        $this->assertSame(6, $profiles['training']['modifiers']['job_exp_cap']);
        $this->assertSame(3, $profiles['windfall']['modifiers']['gold_drop_rate_bonus_points']);
    }
}
