<?php

namespace App\Services\Nation\Raid;

use App\Models\Character;
use App\Models\NationRaidBattleResult as SavedBattle;
use App\Models\NationRaidBossCycle;
use App\Models\NationRaidDailyUsage;
use App\Models\NationRaidEvent;
use App\Models\NationRaidParticipation;
use App\Services\ExplorationStaminaService;
use App\Services\Nation\CompetitionEventCoordinatorService;
use App\Services\Nation\NationRaidBattleTelemetryService;
use Illuminate\Support\Facades\Log;

class NationRaidSettlementService
{
    public function __construct(
        private readonly CompetitionEventCoordinatorService $coordinator,
        private readonly NationRaidTransactionRunner $transactions,
        private readonly NationRaidSharedHpService $hp,
        private readonly NationRaidCoordinationService $coordination,
        private readonly NationRaidBattleViewService $view,
        private readonly ExplorationStaminaService $stamina,
        private readonly NationRaidBattleTelemetryService $telemetry,
        private readonly NationRaidTelemetryAdapter $telemetryAdapter,
    ) {}

    public function resolve(SavedBattle $reference, array $calculation): SavedBattle
    {
        $newlyResolved = false;
        $result = $this->transactions->run(function (int $attempt) use ($reference, $calculation, &$newlyResolved): SavedBattle {
            $newlyResolved = false;
            [$event, $cycle, $participation, $usage, $battle] = $this->lock($reference);
            if ($battle->status !== SavedBattle::STATUS_STARTED) {
                return $battle;
            }
            throw_unless(in_array($event->status, [NationRaidEvent::STATUS_ACTIVE, NationRaidEvent::STATUS_FINALIZING], true)
                && now()->lt($battle->resolution_deadline_at), \DomainException::class, '出撃の確定期限を過ぎました。');
            $admission = $battle->summary['admission'];
            $engine = new NationRaidBattleResult(...$calculation['engine_result']);
            throw_unless(hash_equals($admission['ruleset_hash'], $engine->rulesetHash)
                && $engine->seed === $admission['engine_seed'] && $engine->strategy === $battle->strategy
                && $engine->stage === $admission['encounter']['stage'] && $engine->form === $battle->target_form
                && $engine->bossSetExactIdentities === $admission['player']['boss_set_exact_identities']
                && $engine->calculatedBossDamage >= 0 && $engine->maxOneActionDamage >= 0
                && $engine->maxOneActionDamage <= $engine->calculatedBossDamage
                && $engine->turnsCompleted >= 1 && $engine->turnsCompleted <= 20,
                \LogicException::class, 'Raid calculation does not match its admission snapshot.');

            $coordination = $this->coordination->snapshot(
                $event,
                $participation,
                true,
                (string) $admission['ruleset_hash'],
            );
            $bonus = (int) floor($engine->calculatedBossDamage * $coordination['bonus_rate']);
            $personal = $this->hp->apply($event, $cycle, $engine->calculatedBossDamage, 'personal');
            $linked = $this->hp->apply($event, $personal['cycle'], $bonus, 'coordination');
            $segments = [...$personal['segments'], ...$linked['segments']];
            $current = $linked['cycle'];
            $event->state_version++;
            $event->save();
            $participation->resolved_sorties++;
            $participation->personal_damage_total += $engine->calculatedBossDamage;
            $participation->max_action_damage = max($participation->max_action_damage, $engine->maxOneActionDamage);
            $participation->first_resolved_at ??= now();
            $participation->last_resolved_at = now();
            $participation->save();
            $dailyResolutionNo = (int) $usage->resolved_count + 1;
            $usage->increment('resolved_count');

            // 表示も開始装備snapshot。戦闘終了後の装備変更では書き換えない。
            $displayCharacter = new Character;
            $displayCharacter->setAttribute('id', $battle->character_id);
            $coordination = $this->view->coordinationPresentation($displayCharacter, $coordination);
            $display = $this->view->result($engine, $admission['player'], $admission['encounter'],
                $calculation['player_battle_logs'], $coordination, $event->boss_name,
                $admission['stamina_cost'], $admission['stamina']);
            $target = NationRaidBossCycle::query()->where('event_id', $event->id)->where('cycle_no', $battle->target_cycle_no)->firstOrFail();
            $display['boss_remaining_hp'] = $target->current_hp;
            $display['shared_hp_after'] = ['cycle_no' => $current->cycle_no, 'hp' => $current->current_hp, 'max_hp' => $current->max_hp];
            $battle->fill([
                'status' => SavedBattle::STATUS_RESOLVED,
                'turn_count' => $engine->turnsCompleted, 'end_reason' => $engine->outcome,
                'calculated_damage_total' => $engine->calculatedBossDamage,
                'applied_damage_total' => array_sum(array_column($personal['segments'], 'damage')),
                'coordination_damage_total' => array_sum(array_column($linked['segments'], 'damage')),
                'nation_damage_total' => $participation->is_nation_eligible && $battle->nation_id !== null ? $engine->calculatedBossDamage + $bonus : 0,
                'max_action_damage' => $engine->maxOneActionDamage,
                'turn_log' => $engine->turns, 'damage_segments' => $segments,
                'summary' => [...$battle->summary, 'daily_resolution_no' => $dailyResolutionNo, 'calculation' => $calculation, 'display' => $display],
                'settlement_attempts' => $attempt, 'resolved_at' => now(),
            ])->save();
            $newlyResolved = true;

            return $battle;
        });

        if ($newlyResolved) {
            $this->recordTelemetry($result);
        }

        return $result;
    }

    public function refund(SavedBattle $reference, string $reason = 'stale_started'): SavedBattle
    {
        return $this->transactions->run(function (int $attempt) use ($reference, $reason): SavedBattle {
            [, , , $usage, $battle] = $this->lock($reference);
            if (in_array($battle->status, [SavedBattle::STATUS_RESOLVED, SavedBattle::STATUS_REFUNDED], true)) {
                return $battle;
            }
            throw_unless(in_array($battle->status, [SavedBattle::STATUS_STARTED, SavedBattle::STATUS_ABORTED], true), \LogicException::class, 'Unknown sortie state.');
            $character = Character::query()->whereKey($battle->character_id)->lockForUpdate()->firstOrFail();
            throw_unless((int) $character->user_id === $battle->account_id && $usage->used_count > 0,
                \LogicException::class, 'Raid refund owner or usage does not match.');
            $cost = (int) $battle->summary['admission']['stamina_cost'];
            $battle->fill(['status' => SavedBattle::STATUS_ABORTED, 'aborted_at' => $battle->aborted_at ?? now(), 'failure_code' => $reason])->save();
            $refund = $this->stamina->refundForExplore($character, $cost);
            throw_unless($refund['refunded'] === $cost, \LogicException::class, 'Raid stamina refund is incomplete.');
            $usage->used_count--;
            $usage->refunded_count++;
            $usage->save();
            $battle->fill([
                'status' => SavedBattle::STATUS_REFUNDED,
                'refund_key' => hash('sha256', 'nation-raid-refund:'.$battle->battle_token),
                'refunded_at' => now(), 'settlement_attempts' => $attempt,
            ])->save();

            return $battle;
        });
    }

    /** 回収は公開gateがOFFでも実施する。新規出撃ではなく確保済み消費の返却のみ。 */
    public function recoverExpired(int $limit = 100, ?int $eventId = null): array
    {
        $counts = ['refunded' => 0, 'failed' => 0];
        $stale = SavedBattle::query()->whereIn('status', [SavedBattle::STATUS_STARTED, SavedBattle::STATUS_ABORTED])
            ->when($eventId !== null, fn ($query) => $query->where('event_id', $eventId))
            ->where('resolution_deadline_at', '<=', now())->orderBy('id')->limit($limit)->get();
        foreach ($stale as $battle) {
            try {
                if ($this->refund($battle)->status === SavedBattle::STATUS_REFUNDED) {
                    $counts['refunded']++;
                }
            } catch (\Throwable $exception) {
                $counts['failed']++;
                Log::error('Raid recovery failed', ['battle_token' => $battle->battle_token, 'error_class' => $exception::class]);
            }
        }

        return $counts;
    }

    private function lock(SavedBattle $reference): array
    {
        $this->coordinator->lock();
        $event = NationRaidEvent::query()->whereKey($reference->event_id)->lockForUpdate()->firstOrFail();
        $cycle = NationRaidBossCycle::query()->where('event_id', $event->id)->where('cycle_no', $event->current_cycle_no)->lockForUpdate()->firstOrFail();
        $participation = NationRaidParticipation::query()->whereKey($reference->participation_id)->lockForUpdate()->firstOrFail();
        $usage = NationRaidDailyUsage::query()->where('event_id', $event->id)->where('account_id', $participation->account_id)
            ->where('raid_day', $reference->raid_day)->lockForUpdate()->firstOrFail();
        $battle = SavedBattle::query()->whereKey($reference->id)->lockForUpdate()->firstOrFail();

        return [$event, $cycle, $participation, $usage, $battle];
    }

    private function recordTelemetry(SavedBattle $battle): void
    {
        try {
            $stored = $this->telemetry->record($this->telemetryAdapter->data($battle, $battle->event, $battle->participation));
            if ($stored === null) {
                Log::warning('Raid telemetry unavailable after settlement', ['battle_token_hash' => hash('sha256', $battle->battle_token)]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Raid telemetry failed after settlement', ['battle_token_hash' => hash('sha256', $battle->battle_token), 'error_class' => $exception::class]);
        }
    }
}
