<?php

namespace App\Livewire\Admin;

use App\Models\NationRaidEvent;
use App\Services\Nation\Raid\NationRaidDailyLineageService;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidOperationsService;
use App\Services\Nation\Raid\NationRaidSettlementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

final class NationRaidOperationsManager extends Component
{
    public string $eventKey = '';
    public string $eventName = '国家対抗レイド';
    public string $startsAt = '';
    public string $pauseReason = '';

    public function boot(): void
    {
        abort_unless(Auth::check() && Auth::user()?->role === 'admin', 403);
    }

    public function createDraft(NationRaidEventService $service): void
    {
        $this->validate([
            'eventKey' => ['required', 'regex:/\A[a-z0-9][a-z0-9_-]{2,63}\z/', 'unique:nation_raid_events,event_key'],
            'eventName' => ['required', 'string', 'max:80'],
            'startsAt' => ['required', 'date_format:Y-m-d\TH:i'],
        ]);
        $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $this->startsAt, config('app.timezone'));
        if ($start->lt(now()->addHours((int) config('nation_raid.event.announcement_lead_hours', 72)))) {
            $this->addError('startsAt', '開催予告に必要な時間を確保した開始日時を指定してください。');
            return;
        }
        $this->run('create_draft', null, function () use ($service, $start): array {
            $event = $service->createDraft($this->eventKey, $this->eventName, $start);
            $this->reset('eventKey', 'startsAt');
            return ['event_id' => $event->id, 'message' => '開催の下書きを保存しました。承認・開催予約は行っていません。'];
        });
    }

    public function operate(int $eventId, int $version, string $action, NationRaidOperationsService $service): void
    {
        $this->resetErrorBag();
        if ($action === 'pause') {
            $this->pauseReason = trim($this->pauseReason);
            $this->validate(['pauseReason' => ['required', 'string', 'max:160']]);
        }
        $this->run($action, $eventId, function () use ($service, $eventId, $version, $action): array {
            $event = $service->operate(Auth::user(), $eventId, $version, $action, $this->pauseReason);
            return ['event_id' => $event->id, 'state_version' => $event->state_version, 'message' => match ($action) {
                'schedule' => '開催予約を保存しました。', 'activate' => 'イベント開始を確認しました。',
                'pause' => '新規出撃を停止しました。確保済みの精算・返却は継続します。',
                'resume' => '出撃受付を再開しました。', 'close' => '終了処理へ移行しました。精算・返却・系譜の確認後に戦果を確定してください。',
                'cancel' => '開始前の開催を取り消しました。履歴は保持しています。',
            }];
        });
    }

    public function recoverExpiredSorties(int $eventId, NationRaidSettlementService $settlement): void
    {
        $this->run('recover_expired_sorties', $eventId, function () use ($eventId, $settlement): array {
            NationRaidEvent::query()->findOrFail($eventId);
            $result = $settlement->recoverExpired(eventId: $eventId);
            return [...$result, 'message' => "期限切れ出撃の返却 {$result['refunded']}件 / 回収保留 {$result['failed']}件。"];
        });
    }

    public function retryLineages(int $eventId, NationRaidDailyLineageService $lineages): void
    {
        $this->run('retry_lineages', $eventId, function () use ($eventId, $lineages): array {
            $event = NationRaidEvent::query()->findOrFail($eventId);
            $count = 0;
            for ($day = 1; $day <= 7; $day++) {
                if ($lineages->finalizeDay($event, $day) === null) {
                    break;
                }
                $count++;
            }
            return ['determined_days' => $count, 'message' => "日次系譜を確認しました（{$count}日分確定）。切替前・精算待ちは保留します。"];
        });
    }

    public function render(NationRaidOperationsService $service)
    {
        return view('livewire.admin.nation-raid-operations-manager', $service->dashboardData())->layout('components.layouts.admin');
    }

    private function run(string $action, ?int $eventId, \Closure $callback): void
    {
        session()->forget(['status', 'error']);
        try {
            $result = $callback();
            session()->flash('status', $result['message']);
            unset($result['message']);
            $this->audit($action, $eventId, ($result['failed'] ?? 0) > 0 ? 'partial' : 'success', $result);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            session()->flash('error', $exception->getMessage());
            $this->audit($action, $eventId, 'rejected');
        } catch (\Throwable $exception) {
            report($exception);
            session()->flash('error', '処理を完了できませんでした。状態とログを確認してください。');
            $this->audit($action, $eventId, 'failed', ['error_class' => $exception::class]);
        }
    }

    private function audit(string $action, ?int $eventId, string $result, array $context = []): void
    {
        Log::notice('Nation raid admin operation', [
            'admin_user_id' => Auth::id(), 'event_id' => $eventId, 'action' => $action, 'result' => $result, ...$context,
        ]);
    }
}
