<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

class WebDatabaseConnection
{
    public const NAME = 'web_secondary';

    public const THIRD_NAME = 'web_tertiary';

    public function __construct(private Repository $config, private DatabaseManager $database) {}

    public function apply(bool $console, ?int $slot = null): void
    {
        if ($console || ! $this->config->get('database.web_pool.enabled', false)) {
            return;
        }

        $primary = $this->config->get('database.default');
        $connection = $this->config->get('database.connections.'.$primary, []);
        $accounts = [self::NAME => [
            'username' => $this->config->get('database.web_pool.username', ''),
            'password' => $this->config->get('database.web_pool.password', ''),
        ]];
        $third = [
            'username' => $this->config->get('database.web_pool.third_username', ''),
            'password' => $this->config->get('database.web_pool.third_password', ''),
        ];
        // Empty optional credentials preserve the existing two-account configuration.
        if ($third['username'] !== '' || $third['password'] !== '') {
            $accounts[self::THIRD_NAME] = $third;
        }
        if (! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)
            || ! empty($connection['url']) || isset($connection['read']) || isset($connection['write'])
        ) {
            throw new RuntimeException('Web database pool configuration is incomplete or unsupported.');
        }
        $usernames = [$connection['username'] ?? null, $this->config->get('database.worker.username')];
        foreach ($accounts as $name => $account) {
            if (! is_string($account['username']) || trim($account['username']) === ''
                || ! is_string($account['password']) || $account['password'] === ''
                || in_array($account['username'], $usernames, true)
                || $this->config->has('database.connections.'.$name)
            ) {
                throw new RuntimeException('Web database pool configuration is incomplete or unsupported.');
            }
            $usernames[] = $account['username'];
        }
        if ($this->database->getConnections() !== []) {
            throw new RuntimeException('Web database account must be selected before opening any connection.');
        }
        $names = [$primary, ...array_keys($accounts)];
        $slot ??= random_int(0, count($names) - 1);
        if (! array_key_exists($slot, $names)) {
            throw new RuntimeException('Web database pool slot is not configured.');
        }

        foreach ($accounts as $name => $account) {
            $this->config->set('database.connections.'.$name, array_replace($connection, $account));
        }
        if ($slot === 0) {
            return;
        }

        // Select once before sessions/auth/game queries; keep every transaction on one PDO.
        $this->database->setDefaultConnection($names[$slot]);
        foreach (['queue.batching.database', 'queue.failed.database', 'queue.connections.database.connection',
            'cache.stores.database.connection', 'cache.stores.database.lock_connection', 'session.connection'] as $key) {
            if ($this->config->get($key) === $primary) {
                $this->config->set($key, $names[$slot]);
            }
        }
    }
}
