<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NamelessMariaDbIsolationTest extends TestCase
{
    #[DataProvider('unsafeEnvironments')]
    public function test_unsafe_target_is_rejected_before_laravel_or_database_boot(array $overrides, bool $confirm): void
    {
        $environment = array_replace(getenv(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '33818', 'DB_DATABASE' => 'valzeria_nameless_verify_guard',
            'DB_USERNAME' => 'fixture', 'DB_PASSWORD' => 'must-not-be-disclosed', 'DB_URL' => '',
        ], $overrides);
        $command = [PHP_BINARY, dirname(__DIR__, 2).'/scripts/verify/nameless-mariadb.php', 'all'];
        if ($confirm) { $command[] = '--confirm-isolated-database'; }
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            dirname(__DIR__, 2), $environment);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $this->assertSame(2, proc_close($process));
        $this->assertStringContainsString('Explicit testing/loopback/disposable database', $output);
        $this->assertStringNotContainsString('must-not-be-disclosed', $output);
    }

    public static function unsafeEnvironments(): array
    {
        return [
            'confirmation missing' => [[], false],
            'production' => [['APP_ENV' => 'production'], true],
            'non fixture database' => [['DB_DATABASE' => 'valzeria'], true],
            'remote host' => [['DB_HOST' => '192.0.2.1'], true],
            'default database port' => [['DB_PORT' => '3306'], true],
            'non numeric port' => [['DB_PORT' => 'invalid'], true],
            'port zero' => [['DB_PORT' => '0'], true],
            'redirect URL' => [['DB_URL' => 'mysql://example.invalid/valzeria'], true],
            'sqlite fallback' => [['DB_CONNECTION' => 'sqlite'], true],
        ];
    }
}
