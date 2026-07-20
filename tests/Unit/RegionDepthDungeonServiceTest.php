<?php

namespace Tests\Unit;

use App\Services\RegionDepthDungeonService;
use Tests\TestCase;

class RegionDepthDungeonServiceTest extends TestCase
{
    public function test_enemy_multipliers_are_linear_without_an_upper_bound(): void
    {
        $service = app(RegionDepthDungeonService::class);

        $this->assertSame(['main' => 1.0, 'hp' => 1.0, 'agi_luk' => 1.0], $service->enemyMultipliers(0));
        $this->assertSame(3.0, $service->enemyMultipliers(200)['main']);
        $this->assertSame(2.0, $service->enemyMultipliers(200)['hp']);
        $this->assertSame(11.0, $service->enemyMultipliers(1000)['main']);
        $this->assertSame(6.0, $service->enemyMultipliers(1000)['agi_luk']);
    }

    public function test_granberg_black_furnace_starts_at_sandra_entry_level_stats(): void
    {
        $service = app(RegionDepthDungeonService::class);

        $this->assertSame([
            'hp' => 1.427,
            'str' => 1.397,
            'def' => 1.388,
            'agi' => 1.484,
            'mag' => 1.208,
            'spr' => 1.287,
            'luk' => 1.234,
        ], $service->baseEnemyStatMultipliers('granberg_black_furnace'));
    }

    public function test_granberg_black_furnace_uses_sandra_entry_rewards_before_danger_bonus(): void
    {
        $service = app(RegionDepthDungeonService::class);

        $this->assertSame(1.345, $service->baseExpMultiplier('granberg_black_furnace'));
        $this->assertSame(3, $service->baseJobExp('granberg_black_furnace', 0));
        $this->assertSame(3, $service->calculateJobExp($service->baseJobExp('granberg_black_furnace', 3), 0)['total']);
        $this->assertSame(4, $service->calculateJobExp($service->baseJobExp('granberg_black_furnace', 3), 200)['total']);
    }

    public function test_job_exp_bonus_keeps_zero_base_at_zero_and_caps_only_region_depth_rewards_at_eight(): void
    {
        $service = app(RegionDepthDungeonService::class);

        $this->assertSame(0, $service->calculateJobExp(0, 10000)['total']);
        $this->assertSame(3, $service->calculateJobExp(3, 100, 10000)['total']);
        $this->assertSame(4, $service->calculateJobExp(3, 100, 1)['total']);
        $this->assertSame(6, $service->calculateJobExp(3, 500, 1)['total']);
        $this->assertSame(8, $service->calculateJobExp(3, 1000)['total']);
        $this->assertSame(8, $service->calculateJobExp(1, 1400)['total']);
    }

    public function test_labels_and_prefixes_cover_the_highest_danger_band(): void
    {
        $service = app(RegionDepthDungeonService::class);

        $this->assertSame('安定', $service->dangerLabel(24));
        $this->assertSame('警戒', $service->dangerLabel(25));
        $this->assertSame('炉神域', $service->dangerLabel(1000));
        $this->assertSame('', $service->enemyPrefix(24));
        $this->assertSame('硬質化した', $service->enemyPrefix(25));
        $this->assertSame('炉神級の', $service->enemyPrefix(1000));
    }

    public function test_granberg_danger_increases_on_a_thirty_three_percent_roll(): void
    {
        $service = app(RegionDepthDungeonService::class);

        $this->assertSame(33, $service->dangerIncreasePercent('granberg_black_furnace'));
        $this->assertTrue($service->shouldIncreaseDanger('granberg_black_furnace', 33));
        $this->assertFalse($service->shouldIncreaseDanger('granberg_black_furnace', 34));
    }
}
