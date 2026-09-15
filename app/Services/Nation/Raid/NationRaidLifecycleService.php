<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidEvent;
use Illuminate\Support\Facades\Log;

/** 兵站準備開始、開催開始、受付終了、終了30分後の安全な自動確定を進める。 */
final readonly class NationRaidLifecycleService
{
    public function __construct(
        private NationRaidEventService $events,
        private NationRaidPreparationService $preparations,
        private NationRaidSettlementService $settlement,
        private NationRaidDailyLineageService $lineages,
    ) {}

    /** @return array{preparations:int,started:int,closing:int,finalized:int,finalization_waiting:int,deferred:int,missed:int,failed:int} */
    public function advanceDue(): array
    {
        $counts = ['preparations' => 0, 'started' => 0, 'closing' => 0, 'finalized' => 0,
            'finalization_waiting' => 0, 'deferred' => 0, 'missed' => 0, 'failed' => 0];
        $at = now();

        foreach (NationRaidEvent::query()->where('status', NationRaidEvent::STATUS_SCHEDULED)
            ->whereNull('preparation_frozen_at')->where('starts_at', '>', $at)
            ->select(['id', 'starts_at', 'ruleset_snapshot'])->lazyById(100) as $event) {
            if (! is_array($event->ruleset_snapshot['raid_cycle'] ?? null)) {
                continue;
            }
            if ($at->lt($this->preparations->preparationStartsAt($event))) {
                continue;
            }
            try {
                $this->events->freezePreparation($event, $at);
                $counts['preparations']++;
                $this->audit($event, 'freeze_preparation', 'success');
            } catch (\Throwable $exception) {
                $counts['failed']++;
                $this->audit($event, 'freeze_preparation', 'failed', $exception);
            }
        }
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

        foreach (NationRaidEvent::query()->where('status', NationRaidEvent::STATUS_FINALIZING)
            ->where('ends_at', '<=', $at)->select(['id', 'ends_at', 'ruleset_snapshot'])->lazyById(100) as $event) {
            // 初回開催など旧rulesetへ、自動確定契約を遡及適用しない。
            if (! is_array($event->ruleset_snapshot['raid_cycle'] ?? null)) {
                continue;
            }
            $delay = (int) ($event->ruleset_snapshot['raid_cycle']['automatic_finalization_delay_minutes']
                ?? config('nation_raid.event.automatic_finalization_delay_minutes', 30));
            if ($at->lt($event->ends_at->copy()->addMinutes($delay))) {
                continue;
            }
            try {
                $recovery = $this->settlement->recoverExpired(eventId: $event->id);
                if ($recovery['failed'] > 0) {
                    $counts['finalization_waiting']++;
                    continue;
                }
                foreach (range(1, 7) as $day) {
                    if ($this->lineages->finalizeDay($event, $day) === null) {
                        break;
                    }
                }
                $this->events->completeFinalization($event, $at);
                $counts['finalized']++;
                $this->audit($event, 'complete_finalization', 'success');
            } catch (\DomainException $exception) {
                if ($this->isRetryableFinalizationException($exception)) {
                    // 精算・返却・日次記録が残る間は破壊的に確定せず、次の毎分実行で再試行する。
                    $counts['finalization_waiting']++;
                } else {
                    $counts['failed']++;
                    $this->audit($event, 'complete_finalization', 'failed', $exception);
                }
            } catch (\Throwable $exception) {
                $counts['failed']++;
                $this->audit($event, 'complete_finalization', 'failed', $exception);
            }
        }

        return $counts;
    }

    private function isRetryableFinalizationException(\DomainException $exception): bool
    {
        return in_array($exception->getMessage(), [
            '未確定または未返却の出撃が残っているためイベントを確定できません。',
            '日次系譜がすべて確定していません。',
        ], true);
    }

    private function audit(NationRaidEvent $event, string $action, string $result, ?\Throwable $exception = null): void
    {
        Log::log($exception ? 'error' : 'notice', 'Nation raid lifecycle operation', [
            'event_id' => $event->id, 'action' => $action, 'result' => $result,
            'error_class' => $exception === null ? null : $exception::class,
        ]);
    }
}
