<?php

namespace App\Services\Nation\Raid;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/** V2-R5: 3 attemptのみ。戦闘計算をcallbackへ入れず、rollback後にだけ待つ。 */
class NationRaidTransactionRunner
{
    public function run(callable $callback): mixed
    {
        $connection = DB::connection();
        $previousTimeout = null;
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $previousTimeout = (int) $connection->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS value')->value;
            $timeout = max(1, (int) config('nation_raid.settlement.innodb_lock_wait_timeout_seconds', 3));
            $connection->statement('SET SESSION innodb_lock_wait_timeout = '.$timeout);
        }

        try {
            $attempts = max(1, (int) config('nation_raid.settlement.attempts', 3));
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                try {
                    return $connection->transaction(fn () => $callback($attempt), 1);
                } catch (Throwable $exception) {
                    if ($attempt >= $attempts || ! $this->isRetryable($exception)) {
                        throw $exception;
                    }
                    $this->waitBeforeRetry($attempt);
                }
            }
        } finally {
            if ($previousTimeout !== null) {
                $connection->statement('SET SESSION innodb_lock_wait_timeout = '.$previousTimeout);
            }
        }

        throw new \LogicException('Raid transaction ended without a result.');
    }

    public function isRetryable(Throwable $exception): bool
    {
        if (! $exception instanceof QueryException && ! $exception instanceof \PDOException) {
            return false;
        }
        $error = $exception->errorInfo ?? [];

        return in_array((int) ($error[1] ?? 0), [1205, 1213], true)
            || (string) ($error[0] ?? $exception->getCode()) === '40001';
    }

    protected function waitBeforeRetry(int $attempt): void
    {
        $backoff = (array) config('nation_raid.settlement.backoff_milliseconds', [50, 150]);
        $milliseconds = max(0, (int) ($backoff[$attempt - 1] ?? 150));
        $jitter = random_int(0, max(0, (int) config('nation_raid.settlement.jitter_max_milliseconds', 50)));
        usleep(($milliseconds + $jitter) * 1000);
    }
}
