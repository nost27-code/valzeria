<?php

namespace App\Services;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GameplayMetricWriter
{
    /** Run only after the gameplay transaction commits. Retry telemetry, never gameplay. */
    public function write(string $metricType, string $context, Closure $write): void
    {
        $connection = null;
        $previousTimeout = null;
        $phase = 'connection';
        $attempt = 0;
        $startedAt = hrtime(true);

        try {
            $connection = DB::connection();
            if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                $previousTimeout = (int) $connection->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS value')->value;
                $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');
            }

            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $connection->transaction(function () use ($write, &$phase): void {
                        $phase = 'gameplay_metrics';
                        $write($phase);
                        $phase = 'commit';
                    }, 1);

                    return;
                } catch (QueryException $exception) {
                    // Only retry known lock errors after the whole metric transaction rolls back.
                    // Connection/commit failures may have an unknown outcome; do not double count.
                    $code = (int) ($exception->errorInfo[1] ?? 0);
                    if (! in_array($code, [1205, 1213], true)
                        || $attempt >= 3
                        // MariaDB can take almost two seconds for a one-second timeout.
                        || (hrtime(true) - $startedAt) >= 3_000_000_000) {
                        throw $exception;
                    }

                    usleep(($attempt * 25 + random_int(0, 25)) * 1000);
                }
            }
        } catch (Throwable $exception) {
            $this->reportFailure($metricType, $context, $exception, $phase, $attempt,
                (int) ((hrtime(true) - $startedAt) / 1_000_000));
        } finally {
            if ($previousTimeout !== null) {
                try {
                    $connection->statement('SET SESSION innodb_lock_wait_timeout = '.$previousTimeout);
                } catch (Throwable $exception) {
                    $this->reportFailure($metricType, $context, $exception, 'restore_lock_timeout', $attempt);
                }
            }
        }
    }

    public function reportFailure(
        string $metricType,
        string $context,
        Throwable $exception,
        string $phase = 'prepare',
        int $attempts = 0,
        int $elapsedMs = 0,
    ): void {
        try {
            // Never log SQL, bindings, exception messages, player identifiers or payloads.
            $error = $exception instanceof QueryException ? $exception->errorInfo : [];
            Log::warning('[GameplayMetrics] 計測レコードを保存できませんでした。', [
                'metric_type' => $metricType,
                'context' => $context,
                'exception' => $exception::class,
                'phase' => $phase,
                'database_error_code' => isset($error[1]) ? (int) $error[1] : null,
                'sql_state' => isset($error[0]) && preg_match('/\A[A-Z0-9]{5}\z/', (string) $error[0]) ? $error[0] : null,
                'attempts' => $attempts,
                'elapsed_ms' => $elapsedMs,
            ]);
        } catch (Throwable) {
            // Neither telemetry nor its diagnostics may fail the committed gameplay result.
        }
    }
}
