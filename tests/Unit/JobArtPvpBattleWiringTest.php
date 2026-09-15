<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class JobArtPvpBattleWiringTest extends TestCase
{
    public function test_existing_player_combat_paths_keep_their_champ_availability_context(): void
    {
        $pvp = file_get_contents($this->projectPath('app/Services/PvPBattleService.php'));
        $champ = file_get_contents($this->projectPath('app/Services/ChampBattleService.php'));
        $arenaNpc = file_get_contents($this->projectPath('app/Services/ArenaNpcBattleService.php'));

        $this->assertIsString($pvp);
        $this->assertIsString($champ);
        $this->assertIsString($arenaNpc);
        $this->assertSame(2, preg_match_all("/attachBossSet\\([^;]+, 'champ', 'pvp', true\\);/", $pvp));
        $this->assertSame(2, preg_match_all("/attachBossSet\\([^;]+, 'champ', 'champ', true\\);/", $champ));
        $this->assertSame(1, preg_match_all("/attachBossSet\\([^;]+, 'champ', 'arena_npc', true\\);/", $arenaNpc));
        $this->assertStringContainsString("new BattleState(\$attackerActor, \$defenderActor, 'pvp')", $pvp);
        $this->assertStringContainsString("new \\App\\Services\\Battle\\BattleState(\$attacker, \$defender, 'champ')", $champ);
        $this->assertStringContainsString("new BattleState(\$attackerActor, \$npcActor, 'arena_npc')", $arenaNpc);
    }

    public function test_six_hero_official_battles_feed_pvp_metrics_but_practice_battles_do_not(): void
    {
        $official = file_get_contents($this->projectPath('app/Services/SixHeroOfficialBattleService.php'));
        $practice = file_get_contents($this->projectPath('app/Services/SixHeroPracticeBattleService.php'));

        $this->assertIsString($official);
        $this->assertIsString($practice);
        $this->assertStringContainsString("recordJobArtBattle(\$attacker, 'pvp', \$resolution->result)", $official);
        $this->assertStringNotContainsString('recordJobArtBattle', $practice);
    }

    private function projectPath(string $path): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
