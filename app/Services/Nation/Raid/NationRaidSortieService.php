<?php

namespace App\Services\Nation\Raid;

use App\Models\Character;
use App\Models\NationRaidBattleResult as SavedBattle;
use App\Models\NationRaidBossCycle;
use App\Models\NationRaidDailyLineageSnapshot;
use App\Models\NationRaidDailyUsage;
use App\Models\NationRaidEvent;
use App\Models\NationRaidParticipation;
use App\Services\AuthService;
use App\Services\ExplorationStaminaService;
use App\Services\Nation\CompetitionEventCoordinatorService;
use Illuminate\Support\Facades\Log;
use Throwable;

/** 開始・計算・精算を分離する正式出撃。UIからdamage/形態/再臨は受け取らない。 */
class NationRaidSortieService
{
    public function __construct(
        private readonly CompetitionEventCoordinatorService $coordinator,
        private readonly NationRaidTransactionRunner $transactions,
        private readonly NationRaidParticipationSnapshotService $participations,
        private readonly NationRaidPlayerPreparationService $preparation,
        private readonly NationRaidSortieCombatService $combat,
        private readonly NationRaidSettlementService $settlement,
        private readonly NationRaidBattleViewService $view,
        private readonly ExplorationStaminaService $stamina,
        private readonly AuthService $auth,
        private readonly NationRaidRules $rules,
        private readonly NationRaidStrategyPolicy $strategies,
    ) {}

    public function fight(NationRaidEvent $event, Character $character, string $strategy, string $token): SavedBattle
    {
        // admission自体のcommit応答が失われても同じtokenを照会。startedは再計算せず回収を待つ。
        try {
            [$battle, $created] = $this->start($event, $character, $strategy, $token);
        } catch (\Illuminate\Database\QueryException $exception) {
            $existing = $this->existing($event, $character, $strategy, $token);
            if ($existing) {
                return $existing;
            }
            throw $exception;
        }
        if (! $created || $battle->status !== SavedBattle::STATUS_STARTED) {
            return $battle;
        }

        try {
            // DB retry callbackの外。結果を一度だけ計算し、全settlement attemptで使い回す。
            $calculation = $this->combat->resolve($battle);
            return $this->settlement->resolve($battle, $calculation);
        } catch (Throwable $exception) {
            Log::warning('Raid sortie resolution failed', ['battle_token' => $token, 'error_class' => $exception::class]);
            // COMMIT済みで応答だけ失われたケースもここで再照会する。resolvedは返却しない。
            try {
                $stored = SavedBattle::query()->where('battle_token', $token)->firstOrFail();
                if ($stored->status !== SavedBattle::STATUS_STARTED && $stored->status !== SavedBattle::STATUS_ABORTED) {
                    return $stored;
                }
                return $this->settlement->refund($stored, 'resolution_failed');
            } catch (Throwable $refundError) {
                Log::error('Raid sortie awaits recovery', ['battle_token' => $token, 'error_class' => $refundError::class]);
                return $battle->fresh() ?? $battle;
            }
        }
    }

    /** @return array{SavedBattle,bool} */
    public function start(NationRaidEvent $event, Character $character, string $strategy, string $token): array
    {
        $strategy = $this->strategies->forNewSortie($strategy);
        throw_unless((bool) preg_match('/\A[a-f0-9]{64}\z/', $token), \DomainException::class, '出撃情報を読み直してください。');
        throw_unless(in_array($strategy, $this->rules->strategyKeys(), true), \DomainException::class, '作戦を選んでください。');

        $reservationBegan = hrtime(true);
        $lockWorkMs = null;
        [$battle, $created] = $this->transactions->run(function () use ($event, $character, $strategy, $token, &$lockWorkMs): array {
            $this->coordinator->lock();
            $lockAcquired = hrtime(true);
            $event = NationRaidEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($existing = $this->existing($event, $character, $strategy, $token)) {
                return [$existing, false];
            }
            $this->assertAdmission($event);
            $day = $event->raidDayAt(now());
            throw_unless($day !== null && $day >= 1 && $day <= 7, \DomainException::class, '出撃できる期間ではありません。');
            $cycle = NationRaidBossCycle::query()->where('event_id', $event->id)
                ->where('cycle_no', $event->current_cycle_no)->lockForUpdate()->firstOrFail();
            $participation = $this->participations->createLateEntry($event, $character);
            $usage = NationRaidDailyUsage::query()->firstOrCreate([
                'event_id' => $event->id, 'account_id' => $character->user_id, 'raid_day' => $day,
            ], ['participation_id' => $participation->id]);
            $usage = NationRaidDailyUsage::query()->whereKey($usage->id)->lockForUpdate()->firstOrFail();
            throw_if(SavedBattle::query()->where('event_id', $event->id)->where('account_id', $character->user_id)
                ->whereIn('status', [SavedBattle::STATUS_STARTED, SavedBattle::STATUS_ABORTED])->exists(),
                \DomainException::class, '前の出撃を処理中です。しばらく待ってから確認してください。');
            $used = (int) NationRaidDailyUsage::query()->where('event_id', $event->id)->where('account_id', $character->user_id)->sum('used_count');
            $locked = Character::query()->whereKey($character->id)->lockForUpdate()->firstOrFail();
            $locked->load('user');
            throw_unless((int) $locked->user_id === (int) $participation->account_id
                && ! $locked->isExcludedFromPublicLogs() && ! $locked->is_frozen
                && $locked->user !== null && ! $this->auth->isGuestUser($locked->user),
                \DomainException::class, 'このアカウントは正式出撃できません。');

            $lineage = $this->lineageForDay($event, $day);
            throw_unless(($cycle->parameter_snapshot['ruleset_hash'] ?? null) === $event->ruleset_hash,
                \DomainException::class, 'ボスの開始情報を確認できません。');
            $cost = (int) config('nation_raid.event.sortie_stamina_cost', 10);
            $stamina = $this->stamina->consumeRequired($locked, $cost, "レイドボスへの出撃には探索力{$cost}が必要です。");
            throw_unless($stamina['ok'], \DomainException::class, $stamina['error'] ?? '探索力が足りません。');
            $seed = bin2hex(random_bytes(32));
            $admission = [
                'schema' => 'nation-raid-admission-v2', 'ruleset_hash' => $event->ruleset_hash,
                'cycle_id' => $cycle->id,
                'encounter' => ['stage' => $cycle->stage_no ?? $event->stage_count,
                    'current_hp' => $cycle->current_hp, 'max_hp' => $cycle->max_hp],
                'engine_seed' => (int) hexdec(substr($seed, 0, 7)),
                'stamina_cost' => $cost, 'stamina' => $stamina['stamina'],
            ];
            $battle = SavedBattle::query()->create([
                'event_id' => $event->id, 'participation_id' => $participation->id,
                'battle_token' => $token, 'sortie_seed' => $seed, 'status' => SavedBattle::STATUS_STARTED,
                'account_id' => $participation->account_id, 'character_id' => $locked->id,
                'nation_id' => $participation->is_nation_eligible ? $participation->nation_id : null,
                'raid_day' => $day, 'day_sortie_no' => $usage->used_count + 1, 'event_sortie_no' => $used + 1,
                'target_cycle_no' => $cycle->cycle_no, 'target_cycle_kind' => $cycle->cycle_kind,
                'target_stage_no' => $cycle->stage_no, 'target_echo_no' => $cycle->echo_no,
                'target_form' => $cycle->current_form, 'target_parameter_snapshot' => $cycle->parameter_snapshot,
                'boss_species_key' => $cycle->boss_species_key, 'strategy' => $strategy, 'dominant_lineage' => $lineage,
                'summary' => ['admission' => $admission],
                'started_at' => now(), 'resolution_deadline_at' => now()->addMinutes((int) config('nation_raid.event.resolution_grace_minutes', 10)),
            ]);
            $usage->increment('used_count');
            $lockWorkMs = (hrtime(true) - $lockAcquired) / 1_000_000;

            return [$battle, true];
        });
        if (! $created) {
            return [$battle, false];
        }
        $reservationMs = (hrtime(true) - $reservationBegan) / 1_000_000;
        try {
            return [$this->preparePlayer($battle, $reservationMs, $lockWorkMs), true];
        } catch (Throwable $exception) {
            Log::warning('Raid player preparation failed', ['battle_token_hash' => hash('sha256', $token), 'error_class' => $exception::class]);
            try {
                return [$this->settlement->refund($battle, 'preparation_failed'), true];
            } catch (Throwable $refundError) {
                Log::error('Raid preparation awaits recovery', ['battle_token_hash' => hash('sha256', $token), 'error_class' => $refundError::class]);
                return [$battle->fresh() ?? $battle, false];
            }
        }
    }

    /** 短い予約commit後、battle → Characterだけをlock。coordinator/event/cycleを後から取らない。 */
    private function preparePlayer(SavedBattle $reference, float $reservationMs, ?float $lockWorkMs): SavedBattle
    {
        return $this->transactions->run(function () use ($reference, $reservationMs, $lockWorkMs): SavedBattle {
            $battle = SavedBattle::whereKey($reference->id)->lockForUpdate()->firstOrFail();
            if ($battle->status !== SavedBattle::STATUS_STARTED || isset($battle->summary['admission']['player'])) {
                return $battle;
            }
            throw_if(now()->gt($battle->resolution_deadline_at), \DomainException::class, '出撃準備の期限を過ぎました。');
            $character = Character::whereKey($battle->character_id)->lockForUpdate()->firstOrFail();
            $character->load('user');
            throw_unless((int) $character->user_id === $battle->account_id && $character->user !== null
                && ! $character->is_frozen && ! $character->isExcludedFromPublicLogs() && ! $this->auth->isGuestUser($character->user),
                \DomainException::class, 'このアカウントは正式出撃できません。');
            $summary = $battle->summary;
            throw_unless($this->rules->matchesCombatRulesetHash($summary['admission']['ruleset_hash']),
                \DomainException::class, '戦闘ルールの確認中のため出撃を停止しています。');
            $captureBegan = hrtime(true);
            $player = $this->preparation->capture($character);
            $source = $summary['admission']['encounter'];
            $encounter = $this->view->encounter($source['stage'], $source['current_hp'], $source['max_hp'], $battle->dominant_lineage);
            throw_unless($encounter['form']['key'] === $battle->target_form, \DomainException::class, 'ボスの開始情報を確認できません。');
            if ($battle->target_cycle_kind === NationRaidBossCycle::KIND_ECHO) {
                $encounter['stage_name'] = '残響 '.$battle->target_echo_no;
            }
            $summary['admission']['encounter'] = $encounter;
            $summary['admission']['player'] = $player;
            $summary['admission']['prepared_at'] = now()->toIso8601String();
            // 非決定的な計測値は戦闘ruleset/hashと分離。lock_workはcommit時間を含まない。
            $summary['operational'] = ['reservation_transaction_ms' => $reservationMs,
                'reservation_lock_work_ms' => $lockWorkMs, 'player_capture_ms' => (hrtime(true) - $captureBegan) / 1_000_000];
            $battle->update(['summary' => $summary, 'killer_raw_rate' => $player['killer_raw_rate'],
                'killer_effective_rate' => $player['killer_effective_rate'], 'job_art_slots_snapshot' => $player['boss_set']]);

            return $battle;
        });
    }

    public function assertAdmission(NationRaidEvent $event): void
    {
        throw_unless((bool) config('features.nation_competitive_raid_enabled', false)
            && (bool) config('features.nation_community_enabled', false)
            && (bool) config('features.nation_development_enabled', false)
            && ! (bool) config('features.nation_war_enabled', false), \DomainException::class, '現在レイドへの出撃を停止しています。');
        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources'] as $flag) {
            throw_unless((bool) config("battle.job_art_v2.{$flag}", false), \DomainException::class, '戦闘機能の確認中のため出撃を停止しています。');
        }
        throw_unless($event->acceptsNewSortiesAt(now()), \DomainException::class, '現在出撃を受け付けていません。');
        throw_unless($event->balance_approved_at !== null && filled($event->balance_approval_reference), \DomainException::class, '開催の確認中です。');
        throw_unless($this->rules->matchesCombatRulesetHash($event->ruleset_hash)
            && hash_equals($event->ruleset_hash, hash('sha256', NationRaidJson::encode($event->ruleset_snapshot, JSON_UNESCAPED_UNICODE))),
            \DomainException::class, '戦闘ルールの確認中のため出撃を停止しています。');
    }

    public function lineageForDay(NationRaidEvent $event, int $day): ?string
    {
        if ($day === 1) {
            return null;
        }
        $snapshot = NationRaidDailyLineageSnapshot::query()->where('event_id', $event->id)->where('raid_day', $day)->first();
        // 定期集計と前日出撃の精算を待つ。未確定を「観測日」へ黙って置換しない。
        throw_unless($snapshot?->determined_at !== null, \DomainException::class, '本日の対抗系譜を集計中です。');
        throw_if($snapshot->selected_lineage !== null && $this->rules->counterAction($snapshot->selected_lineage) === null,
            \DomainException::class, '対抗系譜の確認中です。');

        return $snapshot->selected_lineage;
    }

    private function existing(NationRaidEvent $event, Character $character, string $strategy, string $token): ?SavedBattle
    {
        $existing = SavedBattle::query()->where('battle_token', $token)->first();
        if ($existing) {
            throw_unless((int) $existing->event_id === (int) $event->id && $existing->account_id === (int) $character->user_id
                && (int) $existing->character_id === (int) $character->id
                && $this->strategies->matchesReplay($existing->strategy, $strategy),
                \DomainException::class, '出撃情報が一致しません。画面を読み直してください。');
        }

        return $existing;
    }
}
