<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Services\CharacterStatusService;
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

    public function test_accessory_enhancement_adds_one_total_stat_per_level_after_plus_five(): void
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
            ['str' => 17, 'def' => 17, 'agi' => 17, 'mag' => 17, 'spr' => 17, 'luk' => 16],
            EquipmentEnhancementService::enhancedStatTotalsForItem($item, 30)
        );
    }

    public function test_eightfold_scaled_accessory_keeps_the_enhancement_result_at_exactly_eight_times_the_previous_value(): void
    {
        $scaledLowerRank = (object) [
            'type' => 'accessory',
            'accessory_performance_scale_version' => 2,
            'agi_bonus' => 128,
            'luk_bonus' => 64,
        ];
        $scaledHighRank = (object) [
            'type' => 'accessory',
            'accessory_rank' => 'SS',
            'accessory_performance_scale_version' => 2,
            'str_bonus' => 264,
        ];

        // 8倍化前: +4で 敏捷21 / 運11、SS単能力+30で 攻撃200。
        $this->assertSame(
            ['agi' => 168, 'luk' => 88],
            EquipmentEnhancementService::enhancedStatTotalsForItem($scaledLowerRank, 4)
        );
        $this->assertSame(
            ['str' => 1600],
            EquipmentEnhancementService::enhancedStatTotalsForItem($scaledHighRank, 30)
        );
    }

    public function test_scaled_accessory_enhancement_keeps_hp_at_fourfold_and_other_stats_at_eightfold(): void
    {
        $item = (object) [
            'type' => 'accessory',
            'accessory_performance_scale_version' => 2,
            // 8倍化前はHP+5 / 攻撃+10。
            'hp_bonus' => 20,
            'str_bonus' => 80,
        ];

        // +4後の旧値はHP+8 / 攻撃+15。HPは4倍、攻撃は8倍に戻す。
        $this->assertSame(
            ['hp' => 32, 'str' => 120],
            EquipmentEnhancementService::enhancedStatTotalsForItem($item, 4)
        );
    }

    public function test_accessory_enhancement_never_lowers_a_stat_or_has_an_empty_paid_level(): void
    {
        $examples = [
            'S single' => ['accessory_rank' => 'S', 'str_bonus' => 192],
            'S mixed' => ['accessory_rank' => 'S', 'hp_bonus' => 324, 'mp_bonus' => 320, 'luk_bonus' => 64],
            'SS single' => ['accessory_rank' => 'SS', 'str_bonus' => 264],
            'SS HP' => ['accessory_rank' => 'SS', 'hp_bonus' => 1320],
            'SS full' => ['accessory_rank' => 'SS', 'str_bonus' => 88, 'def_bonus' => 88, 'agi_bonus' => 88, 'mag_bonus' => 88, 'spr_bonus' => 88, 'luk_bonus' => 88],
            'SS mixed' => ['accessory_rank' => 'SS', 'hp_bonus' => 440, 'mp_bonus' => 440, 'luk_bonus' => 88],
            'SSS HP' => ['accessory_rank' => 'SSS', 'hp_bonus' => 2000],
            'EPIC full' => ['accessory_rank' => 'EPIC', 'str_bonus' => 160, 'def_bonus' => 160, 'agi_bonus' => 160, 'mag_bonus' => 160, 'spr_bonus' => 160, 'luk_bonus' => 160],
        ];

        foreach ($examples as $label => $definition) {
            $item = (object) (['type' => 'accessory', 'accessory_performance_scale_version' => 2] + $definition);
            $maxLevel = $definition['accessory_rank'] === 'S' ? 25 : 30;
            $previous = EquipmentEnhancementService::enhancedStatTotalsForItem($item, 0);

            for ($level = 1; $level <= $maxLevel; $level++) {
                $current = EquipmentEnhancementService::enhancedStatTotalsForItem($item, $level);
                foreach ($previous as $stat => $value) {
                    $this->assertGreaterThanOrEqual($value, $current[$stat], "{$label} +{$level} {$stat} decreased");
                }
                $this->assertGreaterThan(array_sum($previous), array_sum($current), "{$label} +{$level} has no gain");
                $previous = $current;
            }
        }
    }

    public function test_ss_accessory_growth_uses_the_base_stats_without_changing_plus_zero(): void
    {
        $single = (object) ['type' => 'accessory', 'accessory_rank' => 'SS', 'accessory_performance_scale_version' => 2, 'str_bonus' => 264];
        $life = (object) ['type' => 'accessory', 'accessory_rank' => 'SS', 'accessory_performance_scale_version' => 2, 'hp_bonus' => 1320];
        $spirit = (object) ['type' => 'accessory', 'accessory_rank' => 'SS', 'accessory_performance_scale_version' => 2, 'mp_bonus' => 1320];

        $this->assertSame(['str' => 264], EquipmentEnhancementService::enhancedStatTotalsForItem($single, 0));
        $this->assertSame(['hp' => 1320], EquipmentEnhancementService::enhancedStatTotalsForItem($life, 0));
        $this->assertSame(['mp' => 1320], EquipmentEnhancementService::enhancedStatTotalsForItem($spirit, 0));
        $this->assertSame(['str' => 1600], EquipmentEnhancementService::enhancedStatTotalsForItem($single, 30));
        $this->assertSame(['hp' => 1947], EquipmentEnhancementService::enhancedStatTotalsForItem($life, 30));
        $this->assertSame(['mp' => 1947], EquipmentEnhancementService::enhancedStatTotalsForItem($spirit, 30));
    }

    public function test_hp_sp_and_mixed_accessories_reach_the_existing_performance_band_floor(): void
    {
        $examples = [
            ['rank' => 'S', 'level' => 25, 'base' => ['hp_bonus' => 980], 'expected' => ['hp' => 1406]],
            ['rank' => 'S', 'level' => 25, 'base' => ['hp_bonus' => 324, 'mp_bonus' => 320, 'luk_bonus' => 64], 'expected' => ['hp' => 464, 'mp' => 459, 'luk' => 91]],
            ['rank' => 'SS', 'level' => 30, 'base' => ['hp_bonus' => 440, 'mp_bonus' => 440, 'luk_bonus' => 88], 'expected' => ['hp' => 649, 'mp' => 649, 'luk' => 129]],
            ['rank' => 'SS', 'level' => 30, 'base' => ['hp_bonus' => 440, 'def_bonus' => 224], 'expected' => ['hp' => 649, 'def' => 330]],
        ];

        foreach ($examples as $example) {
            $item = (object) (['type' => 'accessory', 'accessory_rank' => $example['rank'], 'accessory_performance_scale_version' => 2] + $example['base']);

            $this->assertSame($example['expected'], EquipmentEnhancementService::enhancedStatTotalsForItem($item, $example['level']));
        }
    }

    public function test_accessory_enhancement_preview_and_effective_equipment_use_the_same_resource_growth(): void
    {
        $item = new Item([
            'type' => 'accessory',
            'accessory_rank' => 'SS',
            'accessory_performance_scale_version' => 2,
            'hp_bonus' => 440,
            'mp_bonus' => 440,
            'luk_bonus' => 88,
        ]);
        $owned = new CharacterItem(['enhance_level' => 29]);
        $owned->setRelation('item', $item);

        $preview = EquipmentEnhancementService::enhancedStatsFor($owned);
        $effective = app(CharacterStatusService::class)->equipmentStatsForItem(new Character, $item, 30);

        $this->assertSame(649, $preview['hp']['next']);
        $this->assertGreaterThan($preview['hp']['current'], $preview['hp']['next']);
        $this->assertSame(649, $preview['mp']['next']);
        $this->assertSame(649, $effective['hp']);
        $this->assertSame(649, $effective['mp']);
        $this->assertSame(129, $effective['luk']);
    }

    public function test_high_rank_accessories_reach_the_restored_targets_at_plus_thirty(): void
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

    public function test_epic_full_ability_plus_ten_matches_the_july_value_in_preview_and_equipment(): void
    {
        $item = new Item([
            'type' => 'accessory',
            'accessory_rank' => 'EPIC',
            'accessory_performance_scale_version' => 2,
            'str_bonus' => 160,
            'def_bonus' => 160,
            'agi_bonus' => 160,
            'mag_bonus' => 160,
            'spr_bonus' => 160,
            'luk_bonus' => 160,
        ]);
        $owned = new CharacterItem(['enhance_level' => 9]);
        $owned->setRelation('item', $item);

        $expected = ['str' => 640, 'def' => 640, 'agi' => 640, 'mag' => 640, 'spr' => 640, 'luk' => 640];
        $this->assertSame(array_fill_keys(array_keys($expected), 160), EquipmentEnhancementService::enhancedStatTotalsForItem($item, 0));
        $this->assertSame($expected, EquipmentEnhancementService::enhancedStatTotalsForItem($item, 10));
        $this->assertSame(640, EquipmentEnhancementService::enhancedStatsFor($owned)['str']['next']);

        $effective = app(CharacterStatusService::class)->equipmentStatsForItem(new Character, $item, 10);
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $effective[$key]);
        }
    }

    public function test_ss_and_sss_growth_at_plus_ten_tracks_the_epic_enhancement_portion(): void
    {
        $bases = ['SS' => 88, 'SSS' => 112, 'EPIC' => 160];
        $growth = [];
        foreach ($bases as $rank => $base) {
            $item = (object) ([
                'type' => 'accessory',
                'accessory_rank' => $rank,
                'accessory_performance_scale_version' => 2,
            ] + array_fill_keys(['str_bonus', 'def_bonus', 'agi_bonus', 'mag_bonus', 'spr_bonus', 'luk_bonus'], $base));
            $atZero = EquipmentEnhancementService::enhancedStatTotalsForItem($item, 0);
            $atTen = EquipmentEnhancementService::enhancedStatTotalsForItem($item, 10);
            $growth[$rank] = array_sum($atTen) - array_sum($atZero);
        }

        $this->assertEqualsWithDelta($growth['EPIC'] * 0.5, $growth['SS'], 16);
        $this->assertEqualsWithDelta($growth['EPIC'] * 0.75, $growth['SSS'], 16);
    }

    public function test_s_accessory_gains_at_each_level_through_plus_twenty_five(): void
    {
        $item = (object) [
            'type' => 'accessory',
            'accessory_rank' => 'S',
            'str_bonus' => 24,
        ];

        $this->assertSame(['str' => 54], EquipmentEnhancementService::enhancedStatTotalsForItem($item, 25));
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

    public function test_equipment_gold_costs_are_fixed_by_the_target_enhancement_level(): void
    {
        $service = app(EquipmentEnhancementService::class);
        $method = new \ReflectionMethod($service, 'goldCostForLevel');
        $method->setAccessible(true);
        $gWeapon = new Item(['type' => 'weapon', 'weapon_rank' => 'G']);
        $sssWeapon = new Item(['type' => 'weapon', 'weapon_rank' => 'SSS']);
        $epicWeapon = new Item(['type' => 'weapon', 'weapon_rank' => 'EPIC']);
        $gArmor = new Item(['type' => 'armor', 'armor_rank' => 'G']);
        $sssArmor = new Item(['type' => 'armor', 'armor_rank' => 'SSS']);
        $epicArmor = new Item(['type' => 'armor', 'armor_rank' => 'EPIC']);
        $gAccessory = new Item(['type' => 'accessory', 'accessory_rank' => 'G']);
        $sssAccessory = new Item(['type' => 'accessory', 'accessory_rank' => 'SSS']);
        $epicAccessory = new Item(['type' => 'accessory', 'accessory_rank' => 'EPIC']);

        foreach ([1 => 300, 5 => 7500, 10 => 30000, 20 => 120000, 30 => 270000] as $level => $expected) {
            $this->assertSame($expected, $method->invoke($service, $level, 'weapon', $gWeapon));
            $this->assertSame($expected, $method->invoke($service, $level, 'weapon', $sssWeapon));
            $this->assertSame($expected, $method->invoke($service, $level, 'weapon', $epicWeapon));
            $this->assertSame($expected, $method->invoke($service, $level, 'armor', $gArmor));
            $this->assertSame($expected, $method->invoke($service, $level, 'armor', $sssArmor));
            $this->assertSame($expected, $method->invoke($service, $level, 'armor', $epicArmor));
            $this->assertSame($expected, $method->invoke($service, $level, 'accessory', $gAccessory));
            $this->assertSame($expected, $method->invoke($service, $level, 'accessory', $sssAccessory));
            $this->assertSame($expected, $method->invoke($service, $level, 'accessory', $epicAccessory));
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

    public function test_armor_recipes_use_the_same_fixed_city_materials_with_guard_stones(): void
    {
        $service = app(EquipmentEnhancementService::class);
        $method = new \ReflectionMethod($service, 'armorMaterialsFor');
        $method->setAccessible(true);

        $plusTen = collect($method->invoke($service, 10))
            ->mapWithKeys(fn (array $material): array => [$material['material_id'] => $material['quantity']])
            ->all();
        $plusThirty = collect($method->invoke($service, 30))
            ->mapWithKeys(fn (array $material): array => [$material['material_id'] => $material['quantity']])
            ->all();

        $this->assertSame(6, $plusTen['5008'] ?? null);
        $this->assertSame(1, $plusTen['5009'] ?? null);
        $this->assertSame(1, $plusTen['WEV0037'] ?? null);
        $this->assertSame(1, $plusTen['WEV0039'] ?? null);
        $this->assertSame(16, $plusThirty['5008'] ?? null);
        $this->assertSame(10, $plusThirty['5009'] ?? null);
        $this->assertSame(3, $plusThirty['WEV0032'] ?? null);
        $this->assertSame(3, $plusThirty['WEV0051'] ?? null);
    }
}
