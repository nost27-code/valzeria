<?php

namespace App\Console\Commands;

use App\Models\NationRaidEvent;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidJson;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class FinalizeNationRaidEvent extends Command
{
    protected $signature = 'nation-raid:finalize {event : 対象イベントID} {--confirm-rewards : 順位と報酬権利の確定を明示確認}';

    protected $description = '回収・日次投票完了後、対象レイドの順位と報酬を原子的に確定する（公開flagは変更しない）';

    public function handle(NationRaidEventService $service): int
    {
        if (! $this->option('confirm-rewards') || ! ctype_digit((string) $this->argument('event'))) {
            $this->error('対象イベントと --confirm-rewards の指定が必要です。');

            return self::FAILURE;
        }
        $id = (int) $this->argument('event');
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $scoped = $dispatcher ? clone $dispatcher : new Dispatcher;
        $queryCount = 0;
        $scoped->listen(QueryExecuted::class, function () use (&$queryCount): void { $queryCount++; });
        $connection->setEventDispatcher($scoped);
        $started = hrtime(true);
        try {
            $event = NationRaidEvent::findOrFail($id);
            $completed = $service->completeFinalization($event);
            $metrics = ['elapsed_ms' => (hrtime(true) - $started) / 1_000_000, 'query_count' => $queryCount,
                'snapshot_bytes' => strlen((string) $completed->getRawOriginal('final_standings_snapshot')),
                'process_peak_memory_bytes' => memory_get_peak_usage(true)];
            Log::notice('Nation raid finalization', ['event_id' => $id, 'source' => 'console', 'result' => 'success', ...$metrics]);
            $this->info('戦果・報酬権利を確定しました。個人報酬は本人が受け取れます。公開設定は変更していません。');
            $this->line(NationRaidJson::encode($metrics));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            // QueryExecutedは成功したSQLだけを数える。失敗SQL・bindings・例外messageは保存しない。
            // 失敗時は確定snapshotがないためsnapshot_bytesを推測して記録しない。
            Log::error('Nation raid finalization', ['event_id' => $id, 'source' => 'console', 'result' => 'failed',
                'error_class' => $exception::class, 'elapsed_ms' => (hrtime(true) - $started) / 1_000_000,
                'query_count' => $queryCount, 'process_peak_memory_bytes' => memory_get_peak_usage(true),
                ...$this->databaseErrorCodes($exception)]);
            $this->error($exception instanceof \DomainException ? $exception->getMessage() : '確定に失敗しました。保存状態を確認してください。');

            return self::FAILURE;
        } finally {
            $dispatcher ? $connection->setEventDispatcher($dispatcher) : $connection->unsetEventDispatcher();
        }
    }

    /** Extract only bounded driver codes, including wrapped PDO/QueryException failures. */
    private function databaseErrorCodes(\Throwable $exception): array
    {
        for ($depth = 0; $exception !== null && $depth < 8; $depth++, $exception = $exception->getPrevious()) {
            if (! $exception instanceof \PDOException) {
                continue;
            }
            $info = $exception->errorInfo ?? [];
            $state = $info[0] ?? null;
            $native = $info[1] ?? null;
            $state = is_string($state) && preg_match('/^[0-9A-Z]{5}$/D', $state) ? $state : null;
            $native = (is_int($native) || (is_string($native) && strlen($native) <= 10 && ctype_digit($native)))
                && (int) $native >= 0 && (int) $native <= 2_147_483_647 ? (int) $native : null;
            if ($state !== null || $native !== null) {
                return ['sqlstate' => $state, 'native_code' => $native];
            }
        }

        return ['sqlstate' => null, 'native_code' => null];
    }
}
