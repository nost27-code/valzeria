<?php

namespace Tests\Unit;

use App\Services\NamelessEquipmentCollectionService;
use RuntimeException;
use Tests\TestCase;

class NamelessEquipmentDropRateTest extends TestCase
{
    public function test_approved_endpoints_intermediate_depths_and_bounds(): void
    {
        config(['nameless_relics.equipment_drop_chance_bps' => 10,
            'nameless_relics.equipment_drop_depth_multiplier_at_max' => 10, 'nameless_relics.max_depth' => 100]);
        $service = app(NamelessEquipmentCollectionService::class);
        foreach ([0 => 10, 1 => 10, 10 => 18, 25 => 32, 50 => 55, 75 => 77, 100 => 100, 101 => 100] as $depth => $rate) {
            $this->assertSame($rate, $service->dropChanceBpsAtDepth($depth));
        }
        $previous = 0;
        for ($depth = 1; $depth <= 100; $depth++) {
            $current = $service->dropChanceBpsAtDepth($depth);
            $this->assertGreaterThanOrEqual($previous, $current);
            $this->assertLessThanOrEqual(100, $current);
            $previous = $current;
        }
    }

    public function test_each_depth_accepts_exactly_its_rate_of_all_tickets(): void
    {
        config(['nameless_relics.equipment_drop_chance_bps' => 10,
            'nameless_relics.equipment_drop_depth_multiplier_at_max' => 10, 'nameless_relics.max_depth' => 100]);
        $service = app(NamelessEquipmentCollectionService::class);
        foreach ([1 => 10, 50 => 55, 100 => 100] as $depth => $expected) {
            $accepted = 0;
            for ($ticket = 1; $ticket <= 10000; $ticket++) {
                $accepted += (int) $service->dropsForTicket($ticket, $depth);
            }
            $this->assertSame($expected, $accepted);
        }
        $this->assertTrue($service->dropsForTicket(10));
        $this->assertFalse($service->dropsForTicket(11));
    }

    public function test_disabled_and_forced_rates_remain_stable_at_every_depth(): void
    {
        $service = app(NamelessEquipmentCollectionService::class);
        foreach ([0, 10000] as $base) {
            config(['nameless_relics.equipment_drop_chance_bps' => $base,
                'nameless_relics.equipment_drop_depth_multiplier_at_max' => 10, 'nameless_relics.max_depth' => 100]);
            foreach ([1, 50, 100] as $depth) {
                $this->assertSame($base, $service->dropChanceBpsAtDepth($depth));
                $this->assertSame($base === 10000, $service->dropsForTicket(1, $depth));
                $this->assertSame($base === 10000, $service->dropsForTicket(10000, $depth));
            }
        }
    }

    public function test_invalid_rates_and_tickets_are_rejected(): void
    {
        $service = app(NamelessEquipmentCollectionService::class);
        foreach ([[-1, 10, 1], [10001, 10, 1], [10, 0, 1], [10, INF, 1], [10, 10, 0], [10, 10, 10001]] as [$base, $multiplier, $ticket]) {
            config(['nameless_relics.equipment_drop_chance_bps' => $base,
                'nameless_relics.equipment_drop_depth_multiplier_at_max' => $multiplier]);
            try { $service->dropsForTicket($ticket, 100); $this->fail('不正な抽選を拒否する'); }
            catch (RuntimeException $exception) { $this->assertSame('武具の抽選設定が不正です。', $exception->getMessage()); }
        }
    }
}
