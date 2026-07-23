<?php

namespace Tests\Feature;

use App\Models\Item;
use Database\Seeders\WeaponStatRescaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeaponStatRescaleSeederFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_rank_scaling_seeder_does_not_change_weapon_offense(): void
    {
        $weapon = Item::query()->create([
            'name' => '旧Seeder試験剣',
            'type' => 'weapon',
            'weapon_rank' => 'EPIC',
            'str_bonus' => 536,
            'mag_bonus' => 656,
            'is_active' => true,
        ]);

        $this->seed(WeaponStatRescaleSeeder::class);
        $this->seed(WeaponStatRescaleSeeder::class);

        $weapon->refresh();
        $this->assertSame(536, $weapon->str_bonus);
        $this->assertSame(656, $weapon->mag_bonus);
        $this->assertNull($weapon->weapon_offense_scale_version);
    }
}
