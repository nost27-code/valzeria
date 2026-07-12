<?php

namespace Tests\Unit;

use Tests\TestCase;

class ReleaseDeploymentScriptTest extends TestCase
{
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
        $this->assertFileExists(base_path('scripts/deploy/remote-release.sh'));
        $remoteSource = file_get_contents(base_path('scripts/deploy/remote-release.sh'));
        $this->assertStringContainsString('restored the previous release', $remoteSource);
        $this->assertStringContainsString('link_public_directory', $remoteSource);
        $this->assertStringContainsString('Refusing to replace non-link public directory', $remoteSource);
        $this->assertStringContainsString('-ef "$PUBLIC_DIR/$file"', $remoteSource);
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
    }

    public function test_github_actions_keep_staging_and_production_separate(): void
    {
        $staging = file_get_contents(base_path('.github/workflows/deploy-staging.yml'));
        $production = file_get_contents(base_path('.github/workflows/deploy-production.yml'));

        $this->assertNotFalse($staging);
        $this->assertNotFalse($production);
        $this->assertStringContainsString('environment: staging', $staging);
        $this->assertStringContainsString('runs-on: [self-hosted, Windows, X64]', $staging);
        $this->assertStringContainsString('actions/upload-artifact@v4', $staging);
        $this->assertStringContainsString('actions/download-artifact@v4', $staging);
        $this->assertStringContainsString('-Target staging', $staging);
        $this->assertStringNotContainsString('SSH_PRIVATE_KEY', $staging);
        $this->assertStringContainsString('DEPLOY_PHP_BINARY: /usr/bin/php8.4', $staging);
        $this->assertStringNotContainsString('secrets.DEPLOY_PHP_BINARY', $staging);
        $this->assertStringContainsString('environment: production', $production);
        $this->assertStringContainsString("inputs.confirmation == 'deploy-production'", $production);
        $this->assertStringContainsString('runs-on: [self-hosted, Windows, X64]', $production);
        $this->assertStringContainsString('-Target production', $production);
        $this->assertStringNotContainsString('SSH_PRIVATE_KEY', $production);
        $this->assertStringContainsString('DEPLOY_PHP_BINARY: /usr/bin/php8.4', $production);
        $this->assertStringNotContainsString('secrets.DEPLOY_PHP_BINARY', $production);

        $this->assertFileExists(base_path('scripts/deploy/invoke-remote-release.ps1'));
        $invokeRelease = file_get_contents(base_path('scripts/deploy/invoke-remote-release.ps1'));
        $this->assertStringContainsString('valzeria_staging_deploy', $invokeRelease);
        $this->assertStringContainsString('valzeria_production_deploy', $invokeRelease);
        $this->assertStringContainsString('StrictHostKeyChecking=yes', $invokeRelease);

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
        $this->assertStringContainsString('staging_valzeria_current', $resetScript);
        $this->assertStringContainsString('db:wipe --force', $resetScript);
        $this->assertStringContainsString('dungeon:validate', $resetScript);
    }
}
