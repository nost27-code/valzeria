<?php

namespace App\Console\Commands;

use App\Models\NationRaidBattleResult;
use App\Models\NationRaidBattleTelemetryLog;
use App\Models\NationRaidEvent;
use App\Services\Nation\NationRaidBattleTelemetryService;
use App\Services\Nation\Raid\NationRaidTelemetryAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** 保存済みresolvedだけを再投影する。HP/探索力/順位/報酬/戦闘計算に触れない。 */
class RecordNationRaidTelemetry extends Command
{
    protected $signature = 'nation-raid:telemetry {event : 対象イベントID} {--after-id=0 : 前回表示された結果IDから再開} {--limit=100 : 調査件数（最大1000）}';

    protected $description = '確定済みレイド出撃の未記録分析データを補完する（既存行は上書きしない）';

    public function handle(NationRaidTelemetryAdapter $adapter, NationRaidBattleTelemetryService $writer): int
    {
        $eventId = filter_var($this->argument('event'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $after = filter_var($this->option('after-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($eventId === false || $after === false || $limit === false
            || ! Schema::hasTable('nation_raid_events') || ! Schema::hasTable('nation_raid_battle_telemetry')) {
            $this->error('対象・件数・必要tableを確認してください。');
            return self::FAILURE;
        }
        $event = NationRaidEvent::query()->find($eventId);
        if ($event === null) {
            $this->error('対象イベントがありません。');
            return self::FAILURE;
        }
        $counts = ['recorded' => 0, 'existing' => 0, 'failed' => 0, 'last_id' => $after];
        foreach (NationRaidBattleResult::query()->where('event_id', $event->id)->where('status', 'resolved')
            ->where('id', '>', $after)->with('participation')->orderBy('id')->limit($limit)->get() as $battle) {
            $counts['last_id'] = $battle->id;
            $tokenHash = hash('sha256', $battle->battle_token);
            try {
                if (NationRaidBattleTelemetryLog::query()->where('battle_token_hash', $tokenHash)->exists()) {
                    $counts['existing']++;
                    continue;
                }
                $stored = $writer->record($adapter->data($battle, $event, $battle->participation));
                $counts[$stored === null ? 'failed' : ($stored->wasRecentlyCreated ? 'recorded' : 'existing')]++;
            } catch (\Throwable $exception) {
                $counts['failed']++;
                Log::warning('Raid telemetry recovery failed', ['battle_token_hash' => $tokenHash, 'error_class' => $exception::class]);
            }
        }
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));
        if ($counts['failed'] > 0) {
            $this->warn('失敗分の再試行は同じafter-idを指定してください。既存行は再加算されません。');
        }
        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
