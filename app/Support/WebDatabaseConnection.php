<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

class WebDatabaseConnection
{
    public const NAME = 'web_secondary';

    public function __construct(private Repository $config, private DatabaseManager $database) {}

    public function apply(bool $console, ?int $slot = null): void
    {
        if ($console || ! $this->config->get('database.web_pool.enabled', false)) {
            return;
        }

        $primary = $this->config->get('database.default');
        $connection = $this->config->get('database.connections.'.$primary, []);
        $username = $this->config->get('database.web_pool.username', '');
        $password = $this->config->get('database.web_pool.password', '');
        if (! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)
            || ! is_string($username) || trim($username) === ''
            || ! is_string($password) || $password === ''
            || $username === ($connection['username'] ?? null)
            || $username === $this->config->get('database.worker.username')
            || ! empty($connection['url']) || isset($connection['read']) || isset($connection['write'])
            || $this->config->has('database.connections.'.self::NAME)
        ) {
            throw new RuntimeException('Web database pool configuration is incomplete or unsupported.');
        }
        if ($this->database->getConnections() !== []) {
            throw new RuntimeException('Web database account must be selected before opening any connection.');
        }
        $slot ??= random_int(0, 1);
        if (! in_array($slot, [0, 1], true)) {
            throw new RuntimeException('Web database pool slot must be 0 or 1.');
        }

        $connection['username'] = $username;
        $connection['password'] = $password;
        $this->config->set('database.connections.'.self::NAME, $connection);
        if ($slot === 0) {
            return;
        }

        // Select once before sessions/auth/game queries; keep every transaction on one PDO.
        $this->database->setDefaultConnection(self::NAME);
        foreach (['queue.batching.database', 'queue.failed.database', 'queue.connections.database.connection',
            'cache.stores.database.connection', 'cache.stores.database.lock_connection', 'session.connection'] as $key) {
            if ($this->config->get($key) === $primary) {
                $this->config->set($key, self::NAME);
            }
        }
    }
}
