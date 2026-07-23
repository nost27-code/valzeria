<?php

namespace Tests\Unit;

use App\Services\Battle\WeaponOffenseCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WeaponOffenseCalculatorTest extends TestCase
{
    #[DataProvider('effectiveOffenseCases')]
    public function test_calculates_effective_offense(int $baseStat, int $weaponOffense, int $expected): void
    {
        $this->assertSame($expected, (new WeaponOffenseCalculator())->calculateEffectiveOffense($baseStat, $weaponOffense));
    }

    public function test_calculates_an_equipment_stat_with_the_same_formula(): void
    {
        $this->assertSame(2537, (new WeaponOffenseCalculator())->calculateEffectiveStat(2479, 536));
    }

    public function test_calculates_the_armor_proportional_bonus(): void
    {
        $this->assertSame(235, (new WeaponOffenseCalculator())->calculateProportionalBonus(1960, 288));
    }

    public static function effectiveOffenseCases(): array
    {
        return [
            [2479, 536, 2537],
            [2479, 640, 2644],
            [1326, 656, 1423],
            [1326, 800, 1503],
            [1000, 0, 800],
            [-1, -1, 0],
        ];
    }
}
