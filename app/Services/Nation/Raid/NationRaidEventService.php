<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidBattleResult;
use App\Models\NationRaidBossCycle;
use App\Models\NationRaidEvent;
use App\Models\User;
use App\Services\Nation\CompetitionEventCoordinatorService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/** Phase 3のevent状態・開催排他・開始snapshotだけを扱う。出撃damageと報酬は扱わない。 */
final class NationRaidEventService
{
    public function __construct(
        private readonly CompetitionEventCoordinatorService $coordinator,
        private readonly NationRaidParticipationSnapshotService $participations,
        private readonly NationRaidRules $rules,
        private readonly NationRaidDailyLineageService $lineages,
        private readonly NationRaidRewardPolicy $rewardPolicy,
    ) {}

    public function createDraft(
        string $eventKey,
        string $name,
        DateTimeInterface $startsAt,
        string $bossName = '十系喰らいの黒天竜 ヴァルグレイド',
    ): NationRaidEvent {
        $eventKey = trim($eventKey);
        $name = trim($name);
        $bossName = trim($bossName);
        throw_unless((bool) preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/', $eventKey), \InvalidArgumentException::class, 'event keyの形式が不正です。');
        throw_if($name === '' || mb_strlen($name) > 80, \InvalidArgumentException::class, 'イベント名は1〜80文字で指定してください。');
        throw_if($bossName === '' || mb_strlen($bossName) > 80, \InvalidArgumentException::class, 'ボス名は1〜80文字で指定してください。');

        $start = CarbonImmutable::instance($startsAt);
        $end = $start->addHours((int) config('nation_raid.event.duration_hours', 168));
        $snapshot = $this->rules->rulesetSnapshot();

        return DB::transaction(function () use ($eventKey, $name, $bossName, $start, $end, $snapshot): NationRaidEvent {
            $event = NationRaidEvent::query()->create([
                'event_key' => $eventKey,
                'name' => $name,
                'boss_name' => $bossName,
                'status' => NationRaidEvent::STATUS_DRAFT,
                'starts_at' => $start,
                'ends_at' => $end,
                'stage_count' => NationRaidRules::MAX_STAGES,
                'cycle_max_hp' => $this->rules->stageMaxHp(1),
                'total_target_hp' => $this->rules->totalTargetHp(),
                'ruleset_version' => (string) $snapshot['version'],
                'ruleset_hash' => $this->rules->rulesetHash(),
                'ruleset_snapshot' => $snapshot,
                'reward_policy_snapshot' => $policy = $this->rewardPolicy->candidate(),
                'reward_policy_hash' => $this->rewardPolicy->hash($policy),
            ]);
            $this->lineages->initializeDraft($event);
            return $event;
        }, 3);
    }

    public function approveBalance(NationRaidEvent $event, User $admin, string $reference): NationRaidEvent
    {
        throw_unless($admin->role === 'admin', \DomainException::class, 'バランス承認は管理者だけが実行できます。');
        $reference = trim($reference);
        throw_if($reference === '' || mb_strlen($reference) > 255, \InvalidArgumentException::class, '承認根拠を1〜255文字で指定してください。');

        return DB::transaction(function () use ($event, $admin, $reference): NationRaidEvent {
            $locked = NationRaidEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            throw_unless($locked->status === NationRaidEvent::STATUS_DRAFT, \DomainException::class, 'draft以外のイベントはバランス承認を変更できません。');
            $this->rewardPolicy->forEvent($locked);
            $locked->update([
                'balance_approved_at' => now(),
                'balance_approved_by_user_id' => $admin->id,
                'balance_approval_reference' => $reference,
                'state_version' => (int) $locked->state_version + 1,
            ]);

            return $locked->refresh();
        }, 3);
    }

    public function schedule(NationRaidEvent $event, DateTimeInterface $announcedAt): NationRaidEvent
    {
        return $this->scheduleWithNotice($event, $announcedAt);
    }

    /** 2026-09-05承認の初回だけ。告知時刻を遡らせず、通常予約の72時間条件を維持する。 */
    public function scheduleInitialLaunch(NationRaidEvent $event, User $admin, string $reference): NationRaidEvent
    {
        throw_unless($admin->role === 'admin', \DomainException::class, '初回開催の予告短縮は管理者だけが実行できます。');
        throw_unless($event->event_key === 'valgreid-inaugural', \DomainException::class, '予告短縮は指定された初回開催だけで利用できます。');
        $reference = trim($reference);
        throw_if($reference === '' || mb_strlen($reference) > 255, \InvalidArgumentException::class, '予告短縮の承認根拠を1〜255文字で指定してください。');

        return $this->scheduleWithNotice($event, CarbonImmutable::now(), $admin, $reference);
    }

    private function scheduleWithNotice(
        NationRaidEvent $event,
        DateTimeInterface $announcedAt,
        ?User $initialLaunchAdmin = null,
        ?string $initialLaunchReference = null,
    ): NationRaidEvent
    {
        $announcement = CarbonImmutable::instance($announcedAt);

        return DB::transaction(function () use ($event, $announcement, $initialLaunchAdmin, $initialLaunchReference): NationRaidEvent {
            $coordinator = $this->coordinator->lock();
            $locked = NationRaidEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === NationRaidEvent::STATUS_SCHEDULED) {
                return $locked;
            }
            throw_unless($locked->status === NationRaidEvent::STATUS_DRAFT, \DomainException::class, 'draft以外のイベントは開催予約できません。');
            $this->assertBalanceApproved($locked);

            // 未開催の旧draftのみ補完可能。開始済みeventの同票順を後付けしない。
            $this->lineages->initializeDraft($locked);

            $leadHours = (int) config('nation_raid.event.announcement_lead_hours', 72);
            if ($initialLaunchAdmin !== null) {
                throw_unless($locked->event_key === 'valgreid-inaugural', \DomainException::class, '初回開催の識別子が一致しません。');
                throw_if(NationRaidEvent::query()->whereKeyNot($locked->id)
                    ->where(fn ($query) => $query->whereNotNull('announced_at')->orWhereNotNull('activated_at'))
                    ->exists(), \DomainException::class, '予告済み・開始済みの開催があるため初回の予告短縮は利用できません。');
                throw_if($announcement->lt($locked->starts_at), \DomainException::class, '初回開催は実際に開始できる時刻に予約してください。');
                throw_unless($announcement->lt($locked->ends_at), \DomainException::class, '終了時刻を過ぎた初回開催は予約できません。');
            }
            throw_if(
                $initialLaunchAdmin === null && $announcement->gt($locked->starts_at->copy()->subHours($leadHours)),
                \DomainException::class,
                "開催予告は開始{$leadHours}時間前までに確定してください。",
            );
            $this->coordinator->assertRaidWindowAvailable($locked->starts_at, $locked->ends_at, $locked->id);

            $locked->update([
                'status' => NationRaidEvent::STATUS_SCHEDULED,
                'announced_at' => $announcement,
                'published_nation_counts_snapshot' => $this->participations->nationCountsAt($announcement),
                'state_version' => (int) $locked->state_version + 1,
            ]);
            $this->coordinator->refreshLocked($coordinator);

            if ($initialLaunchAdmin !== null) {
                $audit = ['event_id' => $locked->id, 'event_key' => $locked->event_key,
                    'admin_user_id' => $initialLaunchAdmin->id, 'approval_reference' => $initialLaunchReference,
                    'announced_at' => $announcement->toIso8601String(),
                    'starts_at' => $locked->starts_at->toIso8601String(),
                    'ends_at' => $locked->ends_at->toIso8601String()];
                DB::afterCommit(static fn () => \Illuminate\Support\Facades\Log::notice('Nation raid initial announcement exception', $audit));
            }

            return $locked->refresh();
        }, 3);
    }

    public function activate(NationRaidEvent $event, ?DateTimeInterface $at = null): NationRaidEvent
    {
        $at = $at ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        return DB::transaction(function () use ($event, $at): NationRaidEvent {
            $coordinator = $this->coordinator->lock();
            $locked = NationRaidEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === NationRaidEvent::STATUS_ACTIVE) {
                return $locked;
            }
            throw_unless($locked->status === NationRaidEvent::STATUS_SCHEDULED, \DomainException::class, '開催予約済みではないイベントは開始できません。');
            throw_if($at->lt($locked->starts_at), \DomainException::class, 'イベント開始時刻へ到達していません。');
            throw_if(! $at->lt($locked->ends_at), \DomainException::class, '終了時刻を過ぎたイベントは開始できません。');
            $this->assertBalanceApproved($locked);
            $this->assertRulesetSnapshotIntegrity($locked);
            $this->assertActivationPreflight();
            $this->coordinator->assertRaidWindowAvailable($locked->starts_at, $locked->ends_at, $locked->id);

            $this->participations->freezeAtStart($locked, $at);
            $this->lineages->recordObservationDay($locked, $at);
            $cycleSnapshot = $this->cycleParameterSnapshot(1, $locked);
            NationRaidBossCycle::query()->firstOrCreate(
                ['event_id' => $locked->id, 'cycle_no' => 1],
                [
                    'cycle_kind' => NationRaidBossCycle::KIND_MAIN,
                    'stage_no' => 1,
                    'echo_no' => null,
                    'max_hp' => $cycleSnapshot['boss']['max_hp'],
                    'current_hp' => $cycleSnapshot['boss']['max_hp'],
                    'current_form' => NationRaidRules::FORM_SEALED_SCALE,
                    'boss_species_key' => $cycleSnapshot['boss']['species_key'],
                    'parameter_snapshot' => $cycleSnapshot,
                    'started_at' => $at,
                ],
            );

            $locked->update([
                'status' => NationRaidEvent::STATUS_ACTIVE,
                'activated_at' => $at,
                'current_cycle_no' => 1,
                'sorties_paused_at' => null,
                'sorties_pause_reason' => null,
                'state_version' => (int) $locked->state_version + 1,
            ]);
            $this->coordinator->refreshLocked($coordinator);

            return $locked->refresh()->load(['cycles', 'participations']);
        }, 3);
    }

    public function pauseSorties(NationRaidEvent $event, string $reason): NationRaidEvent
    {
        $reason = trim($reason);
        throw_if($reason === '' || mb_strlen($reason) > 160, \InvalidArgumentException::class, '停止理由を1〜160文字で指定してください。');

        return DB::transaction(function () use ($event, $reason): NationRaidEvent {
            $coordinator = $this->coordinator->lock();
            $locked = NationRaidEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            throw_unless($locked->status === NationRaidEvent::STATUS_ACTIVE, \DomainException::class, '開催中ではないイベントの出撃は停止できません。');
            if ($locked->sorties_paused_at === null) {
                $locked->update([
                    'sorties_paused_at' => now(),
                    'sorties_pause_reason' => $reason,
                    'state_version' => (int) $locked->state_version + 1,
                ]);
            }
            $this->coordinator->refreshLocked($coordinator);

            return $locked->refresh();
        }, 3);
    }

    public function resumeSorties(NationRaidEvent $event): NationRaidEvent
    {
        return DB::transaction(function () use ($event): NationRaidEvent {
            $coordinator = $this->coordinator->lock();
            $locked = NationRaidEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            throw_unless($locked->status === NationRaidEvent::STATUS_ACTIVE, \DomainException::class, '開催中ではないイベントは再開できません。');
            $this->assertActivationPreflight();
            $this->coordinator->assertRaidWindowAvailable($locked->starts_at, $locked->ends_at, $locked->id);
            if ($locked->sorties_paused_at !== null) {
                $locked->update([
                    'sorties_paused_at' => null,
                    'sorties_pause_reason' => null,
                    'state_version' => (int) $locked->state_version + 1,
                ]);
            }
            $this->coordinator->refreshLocked($coordinator);

            return $locked->refresh();
        }, 3);
    }

    public function beginFinalization(NationRaidEvent $event, ?DateTimeInterface $at = null): NationRaidEvent
    {
        $at = $at ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        return DB::transaction(function () use ($event, $at): NationRaidEvent {
            $coordinator = $this->coordinator->lock();
            $locked = NationRaidEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === NationRaidEvent::STATUS_FINALIZING) {
                return $locked;
            }
            throw_unless($locked->status === NationRaidEvent::STATUS_ACTIVE, \DomainException::class, '開催中ではないイベントは終了処理へ進めません。');
            throw_if($at->lt($locked->ends_at), \DomainException::class, 'イベント終了時刻へ到達していません。');
            $locked->update([
                'status' => NationRaidEvent::STATUS_FINALIZING,
                'sorties_paused_at' => $locked->sorties_paused_at ?? $at,
                'sorties_pause_reason' => $locked->sorties_pause_reason ?? 'イベント終了処理中',
                'finalization_started_at' => $at,
                'state_version' => (int) $locked->state_version + 1,
            ]);
            $this->coordinator->refreshLocked($coordinator);

            return $locked->refresh();
        }, 3);
    }

    /** 未回収確認・日次記録・最終順位・報酬権利を一括確定。公開flagの変更はしない。 */
    public function completeFinalization(NationRaidEvent $event, ?DateTimeInterface $at = null): NationRaidEvent
    {
        $at = $at ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        return DB::transaction(function () use ($event, $at): NationRaidEvent {
            $coordinator = $this->coordinator->lock();
            $locked = NationRaidEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === NationRaidEvent::STATUS_COMPLETED) {
                $standings = app(NationRaidRankingService::class)->standings($locked);
                $this->rewardPolicy->forEvent($locked);
                // 明示CLIの再実行で旧completedの欠損projectionだけ復元。報酬は再発行しない。
                app(NationRaidFinalResultService::class)->storeLocked($locked, $standings);
                return $locked;
            }
            throw_unless($locked->status === NationRaidEvent::STATUS_FINALIZING, \DomainException::class, '終了処理中ではないイベントは確定できません。');
            throw_if($at->lt($locked->ends_at) || $locked->activated_at === null, \DomainException::class, '未開催または期間内のイベントは確定できません。');
            throw_if(
                NationRaidBattleResult::query()->where('event_id', $locked->id)
                    ->whereIn('status', [NationRaidBattleResult::STATUS_STARTED, NationRaidBattleResult::STATUS_ABORTED])->exists(),
                \DomainException::class,
                '未確定または未返却の出撃が残っているためイベントを確定できません。',
            );
            $this->rewardPolicy->forEvent($locked);
            $days = \App\Models\NationRaidDailyLineageSnapshot::where('event_id', $locked->id)->whereNotNull('determined_at')->orderBy('raid_day')->pluck('raid_day')->all();
            throw_unless($days === range(1, 7), \DomainException::class, '日次系譜がすべて確定していません。');
            $standings = app(NationRaidRankingService::class)->standings($locked);
            $standings['is_final'] = true;
            app(NationRaidFinalResultService::class)->storeLocked($locked, $standings);
            app(NationRaidRewardService::class)->prepareLocked($locked, $standings);
            $locked->update([
                'status' => NationRaidEvent::STATUS_COMPLETED,
                'final_standings_snapshot' => $standings,
                'final_standings_hash' => $this->rewardPolicy->hash($standings),
                'finalized_at' => $at,
                'state_version' => (int) $locked->state_version + 1,
            ]);
            $this->coordinator->refreshLocked($coordinator);

            return $locked->refresh();
        }, 3);
    }

    public function cancelBeforeStart(NationRaidEvent $event): NationRaidEvent
    {
        return DB::transaction(function () use ($event): NationRaidEvent {
            $coordinator = $this->coordinator->lock();
            $locked = NationRaidEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === NationRaidEvent::STATUS_CANCELLED) {
                return $locked;
            }
            throw_unless(
                in_array($locked->status, [NationRaidEvent::STATUS_DRAFT, NationRaidEvent::STATUS_SCHEDULED], true),
                \DomainException::class,
                '開始後のイベントは取消できません。出撃停止と終了回収を行ってください。',
            );
            $locked->update([
                'status' => NationRaidEvent::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'state_version' => (int) $locked->state_version + 1,
            ]);
            $this->coordinator->refreshLocked($coordinator);

            return $locked->refresh();
        }, 3);
    }

    private function assertBalanceApproved(NationRaidEvent $event): void
    {
        $this->rewardPolicy->forEvent($event);
        throw_unless(
            $event->balance_approved_at !== null && $event->balance_approved_by_user_id !== null
                && filled($event->balance_approval_reference),
            \DomainException::class,
            'Phase 2のバランス裁定が記録されていないため開催できません。',
        );
    }

    private function assertRulesetSnapshotIntegrity(NationRaidEvent $event): void
    {
        $snapshot = $event->ruleset_snapshot;
        throw_unless(is_array($snapshot), \DomainException::class, 'レイドruleset snapshotを復元できません。');
        $actualHash = hash('sha256', NationRaidJson::encode($snapshot, JSON_UNESCAPED_UNICODE));
        throw_unless(hash_equals((string) $event->ruleset_hash, $actualHash), \DomainException::class, 'レイドruleset snapshotの整合性検証に失敗しました。');
        throw_unless(
            isset($snapshot['version']) && hash_equals((string) $event->ruleset_version, (string) $snapshot['version']),
            \DomainException::class,
            'レイドruleset versionとsnapshotが一致しません。',
        );
    }

    private function assertActivationPreflight(): void
    {
        throw_unless((bool) config('features.nation_competitive_raid_enabled', false), \DomainException::class, '国家対抗レイドの公開gateがOFFです。');
        throw_unless((bool) config('features.nation_community_enabled', false), \DomainException::class, '国家コミュニティgateがOFFです。');
        throw_unless((bool) config('features.nation_development_enabled', false), \DomainException::class, '国家発展gateがOFFです。');
        throw_if((bool) config('features.nation_war_enabled', false), \DomainException::class, '国家戦gateがONの間は国家対抗レイドを開始できません。');

        $required = [
            'dynamic_single',
            'hit_resolution',
            'damage_application',
            'resources',
        ];
        $missing = array_values(array_filter(
            $required,
            fn (string $key): bool => ! (bool) config("battle.job_art_v2.{$key}", false),
        ));
        throw_if($missing !== [], \DomainException::class, '戦技v2の必要flagがOFFです: '.implode(', ', $missing));
    }

    /** @return array<string, mixed> */
    public function cycleParameterSnapshot(int $stage, NationRaidEvent $event): array
    {
        $ruleset = $event->ruleset_snapshot;
        $stages = is_array($ruleset['stages'] ?? null) ? $ruleset['stages'] : [];
        $stageSnapshot = collect($stages)->first(
            fn ($candidate): bool => is_array($candidate) && (int) ($candidate['stage'] ?? 0) === $stage,
        );
        $forms = is_array($ruleset['forms'] ?? null) ? $ruleset['forms'] : [];
        $fixed = is_array($ruleset['fixed'] ?? null) ? $ruleset['fixed'] : [];
        throw_unless(is_array($stageSnapshot), \DomainException::class, "第{$stage}再臨のparameter snapshotがありません。");
        throw_unless(isset($forms[NationRaidRules::FORM_SEALED_SCALE]), \DomainException::class, '第一形態のparameter snapshotがありません。');
        foreach (['boss_max_sp', 'boss_defense', 'boss_spirit', 'boss_agility', 'boss_luck', 'boss_species_key'] as $key) {
            throw_unless(array_key_exists($key, $fixed), \DomainException::class, "レイドruleset snapshotに{$key}がありません。");
        }

        // 旧開催回は保存済みの均一HPを維持。新形式の欠損を旧形式へ黙って戻さない。
        $maxHp = $stageSnapshot['max_hp'] ?? null;
        if (! array_key_exists('max_hp', $stageSnapshot)
            && ($ruleset['version'] ?? null) === 'nation-raid-phase1-v4-equipment-resistance') {
            $maxHp = $event->cycle_max_hp;
        }
        throw_unless(is_int($maxHp) && $maxHp > 0, \DomainException::class, "第{$stage}再臨のHP snapshotが不正です。");

        return [
            'ruleset_hash' => $event->ruleset_hash,
            'stage' => $stageSnapshot,
            'boss' => [
                'max_hp' => $maxHp,
                'max_sp' => (int) $fixed['boss_max_sp'],
                'defense' => (int) $fixed['boss_defense'],
                'spirit' => (int) $fixed['boss_spirit'],
                'agility' => (int) $fixed['boss_agility'],
                'luck' => (int) $fixed['boss_luck'],
                'species_key' => (string) $fixed['boss_species_key'],
            ],
            'forms' => $forms,
        ];
    }
}
