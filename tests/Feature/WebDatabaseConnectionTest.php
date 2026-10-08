<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DatabaseConnectionCooldown;
use App\Support\WebDatabaseConnection;
use App\Support\WorkerDatabaseConnection;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\Store;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WebDatabaseConnectionTest extends TestCase
{
    private function configurePool(): void
    {
        foreach (array_keys(app('db')->getConnections()) as $name) {
            app('db')->purge($name);
        }
        config(['database.default' => 'mysql', 'database.web_pool' => [
            'enabled' => true, 'username' => 'second_web', 'password' => 'fixture-web-secret',
            'third_username' => 'third_web', 'third_password' => 'fixture-third-secret'],
            'database.worker' => ['enabled' => true, 'username' => 'worker_account', 'password' => 'fixture-worker-secret'],
            'database.connections.mysql.username' => 'first_web', 'database.connections.mysql.url' => null,
            'database.connections.mysql.read' => null, 'database.connections.mysql.write' => null,
            'database.connections.mysql.driver' => 'mysql', 'database.connections.mysql.database' => 'same_database',
            'queue.batching.database' => 'mysql', 'queue.failed.database' => 'mysql',
            'queue.connections.database.connection' => 'mysql', 'session.connection' => 'mysql',
            'cache.stores.database.connection' => 'mysql', 'cache.stores.database.lock_connection' => 'unrelated']);
        $connections = config('database.connections');
        unset($connections['web_secondary'], $connections['web_tertiary']);
        config(['database.connections' => $connections]);
    }

    public function test_each_http_slot_preserves_the_same_database_options_and_uses_one_account(): void
    {
        foreach ([0 => 'mysql', 1 => 'web_secondary', 2 => 'web_tertiary'] as $slot => $expected) {
            $this->configurePool();
            $primary = config('database.connections.mysql');
            app(WebDatabaseConnection::class)->apply(false, $slot);
            unset($primary['username'], $primary['password']);
            foreach (['web_secondary' => 'second_web', 'web_tertiary' => 'third_web'] as $name => $username) {
                $secondary = config('database.connections.'.$name);
                $this->assertSame($username, $secondary['username']);
                unset($secondary['username'], $secondary['password']);
                $this->assertSame($primary, $secondary);
            }
            $this->assertSame($expected, config('database.default'));
            foreach (['queue.batching.database', 'queue.failed.database', 'queue.connections.database.connection',
                'cache.stores.database.connection', 'session.connection'] as $key) {
                $this->assertSame($expected, config($key));
            }
            $this->assertSame('unrelated', config('cache.stores.database.lock_connection'));
            $this->assertSame([], app('db')->getConnections());
        }
    }

    public function test_optional_third_account_can_be_removed_without_changing_two_account_behavior(): void
    {
        foreach ([0 => 'mysql', 1 => 'web_secondary'] as $slot => $expected) {
            $this->configurePool();
            config(['database.web_pool.third_username' => '', 'database.web_pool.third_password' => '']);
            app(WebDatabaseConnection::class)->apply(false, $slot);
            $this->assertSame($expected, config('database.default'));
            $this->assertNull(config('database.connections.web_tertiary'));
        }
        $this->configurePool();
        config(['database.web_pool.third_username' => '', 'database.web_pool.third_password' => '']);
        $this->expectExceptionMessage('slot is not configured');
        app(WebDatabaseConnection::class)->apply(false, 2);
    }

    public function test_third_connection_limit_does_not_pause_other_accounts(): void
    {
        $this->freezeTime();
        $this->configurePool();
        app(WebDatabaseConnection::class)->apply(false, 2);
        $cooldown = new DatabaseConnectionCooldown;
        $cooldown->recordConnectionLimit();
        $this->assertSame(3, $cooldown->remainingSeconds());
        foreach (['mysql', 'web_secondary'] as $name) {
            app('db')->setDefaultConnection($name);
            $this->assertSame(0, $cooldown->remainingSeconds());
        }
        $this->configurePool();
        app(WorkerDatabaseConnection::class)->apply(true, 'worker');
        $this->assertSame(0, $cooldown->remainingSeconds());
    }

    public function test_disabled_http_and_cli_keep_existing_roles(): void
    {
        $this->configurePool();
        app(WebDatabaseConnection::class)->apply(true, 1);
        $this->assertSame('mysql', config('database.default'));
        app(WorkerDatabaseConnection::class)->apply(true, 'worker');
        $this->assertSame('worker', config('database.default'));
        $this->configurePool();
        config(['database.web_pool.enabled' => false, 'database.web_pool.password' => '']);
        app(WebDatabaseConnection::class)->apply(false, 1);
        $this->assertSame('mysql', config('database.default'));
    }

    public function test_invalid_pool_is_rejected_even_when_the_primary_slot_would_be_selected(): void
    {
        foreach ([['database.web_pool.username' => ''], ['database.web_pool.password' => ''],
            ['database.web_pool.username' => 'first_web'], ['database.web_pool.username' => 'worker_account'],
            ['database.connections.mysql.url' => 'mysql://override'],
            ['database.connections.mysql.read' => ['username' => 'override']],
            ['database.connections.mysql.driver' => 'sqlite'],
            ['database.web_pool.third_username' => ''], ['database.web_pool.third_password' => ''],
            ['database.web_pool.third_username' => 'first_web'], ['database.web_pool.third_username' => 'second_web'],
            ['database.web_pool.third_username' => 'worker_account'], ['database.web_pool.third_username' => null],
            ['database.connections.web_tertiary' => []]] as $invalid) {
            $this->configurePool();
            config($invalid);
            try {
                app(WebDatabaseConnection::class)->apply(false, 0);
                $this->fail('Invalid web pool accepted');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('incomplete or unsupported', $e->getMessage());
                $this->assertSame('mysql', config('database.default'));
                $this->assertSame([], app('db')->getConnections());
            }
        }
    }

    public function test_open_connection_cannot_be_switched(): void
    {
        $this->configurePool();
        app('db')->connection('mysql');
        $this->expectExceptionMessage('before opening any connection');
        app(WebDatabaseConnection::class)->apply(false, 1);
    }

    public function test_secondary_connection_limit_does_not_pause_primary_or_worker(): void
    {
        $this->freezeTime();
        $this->configurePool();
        $cooldown = new DatabaseConnectionCooldown;
        app(WebDatabaseConnection::class)->apply(false, 1);
        $cooldown->recordConnectionLimit();
        $this->assertSame(3, $cooldown->remainingSeconds());
        app('db')->setDefaultConnection('mysql');
        $this->assertSame(0, $cooldown->remainingSeconds());
        $this->configurePool();
        app(WorkerDatabaseConnection::class)->apply(true, 'worker');
        $this->assertSame(0, $cooldown->remainingSeconds());
    }

    public function test_shared_database_preserves_login_csrf_cache_and_rollback_between_http_accounts(): void
    {
        $this->configurePool();
        config(['cache.stores.database.lock_connection' => null]);
        $file = tempnam(sys_get_temp_dir(), 'web-pool-sqlite-');
        app('db')->extend('mysql', fn ($config, $name) => new SQLiteConnection(new \PDO('sqlite:'.$file), $file, '', $config));
        $originalDatabase = config('database');
        try {
            app(WebDatabaseConnection::class)->apply(false, 0);
            $db = app('db')->connection();
            $db->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, password TEXT, remember_token TEXT)');
            $db->statement('CREATE TABLE sessions (id TEXT PRIMARY KEY, user_id INTEGER, ip_address TEXT, user_agent TEXT, payload TEXT, last_activity INTEGER)');
            $db->statement('CREATE TABLE cache (key TEXT PRIMARY KEY, value TEXT, expiration INTEGER)');
            $db->statement('CREATE TABLE operation_probe (value INTEGER)');
            $db->table('users')->insert(['id' => 1, 'name' => 'Fixture', 'email' => 'fixture@example.test', 'password' => 'unused']);
            $first = new Store('pool_session', new DatabaseSessionHandler($db, 'sessions', 120));
            $first->start();
            $guard = new SessionGuard('web', app('auth')->createUserProvider('users'), $first);
            $guard->login(User::findOrFail(1));
            $first->put('state', 'oauth-fixture-state');
            $first->put('current_character_id', 77);
            $first->save();
            $id = $first->getId();
            $csrf = $first->token();
            app('cache')->store('database')->put('pool-cache-proof', 'shared-value', 60);
            $db->disconnect();
            app('db')->purge('mysql');
            foreach ([1, 2] as $slot) {
                config(['database' => $originalDatabase]);
                app(WebDatabaseConnection::class)->apply(false, $slot);
                $db = app('db')->connection();
                $second = new Store('pool_session', new DatabaseSessionHandler($db, 'sessions', 120), $id);
                $second->start();
                $guard = new SessionGuard('web', app('auth')->createUserProvider('users'), $second);
                $this->assertSame(1, $guard->id());
                $this->assertSame($csrf, $second->token());
                $this->assertSame('oauth-fixture-state', $second->get('state'));
                $this->assertSame(77, $second->get('current_character_id'));
                app('cache')->forgetDriver('database');
                $this->assertSame('shared-value', app('cache')->store('database')->get('pool-cache-proof'));
                $pdo = $db->getPdo();
                $db->beginTransaction();
                $db->insert('INSERT INTO operation_probe VALUES (1)');
                $this->assertSame($pdo, app('db')->connection()->getPdo());
                $db->rollBack();
                $this->assertSame(0, $db->table('operation_probe')->count());
                try {
                    app(WebDatabaseConnection::class)->apply(false, 0);
                    $this->fail('An open request account was switched');
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('incomplete or unsupported', $e->getMessage());
                }
                app('db')->purge(config('database.default'));
            }

        } finally {
            foreach (array_keys(app('db')->getConnections()) as $name) {
                app('db')->purge($name);
            }
            unset($db, $first, $second, $guard, $pdo);
            @unlink($file);
        }
    }

    public function test_cached_http_bootstrap_selects_each_slot_while_cli_default_and_cache_stay_primary(): void
    {
        $this->configurePool();
        $cache = tempnam(base_path('bootstrap/cache'), 'web-pool-config-');
        file_put_contents($cache, '<?php return '.var_export(config()->all(), true).';');
        $before = hash_file('sha256', $cache);
        try {
            foreach ([0 => 'mysql', 1 => 'web_secondary', 2 => 'web_tertiary'] as $slot => $expected) {
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/web-database-bootstrap.php')], base_path(),
                    ['APP_CONFIG_CACHE' => 'bootstrap/cache/'.basename($cache), 'APP_RUNNING_IN_CONSOLE' => 'false',
                        'PROBE_SLOT' => (string) $slot, 'VALZERIA_DB_ROLE' => 'web', 'DB_WEB_SECONDARY_USERNAME' => 'ignored-runtime-value']);
                $process->mustRun();
                $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($expected, $result['connection']);
                $this->assertSame(['first_web', 'second_web', 'third_web'][$slot], $result['username']);
                $this->assertSame('same_database', $result['database']);
                $this->assertSame(0, $result['open_connections']);
                $this->assertSame($before, hash_file('sha256', $cache));
                $this->assertStringNotContainsString('fixture-web-secret', $process->getOutput());
                $this->assertStringNotContainsString('fixture-third-secret', $process->getOutput());
            }
            foreach (['web' => 'mysql', 'worker' => 'worker'] as $role => $expected) {
                $process = new Process([PHP_BINARY, base_path('artisan'), 'db:connection-role'], base_path(),
                    ['APP_CONFIG_CACHE' => 'bootstrap/cache/'.basename($cache), 'APP_RUNNING_IN_CONSOLE' => 'true', 'VALZERIA_DB_ROLE' => $role]);
                $process->mustRun();
                $this->assertSame($expected, json_decode($process->getOutput(), true)['connection']);
            }
            $this->assertSame($before, hash_file('sha256', $cache));
        } finally {
            unlink($cache);
        }
    }
}
