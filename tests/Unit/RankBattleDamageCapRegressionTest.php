<?php

namespace Tests\Unit;

use App\Services\Battle\BattleActor;
use App\Services\Battle\DamageCalculator;
use PHPUnit\Framework\TestCase;

/**
 * 武器のランク比例補正でATKが大きく伸びても、ランク戦のダメージ上限
 * （通常18%/22%、必殺35%/40% ※HP割合）が変わらず機能することを確認する回帰テスト。
 */
class RankBattleDamageCapRegressionTest extends TestCase
{
    public function test_normal_attack_damage_stays_within_cap_even_with_extreme_atk(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 50_000, luk: 10); // 武器比例補正で極端に伸びた想定
        $defender = $this->actor(def: 500, luk: 10, maxHp: 100_000);

        $damage = $calculator->calculateRankBattleDamage($attacker, $defender, 'physical', 100, false);

        $this->assertLessThanOrEqual((int) floor(100_000 * 0.18), $damage);
    }

    public function test_critical_normal_attack_damage_stays_within_critical_cap(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 50_000, luk: 10);
        $defender = $this->actor(def: 500, luk: 10, maxHp: 100_000);

        $damage = $calculator->calculateRankBattleDamage($attacker, $defender, 'physical', 100, true);

        $this->assertLessThanOrEqual((int) floor(100_000 * 0.22), $damage);
    }

    public function test_skill_damage_total_stays_within_skill_cap_across_hits(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 50_000, luk: 10);
        $defender = $this->actor(def: 500, luk: 10, maxHp: 100_000);

        $hitCount = 3;
        $total = 0;
        for ($i = 0; $i < $hitCount; $i++) {
            $total += $calculator->calculateRankBattleDamage($attacker, $defender, 'physical', 250, false, 1.0, null, null, null, true, $hitCount);
        }

        $this->assertLessThanOrEqual((int) floor(100_000 * 0.35) + $hitCount, $total);
    }

    public function test_normal_attack_damage_still_respects_floor_when_atk_is_small(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(str: 1, luk: 10);
        $defender = $this->actor(def: 10_000, luk: 10, maxHp: 100_000);

        $damage = $calculator->calculateRankBattleDamage($attacker, $defender, 'physical', 100, false);

        $this->assertGreaterThanOrEqual((int) floor(100_000 * 0.04), $damage);
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
