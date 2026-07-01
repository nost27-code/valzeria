<?php

namespace Tests\Unit;

use App\Services\EquipmentEnhancementService;
use PHPUnit\Framework\TestCase;

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

    public function test_accessory_enhancement_caps_total_bonus_at_plus_five(): void
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
            ['str' => 13, 'def' => 13, 'agi' => 13, 'mag' => 13, 'spr' => 12, 'luk' => 12],
            EquipmentEnhancementService::enhancedStatTotalsForItem($item, 5)
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
}
