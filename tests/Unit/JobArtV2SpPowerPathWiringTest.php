<?php

namespace Tests\Unit;

use Tests\TestCase;

class JobArtV2SpPowerPathWiringTest extends TestCase
{
    public function test_non_persistent_competitive_routes_enable_the_output_budget(): void
    {
        $pvp = $this->source('app/Services/PvPBattleService.php');
        $arenaNpc = $this->source('app/Services/ArenaNpcBattleService.php');
        $nationWar = $this->source('app/Services/Nation/NationWarBattleEngine.php');

        $this->assertSame(2, preg_match_all(
            "/attachBossSet\\([^;]+, 'champ', 'pvp', true\\);/",
            $pvp,
        ));
        $this->assertSame(1, preg_match_all(
            "/attachBossSet\\([^;]+, 'champ', 'arena_npc', true\\);/",
            $arenaNpc,
        ));
        $this->assertStringContainsString("'sp_output_budget_enabled' => true", $nationWar);
    }

    public function test_pve_prediction_and_tower_skip_the_budget_while_champ_budgets_both_sides(): void
    {
        $training = $this->source('app/Services/TrainingGroundBattleService.php');
        $tower = $this->source('app/Services/TowerBattleService.php');
        $champ = $this->source('app/Services/ChampBattleService.php');

        $this->assertStringContainsString("'sp_output_budget_enabled' => false", $training);
        $this->assertMatchesRegularExpression(
            '/configureSpOutput\([^;]+?\'tower\',\s*false,\s*\(int\) \$run->tower_max_mp,/s',
            $tower,
        );
        $this->assertSame(2, preg_match_all(
            "/attachBossSet\\([^;]+, 'champ', 'champ', true\\);/",
            $champ,
        ));
        $this->assertSame(0, preg_match_all("/attachBossSet\\([^;]+, 'champ'\\);/", $champ));
    }

    public function test_every_secondary_player_versus_player_entrypoint_delegates_to_the_budgeted_service(): void
    {
        foreach ([
            'app/Services/TrainingGroundPvpBattleService.php',
            'app/Services/SixHeroOfficialBattleService.php',
            'app/Services/SixHeroPracticeBattleService.php',
            'app/Services/Admin/SixHeroBattleSimulatorService.php',
        ] as $path) {
            $source = $this->source($path);

            $this->assertStringContainsString('PvPBattleService', $source, $path);
            $this->assertStringContainsString('->resolveBattle(', $source, $path);
        }
    }

    public function test_champ_output_has_an_independent_default_off_gate(): void
    {
        $config = $this->source('config/battle.php');
        $featureGate = $this->source('app/Services/JobArtV2FeatureGate.php');

        $this->assertStringContainsString(
            "'champ_enabled' => env('BATTLE_JOB_ART_SP_POWER_SCALING_CHAMP', false)",
            $config,
        );
        $this->assertStringContainsString(
            "config('battle.job_art_v2.sp_power_scaling.champ_enabled', false)",
            $featureGate,
        );
    }

    public function test_battle_service_call_sites_and_custom_entrypoints_are_frozen(): void
    {
        $this->assertSame([
            'Http/Controllers/BattleController.php',
            'Services/Admin/ValzeriaLabReplayService.php',
            'Services/Admin/ValzeriaLabVirtualAdventurerService.php',
            'Services/ExplorationService.php',
            'Services/HeroTrialBenchmarkService.php',
            'Services/HeroTrialService.php',
            'Services/MapExplorationBatchService.php',
            'Services/Nation/NationWarBattleEngine.php',
            'Services/Nation/Raid/Simulation/NationRaidPassiveBossActionProfileProvider.php',
            'Services/Nation/Raid/Simulation/NationRaidTurnByTurnActionProfileBridge.php',
            'Services/SubAreaExplorationService.php',
            'Services/TrainingGroundBattleService.php',
        ], $this->appFilesMatching('/(?:->|parent::)executeBattle\s*\(/'));

        $this->assertSame([
            'Services/Admin/SixHeroBattleSimulatorService.php',
            'Services/PvPBattleService.php',
            'Services/SixHeroOfficialBattleService.php',
            'Services/SixHeroPracticeBattleService.php',
            'Services/TrainingGroundPvpBattleService.php',
        ], $this->appFilesMatching('/->resolveBattle\s*\(/'));

        $this->assertStringContainsString(
            '? $pvpBattleService->practice($character, $opponent)',
            $this->source('app/Http/Controllers/TrainingGroundController.php'),
        );
        $this->assertStringContainsString(
            '$practiceBattleService->execute(',
            $this->source('app/Livewire/SixHeroHallScreen.php'),
        );
        $this->assertStringContainsString(
            '$benchmarkService->simulate(',
            $this->source('app/Livewire/Admin/BattleSimulator.php'),
        );
    }

    private function source(string $path): string
    {
        $source = file_get_contents(base_path($path));
        $this->assertIsString($source, $path);

        return $source;
    }

    /** @return list<string> */
    private function appFilesMatching(string $pattern): array
    {
        $matches = [];
        $root = app_path();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            if (! is_string($source) || preg_match($pattern, $source) !== 1) {
                continue;
            }

            $matches[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }

        sort($matches);

        return $matches;
    }
}
