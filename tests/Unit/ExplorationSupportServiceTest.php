<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Services\ExplorationSupportService;
use PHPUnit\Framework\TestCase;

class ExplorationSupportServiceTest extends TestCase
{
    public function test_guard_incense_reduces_only_non_zero_direct_damage(): void
    {
        $service = new ExplorationSupportService();

        $this->assertSame(92, $service->reduceDirectDamage(100, ['item_key' => 'support_guard_incense']));
        $this->assertSame(1, $service->reduceDirectDamage(1, ['item_key' => 'support_guard_incense']));
        $this->assertSame(0, $service->reduceDirectDamage(0, ['item_key' => 'support_guard_incense']));
    }

    public function test_first_aid_kit_shortens_and_halves_target_conditions(): void
    {
        $service = new ExplorationSupportService();
        $snapshot = ['item_key' => 'support_first_aid_kit'];

        $this->assertSame(1, $service->adjustedConditionDuration(1, $snapshot));
        $this->assertSame(2, $service->adjustedConditionDuration(3, $snapshot));
        $this->assertSame(5, $service->adjustedDotDamage(10, $snapshot));
        $this->assertSame(1, $service->adjustedDotDamage(1, $snapshot));
    }

    public function test_special_herbal_recovers_once_per_battle_up_to_three_times(): void
    {
        $service = new ExplorationSupportService();
        $snapshot = ['item_key' => 'support_special_herbal', 'proc_count' => 0];
        $hp = 30;

        $this->assertSame(20, $service->trySpecialHerbal(new Character(), $hp, 100, $snapshot));
        $this->assertSame(50, $hp);
        $this->assertNull($service->trySpecialHerbal(new Character(), $hp, 100, $snapshot));

        $snapshot = ['item_key' => 'support_special_herbal', 'proc_count' => 3];
        $hp = 30;
        $this->assertNull($service->trySpecialHerbal(new Character(), $hp, 100, $snapshot));
    }

    public function test_special_herbal_ignores_battles_without_an_active_support_item(): void
    {
        $service = new ExplorationSupportService();
        $snapshot = null;
        $hp = 30;

        $this->assertNull($service->trySpecialHerbal(new Character(), $hp, 100, $snapshot));
        $this->assertSame(30, $hp);
    }
}
