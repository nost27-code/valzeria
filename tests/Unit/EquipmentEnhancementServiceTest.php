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

    public function test_high_rank_accessories_reach_their_configured_targets_at_plus_thirty(): void
    {
        $ssItem = (object) [
            'type' => 'accessory',
            'accessory_rank' => 'SS',
            'str_bonus' => 33,
        ];
        $sssItem = (object) [
            'type' => 'accessory',
            'accessory_rank' => 'SSS',
            'str_bonus' => 44,
        ];
        $ssFullItem = (object) [
            'type' => 'accessory',
            'accessory_rank' => 'SS',
            'str_bonus' => 11,
            'def_bonus' => 11,
            'agi_bonus' => 11,
            'mag_bonus' => 11,
            'spr_bonus' => 11,
            'luk_bonus' => 11,
        ];
        $sssFullItem = (object) [
            'type' => 'accessory',
            'accessory_rank' => 'SSS',
            'str_bonus' => 14,
            'def_bonus' => 14,
            'agi_bonus' => 14,
            'mag_bonus' => 14,
            'spr_bonus' => 14,
            'luk_bonus' => 14,
        ];
        $epicSingleItem = (object) [
            'type' => 'accessory',
            'accessory_rank' => 'EPIC',
            'str_bonus' => 60,
        ];
        $epicFullItem = (object) [
            'type' => 'accessory',
            'accessory_rank' => 'EPIC',
            'str_bonus' => 20,
            'def_bonus' => 20,
            'agi_bonus' => 20,
            'mag_bonus' => 20,
            'spr_bonus' => 20,
            'luk_bonus' => 20,
        ];

        $this->assertSame(['str' => 200], EquipmentEnhancementService::enhancedStatTotalsForItem($ssItem, 30));
        $this->assertSame(['str' => 300], EquipmentEnhancementService::enhancedStatTotalsForItem($sssItem, 30));
        $this->assertSame(['str' => 400], EquipmentEnhancementService::enhancedStatTotalsForItem($epicSingleItem, 30));
        $this->assertSame(
            ['str' => 100, 'def' => 100, 'agi' => 100, 'mag' => 100, 'spr' => 100, 'luk' => 100],
            EquipmentEnhancementService::enhancedStatTotalsForItem($ssFullItem, 30)
        );
        $this->assertSame(
            ['str' => 150, 'def' => 150, 'agi' => 150, 'mag' => 150, 'spr' => 150, 'luk' => 150],
            EquipmentEnhancementService::enhancedStatTotalsForItem($sssFullItem, 30)
        );
        $this->assertSame(
            ['str' => 200, 'def' => 200, 'agi' => 200, 'mag' => 200, 'spr' => 200, 'luk' => 200],
            EquipmentEnhancementService::enhancedStatTotalsForItem($epicFullItem, 30)
        );
    }

    public function test_lower_rank_accessories_keep_the_existing_total_bonus_formula(): void
    {
        $item = (object) [
            'type' => 'accessory',
            'accessory_rank' => 'S',
            'str_bonus' => 24,
        ];

        $this->assertSame(['str' => 49], EquipmentEnhancementService::enhancedStatTotalsForItem($item, 25));
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

    public function test_weapon_gold_costs_are_fixed_by_the_target_enhancement_level(): void
    {
        $service = app(EquipmentEnhancementService::class);
        $method = new \ReflectionMethod($service, 'goldCostForLevel');
        $method->setAccessible(true);
        $gWeapon = new Item(['type' => 'weapon', 'weapon_rank' => 'G']);
        $sssWeapon = new Item(['type' => 'weapon', 'weapon_rank' => 'SSS']);
        $epicWeapon = new Item(['type' => 'weapon', 'weapon_rank' => 'EPIC']);

        foreach ([1 => 300, 5 => 7500, 10 => 30000, 20 => 120000, 30 => 270000] as $level => $expected) {
            $this->assertSame($expected, $method->invoke($service, $level, 'weapon', $gWeapon));
            $this->assertSame($expected, $method->invoke($service, $level, 'weapon', $sssWeapon));
            $this->assertSame($expected, $method->invoke($service, $level, 'weapon', $epicWeapon));
        }

        $this->assertSame(2836500, array_sum(array_map(
            fn (int $level): int => $method->invoke($service, $level, 'weapon', $gWeapon),
            range(1, 30)
        )));
    }

    public function test_weapon_recipes_use_fixed_materials_for_each_target_level(): void
    {
        $service = app(EquipmentEnhancementService::class);
        $method = new \ReflectionMethod($service, 'weaponMaterialsFor');
        $method->setAccessible(true);
        $materials = $method->invoke($service, 15);

        $this->assertSame([
            ['material_id' => 'MAT_ENHANCE_STONE', 'material_name' => '強化石', 'quantity' => 8],
            ['material_id' => 'MAT_ENHANCE_HIGH_STONE', 'material_name' => '高純度強化石', 'quantity' => 4],
            ['material_id' => 'WEV0028', 'material_name' => '砂金石', 'quantity' => 3],
            ['material_id' => 'WEV0043', 'material_name' => '砂王金晶', 'quantity' => 2],
            ['material_id' => 'MAT_REFINING_CORE_LOW', 'material_name' => '粗精錬核', 'quantity' => 1],
        ], $materials);
    }

    public function test_weapon_recipes_use_the_fixed_material_bands(): void
    {
        $service = app(EquipmentEnhancementService::class);
        $method = new \ReflectionMethod($service, 'weaponMaterialsFor');
        $method->setAccessible(true);

        foreach ([
            11 => ['WEV0027' => 2, 'WEV0041' => 1],
            14 => ['WEV0028' => 2, 'WEV0043' => 1],
            15 => ['WEV0028' => 3, 'WEV0043' => 2],
            16 => ['WEV0028' => 2, 'WEV0043' => 2],
            17 => ['MAT_REGION_MAGIC_CRYSTAL' => 2, 'WEV0045' => 2],
            20 => ['MAT_REGION_MAGIC_CRYSTAL' => 4, 'WEV0045' => 3],
            21 => ['WEV0030' => 1, 'WEV0047' => 1],
            24 => ['WEV0031' => 1, 'WEV0049' => 1],
            25 => ['WEV0031' => 2, 'WEV0049' => 2],
            26 => ['WEV0031' => 1, 'WEV0049' => 2],
            27 => ['WEV0032' => 1, 'WEV0051' => 2],
            30 => ['WEV0032' => 3, 'WEV0051' => 3],
        ] as $level => $expectedMaterials) {
            $actualMaterials = collect($method->invoke($service, $level))
                ->mapWithKeys(fn (array $material): array => [$material['material_id'] => $material['quantity']])
                ->all();

            foreach ($expectedMaterials as $materialCode => $quantity) {
                $this->assertSame($quantity, $actualMaterials[$materialCode] ?? null, "+{$level} {$materialCode}");
            }
        }
    }

    public function test_accessory_recipes_use_the_same_fixed_city_materials_with_tuning_stones(): void
    {
        $service = app(EquipmentEnhancementService::class);
        $method = new \ReflectionMethod($service, 'accessoryMaterialsFor');
        $method->setAccessible(true);

        $plusTen = collect($method->invoke($service, 10))
            ->mapWithKeys(fn (array $material): array => [$material['material_id'] => $material['quantity']])
            ->all();
        $plusThirty = collect($method->invoke($service, 30))
            ->mapWithKeys(fn (array $material): array => [$material['material_id'] => $material['quantity']])
            ->all();

        $this->assertSame(6, $plusTen['ACC0008'] ?? null);
        $this->assertSame(1, $plusTen['ACC0009'] ?? null);
        $this->assertSame(1, $plusTen['WEV0037'] ?? null);
        $this->assertSame(1, $plusTen['WEV0039'] ?? null);
        $this->assertSame(16, $plusThirty['ACC0008'] ?? null);
        $this->assertSame(10, $plusThirty['ACC0009'] ?? null);
        $this->assertSame(3, $plusThirty['WEV0032'] ?? null);
        $this->assertSame(3, $plusThirty['WEV0051'] ?? null);
    }
}
