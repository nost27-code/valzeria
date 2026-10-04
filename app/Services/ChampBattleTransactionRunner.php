<?php

namespace App\Services;

use App\Exceptions\ChampBattleStateChangedException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChampBattleTransactionRunner
{
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
                    // Server timeout resolution can exceed one second; do not accumulate
                    // three full waits when the lock is persistently held elsewhere.
                    if ($attempt === 3 || $elapsedMs >= 2000) {
                        Log::warning('Champ battle database contention handled.', [
                            'database_error_code' => (int) ($error[1] ?? 0),
                            'reason' => $stateChanged ? 'champ_state_changed' : 'database_lock',
                            'phase' => $phase,
                            'attempts' => $attempt,
                            'elapsed_ms' => $elapsedMs,
                        ]);

                        return [
                            'ok' => false,
                            'message' => 'ほかの冒険者のチャンプ戦を処理中です。少し待ってから、もう一度挑戦してください。',
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

    protected function waitBeforeRetry(int $attempt): void
    {
        // Back off only after releasing all locks held by this attempt.
        usleep(($attempt * 50 + random_int(0, 25)) * 1000);
    }
}
