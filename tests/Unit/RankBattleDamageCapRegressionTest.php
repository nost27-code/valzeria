<?php

namespace Tests\Unit;

use App\Services\Battle\BattleActor;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\JobArtHitPower;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 武器のランク比例補正で攻撃が大きく伸びても、ランク戦の通常攻撃基準の
 * ダメージ上限（通常18%/会心22%）と表示威力の線形倍率を確認する回帰テスト。
 */
class RankBattleDamageCapRegressionTest extends TestCase
{
    #[DataProvider('displayPowerCases')]
    public function test_rank_battle_skill_uses_the_displayed_power_without_compression(int $power): void
    {
        $calculator = new DamageCalculator;
        $attacker = $this->actor(str: 2_500, def: 100, luk: 10, maxHp: 20_000);
        $defender = $this->actor(str: 100, def: 0, luk: 10, maxHp: 20_000);

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

    public function test_rank_battle_skill_cap_scales_from_the_normal_attack_cap(): void
    {
        $calculator = new DamageCalculator;
        $attacker = $this->actor(str: 50_000, luk: 10, maxHp: 100_000);
        $defender = $this->actor(def: 500, luk: 10, maxHp: 100_000);

        mt_srand(20_260_822);
        $normal = $calculator->calculateRankBattleDamage($attacker, $defender, 'physical', 100, false);
        mt_srand(20_260_822);
        $skill = $calculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            'physical',
            320,
            false,
            isSkill: true,
        );

        $this->assertSame(18_000, $normal);
        $this->assertSame(57_600, $skill);
    }

    public function test_duel_skill_uses_the_displayed_power_from_the_same_normal_attack_base(): void
    {
        $calculator = new DamageCalculator;
        $attacker = $this->actor(str: 2_000, def: 2_000, luk: 10, maxHp: 20_000);
        $defender = $this->actor(str: 100, def: 2_000, luk: 10, maxHp: 20_000);

        mt_srand(20_260_822);
        $normal = $calculator->calculateDuelDamage($attacker, $defender, 'physical', 100, false);
        mt_srand(20_260_822);
        $skill = $calculator->calculateDuelDamage($attacker, $defender, 'physical', 320, false);

        $this->assertSame(intdiv($normal * 320, 100), $skill);
    }

    public function test_normal_attack_damage_stays_within_cap_even_with_extreme_atk(): void
    {
        $calculator = new DamageCalculator;
        $attacker = $this->actor(str: 50_000, luk: 10); // 武器比例補正で極端に伸びた想定
        $defender = $this->actor(def: 500, luk: 10, maxHp: 100_000);

        $damage = $calculator->calculateRankBattleDamage($attacker, $defender, 'physical', 100, false);

        $this->assertLessThanOrEqual((int) floor(100_000 * 0.18), $damage);
    }

    public function test_critical_normal_attack_damage_stays_within_critical_cap(): void
    {
        $calculator = new DamageCalculator;
        $attacker = $this->actor(str: 50_000, luk: 10);
        $defender = $this->actor(def: 500, luk: 10, maxHp: 100_000);

        $damage = $calculator->calculateRankBattleDamage($attacker, $defender, 'physical', 100, true);

        $this->assertLessThanOrEqual((int) floor(100_000 * 0.22), $damage);
    }

    public function test_skill_damage_total_scales_the_normal_cap_by_displayed_power_across_hits(): void
    {
        $calculator = new DamageCalculator;
        $attacker = $this->actor(str: 50_000, luk: 10);
        $defender = $this->actor(def: 500, luk: 10, maxHp: 100_000);

        $hitCount = 3;
        $total = 0;
        foreach (JobArtHitPower::split(250, $hitCount) as $hitPower) {
            $total += $calculator->calculateRankBattleDamage($attacker, $defender, 'physical', $hitPower, false, 1.0, null, null, null, true, $hitCount);
        }

        $this->assertSame((int) floor(100_000 * 0.18 * 2.5), $total);
    }

    public function test_normal_attack_damage_still_respects_floor_when_atk_is_small(): void
    {
        $calculator = new DamageCalculator;
        $attacker = $this->actor(str: 1, luk: 10);
        $defender = $this->actor(def: 10_000, luk: 10, maxHp: 100_000);

        $damage = $calculator->calculateRankBattleDamage($attacker, $defender, 'physical', 100, false);

        $this->assertGreaterThanOrEqual((int) floor(100_000 * 0.04), $damage);
    }

    public function test_rank_battle_accepts_room_rule_damage_options(): void
    {
        $calculator = new DamageCalculator;
        $attacker = $this->actor(str: 1, luk: 10);
        $defender = $this->actor(def: 10_000, luk: 10, maxHp: 100_000);

        $damage = $calculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            'physical',
            minimumDamageGuaranteeEnabled: false,
            damageCapEnabled: false,
        );

        $this->assertSame(1, $damage);
    }

    private function actor(int $str = 10, int $def = 10, int $luk = 10, int $maxHp = 1000): BattleActor
    {
        return new BattleActor('テストアクター', true, [
            'max_hp' => $maxHp,
            'hp' => $maxHp,
            'str' => $str,
            'def' => $def,
            'agi' => 10,
            'mag' => 10,
            'spr' => $def,
            'luk' => $luk,
        ]);
    }
}
