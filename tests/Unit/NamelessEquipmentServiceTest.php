<?php

namespace Tests\Unit;

use App\Models\PlayerNamelessEquipment;
use App\Services\NamelessEquipmentService;
use PHPUnit\Framework\TestCase;

class NamelessEquipmentServiceTest extends TestCase
{
    public function test_model_uses_the_pluralized_migration_table_name(): void
    {
        $this->assertSame('player_nameless_equipments', (new PlayerNamelessEquipment())->getTable());
    }

    public function test_power_and_total_gold_follow_the_nameless_equipment_formula(): void
    {
        $this->assertSame(5, NamelessEquipmentService::powerFor(0));
        $this->assertSame(10, NamelessEquipmentService::powerFor(1));
        $this->assertSame(500, NamelessEquipmentService::powerFor(99));
        $this->assertSame(990000, NamelessEquipmentService::goldCostForNextLevel(99));
        $this->assertSame(49500000, NamelessEquipmentService::totalGoldCostFor(99));
    }

    public function test_material_totals_and_city_caps_follow_the_specification(): void
    {
        $this->assertSame(4950, array_sum(range(1, 99)));
        $this->assertSame(1030, NamelessEquipmentService::totalFineMaterialsFor(99));
        $this->assertSame(35, NamelessEquipmentService::milestoneMaterialTotalFor(99));
        $this->assertSame(10, NamelessEquipmentService::forgeCapForCityOrder(10));
        $this->assertSame(40, NamelessEquipmentService::forgeCapForCityOrder(40));
        $this->assertSame(99, NamelessEquipmentService::forgeCapForCityOrder(100));
    }

    public function test_weapon_types_apply_to_atk_or_mag_only(): void
    {
        foreach (['剣', '短剣', '槍', '斧', '弓', '銃', '拳具'] as $type) {
            $this->assertSame('str', NamelessEquipmentService::statFor('weapon', $type)['key']);
        }
        $this->assertSame('mag', NamelessEquipmentService::statFor('weapon', '杖')['key']);
        $this->assertSame('mag', NamelessEquipmentService::statFor('weapon', '魔導書')['key']);
    }

    public function test_armor_types_apply_to_def_or_spr_only(): void
    {
        foreach (['鎧', '服', '外套', '盾', '装束'] as $type) {
            $this->assertSame('def', NamelessEquipmentService::statFor('armor', $type)['key']);
        }
        $this->assertSame('spr', NamelessEquipmentService::statFor('armor', 'ローブ')['key']);
    }

    public function test_invalid_kind_and_type_are_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        NamelessEquipmentService::statFor('weapon', 'ローブ');
    }
}
