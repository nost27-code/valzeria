<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidEvent;
use Illuminate\Support\Facades\Log;

/** 予約済みの開始と期間終了だけ。下書き生成・承認・再開・報酬/最終確定は行わない。 */
final readonly class NationRaidLifecycleService
{
    public function __construct(private NationRaidEventService $events) {}

    /** @return array{started:int,closing:int,deferred:int,missed:int,failed:int} */
    public function advanceDue(): array
    {
        $counts = ['started' => 0, 'closing' => 0, 'deferred' => 0, 'missed' => 0, 'failed' => 0];
        $at = now();
        // 公開gate OFF/一時停止中でも受付期間は終了させる。精算/回収は別jobが継続する。
        foreach (NationRaidEvent::query()->where('status', NationRaidEvent::STATUS_ACTIVE)
            ->where('ends_at', '<=', $at)->select('id')->lazyById(100) as $event) {
            try {
                $this->events->beginFinalization($event, $at);
                $counts['closing']++;
                $this->audit($event, 'begin_finalization', 'success');
            } catch (\Throwable $exception) {
                $counts['failed']++;
                $this->audit($event, 'begin_finalization', 'failed', $exception);
            }
        }

        foreach (NationRaidEvent::query()->where('status', NationRaidEvent::STATUS_SCHEDULED)
            ->where('starts_at', '<=', $at)->select(['id', 'ends_at'])->lazyById(100) as $event) {
            if ($event->ends_at->lte($at)) {
                // 開催できなかった期間は後ろへずらさず、運営の取消/再計画を待つ。
                $counts['missed']++;
                continue;
            }
            if (! (bool) config('features.nation_competitive_raid_enabled', false)) {
                $counts['deferred']++;
                continue;
            }
            try {
                $this->events->activate($event, $at);
                $counts['started']++;
                $this->audit($event, 'activate', 'success');
            } catch (\Throwable $exception) {
                $counts['failed']++;
                $this->audit($event, 'activate', 'failed', $exception);
            }
        }

        return $counts;
    }

    private function audit(NationRaidEvent $event, string $action, string $result, ?\Throwable $exception = null): void
    {
        Log::log($exception ? 'error' : 'notice', 'Nation raid lifecycle operation', [
            'event_id' => $event->id, 'action' => $action, 'result' => $result,
            'error_class' => $exception === null ? null : $exception::class,
        ]);
    }
}
