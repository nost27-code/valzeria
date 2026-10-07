<?php

namespace Tests\Feature;

use App\Support\DatabaseConnectionCooldown;
use App\Support\WorkerDatabaseConnection;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WorkerDatabaseConnectionTest extends TestCase
{
    private function configureWorker(): void
    {
        app('db')->purge();
        config(['database.default' => 'mysql', 'database.worker' => [
            'enabled' => true, 'username' => 'worker_account', 'password' => 'fixture-secret'],
            'database.connections.mysql.username' => 'web_account',
            'database.connections.mysql.database' => 'same_database',
            'database.connections.mysql.url' => null,
            'queue.batching.database' => 'mysql', 'queue.failed.database' => 'mysql',
            'queue.connections.database.connection' => 'mysql',
            'cache.stores.database.connection' => 'mysql',
            'cache.stores.database.lock_connection' => null,
            'session.connection' => 'unrelated_connection']);
    }

    public function test_worker_copies_target_and_options_and_remaps_only_primary_stores_without_connecting(): void
    {
        $this->configureWorker();
        $primary = config('database.connections.mysql');
        app(WorkerDatabaseConnection::class)->apply(true, 'worker');
        $worker = config('database.connections.worker');
        unset($primary['username'], $primary['password'], $worker['username'], $worker['password']);
        $this->assertSame($primary, $worker);
        $this->assertSame('worker_account', config('database.connections.worker.username'));
        $this->assertSame('worker', config('database.default'));
        $this->assertSame('worker', config('queue.failed.database'));
        $this->assertSame('worker', config('queue.batching.database'));
        $this->assertSame('worker', config('queue.connections.database.connection'));
        $this->assertSame('worker', config('cache.stores.database.connection'));
        $this->assertNull(config('cache.stores.database.lock_connection'));
        $this->assertSame('unrelated_connection', config('session.connection'));
        $this->assertSame([], app('db')->getConnections());
    }

    public function test_http_unmarked_cli_and_disabled_worker_preserve_primary(): void
    {
        $this->configureWorker();
        $selector = app(WorkerDatabaseConnection::class);
        $selector->apply(false, 'worker');
        $selector->apply(true, 'web');
        config(['database.worker.enabled' => false, 'database.worker.password' => '']);
        $selector->apply(true, 'worker');
        $this->assertSame('mysql', config('database.default'));
        $this->assertFalse(config()->has('database.connections.worker'));
    }

    public function test_invalid_worker_never_falls_back_or_opens_a_connection(): void
    {
        foreach ([['database.worker.username' => ''], ['database.worker.password' => ''],
            ['database.worker.username' => 'web_account'], ['database.connections.mysql.url' => 'mysql://override'],
            ['database.connections.mysql.read' => ['username' => 'override']],
            ['database.connections.mysql.driver' => 'sqlite']] as $invalid) {
            $this->configureWorker();
            config(['database.connections.mysql.read' => null]);
            config($invalid);
            try {
                app(WorkerDatabaseConnection::class)->apply(true, 'worker');
                $this->fail('Invalid worker accepted');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('fallback is disabled', $e->getMessage());
                $this->assertSame('mysql', config('database.default'));
                $this->assertSame([], app('db')->getConnections());
            }
        }
    }

    public function test_open_connection_cannot_be_switched(): void
    {
        $this->configureWorker();
        app('db')->connection('mysql'); // Lazy connection object, no PDO/network.
        $this->expectExceptionMessage('before opening any connection');
        app(WorkerDatabaseConnection::class)->apply(true, 'worker');
    }

    public function test_cached_configuration_selects_worker_before_boot_and_cannot_be_recached(): void
    {
        $this->configureWorker();
        $cache = tempnam(base_path('bootstrap/cache'), 'worker-config-');
        file_put_contents($cache, '<?php return '.var_export(config()->all(), true).';');
        $before = hash_file('sha256', $cache);
        try {
            foreach (['web' => 'mysql', 'worker' => 'worker'] as $role => $expected) {
                $process = new Process([PHP_BINARY, base_path('artisan'), 'db:connection-role'], base_path(),
                    ['APP_CONFIG_CACHE' => 'bootstrap/cache/'.basename($cache), 'VALZERIA_DB_ROLE' => $role,
                        'DB_WORKER_USERNAME' => 'ignored-runtime-value']);
                $process->mustRun();
                $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($expected, $result['connection']);
                $this->assertSame($role === 'worker' ? 'worker_account' : 'web_account', $result['username']);
                $this->assertSame('same_database', $result['database']);
                $this->assertStringNotContainsString('fixture-secret', $process->getOutput());
            }
            foreach (['config:cache', 'optimize', 'migrate'] as $command) {
                $process = new Process([PHP_BINARY, base_path('artisan'), $command], base_path(),
                    ['APP_CONFIG_CACHE' => 'bootstrap/cache/'.basename($cache), 'VALZERIA_DB_ROLE' => 'worker']);
                $process->run();
                $this->assertFalse($process->isSuccessful());
                $this->assertStringContainsString('without VALZERIA_DB_ROLE', $process->getOutput().$process->getErrorOutput());
                $this->assertSame($before, hash_file('sha256', $cache));
            }
        } finally {
            unlink($cache);
        }
    }

    public function test_worker_connection_limit_does_not_pause_web_account(): void
    {
        $this->configureWorker();
        $cooldown = new DatabaseConnectionCooldown;
        app(WorkerDatabaseConnection::class)->apply(true, 'worker');
        $cooldown->recordConnectionLimit();
        $this->assertSame(3, $cooldown->remainingSeconds());
        app('db')->setDefaultConnection('mysql');
        $this->assertSame(0, $cooldown->remainingSeconds());
    }

    public function test_worker_transaction_uses_one_pdo_and_rolls_back(): void
    {
        $this->configureWorker();
        app('db')->extend('mysql', fn ($config, $name) => new \Illuminate\Database\SQLiteConnection(
            new \PDO('sqlite::memory:'), $config['database'], '', $config,
        ));
        app(WorkerDatabaseConnection::class)->apply(true, 'worker');
        $connection = app('db')->connection();
        $pdo = $connection->getPdo();
        $connection->statement('CREATE TABLE role_probe (value INTEGER)');
        $connection->beginTransaction();
        $connection->insert('INSERT INTO role_probe VALUES (1)');
        $this->assertSame($pdo, app('db')->connection()->getPdo());
        $this->assertSame(1, $connection->selectOne('SELECT COUNT(*) AS n FROM role_probe')->n);
        $connection->rollBack();
        $this->assertSame(0, $connection->selectOne('SELECT COUNT(*) AS n FROM role_probe')->n);
        $this->assertSame('worker', config('database.default'));
    }

    public function test_cron_wrapper_propagates_worker_role_to_artisan_child(): void
    {
        $root = sys_get_temp_dir().'/valzeria-cron-'.bin2hex(random_bytes(8));
        $scripts = $root.'/valzeria_releases/fixture/scripts';
        mkdir($scripts, 0777, true);
        mkdir($root.'/valzeria_current');
        copy(base_path('scripts/run_current_schedule.php'), $scripts.'/run_current_schedule.php');
        file_put_contents($root.'/valzeria_current/artisan', '<?php echo json_encode([getenv("VALZERIA_DB_ROLE"), $argv]);');
        try {
            $process = new Process([PHP_BINARY, $scripts.'/run_current_schedule.php']);
            $process->mustRun();
            [$role, $arguments] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('worker', $role);
            $this->assertSame('schedule:run', $arguments[1]);
            $this->assertSame('--no-interaction', $arguments[2]);
        } finally {
            unlink($root.'/valzeria_current/artisan');
            unlink($scripts.'/run_current_schedule.php');
            rmdir($scripts);
            rmdir(dirname($scripts));
            rmdir(dirname($scripts, 2));
            rmdir($root.'/valzeria_current');
            rmdir($root);
        }
    }
}
