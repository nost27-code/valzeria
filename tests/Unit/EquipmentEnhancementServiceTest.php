<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Services\EquipmentEnhancementService;
use Tests\TestCase;

class EquipmentEnhancementServiceTest extends TestCase
{
    public function test_accessory_enhancement_distributes_total_bonus_by_base_ratio(): void
    {
        $item = (object) [
            'type' => 'accessory',
            'hp_bonus' => 0,
            'mp_bonus' => 0,
            'str_bonus' => 0,
            'def_bonus' => 0,
            'agi_bonus' => 16,
            'mag_bonus' => 0,
            'spr_bonus' => 0,
            'luk_bonus' => 8,
        ];

        $this->assertSame(
            ['agi' => 21, 'luk' => 11],
            EquipmentEnhancementService::enhancedStatTotalsForItem($item, 4)
        );
    }

    public function test_accessory_enhancement_uses_the_extended_total_bonus_at_plus_thirty(): void
    {
        $item = (object) [
            'type' => 'accessory',
            'hp_bonus' => 0,
            'mp_bonus' => 0,
            'str_bonus' => 11,
            'def_bonus' => 11,
            'agi_bonus' => 11,
            'mag_bonus' => 11,
            'spr_bonus' => 11,
            'luk_bonus' => 11,
        ];

        $this->assertSame(
            ['str' => 16, 'def' => 16, 'agi' => 16, 'mag' => 15, 'spr' => 15, 'luk' => 15],
            EquipmentEnhancementService::enhancedStatTotalsForItem($item, 30)
        );
    }

    public function test_weapon_enhancement_keeps_existing_per_stat_formula(): void
    {
        $item = (object) [
            'type' => 'weapon',
            'hp_bonus' => 0,
            'mp_bonus' => 0,
            'str_bonus' => 100,
            'def_bonus' => 0,
            'agi_bonus' => 0,
            'mag_bonus' => 0,
            'spr_bonus' => 0,
            'luk_bonus' => 0,
        ];

        $this->assertSame(
            ['str' => 115],
            EquipmentEnhancementService::enhancedStatTotalsForItem($item, 5)
        );
    }

    public function test_weapon_enhancement_reaches_forty_seven_point_five_percent_at_plus_thirty(): void
    {
        $item = (object) [
            'type' => 'weapon',
            'str_bonus' => 100,
        ];

        $this->assertSame(
            ['str' => 147],
            EquipmentEnhancementService::enhancedStatTotalsForItem($item, 30)
        );
    }

    public function test_rank_caps_are_resolved_from_the_equipment_rank(): void
    {
        $service = app(EquipmentEnhancementService::class);

        $this->assertSame(10, $service->maxEnhanceFor(new Item(['weapon_rank' => 'G'])));
        $this->assertSame(20, $service->maxEnhanceFor(new Item(['weapon_rank' => 'A'])));
        $this->assertSame(30, $service->maxEnhanceFor(new Item(['weapon_rank' => 'EPIC'])));
    }

    public function test_sss_enhancement_gold_costs_follow_the_three_band_five_million_design(): void
    {
        $service = app(EquipmentEnhancementService::class);
        $method = new \ReflectionMethod($service, 'goldCostForLevel');
        $method->setAccessible(true);
        $item = new Item(['weapon_rank' => 'SSS']);

        $costs = [];
        for ($level = 1; $level <= 30; $level++) {
            $costs[$level] = $method->invoke($service, $level, $item);
        }

        $this->assertSame(300000, array_sum(array_slice($costs, 0, 10)));
        $this->assertSame(1200000, array_sum(array_slice($costs, 10, 10)));
        $this->assertSame(3500000, array_sum(array_slice($costs, 20, 10)));
        $this->assertSame(5000000, array_sum($costs));
        $this->assertSame(585000, $costs[30]);
    }
}
