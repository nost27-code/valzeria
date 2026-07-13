<?php

namespace Tests\Unit;

use Database\Seeders\WeaponStatRescaleSeeder;
use PHPUnit\Framework\TestCase;

class WeaponStatRescaleSeederTest extends TestCase
{
    public function test_scaled_value_applies_configured_rank_multiplier(): void
    {
        $multipliers = ['A' => 1.8, 'S' => 2.5, 'EPIC' => 2.5, 'G' => 1.0];

        $this->assertSame(360, WeaponStatRescaleSeeder::scaledValue(200, 'A', $multipliers));
        $this->assertSame(500, WeaponStatRescaleSeeder::scaledValue(200, 'S', $multipliers));
        $this->assertSame(500, WeaponStatRescaleSeeder::scaledValue(200, 'EPIC', $multipliers));
        $this->assertSame(200, WeaponStatRescaleSeeder::scaledValue(200, 'G', $multipliers));
    }

    public function test_scaled_value_normalizes_rank_case(): void
    {
        $multipliers = ['EPIC' => 2.5];

        $this->assertSame(500, WeaponStatRescaleSeeder::scaledValue(200, 'epic', $multipliers));
    }

    public function test_scaled_value_defaults_to_one_for_unknown_or_unranked_weapon(): void
    {
        $this->assertSame(200, WeaponStatRescaleSeeder::scaledValue(200, 'UNKNOWN', []));
        $this->assertSame(200, WeaponStatRescaleSeeder::scaledValue(200, '', ['EPIC' => 2.5]));
    }

    public function test_scaled_value_keeps_zero_base_at_zero(): void
    {
        $this->assertSame(0, WeaponStatRescaleSeeder::scaledValue(0, 'EPIC', ['EPIC' => 2.5]));
    }

    public function test_scaled_value_rounds_to_nearest_integer(): void
    {
        // 201 * 1.8 = 361.8 -> round to 362
        $this->assertSame(362, WeaponStatRescaleSeeder::scaledValue(201, 'A', ['A' => 1.8]));
    }
}
