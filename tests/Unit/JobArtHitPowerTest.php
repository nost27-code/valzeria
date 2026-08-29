<?php

namespace Tests\Unit;

use App\Services\ArenaNpcBattleService;
use App\Services\Battle\JobArtHitPower;
use App\Services\BattleService;
use App\Services\ChampBattleService;
use App\Services\PvPBattleService;
use App\Services\TowerBattleService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JobArtHitPowerTest extends TestCase
{
    #[DataProvider('splitProvider')]
    public function test_action_total_power_is_distributed_exactly_across_hits(
        int $power,
        int $hits,
        array $expected,
    ): void {
        $actual = JobArtHitPower::split($power, $hits);

        $this->assertSame($expected, $actual);
        $this->assertSame(max(0, $power), array_sum($actual));
        $this->assertCount(max(1, $hits), $actual);
    }

    public function test_all_six_combat_routes_share_the_same_splitter(): void
    {
        $battleSource = file_get_contents((new \ReflectionClass(BattleService::class))->getFileName());
        $this->assertGreaterThanOrEqual(2, substr_count($battleSource, 'JobArtHitPower::split('));
        $this->assertTrue(is_subclass_of(TowerBattleService::class, BattleService::class));

        foreach ([PvPBattleService::class, ChampBattleService::class, ArenaNpcBattleService::class] as $route) {
            $source = file_get_contents((new \ReflectionClass($route))->getFileName());
            $this->assertStringContainsString('JobArtHitPower::split(', $source, $route);
        }
    }

    public function test_centi_power_is_split_without_losing_the_action_total(): void
    {
        $actual = JobArtHitPower::splitCenti(22_561, 3);

        $this->assertSame([7_521, 7_520, 7_520], $actual);
        $this->assertSame(22_561, array_sum($actual));
    }

    public static function splitProvider(): array
    {
        return [
            'snow flower' => [255, 3, [85, 85, 85]],
            'arena combo' => [165, 3, [55, 55, 55]],
            'five-hit finisher' => [225, 5, [45, 45, 45, 45, 45]],
            'remainder goes to the first hits' => [145, 3, [49, 48, 48]],
            'single hit' => [255, 1, [255]],
            'zero power remains zero' => [0, 3, [0, 0, 0]],
        ];
    }
}
