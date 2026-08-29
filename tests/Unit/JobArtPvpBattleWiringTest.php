<?php

namespace Tests\Unit;

use Tests\TestCase;

class JobArtPvpBattleWiringTest extends TestCase
{
    public function test_existing_player_combat_paths_keep_their_champ_availability_context(): void
    {
        $pvp = file_get_contents(base_path('app/Services/PvPBattleService.php'));
        $champ = file_get_contents(base_path('app/Services/ChampBattleService.php'));
        $arenaNpc = file_get_contents(base_path('app/Services/ArenaNpcBattleService.php'));

        $this->assertIsString($pvp);
        $this->assertIsString($champ);
        $this->assertIsString($arenaNpc);
        $this->assertSame(2, preg_match_all("/attachBossSet\\([^;]+, 'champ', 'pvp', true\\);/", $pvp));
        $this->assertSame(2, preg_match_all("/attachBossSet\\([^;]+, 'champ'\\);/", $champ));
        $this->assertSame(1, preg_match_all("/attachBossSet\\([^;]+, 'champ', 'arena_npc', true\\);/", $arenaNpc));
        $this->assertStringContainsString("new BattleState(\$attackerActor, \$defenderActor, 'pvp')", $pvp);
        $this->assertStringContainsString("new \\App\\Services\\Battle\\BattleState(\$attacker, \$defender, 'champ')", $champ);
        $this->assertStringContainsString("new BattleState(\$attackerActor, \$npcActor, 'arena_npc')", $arenaNpc);
    }
}
