<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FerdiaJobExpRewardConfigTest extends TestCase
{
    public function test_ferdia_enemy_job_exp_rewards_are_two_through_five(): void
    {
        $map = require dirname(__DIR__, 2) . '/config/ferdia_world_map.php';
        $rewards = $map['job_exp_rewards'];

        $this->assertSame([
            'normal' => 2,
            'strong' => 3,
            'gate_boss' => 4,
            'final_boss' => 5,
        ], $rewards);

        foreach ([1003, 1007, 1009] as $areaId) {
            $this->assertSame('gate_boss', $map['bosses'][$areaId]['job_exp_reward_key']);
        }

        foreach ([1013, 1029] as $areaId) {
            $this->assertSame('final_boss', $map['bosses'][$areaId]['job_exp_reward_key']);
        }
    }
}
