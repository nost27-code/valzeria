<?php

namespace Tests\Unit;

use App\Services\TowerEnemyScalingService;
use InvalidArgumentException;
use Tests\TestCase;

class TowerEnemyScalingServiceTest extends TestCase
{
    public function test_base_stats_follow_star_tree_tower_floor_curve(): void
    {
        $service = app(TowerEnemyScalingService::class);

        $expected = [
            10 => ['max_hp' => 2000, 'str' => 160, 'def' => 84, 'mag' => 152, 'spr' => 84, 'agi' => 95, 'luk' => 40],
            20 => ['max_hp' => 4700, 'str' => 260, 'def' => 146, 'mag' => 258, 'spr' => 146, 'agi' => 160, 'luk' => 60],
            30 => ['max_hp' => 8600, 'str' => 380, 'def' => 216, 'mag' => 388, 'spr' => 216, 'agi' => 235, 'luk' => 80],
            40 => ['max_hp' => 13700, 'str' => 520, 'def' => 294, 'mag' => 542, 'spr' => 294, 'agi' => 320, 'luk' => 100],
            50 => ['max_hp' => 21200, 'str' => 700, 'def' => 385, 'mag' => 742, 'spr' => 385, 'agi' => 423, 'luk' => 120],
            60 => ['max_hp' => 32300, 'str' => 940, 'def' => 494, 'mag' => 1010, 'spr' => 494, 'agi' => 552, 'luk' => 140],
        ];

        foreach ($expected as $floor => $stats) {
            $this->assertSame($stats, $service->baseStatsForFloor($floor));
        }
    }

    public function test_enemy_profiles_apply_stat_roles_without_changing_luck(): void
    {
        $service = app(TowerEnemyScalingService::class);

        $this->assertSame([
            'max_hp' => 2000,
            'str' => 176,
            'def' => 88,
            'mag' => 106,
            'spr' => 80,
            'agi' => 95,
            'luk' => 40,
        ], $service->statsForFloor(10, 'physical'));

        $this->assertSame([
            'max_hp' => 2000,
            'str' => 112,
            'def' => 80,
            'mag' => 167,
            'spr' => 88,
            'agi' => 95,
            'luk' => 40,
        ], $service->statsForFloor(10, 'magical'));

        $this->assertSame([
            'max_hp' => 2200,
            'str' => 152,
            'def' => 84,
            'mag' => 144,
            'spr' => 84,
            'agi' => 95,
            'luk' => 40,
        ], $service->statsForFloor(10, 'hybrid'));

        $this->assertSame([
            'max_hp' => 1900,
            'str' => 160,
            'def' => 76,
            'mag' => 137,
            'spr' => 76,
            'agi' => 114,
            'luk' => 40,
        ], $service->statsForFloor(10, 'speed'));
    }

    public function test_unknown_profile_falls_back_to_physical(): void
    {
        $service = app(TowerEnemyScalingService::class);

        $this->assertSame(
            $service->statsForFloor(10, 'physical'),
            $service->statsForFloor(10, 'missing')
        );
    }

    public function test_floor_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(TowerEnemyScalingService::class)->baseStatsForFloor(0);
    }
}
