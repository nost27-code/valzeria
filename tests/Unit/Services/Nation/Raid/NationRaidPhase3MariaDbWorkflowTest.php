<?php

namespace Tests\Unit\Services\Nation\Raid;

use Tests\TestCase;

final class NationRaidPhase3MariaDbWorkflowTest extends TestCase
{
    public function test_workflow_uses_only_an_ephemeral_mariadb_10_5_13_database(): void
    {
        $workflow = $this->workflow();

        $this->assertStringContainsString('image: mariadb:10.5.13', $workflow);
        $this->assertStringContainsString('APP_ENV: testing', $workflow);
        $this->assertStringContainsString('DB_HOST: 127.0.0.1', $workflow);
        $this->assertStringContainsString('DB_DATABASE: valzeria_nation_raid_phase3_ci', $workflow);
        $this->assertStringContainsString('php artisan migrate --force --no-interaction --no-ansi', $workflow);
        $this->assertStringNotContainsString('migrate:fresh', $workflow);
        $this->assertStringNotContainsString('local_deploy.php', $workflow);
        $this->assertStringNotContainsString('.env.production', $workflow);
    }

    public function test_workflow_runs_the_complete_fail_closed_phase_three_harness(): void
    {
        $workflow = $this->workflow();

        $this->assertStringContainsString(
            'php scripts/verify/nation-raid-phase3-mariadb.php all --confirm-isolated-database',
            $workflow,
        );
        $this->assertStringContainsString("'scripts/verify/nation-raid-phase3-mariadb.php'", $workflow);
        $this->assertStringContainsString("'database/migrations/2026_09_03_120000_create_nation_raid_event_foundation.php'", $workflow);
        $this->assertStringContainsString("'app/Services/Nation/Raid/NationRaidEventService.php'", $workflow);
        $this->assertStringContainsString("'app/Services/Nation/CompetitionEventCoordinatorService.php'", $workflow);
    }

    public function test_isolation_probe_supports_the_mariadb_10_5_baseline(): void
    {
        $script = file_get_contents(base_path('scripts/verify/nation-raid-phase3-mariadb.php'));
        $this->assertStringContainsString('SELECT @@SESSION.tx_isolation', $script);
        $this->assertStringNotContainsString('SELECT @@transaction_isolation', $script);
    }

    private function workflow(): string
    {
        $path = base_path('.github/workflows/verify-nation-raid-phase3-mariadb.yml');
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        return $contents;
    }
}
