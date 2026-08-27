<?php

namespace Tests\Unit;

use Tests\TestCase;

class PvPBattleTurnLimitWiringTest extends TestCase
{
    public function test_pvp_service_uses_pvp_battle_state_as_the_single_turn_limit_source(): void
    {
        $source = file_get_contents(base_path('app/Services/PvPBattleService.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString(
            "new BattleState(\$attackerActor, \$defenderActor, 'pvp')",
            $source,
        );
        $this->assertStringContainsString(
            'while (!$state->isBattleEnded() && $state->turnCount < $state->maxTurns)',
            $source,
        );
        $this->assertStringContainsString('$state->turnCount >= $state->maxTurns', $source);
        $this->assertMatchesRegularExpression(
            '/!\s*\$attackerActor->isDead\(\)\s*&&\s*\$isTurnLimit\s*&&\s*\$attackerActor->hasHigherRemainingHpRatioThan\(\$defenderActor\)/',
            $source,
        );
        $this->assertStringNotContainsString('$attackerActor->hp > $defenderActor->hp', $source);
    }

    public function test_npc_rank_and_champ_services_use_their_separate_turn_limit_constants(): void
    {
        $npcSource = file_get_contents(base_path('app/Services/ArenaNpcBattleService.php'));
        $champSource = file_get_contents(base_path('app/Services/ChampBattleService.php'));

        $this->assertIsString($npcSource);
        $this->assertIsString($champSource);
        $this->assertStringContainsString('BattleState::PVP_MAX_TURNS', $npcSource);
        $this->assertStringContainsString('BattleState::CHAMP_MAX_TURNS', $champSource);
        $this->assertStringNotContainsString('BattleState::PVP_MAX_TURNS', $champSource);
        $this->assertStringContainsString('calculateDuelDamage(', $champSource);
        $this->assertStringNotContainsString('calculateRankBattleDamage(', $champSource);
    }
}
