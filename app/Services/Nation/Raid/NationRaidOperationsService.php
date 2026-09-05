<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidBattleResult;
use App\Models\NationRaidBossCycle;
use App\Models\NationRaidDailyLineageSnapshot;
use App\Models\NationRaidEvent;
use App\Models\User;
use App\Services\Nation\CompetitionEventCoordinatorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final readonly class NationRaidOperationsService
{
    public function __construct(private NationRaidEventService $events, private CompetitionEventCoordinatorService $coordinator) {}

    /** 管理画面から許可する状態操作のみ。承認・値の直接編集は公開しない。 */
    public function operate(User $admin, int $eventId, int $expectedVersion, string $action, string $reason = ''): NationRaidEvent
    {
        throw_unless($admin->role === 'admin', \DomainException::class, '管理者だけが操作できます。');
        throw_if($action === 'finalize', \DomainException::class, '戦果・報酬の最終確定は運営CLIから実行してください。');
        throw_unless(in_array($action, ['schedule', 'activate', 'pause', 'resume', 'close', 'cancel'], true),
            \DomainException::class, 'この操作は利用できません。');

        return DB::transaction(function () use ($eventId, $expectedVersion, $action, $reason): NationRaidEvent {
            $this->coordinator->lock();
            $event = NationRaidEvent::query()->whereKey($eventId)->lockForUpdate()->firstOrFail();
            throw_unless($event->state_version === $expectedVersion, \DomainException::class,
                '状態が更新されています。最新の表示を確認してから再操作してください。');
            if ($action === 'resume') {
                throw_unless(now()->gte($event->starts_at) && now()->lt($event->ends_at),
                    \DomainException::class, '期間外のイベントは再開できません。');
            }
            return match ($action) {
                // 画面から過去の告知日時を入力して72時間条件をすり抜けない。
                'schedule' => $this->events->schedule($event, now()),
                'activate' => $this->events->activate($event),
                'pause' => $this->events->pauseSorties($event, $reason),
                'resume' => $this->events->resumeSorties($event),
                'close' => $this->events->beginFinalization($event),
                'cancel' => $this->events->cancelBeforeStart($event),
            };
        }, 3);
    }

    /** Read-only。画面表示でeventの生成・開始・返却を実行しない。 */
    public function dashboardData(): array
    {
        $ready = collect(['nation_raid_events', 'nation_raid_boss_cycles', 'nation_raid_battle_results', 'nation_raid_daily_lineage_snapshots'])
            ->every(fn ($table) => Schema::hasTable($table));
        $flags = [
            ['国家対抗レイド公開', (bool) config('features.nation_competitive_raid_enabled'), true],
            ['国家コミュニティ', (bool) config('features.nation_community_enabled'), true],
            ['国家発展', (bool) config('features.nation_development_enabled'), true],
            ['国家戦', (bool) config('features.nation_war_enabled'), false],
        ];
        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources'] as $key) {
            $flags[] = ['戦技v2 '.$key, (bool) config('battle.job_art_v2.'.$key), true];
        }
        if (! $ready) {
            return ['schemaReady' => false, 'events' => [], 'pending' => [], 'flags' => $flags];
        }

        // ruleset/全20turn summaryを一覧へロードしない。稼働中優先の20イベントと未回収20件に限定。
        $events = NationRaidEvent::query()->select([
            'id', 'event_key', 'name', 'status', 'state_version', 'starts_at', 'ends_at', 'announced_at',
            'activated_at', 'sorties_paused_at', 'sorties_pause_reason', 'finalized_at', 'current_cycle_no',
            'balance_approved_at', 'balance_approved_by_user_id', 'balance_approval_reference', 'ruleset_hash',
        ])->withCount([
            'battleResults as resolved_count' => fn ($q) => $q->where('status', NationRaidBattleResult::STATUS_RESOLVED),
            'battleResults as pending_count' => fn ($q) => $q->whereIn('status', [NationRaidBattleResult::STATUS_STARTED, NationRaidBattleResult::STATUS_ABORTED]),
            'battleResults as stale_count' => fn ($q) => $q->whereIn('status', [NationRaidBattleResult::STATUS_STARTED, NationRaidBattleResult::STATUS_ABORTED])->where('resolution_deadline_at', '<=', now()),
            'battleResults as refunded_count' => fn ($q) => $q->where('status', NationRaidBattleResult::STATUS_REFUNDED),
        ])->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'finalizing' THEN 1 WHEN 'scheduled' THEN 2 WHEN 'draft' THEN 3 ELSE 4 END")
            ->latest('id')->limit(20)->get();
        $ids = $events->modelKeys();
        $cycles = NationRaidBossCycle::query()->whereIn('event_id', $ids)
            ->whereHas('event', fn ($q) => $q->whereColumn('nation_raid_events.current_cycle_no', 'nation_raid_boss_cycles.cycle_no'))
            ->get(['event_id', 'cycle_no', 'stage_no', 'echo_no', 'current_hp', 'max_hp']);
        $votes = NationRaidDailyLineageSnapshot::query()->whereIn('event_id', $ids)
            ->whereNotNull('determined_at')->select('event_id')->selectRaw('COUNT(*) as aggregate')->groupBy('event_id')->pluck('aggregate', 'event_id');
        $rows = $events->map(function (NationRaidEvent $event) use ($cycles, $votes): array {
            $cycle = $cycles->firstWhere('event_id', $event->id);
            $row = $event->toArray();
            foreach (['starts_at', 'ends_at', 'announced_at', 'balance_approved_at'] as $key) {
                $row[$key] = $event->$key?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '未記録';
            }
            $row['status_label'] = match ($event->status) {
                NationRaidEvent::STATUS_DRAFT => '下書き', NationRaidEvent::STATUS_SCHEDULED => '開催予約',
                NationRaidEvent::STATUS_ACTIVE => $event->sorties_paused_at ? '出撃停止中' : '開催中',
                NationRaidEvent::STATUS_FINALIZING => '終了処理中', NationRaidEvent::STATUS_COMPLETED => '終了確定',
                NationRaidEvent::STATUS_CANCELLED => '開催取消', default => '要確認',
            };
            $row['missed_start'] = $event->status === NationRaidEvent::STATUS_SCHEDULED && now()->gte($event->ends_at);
            $row['cycle'] = $cycle?->toArray();
            $row['lineages_determined'] = (int) ($votes[$event->id] ?? 0);
            $row['actions'] = match ($event->status) {
                NationRaidEvent::STATUS_DRAFT => ['schedule' => '開催予約', 'cancel' => '開催前取消'],
                NationRaidEvent::STATUS_SCHEDULED => ['activate' => '開始を再試行', 'cancel' => '開催前取消'],
                NationRaidEvent::STATUS_ACTIVE => [$event->sorties_paused_at ? 'resume' : 'pause' => $event->sorties_paused_at ? '出撃受付を再開' : '新規出撃を停止', 'close' => '期間終了を処理'],
                default => [],
            };
            return $row;
        })->all();
        $pending = NationRaidBattleResult::query()->whereIn('status', [NationRaidBattleResult::STATUS_STARTED, NationRaidBattleResult::STATUS_ABORTED])
            ->orderBy('resolution_deadline_at')->orderBy('id')->limit(20)
            ->get(['id', 'event_id', 'raid_day', 'status', 'started_at', 'resolution_deadline_at', 'failure_code'])
            ->map(fn ($battle) => [
                'id' => $battle->id, 'event_id' => $battle->event_id, 'day' => $battle->raid_day,
                'status' => $battle->status === NationRaidBattleResult::STATUS_ABORTED ? '返却待ち' : '精算待ち',
                'deadline' => $battle->resolution_deadline_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                'stale' => $battle->resolution_deadline_at->lte(now()),
            ])->all();
        return ['schemaReady' => true, 'events' => $rows, 'pending' => $pending, 'flags' => $flags];
    }
}
