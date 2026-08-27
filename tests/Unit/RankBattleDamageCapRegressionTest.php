<?php

namespace Tests\Unit;

use App\Services\Battle\BattleActor;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\JobArtHitPower;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * チャンプ戦を除くPvPの推奨式が、最大HP比例の下限・上限へ依存しないことを確認する。
 */
class RankBattleDamageCapRegressionTest extends TestCase
{
    #[DataProvider('displayPowerCases')]
    public function test_rank_battle_skill_uses_the_displayed_power_without_compression(int $power): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 2_500, def: 100, maxHp: 20_000);
        $defender = $this->actor(str: 100, def: 0, maxHp: 20_000);

        mt_srand(20_260_822);
        $normal = $calculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            'physical',
            100,
            false,
        );
        mt_srand(20_260_822);
        $skill = $calculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            'physical',
            $power,
            false,
            isSkill: true,
        );

        $this->assertSame(intdiv($normal * $power, 100), $skill);
    }

    /** @return array<string, array{int}> */
    public static function displayPowerCases(): array
    {
        return [
            '185%' => [185],
            '255%' => [255],
            '320%' => [320],
            '585%' => [585],
        ];
    }

    public function test_rank_battle_skill_scales_uncapped_normal_attack_damage(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 50_000, maxHp: 100_000);
        $defender = $this->actor(def: 500, maxHp: 100_000);

        mt_srand(20_260_822);
        $normal = $calculator->calculateRankBattleDamage($attacker, $defender, 'physical');
        mt_srand(20_260_822);
        $skill = $calculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            'physical',
            320,
            false,
            isSkill: true,
        );

        $this->assertGreaterThan(18_000, $normal);
        $this->assertSame(intdiv($normal * 320, 100), $skill);
    }

    public function test_duel_skill_uses_the_displayed_power_from_the_same_normal_attack_base(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 2_000, def: 2_000, maxHp: 20_000);
        $defender = $this->actor(str: 100, def: 2_000, maxHp: 20_000);

        mt_srand(20_260_822);
        $normal = $calculator->calculateDuelDamage($attacker, $defender, 'physical');
        mt_srand(20_260_822);
        $skill = $calculator->calculateDuelDamage($attacker, $defender, 'physical', 320);

        $this->assertSame(intdiv($normal * 320, 100), $skill);
    }

    public function test_critical_normal_attack_damage_is_not_capped_at_twenty_two_percent_of_target_hp(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 50_000);
        $defender = $this->actor(def: 500, maxHp: 100_000);

        mt_srand(20_260_824);
        $damage = $calculator->calculateRankBattleDamage($attacker, $defender, 'physical', 100, true);

        $this->assertGreaterThan((int) floor(100_000 * 0.22), $damage);
    }

    public function test_multi_hit_skill_scales_uncapped_normal_damage_by_total_displayed_power(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 50_000);
        $defender = $this->actor(def: 500, maxHp: 100_000);

        $hitCount = 3;
        $actualTotal = 0;
        $expectedTotal = 0;
        foreach (JobArtHitPower::split(250, $hitCount) as $index => $hitPower) {
            $seed = 20_260_830 + $index;
            mt_srand($seed);
            $normalEquivalent = $calculator->calculateRankBattleDamage($attacker, $defender, 'physical');
            mt_srand($seed);
            $actual = $calculator->calculateRankBattleDamage(
                $attacker,
                $defender,
                'physical',
                $hitPower,
                false,
                1.0,
                null,
                null,
                null,
                true,
                $hitCount,
            );
            $expected = intdiv($normalEquivalent * $hitPower, 100);

            $this->assertSame($expected, $actual);
            $actualTotal += $actual;
            $expectedTotal += $expected;
        }

        $this->assertSame($expectedTotal, $actualTotal);
        $this->assertGreaterThan((int) floor(100_000 * 0.18 * 2.5), $actualTotal);
    }

    public function test_damage_can_fall_below_the_former_four_percent_hp_floor(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 1);
        $defender = $this->actor(def: 10_000, maxHp: 100_000);

        $damage = $calculator->calculateRankBattleDamage($attacker, $defender, 'physical');

        $this->assertLessThan((int) floor(100_000 * 0.04), $damage);
    }

    public function test_rank_battle_keeps_accepting_legacy_room_rule_damage_options(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 1);
        $defender = $this->actor(def: 10_000, maxHp: 100_000);

        $damage = $calculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            'physical',
            minimumDamageGuaranteeEnabled: false,
            damageCapEnabled: false,
        );

        $this->assertSame(1, $damage);
    }

    public function test_extreme_attack_can_exceed_the_former_maximum_hp_cap(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 50_000);
        $defender = $this->actor(def: 500, maxHp: 100);

        mt_srand(20_260_827);
        $damage = $calculator->calculateRankBattleDamage($attacker, $defender, 'physical');

        $this->assertGreaterThan((int) floor($defender->maxHp * 0.22), $damage);
    }

    public function test_damage_does_not_change_when_only_the_defenders_maximum_hp_changes(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 8_000);
        $lowHpDefender = $this->actor(def: 10_000, maxHp: 1_000);
        $highHpDefender = $this->actor(def: 10_000, maxHp: 1_000_000);

        mt_srand(20_260_827);
        $lowHpDamage = $calculator->calculateRankBattleDamage($attacker, $lowHpDefender, 'physical');
        mt_srand(20_260_827);
        $highHpDamage = $calculator->calculateRankBattleDamage($attacker, $highHpDefender, 'physical');

        $this->assertSame($lowHpDamage, $highHpDamage);
    }

    public function test_higher_defense_reduces_damage_with_the_same_random_roll(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 10_000);
        $defender = $this->actor(def: 10_000);
        $fortifiedDefender = $this->actor(def: 11_000);

        mt_srand(20_260_827);
        $damage = $calculator->calculateRankBattleDamage($attacker, $defender, 'physical');
        mt_srand(20_260_827);
        $fortifiedDamage = $calculator->calculateRankBattleDamage($attacker, $fortifiedDefender, 'physical');

        $this->assertLessThan($damage, $fortifiedDamage);
    }

    public function test_soft_attack_relative_boundary_is_used_when_raw_damage_is_too_low(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 1_000);
        $defender = $this->actor(def: 10_000, maxHp: 1_000_000);

        $effectiveDefense = 10_000;
        $rawDamage = (1_000 * 0.56) - ($effectiveDefense * 0.30);
        $softBoundary = 1_000 * 0.18 * min(1.0, (1.267 * 1_000) / $effectiveDefense);
        mt_srand(20_260_827);
        $variance = rand(96, 104) / 100;
        mt_srand(20_260_827);

        $damage = $calculator->calculateRankBattleDamage($attacker, $defender, 'physical');

        $this->assertLessThan($softBoundary, $rawDamage);
        $this->assertSame((int) floor($softBoundary * $variance), $damage);
    }

    public function test_physical_damage_uses_only_defense(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 5_000);
        $lowSpiritDefender = $this->actor(def: 9_000, spr: 1_000);
        $highSpiritDefender = $this->actor(def: 9_000, spr: 50_000);

        mt_srand(20_260_827);
        $lowSpiritDamage = $calculator->calculateRankBattleDamage($attacker, $lowSpiritDefender, 'physical');
        mt_srand(20_260_827);
        $highSpiritDamage = $calculator->calculateRankBattleDamage($attacker, $highSpiritDefender, 'physical');

        $this->assertSame($lowSpiritDamage, $highSpiritDamage);
    }

    public function test_magical_damage_uses_only_spirit(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 5_000);
        $lowDefenseDefender = $this->actor(def: 1_000, spr: 9_000);
        $highDefenseDefender = $this->actor(def: 50_000, spr: 9_000);

        $effectiveDefense = 9_000;
        $rawDamage = (5_000 * 0.56)
            - ($effectiveDefense * 0.30)
            + (max(0, 5_000 - $effectiveDefense) * 0.16);
        $softBoundary = 5_000 * 0.18 * min(1.0, (1.267 * 5_000) / $effectiveDefense);
        mt_srand(20_260_827);
        $variance = rand(96, 104) / 100;
        mt_srand(20_260_827);

        $lowDefenseDamage = $calculator->calculateRankBattleDamage($attacker, $lowDefenseDefender, 'magical');
        mt_srand(20_260_827);
        $highDefenseDamage = $calculator->calculateRankBattleDamage($attacker, $highDefenseDefender, 'magical');

        $this->assertSame(
            (int) floor(max(1, $rawDamage, $softBoundary) * $variance),
            $lowDefenseDamage,
        );
        $this->assertSame($lowDefenseDamage, $highDefenseDamage);
    }

    public function test_physical_damage_uses_only_the_defense_override(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 5_000);
        $defender = $this->actor(def: 1_000, spr: 1_000);

        mt_srand(20_260_827);
        $lowSpiritOverrideDamage = $calculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            'physical',
            overrideDef: 9_000,
            overrideSpr: 1,
        );
        mt_srand(20_260_827);
        $highSpiritOverrideDamage = $calculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            'physical',
            overrideDef: 9_000,
            overrideSpr: 50_000,
        );

        $this->assertSame($lowSpiritOverrideDamage, $highSpiritOverrideDamage);
    }

    public function test_magical_damage_uses_only_the_spirit_override(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 5_000);
        $defender = $this->actor(def: 1_000, spr: 1_000);

        mt_srand(20_260_827);
        $lowDefenseOverrideDamage = $calculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            'magical',
            overrideDef: 1,
            overrideSpr: 9_000,
        );
        mt_srand(20_260_827);
        $highDefenseOverrideDamage = $calculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            'magical',
            overrideDef: 50_000,
            overrideSpr: 9_000,
        );

        $this->assertSame($lowDefenseOverrideDamage, $highDefenseOverrideDamage);
    }

    public function test_duel_damage_keeps_the_existing_champ_formula(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 1_000);
        $defender = $this->actor(def: 1_000);

        mt_srand(20_260_827);
        $variance = rand(90, 110) / 100;
        mt_srand(20_260_827);

        $damage = $calculator->calculateDuelDamage($attacker, $defender, 'physical');

        $this->assertSame((int) floor(500 * $variance), $damage);
    }

    public function test_legacy_policy_arguments_no_longer_change_rank_battle_damage(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 8_000);
        $defender = $this->actor(def: 10_000, maxHp: 100_000);

        mt_srand(20_260_827);
        $legacyFlagsEnabled = $calculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            'physical',
            minimumDamageGuaranteeEnabled: true,
            damageCapEnabled: true,
        );
        mt_srand(20_260_827);
        $legacyFlagsDisabled = $calculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            'physical',
            minimumDamageGuaranteeEnabled: false,
            damageCapEnabled: false,
        );

        $this->assertSame($legacyFlagsEnabled, $legacyFlagsDisabled);
    }

    public function test_job_art_route_estimate_uses_the_same_soft_boundary(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 1_000);
        $defender = $this->actor(def: 10_000, maxHp: 1_000_000, spr: 1);
        $expected = (int) floor(1_000 * 0.18 * ((1.267 * 1_000) / 10_000));

        $damage = $calculator->estimateJobArtDamage(
            $attacker,
            $defender,
            'physical',
            'pvp',
            100,
        );

        $this->assertSame($expected, $damage);
    }

    private function actor(
        int $str = 10,
        int $def = 10,
        int $luk = 10,
        int $maxHp = 1_000,
        ?int $spr = null,
    ): BattleActor
    {
        return new BattleActor('テストアクター', true, [
            'max_hp' => $maxHp,
            'hp' => $maxHp,
            'max_mp' => 100,
            'mp' => 100,
            'str' => $str,
            'def' => $def,
            'agi' => 10,
            'mag' => $str,
            'spr' => $spr ?? $def,
            'luk' => $luk,
        ]);
    }
}
