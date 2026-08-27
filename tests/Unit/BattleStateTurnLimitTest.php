<?php

namespace Tests\Unit;

use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use PHPUnit\Framework\TestCase;

class BattleStateTurnLimitTest extends TestCase
{
    public function test_default_pve_battle_keeps_the_existing_fifty_turn_limit(): void
    {
        $state = $this->state();

        $this->assertSame('pve', $state->battleType);
        $this->assertSame(50, $state->maxTurns);

        $state->turnCount = 49;
        $this->assertFalse($state->isBattleEnded());

        $state->turnCount = 50;
        $this->assertTrue($state->isBattleEnded());
    }

    public function test_existing_non_pvp_battle_types_keep_the_fifty_turn_limit(): void
    {
        foreach (['pve', 'boss', 'rank', 'arena_npc'] as $battleType) {
            $state = $this->state($battleType);

            $this->assertSame(50, $state->maxTurns, $battleType);
        }
    }

    public function test_pvp_battle_ends_at_the_fiftieth_turn(): void
    {
        $state = $this->state('pvp');

        $this->assertSame('pvp', $state->battleType);
        $this->assertSame(50, $state->maxTurns);

        $state->turnCount = 49;
        $this->assertFalse($state->isBattleEnded());

        $state->turnCount = 50;
        $this->assertTrue($state->isBattleEnded());
    }

    public function test_champ_battle_keeps_its_hundred_turn_constant(): void
    {
        $this->assertSame(100, BattleState::CHAMP_MAX_TURNS);
        $this->assertSame(100, $this->state('champ')->maxTurns);
    }

    private function state(string $battleType = 'pve'): BattleState
    {
        $stats = [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 10,
            'max_mp' => 10,
        ];

        return new BattleState(
            new BattleActor('挑戦者', true, $stats),
            new BattleActor('対戦相手', false, $stats),
            $battleType,
        );
    }
}
