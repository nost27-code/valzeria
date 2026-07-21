<?php

namespace Tests\Unit;

use App\Models\CharacterItem;
use App\Models\EquipmentAffixPrefix;
use App\Models\Item;
use App\Services\EquipmentAffixRulesService;
use Tests\TestCase;

class EquipmentAffixRulesServiceTest extends TestCase
{
    public function test_it_applies_the_configured_engraving_level_and_quality_rates(): void
    {
        $item = new Item(['type' => 'weapon', 'weapon_rank' => 'A', 'str_bonus' => 100]);
        $ssItem = new Item(['type' => 'weapon', 'weapon_rank' => 'SS', 'str_bonus' => 100]);
        $prefix = new EquipmentAffixPrefix(['target_stat' => 'str']);
        $service = app(EquipmentAffixRulesService::class);

        $this->assertSame(['str' => 21], $service->prefixBonuses($item, $prefix, 3, 'good'));
        $this->assertSame(['str' => 41], $service->prefixBonuses($ssItem, $prefix, 5, 'excellent'));
        $this->assertEqualsWithDelta(0.405, $service->weaponKillerDamageRate($ssItem, 5, 'excellent'), 0.00001);
    }

    /**
     * 代表武器(EPIC・固定値500、新仕様=equipment_scaling.php導入後の値)における
     * 銘I〜V×品質別の実計算値を固定する回帰テスト。
     *
     * 500 × 0.30(銘V率) × 1.35(逸品倍率) = 202.5 → 切り上げ+203 が最終仕様であり、
     * 旧率0.40での計算値(+270)ではないことを明示的に検証する。
     */
    public function test_epic_weapon_engraving_values_for_all_levels_and_qualities(): void
    {
        $item = new Item(['type' => 'weapon', 'weapon_rank' => 'EPIC', 'str_bonus' => 500]);
        $prefix = new EquipmentAffixPrefix(['target_stat' => 'str']);
        $service = app(EquipmentAffixRulesService::class);

        $expected = [
            // level => [normal, good, excellent]
            1 => [30, 35, 41],
            2 => [60, 69, 81],
            3 => [90, 104, 122],
            4 => [120, 138, 162],
            5 => [150, 173, 203],
        ];

        foreach ($expected as $level => [$normal, $good, $excellent]) {
            $this->assertSame(['str' => $normal], $service->prefixBonuses($item, $prefix, $level, 'normal'), "Lv{$level} normal");
            $this->assertSame(['str' => $good], $service->prefixBonuses($item, $prefix, $level, 'good'), "Lv{$level} good");
            $this->assertSame(['str' => $excellent], $service->prefixBonuses($item, $prefix, $level, 'excellent'), "Lv{$level} excellent");
        }

        // 銘V逸品の最終仕様値を明示的に固定する。
        $this->assertSame(['str' => 203], $service->prefixBonuses($item, $prefix, 5, 'excellent'));
        $this->assertNotSame(['str' => 270], $service->prefixBonuses($item, $prefix, 5, 'excellent'), '旧率0.40での計算値(+270)であってはならない');
    }

    public function test_it_enforces_the_rank_based_affix_level_caps(): void
    {
        $service = app(EquipmentAffixRulesService::class);

        $this->assertSame(2, $service->clampLevel(new Item(['weapon_rank' => 'D']), 5));
        $this->assertSame(3, $service->clampLevel(new Item(['weapon_rank' => 'A']), 5));
        $this->assertSame(4, $service->clampLevel(new Item(['weapon_rank' => 'S']), 5));
        $this->assertSame(5, $service->clampLevel(new Item(['weapon_rank' => 'SS']), 5));
    }

    public function test_character_item_uses_the_current_rules_for_saved_affixes(): void
    {
        $item = new Item(['type' => 'weapon', 'weapon_rank' => 'A', 'str_bonus' => 100]);
        $prefix = new EquipmentAffixPrefix(['target_stat' => 'str']);
        $characterItem = new CharacterItem([
            'affix_prefix_id' => 1,
            'affix_prefix_level' => 3,
            'affix_suffix_id' => 2,
            'affix_suffix_level' => 3,
            'affix_quality' => 'good',
            'affix_str_bonus' => 1,
            'killer_species_key' => 'dragon',
            'killer_damage_rate' => 0.01,
        ]);
        $characterItem->setRelation('item', $item);
        $characterItem->setRelation('affixPrefix', $prefix);

        $this->assertSame(['str' => 21], $characterItem->affixStatBonuses());
        $this->assertEqualsWithDelta(0.207, $characterItem->effectiveKillerDamageRate(), 0.00001);
        $this->assertContains('種族が竜の敵への与ダメージ +20.7%', $characterItem->slayerEffectLines());
    }

    public function test_engraving_uses_the_enhanced_base_stat_and_tuning_uses_55_percent(): void
    {
        $item = new Item(['type' => 'weapon', 'weapon_rank' => 'EPIC', 'str_bonus' => 500]);
        $single = new EquipmentAffixPrefix(['target_stat' => 'def']);
        $tuning = new EquipmentAffixPrefix(['target_stat' => 'all']);
        $service = app(EquipmentAffixRulesService::class);

        // +30で500 -> 737。銘Vの30%は222.1なので切り上げ222。
        $this->assertSame(['def' => 222], $service->prefixBonuses($item, $single, 5, 'normal', 30));
        $this->assertSame([
            'hp' => 366,
            'str' => 122,
            'def' => 122,
            'mag' => 122,
            'spr' => 122,
            'agi' => 122,
            'luk' => 122,
        ], $service->prefixBonuses($item, $tuning, 5, 'normal', 30));
    }

    public function test_character_item_uses_its_enhancement_level_for_engraving_bonuses(): void
    {
        $item = new Item(['type' => 'weapon', 'weapon_rank' => 'EPIC', 'str_bonus' => 500]);
        $prefix = new EquipmentAffixPrefix(['target_stat' => 'def']);
        $characterItem = new CharacterItem([
            'enhance_level' => 30,
            'affix_prefix_id' => 1,
            'affix_prefix_level' => 5,
            'affix_quality' => 'normal',
        ]);
        $characterItem->setRelation('item', $item);
        $characterItem->setRelation('affixPrefix', $prefix);

        $this->assertSame(['def' => 222], $characterItem->affixStatBonuses());
    }

    public function test_armor_tuning_uses_the_six_times_hp_multiplier(): void
    {
        $item = new Item(['type' => 'armor', 'armor_rank' => 'SS', 'def_bonus' => 500]);
        $tuning = new EquipmentAffixPrefix(['target_stat' => 'all']);

        $this->assertSame(732, app(EquipmentAffixRulesService::class)->prefixBonuses($item, $tuning, 5, 'normal', 30)['hp']);
    }
}
