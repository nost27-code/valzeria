<?php

namespace Tests\Unit;

use App\Services\Battle\BattleActor;
use App\Services\Battle\DamageCalculator;
use PHPUnit\Framework\TestCase;

class EnemyActionBattleTest extends TestCase
{
    public function test_defense_down_changes_physical_damage_without_mutating_base_stat(): void
    {
        $attacker = new BattleActor('敵', false, ['max_hp' => 100, 'str' => 100, 'def' => 10]);
        $defender = new BattleActor('冒険者', true, ['max_hp' => 1000, 'str' => 10, 'def' => 80]);
        $calculator = new DamageCalculator();

        mt_srand(1);
        $normal = $calculator->calculatePhysicalDamage($attacker, $defender, 100, false);
        $defender->conditions['def_down'] = ['turns' => 3, 'rate' => 0.20];
        mt_srand(1);
        $reduced = $calculator->calculatePhysicalDamage($attacker, $defender, 100, false);

        $this->assertSame(80, $defender->def);
        $this->assertGreaterThan($normal, $reduced);
    }

    public function test_recovery_block_reduces_healing_amount(): void
    {
        $actor = new BattleActor('冒険者', true, ['hp' => 100, 'max_hp' => 1000]);
        $actor->conditions['recovery_block'] = ['turns' => 3, 'rate' => 0.35];

        $actual = $actor->healHp(200);

        $this->assertSame(130, $actual);
        $this->assertSame(230, $actor->hp);
    }
}
