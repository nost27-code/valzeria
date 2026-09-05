<?php

namespace App\Services\Nation\Raid\Simulation;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use Throwable;

/** DB側でもwriteを拒否し、snapshot抽出transactionを必ずrollbackする。 */
final class NationRaidReadOnlyDatabaseGuard
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function run(callable $callback): mixed
    {
        $startingLevel = $this->connection->transactionLevel();
        $driver = $this->connection->getDriverName();
        if ($startingLevel !== 0 && $driver !== 'sqlite') {
            throw new RuntimeException('Raid snapshot read-only guard requires no active transaction.');
        }

        $this->enable($driver);
        $this->connection->beginTransaction();

        try {
            return $callback();
        } finally {
            try {
                while ($this->connection->transactionLevel() > $startingLevel) {
                    $this->connection->rollBack();
                }
            } catch (Throwable) {
                // 接続切断時もrestoreを試み、元例外をfinallyで隠さない。
            }
            $this->disable($driver);
        }
    }

    private function enable(string $driver): void
    {
        if ($driver === 'sqlite') {
            $this->connection->statement('PRAGMA query_only = ON');

            return;
        }
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->connection->statement('SET SESSION TRANSACTION READ ONLY');

            return;
        }

        throw new RuntimeException("Unsupported Phase 2 read-only database driver: {$driver}");
    }

    private function disable(string $driver): void
    {
        if ($driver === 'sqlite') {
            $this->connection->statement('PRAGMA query_only = OFF');

            return;
        }
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->connection->statement('SET SESSION TRANSACTION READ WRITE');
        }
    }
}
