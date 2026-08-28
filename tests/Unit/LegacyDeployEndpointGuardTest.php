<?php

namespace Tests\Unit;

use RuntimeException;
use Tests\TestCase;
use Valzeria\Deploy\LegacyDeployEndpointGuard;

final class LegacyDeployEndpointGuardTest extends TestCase
{
    public function test_none_mode_does_not_probe_the_endpoint_contract(): void
    {
        $guard = $this->guardClass();
        $probeCount = 0;

        $guard::assertMigrationRequestIsSafe(
            'https://valzeria.com/server_deploy_api.php',
            'none',
            function () use (&$probeCount): array {
                $probeCount++;

                return [];
            },
        );

        $this->assertSame(0, $probeCount);
    }

    public function test_migration_modes_require_contract_version_two_or_newer(): void
    {
        $guard = $this->guardClass();

        foreach (['backward_compatible', 'maintenance_required'] as $mode) {
            $guard::assertMigrationRequestIsSafe(
                'https://valzeria.com/server_deploy_api.php',
                $mode,
                static fn (): array => ['X-Valzeria-Deploy-Contract' => '2'],
            );
            $guard::assertMigrationRequestIsSafe(
                'https://valzeria.com/server_deploy_api.php',
                $mode,
                static fn (): array => ['x-valzeria-deploy-contract' => ['1', '3']],
            );
        }

        $this->addToAssertionCount(4);
    }

    public function test_migration_mode_is_rejected_when_the_endpoint_is_old_or_unverifiable(): void
    {
        $guard = $this->guardClass();

        foreach ([[], ['X-Valzeria-Deploy-Contract' => '1']] as $headers) {
            try {
                $guard::assertMigrationRequestIsSafe(
                    'https://valzeria.com/server_deploy_api.php',
                    'maintenance_required',
                    static fn (): array => $headers,
                );
                $this->fail('旧APIまたは契約version不明のAPIへmigrationを送信できてしまいました。');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('migration付きデプロイを中止しました', $error->getMessage());
                $this->assertStringContainsString('DEPLOY_MIGRATION_MODE=none', $error->getMessage());
            }
        }
    }

    /** @return class-string */
    private function guardClass(): string
    {
        $path = base_path('scripts/deploy/LegacyDeployEndpointGuard.php');
        $this->assertFileExists($path);
        require_once $path;

        return LegacyDeployEndpointGuard::class;
    }
}
