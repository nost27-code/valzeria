<?php

namespace Tests\Unit;

use App\Services\Battle\BattleActor;
use App\Services\Battle\DamageCalculator;
use PHPUnit\Framework\TestCase;

class CompetitiveCriticalDamageTest extends TestCase
{
    public function test_rank_and_duel_critical_damage_is_one_point_five_after_defense_calculation(): void
    {
        $calculator = new DamageCalculator;
        $attacker = $this->actor(str: 10_000, def: 0, luk: 10);
        $defender = $this->actor(str: 100, def: 0, luk: 10);

        mt_srand(20_260_840);
        $rankNormal = $calculator->calculateRankBattleDamage($attacker, $defender, 'physical', 100, false);
        mt_srand(20_260_840);
        $rankCritical = $calculator->calculateRankBattleDamage($attacker, $defender, 'physical', 100, true);

        mt_srand(20_260_841);
        $duelNormal = $calculator->calculateDuelDamage($attacker, $defender, 'physical', 100, false);
        mt_srand(20_260_841);
        $duelCritical = $calculator->calculateDuelDamage($attacker, $defender, 'physical', 100, true);

        $this->assertEqualsWithDelta($rankNormal * 1.5, $rankCritical, 1.0);
        $this->assertEqualsWithDelta($duelNormal * 1.5, $duelCritical, 1.0);
    }

    public function test_luck_changes_competitive_critical_chance_but_not_confirmed_critical_damage(): void
    {
        $calculator = new DamageCalculator;
        $equalLuck = $this->actor(str: 10_000, def: 0, luk: 10);
        $higherLuck = $this->actor(str: 10_000, def: 0, luk: 110);
        $defender = $this->actor(str: 100, def: 0, luk: 10);

        $this->assertSame(3.0, $calculator->rankBattleCriticalChance($equalLuck, $defender));
        $this->assertSame(6.0, $calculator->rankBattleCriticalChance($higherLuck, $defender));
        $this->assertSame(5.0, $calculator->duelCriticalChance($equalLuck, $defender));
        $this->assertSame(10.0, $calculator->duelCriticalChance($higherLuck, $defender));

        mt_srand(20_260_842);
        $equalLuckDamage = $calculator->calculateRankBattleDamage($equalLuck, $defender, 'physical', 100, true);
        mt_srand(20_260_842);
        $higherLuckDamage = $calculator->calculateRankBattleDamage($higherLuck, $defender, 'physical', 100, true);

        $this->assertSame($equalLuckDamage, $higherLuckDamage);
    }

    private function actor(int $str, int $def, int $luk): BattleActor
    {
        return new BattleActor('テストアクター', true, [
            'max_hp' => 100_000,
            'hp' => 100_000,
            'str' => $str,
            'def' => $def,
            'agi' => 10,
            'mag' => $str,
            'spr' => $def,
            'luk' => $luk,
        ]);
    }
}
