<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

class WorkerDatabaseConnection
{
    public const NAME = 'worker';

    public function __construct(private Repository $config, private DatabaseManager $database) {}

    public function apply(bool $console, string $role, array $arguments = []): void
    {
        if (! $console || $role !== 'worker' || ! $this->config->get('database.worker.enabled', false)) {
            return;
        }

        // Never persist a process-specific default into the shared configuration cache.
        foreach ($arguments as $argument) {
            if (preg_match('/^(?:config:|optimize(?:$|:)|migrate(?:$|:)|db:seed$)/', $argument)) {
                throw new RuntimeException('Run deployment/configuration commands without VALZERIA_DB_ROLE=worker.');
            }
        }

        $primary = $this->config->get('database.default');
        $connection = $this->config->get('database.connections.'.$primary, []);
        $username = $this->config->get('database.worker.username', '');
        $password = $this->config->get('database.worker.password', '');
        if (! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)
            || ! is_string($username) || trim($username) === ''
            || ! is_string($password) || $password === ''
            || $username === ($connection['username'] ?? null)
            || ! empty($connection['url']) || isset($connection['read']) || isset($connection['write'])
            || $this->config->has('database.connections.'.self::NAME)
        ) {
            throw new RuntimeException('Worker database configuration is incomplete or unsupported; primary fallback is disabled.');
        }
        if ($this->database->getConnections() !== []) {
            throw new RuntimeException('Worker database role must be selected before opening any connection.');
        }

        // Copy the primary target/options; only the account differs. Transactions keep one PDO.
        $connection['username'] = $username;
        $connection['password'] = $password;
        $this->config->set('database.connections.'.self::NAME, $connection);
        $this->database->setDefaultConnection(self::NAME);
        foreach (['queue.batching.database', 'queue.failed.database', 'queue.connections.database.connection',
            'cache.stores.database.connection', 'cache.stores.database.lock_connection', 'session.connection'] as $key) {
            if ($this->config->get($key) === $primary) {
                $this->config->set($key, self::NAME);
            }
        }
    }
}
