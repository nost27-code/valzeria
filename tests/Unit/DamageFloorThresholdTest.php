<?php

namespace Tests\Unit;

use App\Services\Battle\BattleActor;
use App\Services\Battle\DamageCalculator;
use PHPUnit\Framework\TestCase;

/**
 * 最低ダメージ(1)境界の回帰テスト。
 *
 * 過去に「秘境ボスDEF1,765・S武器の主能力600は最低ダメージへ張り付かない」と誤って結論づけたが、
 * 正しくは 主能力600+S武器の最終ATK(829) は 敵DEF(1,765)/2=882.5 を下回るため、
 * 会心なしの通常攻撃は最低ダメージ側になる。本テストはこの境界を正しく固定する。
 *
 * 秘境ボス(氷冠竜エルヴァン DEF=1,358)に本実装の耐久補正(def_spr×1.20)を適用した
 * DEF=1,630 を基準に、ランク差ごとの床(1ダメージ)発生有無を検証する。
 */
class DamageFloorThresholdTest extends TestCase
{
    private const HIKYO_BOSS_DEF = 1630; // 1358 × 1.20 (四捨五入)

    public function test_on_rank_and_one_rank_down_do_not_hit_the_damage_floor_for_a_progressed_player(): void
    {
        $calculator = new DamageCalculator();
        $defender = $this->enemy(self::HIKYO_BOSS_DEF);

        // 想定ランク: 主能力3,000 + EPIC(固定500+比例16%)
        $onRank = $this->attacker(3000, 500, 0.16);
        $damage = $calculator->calculatePhysicalDamage($onRank, $defender, 100, false);
        $this->assertGreaterThan(1000, $damage, '想定ランクは最低ダメージに張り付かない');

        // 1段階下: 主能力2,000 + SSS(固定370+比例11%)
        $oneDown = $this->attacker(2000, 370, 0.11);
        $damage = $calculator->calculatePhysicalDamage($oneDown, $defender, 100, false);
        $this->assertGreaterThan(500, $damage, '1段階下は最低ダメージに張り付かない');
    }

    public function test_far_below_recommended_rank_can_hit_the_damage_floor(): void
    {
        $calculator = new DamageCalculator();
        $defender = $this->enemy(self::HIKYO_BOSS_DEF);

        // 3段階以上下(想定EPICに対しB武器)かつ低進行: 主能力500 + B(固定34+比例1%)
        $farBelow = $this->attacker(500, 34, 0.01);
        $finalAtk = 500 + 34 + (int) floor(500 * 0.01);
        $this->assertLessThanOrEqual(self::HIKYO_BOSS_DEF / 2, $finalAtk, '検算: 最終ATKが敵DEF/2を超えない');

        $damage = $calculator->calculatePhysicalDamage($farBelow, $defender, 100, false);
        $this->assertSame(1, $damage, '明らかなランク飛び越えでは最低ダメージ(1)を許容する');
    }

    public function test_two_ranks_down_at_low_progress_can_also_hit_the_floor_but_is_not_guaranteed(): void
    {
        $calculator = new DamageCalculator();
        $defender = $this->enemy(self::HIKYO_BOSS_DEF);

        // 2段階下(SS)だが極端に進行が浅い場合: 主能力400 + SS(固定275+比例7%)
        $lowProgressTwoDown = $this->attacker(400, 275, 0.07);
        $damage = $calculator->calculatePhysicalDamage($lowProgressTwoDown, $defender, 100, false);
        $this->assertSame(1, $damage, '2段階下でも進行が浅すぎれば床に張り付く場合がある(常時ではない)');

        // 2段階下(SS)でも一定進行していれば床に張り付かない: 主能力1,200 + SS
        $normalProgressTwoDown = $this->attacker(1200, 275, 0.07);
        $damage = $calculator->calculatePhysicalDamage($normalProgressTwoDown, $defender, 100, false);
        $this->assertGreaterThan(1, $damage, '2段階下でも通常の進行速度なら常時1ダメージにはならない');
    }

    private function attacker(int $mainStat, int $fixedWeaponBonus, float $proportionalRate): BattleActor
    {
        $weaponBonus = $fixedWeaponBonus + (int) floor($mainStat * $proportionalRate);

        return new BattleActor('テスト攻撃者', true, [
            'str' => $mainStat + $weaponBonus,
            'def' => 10,
            'agi' => 10,
            'luk' => 10,
        ]);
    }

    private function enemy(int $def): BattleActor
    {
        return new BattleActor('氷冠竜エルヴァン(耐久補正後)', false, [
            'str' => 10,
            'def' => $def,
            'agi' => 10,
            'luk' => 10,
            'max_hp' => 100000,
            'hp' => 100000,
        ]);
    }
}
