<?php

namespace App\Services;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExplorationItemTransactionRunner
{
    public function lock(Builder $query): Builder
    {
        $connection = $query->getConnection();

        return $connection instanceof MySqlConnection && $connection->isMaria()
            ? $query->lock('for update nowait')
            : $query->lockForUpdate();
    }

    public function run(int $characterId, callable $callback): array
    {
        $connection = DB::connection();
        $outerLevel = $connection->transactionLevel();
        $previousTimeout = null;
        $startedAt = hrtime(true);
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $previousTimeout = (int) $connection->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS value')->value;
            $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');
        }

        try {
            for ($attempt = 1; $attempt <= 8; $attempt++) {
                $phase = 'character_lock';
                CharacterStatusService::clearRequestCache($characterId);
                try {
                    return $connection->transaction(function () use ($callback, &$phase) {
                        return $callback($phase);
                    }, 1);
                } catch (DeadlockException|QueryException $exception) {
                    app(RequestPerformanceCollector::class)->exception($exception, $phase);
                    // A savepoint rollback does not release all locks. The owner
                    // must roll back an outer transaction before retrying.
                    if ($outerLevel > 0 || $exception instanceof DeadlockException) {
                        throw $exception;
                    }
                    $error = $exception->errorInfo ?? [];
                    if (! in_array((int) ($error[1] ?? 0), [1205, 1213, 3572], true)
                        && (string) ($error[0] ?? $exception->getCode()) !== '40001') {
                        throw $exception;
                    }
                    CharacterStatusService::clearRequestCache($characterId);
                    $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
                    $earlyWait = $phase === 'character_lock';
                    if ($attempt >= ($earlyWait ? 8 : 3) || $elapsedMs >= ($earlyWait ? 3000 : 2000)) {
                        Log::warning($this->contentionLogMessage(), [
                            'database_error_code' => (int) ($error[1] ?? 0),
                            'reason' => 'database_lock',
                            'phase' => $phase,
                            'attempts' => $attempt,
                            'elapsed_ms' => $elapsedMs,
                        ]);

                        return [
                            'success' => false,
                            'message' => $this->contentionMessage(),
                        ];
                    }
                    $this->waitBeforeRetry($attempt);
                }
            }
        } finally {
            if ($previousTimeout !== null) {
                $connection->statement('SET SESSION innodb_lock_wait_timeout = '.$previousTimeout);
            }
        }

        throw new \LogicException('Exploration item transaction ended without a result.');
    }

    protected function contentionLogMessage(): string
    {
        return 'Exploration item database contention handled.';
    }

    protected function contentionMessage(): string
    {
        return '回復アイテムの処理が混み合っています。少し待ってから、もう一度お試しください。';
    }

    protected function waitBeforeRetry(int $attempt): void
    {
        usleep((min($attempt, 2) * 250 + random_int(0, 50)) * 1000);
    }
}
