<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\BattleTypeAffinity;
use App\Services\Battle\CleanseResult;
use App\Services\Battle\DamageApplicationResult;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\DamageTrace;
use App\Services\Battle\DirectAttackResolution;
use App\Services\Battle\HitResult;
use App\Services\Battle\ParryResult;

final class JobArtV2DefenseService
{
    public const COUNTER_JOB_ID = 60;
    public const GUARD_JOB_ID = 66;

    public const COUNTER_EVENT_APPLIED = 'applied';
    public const COUNTER_EVENT_REFRESHED = 'refreshed';
    public const COUNTER_EVENT_EXPIRED = 'expired';

    /** @var list<string> */
    public const CLEANSABLE_STATES = JobArtV2CleanseService::HARMFUL_STATES;

    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
        private readonly JobArtV2ResourceService $resourceService,
        private readonly JobArtV2ParryRandomSource $randomSource,
        private readonly ?JobArtV2CleanseService $cleanseService = null,
        private readonly ?JobArtV2DeckRoleResolver $deckRoleResolver = null,
        private readonly ?JobArtV2UltimateCounterplayService $ultimateCounterplayService = null,
        private readonly ?JobArtV2ProgressionService $progressionService = null,
        private readonly ?DamageCalculator $damageCalculator = null,
    ) {}

    public function applyJobArtCast(BattleActor $actor, BattleState $state, Skill $skill): void
    {
        $metadata = $this->trustedMetadata($actor, $skill);
        $sourceActionId = $state->currentSourceActionId();
        if ($metadata === null || $sourceActionId === null) {
            return;
        }

        $lineage = $metadata['lineage_key'] ?? null;
        $supportsCounterStance = (int) $skill->learn_rank === 1
            || ($this->featureGate->usesRank5V6($actor) && (int) $skill->learn_rank === 5);
        if ($lineage === 'counter'
            && $supportsCounterStance
            && isset($metadata['counter_stance_rounds'], $metadata['parry_rate'])
        ) {
            $event = $actor->counterStanceState() === null
                ? self::COUNTER_EVENT_APPLIED
                : self::COUNTER_EVENT_REFRESHED;
            $actor->replaceCounterStanceState(new JobArtV2CounterStanceState(
                remainingRounds: max(1, (int) $metadata['counter_stance_rounds']),
                appliedRound: $state->turnCount,
                parryRate: max(0.0, min(1.0, (float) $metadata['parry_rate'])),
                guardAfterParryRate: max(0.0, min(1.0, (float) ($metadata['guard_after_parry_rate'] ?? 0.0))),
                counterDamageMultiplierAfterParry: max(1.0, (float) ($metadata['counter_damage_multiplier_after_parry'] ?? 1.0)),
            ));
            $state->recordCounterStanceEvent(
                actor: $actor,
                event: $event,
                remainingRounds: $actor->counterStanceState()?->remainingRounds ?? 0,
                sourceActionId: $sourceActionId,
            );
            $rounds = max(1, (int) ($metadata['counter_stance_rounds'] ?? 2));
            $state->addLog('<span class="text-cyan-700 font-bold">'.e($actor->name).' は剣冠の構えを取った！（'.e((string) $rounds).'ターン）</span>');
        }

        if ($lineage !== 'guard' || ! isset($metadata['guard_rate'])) {
            return;
        }

        if (! empty($metadata['cleanse_harmful_states'])) {
            $result = ($this->cleanseService ?? app(JobArtV2CleanseService::class))
                ->cleanse($actor, $state, $sourceActionId);
            if ($result->success) {
                $state->addLog('<span class="text-emerald-700 font-bold">'.e($actor->name).' の有害状態を浄化した！（'.e(implode(' / ', $result->removedStates)).'）</span>');
                $this->resourceService->recordCleanseSuccess($actor, $state);
            }
        }

        $this->applyGuard(
            $actor,
            $state,
            max(0.0, min(1.0, (float) $metadata['guard_rate'])),
            (bool) ($metadata['cleanse_on_guard_mitigation'] ?? false),
            (bool) ($metadata['guard_expires_next_own_action'] ?? false),
        );
    }

    public function resolveDamage(
        BattleState $state,
        DirectAttackResolution $resolution,
        int $damage,
    ): int {
        $damage = max(0, $damage);
        if (! $resolution->direct
            || $resolution->hitResult !== HitResult::HIT
            || $damage <= 0
            || ! $this->featureGate->usesResources($resolution->target)
        ) {
            return $damage;
        }

        $this->markIncomingHudAction($state, $resolution);

        if ($resolution->damageCategory === 'physical') {
            $parriedDamage = $this->resolveParry($state, $resolution, $damage);
            if ($parriedDamage === 0) {
                return 0;
            }
            $damage = $parriedDamage;
        }

        $damage = $this->resolveGuard($state, $resolution, $damage);

        return $this->progression()->absorbHolyWall($resolution->target, $state, $resolution, $damage);
    }

    public function completeDamageApplication(
        BattleState $state,
        DirectAttackResolution $resolution,
        DamageApplicationResult $result,
        bool $gutsTriggered,
    ): void {
        if (! $resolution->direct
            || $resolution->attacker === $resolution->target
            || ! in_array($result->sourceType, [
                DamageSourceType::NORMAL_ATTACK,
                DamageSourceType::JOB_SKILL,
                DamageSourceType::JOB_ART,
                DamageSourceType::OTHER,
            ], true)
            || ! $this->featureGate->usesResources($resolution->target)
        ) {
            return;
        }

        $state->recordDirectAttackDamageOutcome(
            target: $resolution->target,
            sourceActionId: $resolution->sourceActionId,
            actualHpLoss: $resolution->hitResult === HitResult::HIT ? $result->actualHpLoss : 0,
            gutsTriggered: $gutsTriggered,
        );
    }

    public function completeDirectAttackAction(BattleActor $attacker, BattleState $state): void
    {
        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null) {
            return;
        }

        $target = $attacker === $state->player ? $state->enemy : $state->player;
        $outcome = $state->pullDirectAttackDamageOutcome($target, $sourceActionId);
        if ($outcome === null) {
            return;
        }

        $this->grantDirectAttackDamageReceived($target, $state, $outcome);
    }

    /** @return list<array<string, bool|float|int|string>> */
    public function endRound(BattleState $state): array
    {
        $events = [];
        foreach ([$state->player, $state->enemy] as $actor) {
            $stance = $actor->counterStanceState();
            if ($stance === null || ! $stance->advanceAtRoundEnd($state->turnCount)) {
                continue;
            }

            if ($stance->isExpired()) {
                $actor->replaceCounterStanceState(null);
                $state->recordCounterStanceEvent(
                    actor: $actor,
                    event: self::COUNTER_EVENT_EXPIRED,
                    remainingRounds: 0,
                    sourceActionId: 'round:'.$state->turnCount.':counter:'.$state->actorKey($actor),
                );
                $state->addLog('<span class="text-slate-600 font-bold">'.e($actor->name).' の剣冠の構えが解けた。</span>');
            }

            $events[] = [
                'actor_key' => $state->actorKey($actor),
                'event' => $stance->isExpired() ? self::COUNTER_EVENT_EXPIRED : 'advanced',
                'remaining_rounds' => $actor->counterStanceState()?->remainingRounds ?? 0,
                'source_action_id' => 'round:'.$state->turnCount.':counter:'.$state->actorKey($actor),
            ];
        }

        return $events;
    }

    private function resolveParry(
        BattleState $state,
        DirectAttackResolution $resolution,
        int $damage,
    ): int {
        $result = $state->parryResult($resolution->target, $resolution->sourceActionId);
        if ($result === null) {
            $stance = $resolution->target->counterStanceState();
            $eligible = $stance !== null;
            $rate = $stance?->parryRate ?? 0.0;
            $success = $eligible && $this->randomSource->percentRoll() <= (int) round($rate * 100);
            $result = new ParryResult(
                sourceActionId: $resolution->sourceActionId,
                attackerKey: $state->actorKey($resolution->attacker),
                targetKey: $state->actorKey($resolution->target),
                eligible: $eligible,
                rolled: $eligible,
                success: $success,
                rate: $rate,
            );
            $state->recordParryResult($resolution->target, $result);
            if ($success) {
                $resolution->target->markParrySucceededSinceOwnAction();
                $this->resourceService->recordParrySuccess($resolution->target, $state);
                $state->addLog('<span class="text-cyan-700 font-extrabold">'.e($resolution->target->name).' は剣冠の構えで攻撃を受け流した！</span>');
                if (($stance?->guardAfterParryRate ?? 0.0) > 0.0) {
                    $this->applyGuard($resolution->target, $state, $stance->guardAfterParryRate, false, true);
                }
                if (($stance?->counterDamageMultiplierAfterParry ?? 1.0) > 1.0) {
                    $progression = $resolution->target->jobArtV2ProgressionState();
                    $progression->rank5V6CounterDamageMultiplier = max(
                        $progression->rank5V6CounterDamageMultiplier,
                        $stance->counterDamageMultiplierAfterParry,
                    );
                }
                $this->applyRoyalSwordCounter($state, $resolution, $result);
            }
        }

        $after = $result->success ? 0 : $damage;
        $result->recordHit($damage, $after);

        return $after;
    }

    private function applyRoyalSwordCounter(
        BattleState $state,
        DirectAttackResolution $resolution,
        ParryResult $result,
    ): void {
        if (! $resolution->target->jobArtV2ProgressionState()->hasRoundState('royal_sword_formation')) {
            return;
        }

        $power = JobArtV2CrownBalanceCatalog::ROYAL_SWORD_COUNTER_POWER;
        $damage = $this->royalSwordCounterDamage(
            $state,
            $resolution->target,
            $resolution->attacker,
            $power,
        );
        $resolution->attacker->takeDamage($damage);
        $result->recordCounter($power, $damage);
        $state->addLog(sprintf(
            '<span class="text-amber-700 font-extrabold">%s の王冠剣陣が反撃し、%s に %s のダメージ！</span>',
            e($resolution->target->name),
            e($resolution->attacker->name),
            number_format($damage),
        ));

        if ($resolution->attacker->gutsJustTriggered) {
            $resolution->attacker->gutsJustTriggered = false;
            $state->addLog('<span class="text-orange-700 font-extrabold">'.e($resolution->attacker->name).' は不屈の精神で致死ダメージを耐えた！（HP1）</span>');
        }
    }

    private function royalSwordCounterDamage(
        BattleState $state,
        BattleActor $counterActor,
        BattleActor $target,
        int $power,
    ): int {
        $affinityMultiplier = BattleTypeAffinity::multiplier(
            $counterActor->battleTypeWeights,
            $target->battleTypeWeights,
        );

        if ($state->battleType === 'champ') {
            return $this->calculator()->calculateDuelDamage(
                $counterActor,
                $target,
                'physical',
                $power,
                false,
                $affinityMultiplier,
            );
        }

        if (in_array($state->battleType, ['pvp', 'arena_npc'], true)) {
            return $this->calculator()->calculateRankBattleDamage(
                $counterActor,
                $target,
                'physical',
                $power,
                false,
                $affinityMultiplier,
                null,
                null,
                null,
                true,
                1,
            );
        }

        return $this->calculator()->calculatePhysicalDamage(
            $counterActor,
            $target,
            $power,
            false,
        );
    }

    private function resolveGuard(
        BattleState $state,
        DirectAttackResolution $resolution,
        int $damage,
    ): int {
        $trace = $state->damageTrace($resolution->target, $resolution->sourceActionId);
        if ($trace === null) {
            $guard = $resolution->target->jobArtV2GuardState();
            $ultimateGuard = $this->counterplay()->ultimateGuardForIncoming(
                $resolution->target,
                $state,
                $resolution,
            );
            $crownGuard = $this->progression()->crownGuardForIncoming($resolution->target, $resolution);
            $useUltimateGuard = $ultimateGuard !== null
                && ($guard === null || $ultimateGuard->rate >= $guard->rate);
            $bestRate = $useUltimateGuard ? $ultimateGuard->rate : ($guard?->rate ?? 0.0);
            $useCrownGuard = $crownGuard !== null && $crownGuard['rate'] >= $bestRate;
            if (! $useUltimateGuard && ! $useCrownGuard && ($guard === null || $guard->charges < 1)) {
                return $damage;
            }

            $crownGuardKey = null;
            if ($useCrownGuard) {
                $guardRate = $crownGuard['rate'];
                $crownGuardKey = $crownGuard['key'];
            } elseif ($useUltimateGuard) {
                $this->counterplay()->consumeUltimateGuard($resolution->target, $resolution->sourceActionId);
                $guardRate = $ultimateGuard->rate;
            } else {
                $resolution->target->replaceJobArtV2GuardState(null);
                $guardRate = $guard->rate;
                if ($guard->cleanseOnMitigation) {
                    $state->updateJobArtV2RoleAction($resolution->sourceActionId, [
                        'rank5_v6_guard_cleanse' => true,
                    ]);
                }
            }
            $trace = new DamageTrace(
                sourceActionId: $resolution->sourceActionId,
                attackerKey: $state->actorKey($resolution->attacker),
                targetKey: $state->actorKey($resolution->target),
                guardRate: $guardRate,
                guardConsumed: true,
            );
            $state->recordDamageTrace($resolution->target, $trace);
            if ($crownGuardKey !== null) {
                $state->updateJobArtV2RoleAction($resolution->sourceActionId, [
                    'progression_crown_guard_key' => $crownGuardKey,
                ]);
            }
        }

        // Existing direct-damage reduction uses integer truncation and retains
        // the one-point floor. Do not re-run DamageCalculator or its RNG.
        $after = max(1, (int) ($damage * (1 - $trace->guardRate)));
        $trace->recordHit($damage, $after);
        if ($trace->preventedDamage >= 1) {
            $this->resourceService->recordDamageMitigated($resolution->target, $state);
            $this->counterplay()->recordUltimateMitigation(
                $resolution->target,
                $state,
                $resolution,
                $trace->preventedDamage,
            );
            $crownGuardKey = $state->jobArtV2RoleAction($resolution->sourceActionId)['progression_crown_guard_key'] ?? null;
            if (is_string($crownGuardKey)) {
                $this->progression()->consumeCrownGuard(
                    $resolution->target,
                    $state,
                    $crownGuardKey,
                    $trace->preventedDamage,
                );
            }
            $guardCleanse = (bool) ($state->jobArtV2RoleAction($resolution->sourceActionId)['rank5_v6_guard_cleanse'] ?? false);
            if ($guardCleanse && $state->claimJobArtV2RoleEffect(
                $resolution->target,
                'rank5_v6_guard_cleanse',
                $resolution->sourceActionId,
            )) {
                $cleanse = ($this->cleanseService ?? app(JobArtV2CleanseService::class))
                    ->cleanse($resolution->target, $state, $resolution->sourceActionId, false);
                if ($cleanse->success) {
                    $state->addLog('<span class="text-emerald-700 font-bold">'.e($resolution->target->name).' は軽減に成功し、有害状態を1種浄化した！</span>');
                    $this->resourceService->recordCleanseSuccess($resolution->target, $state);
                }
            }
        }

        return $after;
    }

    public function applyGuard(
        BattleActor $actor,
        BattleState $state,
        float $rate,
        bool $cleanseOnMitigation = false,
        bool $expiresAtNextOwnAction = false,
    ): void
    {
        $previous = $actor->jobArtV2GuardState();
        if ($previous === null || $rate >= $previous->rate) {
            $actor->replaceJobArtV2GuardState(new JobArtV2GuardState(
                $rate,
                1,
                $cleanseOnMitigation || ($previous?->cleanseOnMitigation ?? false),
                $expiresAtNextOwnAction || ($previous?->expiresAtNextOwnAction ?? false),
            ));
        }

        $active = $actor->jobArtV2GuardState();
        $state->addLog(sprintf(
            '<span class="text-blue-700 font-bold">%s は次の直接ダメージを %d%% 軽減する加護を得た！</span>',
            e($actor->name),
            (int) round(($active?->rate ?? 0.0) * 100),
        ));
    }

    /** @return array<string, int|float|string|bool>|null */
    private function trustedMetadata(BattleActor $actor, Skill $skill): ?array
    {
        if (! $this->featureGate->usesResources($actor)) {
            return null;
        }

        $resolution = $this->roles()->resolveActor($actor);
        $trusted = $resolution->active
            ? in_array(
                $resolution->roleFor($skill),
                [JobArtV2DeckRole::MAIN, JobArtV2DeckRole::SECONDARY],
                true,
            ) && $resolution->blockReasonFor($skill) === null
                && $this->prototypeCatalog->isTrustedArtProfile($skill)
            : ($actor->jobArtOrigins[(int) $skill->id] ?? 'current') === 'current'
                && $this->prototypeCatalog->isTrustedCurrentJobArt($actor->currentJobId, $skill);
        if (! $trusted) {
            return null;
        }

        return $this->prototypeCatalog->artResourceMetadata($skill);
    }

    private function roles(): JobArtV2DeckRoleResolver
    {
        return $this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class);
    }

    private function markIncomingHudAction(BattleState $state, DirectAttackResolution $resolution): void
    {
        $action = $state->jobArtV2HudAction($resolution->sourceActionId);
        if ($action === null) {
            return;
        }

        $attributes = ['hit_result' => $resolution->hitResult->value];
        if (($action['action_kind'] ?? null) === null) {
            $attributes['action_kind'] = 'incoming_attack';
            $attributes['action_name'] = match ($resolution->actionType) {
                \App\Services\Battle\BattleActionType::JOB_ART => '奥義',
                \App\Services\Battle\BattleActionType::CURRENT_JOB_SKILL => '必殺技',
                default => '通常攻撃',
            };
        }
        $state->updateJobArtV2HudAction($resolution->sourceActionId, $attributes);
    }

    private function counterplay(): JobArtV2UltimateCounterplayService
    {
        return $this->ultimateCounterplayService ?? app(JobArtV2UltimateCounterplayService::class);
    }

    private function progression(): JobArtV2ProgressionService
    {
        return $this->progressionService ?? app(JobArtV2ProgressionService::class);
    }

    /** @param array{actual_hp_loss:int,guts_triggered:bool} $outcome */
    private function grantDirectAttackDamageReceived(
        BattleActor $target,
        BattleState $state,
        array $outcome,
    ): void {
        if ($outcome['actual_hp_loss'] < 1
            || $outcome['guts_triggered']
            || $target->isDead()
        ) {
            return;
        }

        // ResourceServiceが、装備中の反撃系譜資源だけへ1行動1回で加算する。
        $target->markDirectAttackDamageReceivedSinceOwnAction();
        $this->resourceService->recordDirectAttackDamageReceived(
            $target,
            $state,
            false,
        );
    }

    private function calculator(): DamageCalculator
    {
        return $this->damageCalculator ?? app(DamageCalculator::class);
    }
}
