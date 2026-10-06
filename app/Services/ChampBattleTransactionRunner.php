<?php

namespace App\Services;

use App\Exceptions\ChampBattleStateChangedException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChampBattleTransactionRunner
{
    public function lock(Builder $query, bool $shared = false): Builder
    {
        $connection = $query->getConnection();
        // Xserver's mysql connection also serves MariaDB. Check the actual
        // server before using its shared-lock NOWAIT syntax.
        if ($connection instanceof MySqlConnection && $connection->isMaria()) {
            return $query->lock($shared ? 'lock in share mode nowait' : 'for update nowait');
        }

        return $shared ? $query->sharedLock() : $query->lockForUpdate();
    }

    public function run(int $challengerId, callable $callback): array
    {
        $connection = DB::connection();
        $previousTimeout = null;
        $startedAt = hrtime(true);
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $previousTimeout = (int) $connection->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS value')->value;
            // Bound every lock wait, including material rewards and foreign-key checks.
            $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');
        }

        try {
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $phase = 'challenger_lock';
                CharacterStatusService::clearRequestCache($challengerId);
                try {
                    return $connection->transaction(function () use ($callback, &$phase) {
                        return $callback($phase);
                    }, 1);
                } catch (ChampBattleStateChangedException|DeadlockException|QueryException $exception) {
                    // A deadlock inside an outer transaction invalidates that caller's work.
                    // Let the owner roll it back instead of continuing in a broken savepoint.
                    if ($exception instanceof DeadlockException) {
                        throw $exception;
                    }
                    $stateChanged = $exception instanceof ChampBattleStateChangedException;
                    $error = $exception->errorInfo ?? [];
                    if (! $stateChanged
                        && ! in_array((int) ($error[1] ?? 0), [1205, 1213, 3572], true)
                        && (string) ($error[0] ?? $exception->getCode()) !== '40001') {
                        throw $exception;
                    }

                    // The transaction has rolled back. Never reuse stats from a rolled-back level-up.
                    CharacterStatusService::clearRequestCache($challengerId);
                    $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
                    if ($this->shouldStopRetrying($attempt, $elapsedMs, $phase, $stateChanged)) {
                        Log::warning('Champ battle database contention handled.', [
                            'database_error_code' => (int) ($error[1] ?? 0),
                            'reason' => $stateChanged ? 'champ_state_changed' : 'database_lock',
                            'phase' => $phase,
                            'attempts' => $attempt,
                            'elapsed_ms' => $elapsedMs,
                        ]);

                        return [
                            'ok' => false,
                            'message' => 'ほかの処理と重なりました。少し待ってから、もう一度挑戦してください。',
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

        throw new \LogicException('Champ transaction ended without a result.');
    }

    protected function shouldStopRetrying(int $attempt, int $elapsedMs, string $phase, bool $stateChanged): bool
    {
        // Fallback blocking reference locks occur before gameplay locks/writes.
        // Timeout rounding must not consume all three attempts on the first wait.
        // Later failures retain the shorter budget and rollback before retrying.
        $earlyReferenceWait = ! $stateChanged && $phase === 'champ_character_lock';

        return $attempt >= 3 || (! $earlyReferenceWait && $elapsedMs >= 2000);
    }

    protected function waitBeforeRetry(int $attempt): void
    {
        // NOWAIT must still allow a short operation to finish, without sitting
        // in InnoDB's wait queue and retaining previously acquired row locks.
        // Back off only after releasing all locks held by this attempt.
        usleep(($attempt * 250 + random_int(0, 50)) * 1000);
    }
}
