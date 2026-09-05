<?php

namespace Tests\Unit\Services\Nation\Raid;

use NationRaidPhase4MariaDbHarness;
use Symfony\Component\Process\Process;
use Tests\TestCase;

require_once __DIR__.'/../../../../../scripts/verify/support/NationRaidPhase4MariaDbSafety.php';
require_once __DIR__.'/../../../../../scripts/verify/support/NationRaidPhase4MariaDbHarness.php';

final class NationRaidPhase4MariaDbWorkflowTest extends TestCase
{
    public function test_entrypoint_refuses_without_confirmation_before_booting_laravel(): void
    {
        $process = new Process([PHP_BINARY, base_path('scripts/verify/nation-raid-phase4-mariadb.php'), 'worker', 'invalid']);
        $process->setTimeout(10);
        $process->run();
        $this->assertSame(2, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertStringContainsString('Refusing to run without --confirm-isolated-database', $process->getErrorOutput());
    }

    public function test_harness_cannot_create_fixtures_without_its_database_preflight(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database preflight must pass before creating fixtures.');
        (new NationRaidPhase4MariaDbHarness)->run();
    }

    public function test_confirmation_alone_cannot_boot_with_a_production_environment(): void
    {
        $process = new Process([PHP_BINARY, base_path('scripts/verify/nation-raid-phase4-mariadb.php'),
            'all', '--confirm-isolated-database'], base_path(),
            ['APP_ENV' => 'production', 'DB_DATABASE' => 'valzeria_nation_raid_phase4_ci']);
        $process->setTimeout(10);
        $process->run();
        $this->assertSame(2, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertStringContainsString('Refusing to boot', $process->getErrorOutput());
    }

    public function test_worker_cannot_bypass_its_database_preflight(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database preflight must pass before running a worker.');
        (new NationRaidPhase4MariaDbHarness)->worker('');
    }

    public function test_ci_provisions_an_ephemeral_mariadb_without_production_secrets_or_deployment(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/verify-nation-raid-phase4-mariadb.yml'));
        foreach (['image: mariadb:10.5.13', 'APP_ENV: testing', 'DB_HOST: 127.0.0.1',
            'DB_DATABASE: valzeria_nation_raid_phase4_ci', 'php artisan migrate --force --no-interaction --no-ansi',
            'php scripts/verify/nation-raid-phase4-mariadb.php all --confirm-isolated-database',
            'shell: bash', 'if: always()', 'actions/upload-artifact@v4', 'retention-days: 7',
            'driver: [mysql, mariadb]', 'DB_CONNECTION: ${{ matrix.driver }}', 'name: nation-raid-phase4-mariadb-result-${{ matrix.driver }}'] as $expected) {
            $this->assertStringContainsString($expected, $workflow);
        }
        foreach (['secrets.', 'local_deploy', 'migrate:fresh', 'db:seed', '.env.production', 'pull_request_target'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $workflow);
        }
    }

    public function test_harness_preserves_all_declared_real_concurrency_checks(): void
    {
        $script = file_get_contents(base_path('scripts/verify/support/NationRaidPhase4MariaDbHarness.php'));
        foreach (['same_token_admission_and_settlement', 'different_tokens_one_pending',
            'concurrent_carry_and_nation_coordination', 'stage10_stage20_echo_and_replay',
            'daily_limit_fifth_slot_race', 'settlement_refund_race', 'real_1213_rollback_and_retry',
            'real_1205_exhaustion_session_restore_and_recovery', 'persisted_damage_and_usage_conservation',
            'concurrent_reward_finalization', 'same_reward_claim_currency_once', 'same_fixed_reward_bundle_once', 'competing_reward_choices',
            'different_event_rewards_inventory_race', 'reward_real_1205_preserves_right_and_retry',
            'reward_claim_failure_rollback_and_concurrent_retry', 'player_capture_releases_global_admission_lock',
            'refund_counter_crosses_255', 'same_event_different_owners_claim_independently',
            'reward_capacity_after_owner_lock'] as $check) {
            $this->assertStringContainsString("\$this->scenario('".$check."'", $script);
        }
        foreach (['throw new QueryException', 'throw new PDOException', 'migrate:fresh', 'truncate(', '->delete()'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script);
        }
        $this->assertStringContainsString("['bypass_shell' => true]", $script);
        $this->assertStringContainsString("'worker', \$payload, '--confirm-isolated-database'", $script);
        $this->assertStringContainsString("\$saved = \$battle->summary['calculation'];", $script);
        $this->assertStringContainsString('SELECT CONNECTION_ID()', $script);
    }

    public function test_reward_checks_use_real_services_and_are_covered_by_ci_path_filters(): void
    {
        $source = file_get_contents(base_path('scripts/verify/support/NationRaidPhase4MariaDbRewardScenarios.php'));
        foreach (['app(NationRaidEventService::class)->completeFinalization', 'app(NationRaidRewardService::class)->claim',
            'Character::whereKey($character->id)->lockForUpdate()', 'new NationRaidPhase4ObservedTransactions',
            'reward-writes-staged', 'CharacterNotification::setEventDispatcher($dispatcher)',
            'reward-other-owner-completed', 'assertCompletionClaim', 'beforeExecuting',
            'reward-inventory-staged', 'reward-owner-read-requested', 'owner_read_barrier_reached',
            "'initial_quantity' => 495", "'material_quantity' => 498", '$ownerBarrierActive = false;'] as $expected) {
            $this->assertStringContainsString($expected, $source);
        }
        foreach (['throw new QueryException', 'throw new PDOException', 'migrate:fresh', 'truncate(', '->delete()'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
        $workflow = file_get_contents(base_path('.github/workflows/verify-nation-raid-phase4-mariadb.yml'));
        foreach (['config/nation_raid_rewards.php', 'tests/Feature/NationRaidRewardTest.php', 'tests/Feature/NationRaidFixedRewardTest.php',
            'scripts/verify/fixtures/nation_raid_rewards_v1.php',
            'scripts/verify/support/NationRaidPhase4MariaDb*.php', 'app/Services/CharacterNotificationService.php',
            'app/Services/StorageCapacityService.php', 'app/Models/KisekiTransaction.php'] as $expected) {
            $this->assertStringContainsString($expected, $workflow);
        }
    }
}
