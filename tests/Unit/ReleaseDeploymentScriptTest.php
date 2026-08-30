<?php

namespace Tests\Unit;

use Tests\TestCase;

class ReleaseDeploymentScriptTest extends TestCase
{
    public function test_app_boot_does_not_run_database_or_public_file_repairs(): void
    {
        $source = file_get_contents(base_path('app/Providers/AppServiceProvider.php'));

        $this->assertNotFalse($source);
        $this->assertStringNotContainsString('Schema::', $source);
        $this->assertStringNotContainsString('Artisan::call', $source);
        $this->assertStringNotContainsString('CharacterJob::', $source);
        $this->assertStringNotContainsString('rename(', $source);
        $this->assertStringNotContainsString('symlink(', $source);
    }

    public function test_empty_database_title_migration_creates_its_foreign_key_target(): void
    {
        $source = file_get_contents(base_path('database/migrations/2026_06_07_164108_create_character_titles_table.php'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString("Schema::hasTable('titles')", $source);
        $this->assertStringContainsString("Schema::create('titles'", $source);

        $materialSource = file_get_contents(base_path('database/migrations/2026_06_08_213140_create_character_materials_table.php'));
        $this->assertNotFalse($materialSource);
        $this->assertStringContainsString("Schema::hasTable('materials')", $materialSource);
        $this->assertStringContainsString("Schema::create('materials'", $materialSource);

        $jobClassSource = file_get_contents(base_path('database/migrations/2026_06_05_012201_create_job_classes_table.php'));
        $this->assertNotFalse($jobClassSource);
        $this->assertStringContainsString('$table->string(\'rank\')', $jobClassSource);

        $citySource = file_get_contents(base_path('database/migrations/2026_06_06_065519_create_cities_table.php'));
        $this->assertNotFalse($citySource);
        $this->assertStringContainsString("DB::table('cities')->insert", $citySource);

        $unlockCityForeignSource = file_get_contents(base_path('database/migrations/2026_07_12_000000_ensure_items_unlock_city_foreign_key.php'));
        $this->assertNotFalse($unlockCityForeignSource);
        $this->assertStringContainsString("Schema::getForeignKeys('items')", $unlockCityForeignSource);

        $databaseSeeder = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));
        $this->assertNotFalse($databaseSeeder);
        $this->assertStringContainsString('JobSystemSeeder::class', $databaseSeeder);
        $this->assertStringContainsString('ExplorationSupportMasterSeeder::class', $databaseSeeder);
        $this->assertStringNotContainsString('JobSeeder::class', $databaseSeeder);
    }

    public function test_server_deploy_keeps_the_release_safety_invariants(): void
    {
        $source = file_get_contents(base_path('server_deploy_api.php'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString('hash_hmac', $source);
        $this->assertStringContainsString('deploy_claim_nonce', $source);
        $this->assertStringContainsString('VALZERIA_SIGNATURE_TTL_SECONDS', $source);
        $this->assertStringContainsString('VALZERIA_MAX_ZIP_RATIO', $source);
        $this->assertStringContainsString('VALZERIA_MAX_ZIP_FILES = 16000', $source);
        $this->assertStringContainsString('deploy_atomic_link', $source);
        $this->assertStringContainsString('deploy_prepare_shared_storage', $source);
        $this->assertStringContainsString('deploy_assert_shared_app_key', $source);
        $this->assertStringContainsString('deploy_release_health_check', $source);
        $this->assertStringContainsString('if (!$resetStagingDatabase)', $source);
        $this->assertStringContainsString("Artisan::call('db:seed', ['--force' => true])", $source);
        $this->assertStringContainsString("Artisan::call('db:wipe', ['--force' => true])", $source);
        $this->assertStringContainsString('空DB初期化はステージング専用です。', $source);
        $this->assertStringContainsString('ステージングDBの初期化はステージング専用です。', $source);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=0', $source);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=1', $source);
        $this->assertStringContainsString("'none', 'backward_compatible', 'maintenance_required'", $source);
        $this->assertStringContainsString('deploy_copy_missing($releaseDir . \'/public/build\', $sharedAssets . \'/build\')', $source);
        $this->assertStringContainsString('rolled_back', $source);
        $this->assertStringContainsString('deploy_write_public_htaccess', $source);
        $this->assertStringContainsString('server_staged_zip', $source);
        $this->assertStringContainsString('共有領域へ置いたZIPからのデプロイはステージング専用です。', $source);
        $this->assertStringContainsString('VALZERIA_LEGACY_DEPLOY_CONTRACT_VERSION = 2', $source);
        $this->assertStringContainsString("header('X-Valzeria-Deploy-Contract: ' . VALZERIA_LEGACY_DEPLOY_CONTRACT_VERSION)", $source);
        $serverContractHeaderPosition = strpos($source, "header('X-Valzeria-Deploy-Contract:");
        $serverMethodGuardPosition = strpos($source, "if (\$_SERVER['REQUEST_METHOD'] !== 'POST')");
        $this->assertIsInt($serverContractHeaderPosition);
        $this->assertIsInt($serverMethodGuardPosition);
        $this->assertTrue($serverContractHeaderPosition < $serverMethodGuardPosition);
        $this->assertStringContainsString("'valzeria:preflight-pending-migrations'", $source);
        $this->assertStringContainsString("'valzeria:validate-master-data'", $source);
        $this->assertStringContainsString("'valzeria:validate-release-readiness'", $source);
        $serverMaintenancePosition = strpos($source, "if (\$mode === 'maintenance_required')");
        $this->assertIsInt($serverMaintenancePosition);
        $serverMaintenanceEndPosition = strpos($source, '}', $serverMaintenancePosition);
        $serverPreflightPosition = strpos($source, "'valzeria:preflight-pending-migrations'");
        $serverMigratePosition = strpos($source, 'Artisan::call($migrationCommand');
        $this->assertIsInt($serverMaintenanceEndPosition);
        $this->assertIsInt($serverPreflightPosition);
        $this->assertIsInt($serverMigratePosition);
        $this->assertTrue($serverMaintenancePosition < $serverMaintenanceEndPosition);
        $this->assertTrue($serverMaintenanceEndPosition < $serverPreflightPosition);
        $this->assertTrue($serverMaintenancePosition < $serverPreflightPosition);
        $this->assertTrue($serverPreflightPosition < $serverMigratePosition);
        $serverMaintenanceBlock = substr(
            $source,
            $serverMaintenancePosition,
            $serverMaintenanceEndPosition - $serverMaintenancePosition,
        );
        $this->assertStringContainsString("\$preflightParameters['--allow-enemy-merge'] = true;", $serverMaintenanceBlock);
        $this->assertStringContainsString("\$preflightParameters['--allow-rank5-v6-master-rewrite'] = true;", $serverMaintenanceBlock);
        $this->assertFileExists(base_path('scripts/deploy/remote-release.sh'));
        $remoteSource = file_get_contents(base_path('scripts/deploy/remote-release.sh'));
        $this->assertStringContainsString('restored the previous release', $remoteSource);
        $this->assertStringContainsString('link_public_directory', $remoteSource);
        $this->assertStringContainsString('Refusing to replace non-link public directory', $remoteSource);
        $this->assertStringContainsString('-ef "$PUBLIC_DIR/$file"', $remoteSource);
        $this->assertStringContainsString("realpath('\${escaped_current_link}')", $remoteSource);
        $this->assertStringContainsString('valzeria:preflight-pending-migrations', $remoteSource);
        $this->assertStringContainsString('--allow-enemy-merge', $remoteSource);
        $this->assertStringContainsString('--allow-rank5-v6-master-rewrite', $remoteSource);
        $this->assertStringContainsString('${DEPLOY_RELEASE_SHA:?DEPLOY_RELEASE_SHA is required}', $remoteSource);
        $this->assertStringContainsString('for required in .release-sha artisan', $remoteSource);
        $this->assertStringContainsString('artifact_release_sha="$(cat "$release_dir/.release-sha")"', $remoteSource);
        $this->assertStringContainsString('"$artifact_release_sha" != "$DEPLOY_RELEASE_SHA"', $remoteSource);
        $remoteMaintenancePosition = strpos(
            $remoteSource,
            'if [[ "$DEPLOY_MIGRATION_MODE" == "maintenance_required" ]]; then',
        );
        $this->assertIsInt($remoteMaintenancePosition);
        $remoteMaintenanceEndPosition = strpos($remoteSource, "\n    fi", $remoteMaintenancePosition);
        $remotePreflightPosition = strpos($remoteSource, 'valzeria:preflight-pending-migrations');
        $this->assertIsInt($remoteMaintenanceEndPosition);
        $this->assertIsInt($remotePreflightPosition);
        $this->assertTrue($remoteMaintenancePosition < $remoteMaintenanceEndPosition);
        $this->assertTrue($remoteMaintenanceEndPosition < $remotePreflightPosition);
        $this->assertTrue($remoteMaintenancePosition < $remotePreflightPosition);
        $remoteMaintenanceBlock = substr(
            $remoteSource,
            $remoteMaintenancePosition,
            $remoteMaintenanceEndPosition - $remoteMaintenancePosition,
        );
        $this->assertStringContainsString(
            'preflight_args+=(--allow-enemy-merge --allow-rank5-v6-master-rewrite)',
            $remoteMaintenanceBlock,
        );
        $this->assertStringContainsString('valzeria:validate-release-readiness --all', $remoteSource);
        $this->assertStringNotContainsString('"$DEPLOY_PHP_BINARY" "$release_dir/artisan" cache:clear', $remoteSource);
        $this->assertFileExists(base_path('app/Console/Commands/PreflightPendingMigrations.php'));
        $this->assertFileExists(base_path('app/Console/Commands/ValidateReleaseReadiness.php'));
        $this->assertStringNotContainsString('extractTo(', $source);
    }

    public function test_local_deploy_requires_a_secret_and_explicit_migration_modes(): void
    {
        $source = file_get_contents(base_path('local_deploy.php'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString('VALZERIA_DEPLOY_SECRET', $source);
        $this->assertStringContainsString('.env.production.local', $source);
        $this->assertStringContainsString("'none', 'backward_compatible', 'maintenance_required'", $source);
        $this->assertStringContainsString('X-Deploy-Signature', $source);
        $this->assertStringContainsString("require_once __DIR__ . '/scripts/deploy/LegacyDeployEndpointGuard.php'", $source);
        $this->assertStringContainsString('LegacyDeployEndpointGuard::assertMigrationRequestIsSafe', $source);
        $localContractGuardPosition = strpos($source, 'LegacyDeployEndpointGuard::assertMigrationRequestIsSafe');
        $localUploadPosition = strpos($source, '$cfile = new CURLFile');
        $this->assertIsInt($localContractGuardPosition);
        $this->assertIsInt($localUploadPosition);
        $this->assertTrue($localContractGuardPosition < $localUploadPosition);
        $this->assertStringNotContainsString('$vendorIncludes', $source);
    }

    public function test_staging_deploy_is_isolated_from_production(): void
    {
        $localSource = file_get_contents(base_path('local_deploy.php'));
        $stagingSource = file_get_contents(base_path('local_deploy_staging.php'));
        $serverSource = file_get_contents(base_path('server_deploy_api.php'));

        $this->assertStringContainsString("['production', 'staging']", $localSource);
        $this->assertStringContainsString('staging.valzeria.com', $localSource);
        $this->assertStringContainsString('VALZERIA_STAGING_DEPLOY_SECRET', $stagingSource);
        $this->assertStringContainsString('.env.staging.local', $stagingSource);
        $this->assertStringContainsString('git status --porcelain --untracked-files=all', $stagingSource);
        $this->assertStringContainsString("getenv('STAGING_DEPLOY_ALLOW_DIRTY') !== '1'", $stagingSource);
        $this->assertStringContainsString('bootstrap_empty', $serverSource);
        $this->assertStringContainsString('reset_staging_database', $serverSource);
        $this->assertStringContainsString('STAGING_DEPLOY_RESET_DATABASE', $stagingSource);
        $this->assertFileExists(base_path('local_deploy_staged_zip.php'));
        $stagedZipSource = file_get_contents(base_path('local_deploy_staged_zip.php'));
        $this->assertStringContainsString("require_once __DIR__ . '/scripts/deploy/LegacyDeployEndpointGuard.php'", $stagedZipSource);
        $this->assertStringContainsString('LegacyDeployEndpointGuard::assertMigrationRequestIsSafe', $stagedZipSource);
        $stagedContractGuardPosition = strpos($stagedZipSource, 'LegacyDeployEndpointGuard::assertMigrationRequestIsSafe');
        $stagedUploadPosition = strpos($stagedZipSource, '$ch = curl_init()');
        $this->assertIsInt($stagedContractGuardPosition);
        $this->assertIsInt($stagedUploadPosition);
        $this->assertTrue($stagedContractGuardPosition < $stagedUploadPosition);
    }

    public function test_github_actions_keep_staging_and_production_separate(): void
    {
        $staging = file_get_contents(base_path('.github/workflows/deploy-staging.yml'));
        $production = file_get_contents(base_path('.github/workflows/deploy-production.yml'));

        $this->assertNotFalse($staging);
        $this->assertNotFalse($production);
        $this->assertStringContainsString('environment: staging', $staging);
        $this->assertStringContainsString("github.ref == 'refs/heads/main'", $staging);
        $this->assertStringContainsString('runs-on: [self-hosted, Windows, X64]', $staging);
        $this->assertStringContainsString('actions/upload-artifact@v4', $staging);
        $this->assertStringContainsString('actions/download-artifact@v4', $staging);
        $this->assertStringContainsString('-Target staging', $staging);
        $this->assertStringNotContainsString('SSH_PRIVATE_KEY', $staging);
        $this->assertStringContainsString('DEPLOY_PHP_BINARY: /usr/bin/php8.4', $staging);
        $this->assertStringNotContainsString('secrets.DEPLOY_PHP_BINARY', $staging);
        $this->assertStringContainsString('ref: ${{ github.sha }}', $staging);
        $this->assertStringNotContainsString('ref: main', $staging);
        $this->assertStringContainsString("printf '%s\\n' \"\$actual_release_sha\" > .release-sha", $staging);
        $this->assertStringContainsString('-ReleaseSha $env:EXPECTED_RELEASE_SHA', $staging);
        $this->assertStringContainsString('environment: production', $production);
        $this->assertStringContainsString("inputs.confirmation == 'deploy-production'", $production);
        $this->assertStringContainsString("github.ref == 'refs/heads/main'", $production);
        $this->assertStringContainsString('runs-on: [self-hosted, Windows, X64]', $production);
        $this->assertStringContainsString('-Target production', $production);
        $this->assertStringNotContainsString('SSH_PRIVATE_KEY', $production);
        $this->assertStringContainsString('DEPLOY_PHP_BINARY: /usr/bin/php8.4', $production);
        $this->assertStringNotContainsString('secrets.DEPLOY_PHP_BINARY', $production);
        $this->assertStringContainsString('ref: ${{ github.sha }}', $production);
        $this->assertStringNotContainsString('ref: main', $production);
        $this->assertStringContainsString("printf '%s\\n' \"\$actual_release_sha\" > .release-sha", $production);
        $this->assertStringContainsString('-ReleaseSha $env:EXPECTED_RELEASE_SHA', $production);

        $this->assertFileExists(base_path('scripts/deploy/invoke-remote-release.ps1'));
        $invokeRelease = file_get_contents(base_path('scripts/deploy/invoke-remote-release.ps1'));
        $this->assertStringContainsString('valzeria_staging_deploy', $invokeRelease);
        $this->assertStringContainsString('valzeria_production_deploy', $invokeRelease);
        $this->assertStringContainsString('StrictHostKeyChecking=yes', $invokeRelease);
        $this->assertStringContainsString("[ValidatePattern('^[0-9a-f]{40}$')]", $invokeRelease);
        $this->assertStringContainsString("DEPLOY_RELEASE_SHA='\$ReleaseSha'", $invokeRelease);

        $resetWorkflow = file_get_contents(base_path('.github/workflows/reset-staging-database.yml'));
        $resetScript = file_get_contents(base_path('scripts/deploy/reset-staging-database.sh'));
        $this->assertNotFalse($resetWorkflow);
        $this->assertNotFalse($resetScript);
        $this->assertStringContainsString("inputs.confirmation == 'reset-staging-database'", $resetWorkflow);
        $this->assertStringContainsString('runs-on: [self-hosted, Windows, X64]', $resetWorkflow);
        $this->assertStringNotContainsString('SSH_PRIVATE_KEY', $resetWorkflow);
        $this->assertStringContainsString('DEPLOY_PHP_BINARY: /usr/bin/php8.4', $resetWorkflow);
        $this->assertStringNotContainsString('secrets.DEPLOY_PHP_BINARY', $resetWorkflow);
        $this->assertFileExists(base_path('scripts/deploy/invoke-staging-database-reset.ps1'));
        $this->assertFileExists(base_path('scripts/deploy/sync-staging-master-data.sh'));
        $this->assertFileExists(base_path('scripts/deploy/copy-staging-master-data.php'));
        $masterSyncScript = file_get_contents(base_path('scripts/deploy/sync-staging-master-data.sh'));
        $masterCopyScript = file_get_contents(base_path('scripts/deploy/copy-staging-master-data.php'));
        $this->assertStringContainsString('Production master connection validated.', $masterSyncScript);
        $this->assertStringContainsString('Staging master data synchronized from production.', $masterCopyScript);
        $this->assertStringContainsString('characters, inventories, logs, payments', $masterSyncScript);
        $this->assertStringContainsString('source-only columns skipped', $masterCopyScript);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=0', $masterCopyScript);
        $this->assertStringContainsString('staging_valzeria_current', $resetScript);
        $this->assertStringContainsString('sync-staging-master-data.sh" prepare', $resetScript);
        $this->assertStringContainsString('sync-staging-master-data.sh" apply', $resetScript);
        $this->assertStringContainsString('db:wipe --force', $resetScript);
        $this->assertStringContainsString('dungeon:validate', $resetScript);
        $this->assertLessThan(
            strpos($resetScript, 'db:seed --force'),
            strpos($resetScript, 'sync-staging-master-data.sh" apply'),
            '本番マスタ同期後にSeederを実行して、ステージング専用の追加マスタを復元する。'
        );
    }

    public function test_new_achievement_title_backfill_workflow_is_guarded_and_self_verifying(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/backfill-new-achievement-titles.yml'));

        $this->assertNotFalse($workflow);
        $this->assertStringContainsString('environment: ${{ inputs.target }}', $workflow);
        $this->assertStringContainsString("github.ref == 'refs/heads/main'", $workflow);
        $this->assertStringContainsString('backfill-new-achievement-titles', $workflow);
        $this->assertStringContainsString('runs-on: [self-hosted, Windows, X64]', $workflow);
        $this->assertStringContainsString('DEPLOY_PHP_BINARY: /usr/bin/php8.4', $workflow);
        $this->assertStringContainsString('valzeria_staging_deploy', $workflow);
        $this->assertStringContainsString('valzeria_production_deploy', $workflow);
        $this->assertStringContainsString('StrictHostKeyChecking=yes', $workflow);
        $this->assertStringNotContainsString('SSH_PRIVATE_KEY', $workflow);
        $this->assertStringContainsString('INPUT_CONFIRMATION: ${{ inputs.confirmation }}', $workflow);
        $this->assertStringContainsString('$env:INPUT_CONFIRMATION', $workflow);
        $this->assertStringNotContainsString('if (\'${{ inputs.confirmation }}\'', $workflow);
        $this->assertStringContainsString('EXPECTED_RELEASE_SHA: ${{ inputs.release_sha }}', $workflow);
        $this->assertStringContainsString('WORKFLOW_RELEASE_SHA: ${{ github.sha }}', $workflow);
        $this->assertStringContainsString('Workflow SHA does not match the expected deployed release SHA.', $workflow);
        $this->assertStringContainsString("grep -Fxq '\$expectedReleaseSha' '\$releaseRoot/.release-sha'", $workflow);
        $this->assertStringContainsString('VERIFIED_RELEASE_SHA=$expectedReleaseSha', $workflow);
        $this->assertStringContainsString('schema_audit', $workflow);
        $this->assertStringContainsString('titles:backfill-new-achievements --audit-schema --json', $workflow);
        $this->assertStringContainsString('titles:backfill-new-achievements --json', $workflow);
        $this->assertStringContainsString('titles:backfill-new-achievements --apply --json', $workflow);
        $this->assertStringContainsString('$artisan down --retry=60', $workflow);
        $this->assertStringContainsString('$artisan up --no-interaction', $workflow);
        $this->assertStringNotContainsString('up --no-interaction >/dev/null 2>&1 || true', $workflow);
        $this->assertStringContainsString('if ! $artisan up --no-interaction; then status=1', $workflow);
        $this->assertStringContainsString('duplicate_pairs_after', $workflow);
        $this->assertStringContainsString('duplicate_pairs_before', $workflow);
        $this->assertStringContainsString('Applied grant count does not match the dry-run count.', $workflow);
        $this->assertStringContainsString('Eligible new-title grants remain after apply.', $workflow);
    }

    public function test_schedule_wrapper_resolves_current_outside_the_releases_directory(): void
    {
        $source = file_get_contents(base_path('scripts/run_current_schedule.php'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString(
            "str_ends_with(basename(\$releasesRoot), '_releases')",
            $source
        );
        $this->assertStringContainsString(
            "realpath(\$deployRoot . '/valzeria_current')",
            $source
        );
        $this->assertStringContainsString("in_array('--check', \$argv, true)", $source);
        $this->assertStringNotContainsString(
            "realpath(dirname(__DIR__, 2) . '/valzeria_current')",
            $source
        );
    }
}
