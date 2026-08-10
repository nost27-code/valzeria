<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\HitResult;
use App\Support\JobArtEffectCatalog;
use Closure;

/**
 * FIX_NOW 22件のv2 runtime semantics。
 *
 * すべてbattle-memory-onlyで、legacy master・DB・継承元Skillを変更しない。
 */
final class JobArtV2ProgressionService
{
    public const BLOCKED_BY_DAMAGE_MITIGATION = 'blocked_by_damage_mitigation';

    private const SUPER_PIERCE_STANCE = 'super_pierce_stance';

    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2ProgressionCatalog $catalog,
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
        private readonly JobArtV2ResourceCatalog $resourceCatalog,
        private readonly JobArtLineageCatalog $lineageCatalog,
        private readonly DamageCalculator $damageCalculator,
    ) {}

    public function enabledFor(BattleActor $actor): bool
    {
        return $this->featureGate->usesResources($actor);
    }

    public function beginAction(BattleActor $actor, BattleState $state, int $sourceActionId): void
    {
        if (! $this->enabledFor($actor)) {
            return;
        }

        $progression = $actor->jobArtV2ProgressionState();
        $state->updateJobArtV2RoleAction($sourceActionId, [
            'progression_actor_key' => $state->actorKey($actor),
            'progression_hp_rate_at_action_start' => $actor->maxHp > 0 ? $actor->hp / $actor->maxHp : 0.0,
            'progression_damage_multiplier' => 1.0,
            'progression_silver_shield_ready_generation_at_action_start' => $progression->silverShieldReady
                ? $progression->silverShieldReadyGeneration
                : 0,
        ]);
    }

    public function markNonJobArtAction(BattleActor $actor, BattleState $state): void
    {
        $target = $this->opponent($actor, $state);
        $sourceActionId = $state->currentSourceActionId();
        if ((! $this->enabledFor($actor) && ! $this->enabledFor($target))
            || $sourceActionId === null
            || ! $state->claimJobArtV2RoleEffect($actor, 'progression_non_job_art_action', $sourceActionId)
        ) {
            return;
        }

        $this->recordActionCategory($actor, $target, 'attack');
    }

    public function beginJobArtCast(BattleActor $actor, BattleState $state, Skill $skill): void
    {
        $metadata = $this->catalog->forArt($skill);
        $sourceActionId = $state->currentSourceActionId();
        if ($metadata === null || $sourceActionId === null || ! $this->enabledFor($actor)) {
            return;
        }

        $sameLineage = $this->isCurrentOrSameLineage($actor, $skill);
        $target = $this->opponent($actor, $state);
        $targetState = $target->jobArtV2ProgressionState();
        $actorState = $actor->jobArtV2ProgressionState();
        $jobId = (int) $skill->job_id;
        $rank = (int) $skill->learn_rank;
        $multiplier = 1.0;
        $attributes = [];

        if (($metadata['key'] ?? null) === 'silver_guard_bridge'
            && $this->isCrossLineageInherited($actor, $skill)
            && $actorState->silverShieldReady
        ) {
            // beginJobArtCast is reached only after the activation roll has
            // succeeded and the art is actually committed. Consume before HIT
            // resolution so HIT/MISS/EVADE all share the same one-shot latch.
            $actorState->silverShieldReady = false;
            $attributes['progression_silver_shield_ready_consumed'] = true;
        }

        if ($sameLineage && $jobId === 52 && in_array($rank, [5, 9], true)) {
            $hadStance = $actorState->hasRoundState(self::SUPER_PIERCE_STANCE);
            $attributes['progression_super_pierce_stance'] = $hadStance;
            if ($rank === 9 && $hadStance) {
                $multiplier *= 1.15;
            }
        }

        if ($sameLineage && $jobId === 54 && $rank === 1) {
            $markCount = $this->huntingMarkCount($target, $actor);
            $attributes['progression_hunt_marks_at_start'] = $markCount;
            if ($markCount === 0) {
                $multiplier *= 1.15;
            }
        }
        if ($sameLineage && $jobId === 54 && $rank === 9) {
            $multiplier *= $actorState->huntRankFiveSealSucceeded ? 1.20 : 0.80;
        }

        if ($sameLineage && in_array($jobId, [54, 64], true) && $rank === 9) {
            $this->consumeHuntingMarks($target, $actor, 2);
        }
        if ($sameLineage && in_array($jobId, [54, 64], true) && $rank === 5) {
            $this->consumeHuntingMarks($target, $actor, 1);
        }

        if ($sameLineage && $jobId === 58 && $rank === 9) {
            $lowHp = $actor->maxHp > 0 && $actor->hp / $actor->maxHp <= 0.35;
            $attributes['progression_low_hp_at_start'] = $lowHp;
            if ($lowHp) {
                $multiplier *= 1.20;
            }
            $this->consumeBreakMarks($target, $actor, 3);
        }

        if ($sameLineage && $jobId === 68 && in_array($rank, [5, 9], true)) {
            $attributes['progression_zanshin_at_start'] = $actorState->zanshinAvailable;
        }

        $current = $state->jobArtV2RoleAction($sourceActionId);
        $attributes['progression_damage_multiplier'] = max(
            0.0,
            (float) ($current['progression_damage_multiplier'] ?? 1.0) * $multiplier,
        );
        $state->updateJobArtV2RoleAction($sourceActionId, $attributes);
    }

    public function applyForExecution(
        BattleActor $actor,
        BattleActor $target,
        BattleState $state,
        Skill $sourceSkill,
        Skill $executionSkill,
    ): void {
        $metadata = $this->catalog->forArt($sourceSkill);
        if ($metadata === null || ! $this->enabledFor($actor)) {
            return;
        }

        $sameLineage = $this->isCurrentOrSameLineage($actor, $sourceSkill);
        $jobId = (int) $sourceSkill->job_id;
        $rank = (int) $sourceSkill->learn_rank;

        if ($jobId === 79 && $rank === 5) {
            $this->replaceTemplate($executionSkill, 'PHYSICAL_DAMAGE', true);
        }
        if ($sameLineage && $jobId === 33 && $rank === 1) {
            $this->replaceTemplate($executionSkill, 'V2_ROLE_EFFECT_ONLY', true);
            $executionSkill->power = 0;
            $executionSkill->power_multiplier = 0;
            $executionSkill->hit_count = 0;
        }
        if ($sameLineage && $jobId === 55) {
            $this->replaceTemplate($executionSkill, 'PHYSICAL_DAMAGE', false);
            if ($rank === 9) {
                $executionSkill->setAttribute('sure_hit', true);
            }
        }
        if ($jobId === 67) {
            // Crown transmute replaces the generic Gold/Drop fallback. Cross
            // lineage retains only the source damage and no foreign mechanic.
            $this->replaceTemplate($executionSkill, 'MAGICAL_DAMAGE', true);
        }
        if ($jobId === 68) {
            $this->replaceTemplate($executionSkill, 'PHYSICAL_DAMAGE', true);
        }
        if ($sameLineage && $jobId === 58 && $rank === 5) {
            $this->replaceTemplate($executionSkill, 'MULTI_HIT', false);
            $executionSkill->hit_count = 3;
        }

        if ($sameLineage
            && in_array((int) $sourceSkill->learn_rank, [5, 9], true)
            && ($this->lineageCatalog->forArt($sourceSkill)['lineage_key'] ?? null) === 'aim'
            && $this->hasPreparedEffectForAction($actor, $state, 'magic_aim_prep')
        ) {
            $this->applyAdaptiveAimRoute($actor, $target, $state, $sourceSkill, $executionSkill);
        }
    }

    public function completeJobArtCast(
        BattleActor $actor,
        BattleActor $target,
        BattleState $state,
        Skill $skill,
        ?HitResult $hitResult,
    ): void {
        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null
            || (! $this->enabledFor($actor) && ! $this->enabledFor($target))
            || ! $state->claimJobArtV2RoleEffect($actor, 'progression_complete', $sourceActionId)
        ) {
            return;
        }

        $category = $this->actionCategory($skill);
        $this->recordActionCategory($actor, $target, $category);

        $metadata = $this->catalog->forArt($skill);
        if ($metadata === null || ! $this->enabledFor($actor)) {
            return;
        }

        $sameLineage = $this->isCurrentOrSameLineage($actor, $skill);
        $jobId = (int) $skill->job_id;
        $rank = (int) $skill->learn_rank;
        $landed = $hitResult === null || $hitResult->landed();
        $actorState = $actor->jobArtV2ProgressionState();
        $targetState = $target->jobArtV2ProgressionState();

        if ($jobId === 79 && $rank === 5 && $sameLineage) {
            $inheritanceRate = max(0.0, (float) ($actor->jobArtRates[(int) $skill->id] ?? 1.0));
            $rate = 0.15 * $inheritanceRate;
            $actor->replaceJobArtV2TimedEffect(new JobArtV2TimedEffectState(
                key: 'silver_guard_bridge',
                statModifiers: ['def' => $rate, 'spr' => $rate],
                appliedRound: $state->turnCount,
                remainingRounds: 2,
                sourceActionId: $sourceActionId,
                sourceSkillId: (int) $skill->id,
                removable: true,
                strength: $rate * 100,
            ));
        }

        if ($sameLineage && $rank === 1 && $jobId === 22) {
            $this->applyPreparedEffect($actor, $state, $skill, 'magic_aim_prep', 1.0, 'aim', [5, 9], 1, 3);
        }
        if ($sameLineage && $rank === 1 && $jobId === 33 && $this->hasDefensivePreparation($target)) {
            $this->applyPreparedEffect($actor, $state, $skill, 'break_focus', 1.15, 'break', [5, 9], 1, 3);
        }
        if ($sameLineage && $rank === 1 && $jobId === 98) {
            $this->applyPreparedEffect($actor, $state, $skill, 'split_pierce', 1.0, 'pierce', [5], 2, 5);
        }

        if ($sameLineage && $jobId === 52) {
            if ($rank === 1) {
                $actorState->applyRoundState(self::SUPER_PIERCE_STANCE, 3, $state->turnCount);
            } elseif ($rank === 5 && (bool) ($state->jobArtV2RoleAction()['progression_super_pierce_stance'] ?? false)) {
                $actorState->applyRoundState(self::SUPER_PIERCE_STANCE, 1, $state->turnCount);
            } elseif ($rank === 9 && (bool) ($state->jobArtV2RoleAction()['progression_super_pierce_stance'] ?? false)) {
                $actorState->consumeRoundState(self::SUPER_PIERCE_STANCE);
            }
        }
        if ($sameLineage && $jobId === 62 && $rank === 5) {
            $actorState->pierceCrownRankFiveUsed = true;
        }

        if ($sameLineage && $landed && in_array($jobId, [54, 64], true)) {
            if ($rank === 1) {
                $this->addHuntingMark($target, $actor);
                if ($jobId === 64) {
                    $actorState->observedActionCategory = $targetState->lastActionCategory;
                }
            } elseif ($rank === 5) {
                $category = $targetState->lastActionCategory ?? 'attack';
                $this->reserveSeal($actor, $target, $jobId, $category, $jobId === 64, $state->turnCount);
            } elseif ($jobId === 64 && $rank === 9) {
                $category = $targetState->firstActionCategory
                    ?? $actorState->observedActionCategory
                    ?? $targetState->lastActionCategory
                    ?? 'attack';
                $this->reserveSeal($actor, $target, 64, $category, false, $state->turnCount);
            }
        }

        if ($sameLineage && $landed && $jobId === 67) {
            $targetResource = $this->resourceCatalog->forActor($target)['resource_key'] ?? null;
            if (is_string($targetResource) && $targetResource !== '') {
                $ownerKey = $this->actorKey($actor);
                if ($rank === 1) {
                    $targetState->resourceSuppressions[$ownerKey] = [
                        'owner' => $actor,
                        'resource_key' => $targetResource,
                        'remaining_gains' => 1,
                        'compensation_armed' => false,
                        'compensation_actions' => 0,
                        'compensation_seen_gain' => false,
                        'refund_points' => 0,
                        'created_source_action_id' => $sourceActionId,
                    ];
                } elseif ($rank === 5 && isset($targetState->resourceSuppressions[$ownerKey])) {
                    $targetState->resourceSuppressions[$ownerKey]['compensation_armed'] = true;
                    $targetState->resourceSuppressions[$ownerKey]['compensation_actions'] = 2;
                    $targetState->resourceSuppressions[$ownerKey]['compensation_seen_gain'] = false;
                    $targetState->resourceSuppressions[$ownerKey]['refund_points'] = 2;
                    $targetState->resourceSuppressions[$ownerKey]['created_source_action_id'] = $sourceActionId;
                } elseif ($rank === 9) {
                    $targetState->resourceSuppressions[$ownerKey] = [
                        'owner' => $actor,
                        'resource_key' => $targetResource,
                        'remaining_gains' => 2,
                        'compensation_armed' => false,
                        'compensation_actions' => 0,
                        'compensation_seen_gain' => false,
                        'refund_points' => 0,
                        'created_source_action_id' => $sourceActionId,
                    ];
                    $actorState->transmuteCrownRankNineUsed = true;
                }
            }
        }

        if ($sameLineage && $landed && in_array($jobId, [58, 68], true)) {
            if ($jobId === 58 && in_array($rank, [1, 5], true)) {
                $this->addBreakMark($target, $actor, false);
            }
            if ($jobId === 68 && $rank === 1) {
                $this->addBreakMark($target, $actor, true);
            }
            if ($jobId === 68
                && in_array($rank, [5, 9], true)
                && (bool) ($state->jobArtV2RoleAction()['progression_zanshin_at_start'] ?? false)
            ) {
                $actorState->zanshinAvailable = false;
                $this->addBreakMark($target, $actor, true);
            }
        }

        if ($sameLineage && $landed && $jobId === 59) {
            $actorState->commandLastSuccessfulCategory = $category;
            if ($rank === 1) {
                $actorState->commandActivationBonus = 15;
            } elseif ($rank === 5) {
                $actorState->commandDifferentCategoryFrom = $category;
                $actorState->commandActivationBonus = 20;
            } elseif ($rank === 9) {
                $actorState->commandGuaranteeNextArt = true;
                $actorState->commandDifferentCategoryFrom = $category;
                $actorState->commandRankNineUses++;
            }
        }
        if ($sameLineage && $jobId === 69) {
            if ($landed) {
                $actorState->commandLastSuccessfulCategory = $category;
            }
            if ($rank === 1) {
                $actorState->initiativeRerollNextRound = true;
                $actorState->commandRankOneCooldownUntilRound = $state->turnCount + 3;
            } elseif ($rank === 5) {
                $actorState->commandActivationBonus = 20;
            } elseif ($rank === 9) {
                $actorState->initiativeForceFirstNextRound = true;
                $actorState->commandPrioritizeCurrentArt = true;
                $actorState->commandRankNineUses++;
            }
        }
    }

    public function modifyJobArtDamage(BattleActor $actor, BattleState $state, Skill $skill, int $damage): int
    {
        if (! $this->enabledFor($actor) || $damage <= 0) {
            return max(0, $damage);
        }

        $context = $state->jobArtV2RoleAction();
        if (($context['progression_actor_key'] ?? null) !== $state->actorKey($actor)) {
            return $damage;
        }

        return max(0, (int) floor($damage * max(0.0, (float) ($context['progression_damage_multiplier'] ?? 1.0))));
    }

    public function eligibilityBlockReason(BattleActor $actor, BattleState $state, Skill $skill): ?string
    {
        if (! $this->enabledFor($actor)) {
            return null;
        }

        $metadata = $this->catalog->forArt($skill);
        if (($metadata['key'] ?? null) === 'silver_guard_bridge'
            && $this->isCrossLineageInherited($actor, $skill)
        ) {
            return $actor->jobArtV2ProgressionState()->silverShieldReady
                ? null
                : self::BLOCKED_BY_DAMAGE_MITIGATION;
        }

        if (! $this->isCurrentOrSameLineage($actor, $skill)) {
            return null;
        }

        $jobId = (int) $skill->job_id;
        $rank = (int) $skill->learn_rank;
        $target = $this->opponent($actor, $state);
        if (in_array($jobId, [54, 64], true) && $rank === 5 && $this->huntingMarkCount($target, $actor) < 1) {
            return 'blocked_by_hunting_mark';
        }
        if (in_array($jobId, [54, 64], true) && $rank === 9 && $this->huntingMarkCount($target, $actor) < 2) {
            return 'blocked_by_hunting_mark';
        }
        if ($jobId === 58 && $rank === 9 && $this->breakMarkCount($target, $actor) < 3) {
            return 'blocked_by_break_mark';
        }
        if ($jobId === 67 && $rank === 9 && $actor->jobArtV2ProgressionState()->transmuteCrownRankNineUsed) {
            return 'blocked_by_use_limit';
        }
        if ($jobId === 69 && $rank === 1 && $state->turnCount < $actor->jobArtV2ProgressionState()->commandRankOneCooldownUntilRound) {
            return 'blocked_by_internal_cooldown';
        }
        if (in_array($jobId, [59, 69], true) && $rank === 9 && $actor->jobArtV2ProgressionState()->commandRankNineUses >= ($jobId === 59 ? 2 : 1)) {
            return 'blocked_by_use_limit';
        }

        return null;
    }

    /**
     * Existing damage_mitigated is emitted only for direct HIT damage after
     * an actual one-point-or-more reduction. It is therefore the canonical
     * source for the cross-lineage White Silver Shield latch.
     */
    public function recordQualifyingDamageMitigated(BattleActor $actor, BattleState $state): void
    {
        $sourceActionId = $state->currentSourceActionId();
        if (! $this->enabledFor($actor)
            || $sourceActionId === null
            || ! $state->claimJobArtV2RoleEffect($actor, 'progression_silver_shield_ready', $sourceActionId)
        ) {
            return;
        }

        $progression = $actor->jobArtV2ProgressionState();
        if ($progression->silverShieldReady) {
            return;
        }

        $progression->silverShieldReady = true;
        $progression->silverShieldReadyGeneration++;
    }

    public function consumeSealIfBlocked(BattleActor $actor, BattleState $state, Skill $skill): bool
    {
        if (! $this->enabledFor($actor)) {
            return false;
        }

        $actorState = $actor->jobArtV2ProgressionState();
        $category = $this->actionCategory($skill);
        foreach ($actorState->sealReservations as $ownerKey => $reservation) {
            if ($reservation['category'] !== $category) {
                continue;
            }

            unset($actorState->sealReservations[$ownerKey]);
            $actorState->sealCooldowns[$ownerKey][$category] = 2;
            if ($reservation['owner_job_id'] === 54) {
                $reservation['owner']->jobArtV2ProgressionState()->huntRankFiveSealSucceeded = true;
            }
            $state->addLog('<span class="text-slate-700 font-bold">'.e($actor->name).' の '.e((string) $skill->name).' は封じられた！</span>');

            return true;
        }

        return false;
    }

    /** @param list<Skill> $candidates @return list<Skill> */
    public function orderCandidates(BattleActor $actor, array $candidates): array
    {
        if (! $this->enabledFor($actor)) {
            return $candidates;
        }

        $progression = $actor->jobArtV2ProgressionState();
        if (! $progression->commandPrioritizeCurrentArt && $progression->commandDifferentCategoryFrom === null) {
            return $candidates;
        }

        $indexed = [];
        foreach ($candidates as $index => $candidate) {
            $isCurrent = $this->originFor($actor, $candidate) === 'current';
            $isDifferent = $progression->commandDifferentCategoryFrom !== null
                && $this->actionCategory($candidate) !== $progression->commandDifferentCategoryFrom;
            $priority = ($progression->commandPrioritizeCurrentArt && $isCurrent ? 2 : 0)
                + ($isDifferent && $isCurrent ? 1 : 0);
            $indexed[] = ['skill' => $candidate, 'priority' => $priority, 'index' => $index];
        }
        usort($indexed, static fn (array $left, array $right): int => ($right['priority'] <=> $left['priority']) ?: ($left['index'] <=> $right['index']));

        return array_values(array_map(static fn (array $row): Skill => $row['skill'], $indexed));
    }

    public function activationRate(BattleActor $actor, Skill $skill, float $baseRate): float
    {
        if (! $this->enabledFor($actor) || $this->originFor($actor, $skill) !== 'current') {
            return $baseRate;
        }

        $progression = $actor->jobArtV2ProgressionState();
        if ($progression->commandGuaranteeNextArt) {
            return 100.0;
        }
        if ($progression->commandActivationBonus <= 0) {
            return $baseRate;
        }
        if ($progression->commandDifferentCategoryFrom !== null
            && $this->actionCategory($skill) === $progression->commandDifferentCategoryFrom
        ) {
            return $baseRate;
        }

        return min(100.0, $baseRate + $progression->commandActivationBonus);
    }

    public function finishActivationAttempt(BattleActor $actor, Skill $skill): void
    {
        if (! $this->enabledFor($actor) || $this->originFor($actor, $skill) !== 'current') {
            return;
        }

        $progression = $actor->jobArtV2ProgressionState();
        if ($progression->commandGuaranteeNextArt
            || $progression->commandActivationBonus > 0
            || $progression->commandPrioritizeCurrentArt
        ) {
            $progression->commandGuaranteeNextArt = false;
            $progression->commandActivationBonus = 0;
            $progression->commandDifferentCategoryFrom = null;
            $progression->commandPrioritizeCurrentArt = false;
        }
    }

    public function resourceCost(BattleActor $actor, ?BattleState $state, Skill $skill, int $cost): int
    {
        if ($cost !== 4
            || $state === null
            || ! $this->isCurrentOrSameLineage($actor, $skill)
            || (int) $skill->learn_rank !== 5
            || ($this->lineageCatalog->forArt($skill)['lineage_key'] ?? null) !== 'pierce'
            || ! $this->hasPreparedEffectForAction($actor, $state, 'split_pierce')
        ) {
            return $cost;
        }

        return 2;
    }

    public function modifyIncomingResourceGain(
        BattleActor $actor,
        string $resourceKey,
        int $gain,
    ): int {
        if (! $this->enabledFor($actor) || $gain <= 0) {
            return max(0, $gain);
        }

        $progression = $actor->jobArtV2ProgressionState();

        foreach ($progression->resourceSuppressions as $ownerKey => $suppression) {
            if ($suppression['resource_key'] !== $resourceKey || $suppression['remaining_gains'] <= 0) {
                continue;
            }

            // A configured gain while already at cap is not an actual gain
            // event. Keep both the suppression and Rank5 compensation armed.
            $available = max(0, $actor->resourceCap($resourceKey) - $actor->getResource($resourceKey));
            if (min($available, $gain) <= 0) {
                return $gain;
            }

            $suppression['remaining_gains']--;
            $suppression['compensation_seen_gain'] = true;
            if ($suppression['remaining_gains'] <= 0) {
                unset($progression->resourceSuppressions[$ownerKey]);
            } else {
                $progression->resourceSuppressions[$ownerKey] = $suppression;
            }

            return (int) floor($gain / 2);
        }

        return $gain;
    }

    public function finishAction(BattleActor $actor, BattleState $state): void
    {
        if (! $this->enabledFor($actor)) {
            return;
        }

        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null
            || ! $state->claimJobArtV2RoleEffect($actor, 'progression_finish_action', $sourceActionId)
        ) {
            return;
        }
        $progression = $actor->jobArtV2ProgressionState();
        $context = $state->jobArtV2RoleAction($sourceActionId);
        $silverShieldGenerationAtStart = (int) (
            $context['progression_silver_shield_ready_generation_at_action_start'] ?? 0
        );
        if ($silverShieldGenerationAtStart > 0
            && $progression->silverShieldReady
            && $progression->silverShieldReadyGeneration === $silverShieldGenerationAtStart
        ) {
            // An activation failure does not consume the latch at selection
            // time. If its fallback (or any other action) completes instead,
            // the one allowed own-action opportunity has now elapsed.
            $progression->silverShieldReady = false;
        }

        foreach ($progression->resourceSuppressions as $ownerKey => $suppression) {
            if (! $suppression['compensation_armed']
                || $suppression['created_source_action_id'] === $sourceActionId
            ) {
                continue;
            }

            if ($suppression['compensation_seen_gain']) {
                $suppression['compensation_armed'] = false;
                $progression->resourceSuppressions[$ownerKey] = $suppression;
                continue;
            }

            $suppression['compensation_actions']--;
            if ($suppression['compensation_actions'] <= 0) {
                $owner = $suppression['owner'];
                $ownerResource = $this->resourceCatalog->forActor($owner);
                if (($ownerResource['resource_key'] ?? null) === 'catalyst') {
                    $owner->configureResource('catalyst', (int) $ownerResource['resource_max_points']);
                    $owner->addResource('catalyst', (int) $suppression['refund_points']);
                }
                $suppression['compensation_armed'] = false;
                $suppression['refund_points'] = 0;
            }
            $progression->resourceSuppressions[$ownerKey] = $suppression;
        }
    }

    public function endRound(BattleState $state): array
    {
        $events = [];
        foreach ([$state->player, $state->enemy] as $actor) {
            if (! $this->enabledFor($actor)) {
                continue;
            }

            $progression = $actor->jobArtV2ProgressionState();
            foreach ($progression->advanceRoundStates($state->turnCount) as $key) {
                $events[] = ['actor_key' => $state->actorKey($actor), 'type' => 'progression', 'key' => $key, 'event' => 'expired'];
            }
            foreach ($progression->sealReservations as $ownerKey => $reservation) {
                if ($state->turnCount <= $reservation['last_round']) {
                    continue;
                }
                $reservation['last_round'] = $state->turnCount;
                $reservation['remaining_rounds']--;
                if ($reservation['remaining_rounds'] <= 0) {
                    unset($progression->sealReservations[$ownerKey]);
                } else {
                    $progression->sealReservations[$ownerKey] = $reservation;
                }
            }
            foreach ($progression->sealCooldowns as $ownerKey => $categories) {
                foreach ($categories as $category => $remaining) {
                    $remaining--;
                    if ($remaining <= 0) {
                        unset($progression->sealCooldowns[$ownerKey][$category]);
                    } else {
                        $progression->sealCooldowns[$ownerKey][$category] = $remaining;
                    }
                }
                if (($progression->sealCooldowns[$ownerKey] ?? []) === []) {
                    unset($progression->sealCooldowns[$ownerKey]);
                }
            }
        }

        return $events;
    }

    public function activeEvasionRate(BattleActor $attacker, BattleActor $defender): float
    {
        if (! $this->enabledFor($defender)) {
            return 0.0;
        }

        return min(0.24, $this->huntingMarkCount($attacker, $defender) * 0.08);
    }

    public function accuracyDeltaPoints(BattleActor $actor, Skill $skill): float
    {
        if (! $this->isCurrentOrSameLineage($actor, $skill) || (int) $skill->job_id !== 55) {
            return 0.0;
        }

        return (int) $skill->learn_rank === 5 ? 5.0 : 0.0;
    }

    public function hasBreakMarks(BattleActor $actor): bool
    {
        return array_sum($actor->jobArtV2ProgressionState()->breakMarks) > 0;
    }

    /** @return list<string> */
    public function purgeBreakMarks(BattleActor $target): array
    {
        $progression = $target->jobArtV2ProgressionState();
        if (array_sum($progression->breakMarks) <= 0) {
            return [];
        }

        foreach ($progression->crownBreakMarks as $ownerKey => $count) {
            if ($count <= 0 || ! isset($progression->breakMarkOwners[$ownerKey])) {
                continue;
            }
            $ownerState = $progression->breakMarkOwners[$ownerKey]->jobArtV2ProgressionState();
            if (! $ownerState->zanshinGrantedThisBattle) {
                $ownerState->zanshinAvailable = true;
                $ownerState->zanshinGrantedThisBattle = true;
            }
        }

        $progression->breakMarks = [];
        $progression->crownBreakMarks = [];
        $progression->breakMarkOwners = [];

        return ['break_mark'];
    }

    public function superPierceStanceActive(BattleActor $actor): bool
    {
        return $actor->jobArtV2ProgressionState()->hasRoundState(self::SUPER_PIERCE_STANCE);
    }

    public function superPierceRateFor(BattleActor $actor, Skill $skill): ?float
    {
        if (! $this->isCurrentOrSameLineage($actor, $skill) || (int) $skill->job_id !== 52) {
            return null;
        }

        $rate = match ((int) $skill->learn_rank) {
            5 => 0.35,
            9 => 0.50,
            default => null,
        };
        if ($rate === null) {
            return null;
        }

        return $this->superPierceStanceActive($actor) ? $rate : 0.0;
    }

    public function crownPierceRankFiveUsed(BattleActor $actor): bool
    {
        return $actor->jobArtV2ProgressionState()->pierceCrownRankFiveUsed;
    }

    /** @return array{rate:float,once_per_battle:bool}|null */
    public function superAimSpPressure(BattleActor $actor, Skill $skill): ?array
    {
        if (! $this->isCurrentOrSameLineage($actor, $skill)
            || (int) $skill->job_id !== 55
            || (int) $skill->learn_rank !== 9
            || $actor->jobArtV2ProgressionState()->aimSuperRankNineSpPressureUsed
        ) {
            return null;
        }

        return ['rate' => 0.05, 'once_per_battle' => true];
    }

    public function markSuperAimSpPressureUsed(BattleActor $actor): void
    {
        $actor->jobArtV2ProgressionState()->aimSuperRankNineSpPressureUsed = true;
    }

    public function adjustInitiative(
        BattleActor $firstCandidate,
        BattleActor $secondCandidate,
        bool $firstCandidateWon,
        Closure $reroll,
    ): bool {
        if (! $this->enabledFor($firstCandidate) && ! $this->enabledFor($secondCandidate)) {
            return $firstCandidateWon;
        }

        $firstState = $firstCandidate->jobArtV2ProgressionState();
        $secondState = $secondCandidate->jobArtV2ProgressionState();
        $firstForce = $firstState->initiativeForceFirstNextRound;
        $secondForce = $secondState->initiativeForceFirstNextRound;
        $firstState->initiativeForceFirstNextRound = false;
        $secondState->initiativeForceFirstNextRound = false;

        if ($firstForce xor $secondForce) {
            $firstState->initiativeRerollNextRound = false;
            $secondState->initiativeRerollNextRound = false;

            return $firstForce;
        }

        $firstReroll = $firstState->initiativeRerollNextRound;
        $secondReroll = $secondState->initiativeRerollNextRound;
        $firstState->initiativeRerollNextRound = false;
        $secondState->initiativeRerollNextRound = false;
        if ($firstReroll && ! $firstCandidateWon) {
            return (bool) $reroll();
        }
        if ($secondReroll && $firstCandidateWon) {
            return (bool) $reroll();
        }

        return $firstCandidateWon;
    }

    /** @return list<string> */
    public function effectTexts(Skill $skill): array
    {
        return $this->catalog->effectTexts($skill);
    }

    private function isCurrentOrSameLineage(BattleActor $actor, Skill $skill): bool
    {
        if (! $this->enabledFor($actor)) {
            return false;
        }

        $origin = $this->originFor($actor, $skill);
        if ($origin === 'current') {
            return $this->prototypeCatalog->isTrustedCurrentJobArt($actor->currentJobId, $skill);
        }

        return $origin === 'inherited' && $this->resourceCatalog->isSameLineageInherited($actor, $skill);
    }

    private function isCrossLineageInherited(BattleActor $actor, Skill $skill): bool
    {
        return $this->originFor($actor, $skill) === 'inherited'
            && ! $this->resourceCatalog->isSameLineageInherited($actor, $skill);
    }

    private function originFor(BattleActor $actor, Skill $skill): string
    {
        return (string) ($actor->jobArtOrigins[(int) $skill->id]
            ?? ((int) $skill->job_id === (int) $actor->currentJobId ? 'current' : 'inherited'));
    }

    private function opponent(BattleActor $actor, BattleState $state): BattleActor
    {
        return $actor === $state->player ? $state->enemy : $state->player;
    }

    private function actorKey(BattleActor $actor): string
    {
        return 'actor:'.spl_object_id($actor);
    }

    private function actionCategory(Skill $skill): string
    {
        return match ((string) $skill->effect_template) {
            'HEAL', 'HEAL_CLEANSE', 'DRAIN' => 'heal',
            'GUARD_BARRIER', 'DAMAGE_GUARD_BARRIER', 'GUTS' => 'guard',
            'SELF_BUFF', 'DAMAGE_BUFF', 'MAGICAL_DAMAGE_BUFF' => 'buff',
            'ENEMY_DEBUFF', 'DAMAGE_DEBUFF' => 'debuff',
            default => 'attack',
        };
    }

    private function recordActionCategory(BattleActor $actor, BattleActor $target, string $category): void
    {
        $actorState = $actor->jobArtV2ProgressionState();
        $actorState->lastActionCategory = $category;
        $actorState->firstActionCategory ??= $category;

        $targetState = $target->jobArtV2ProgressionState();
        $ownerKey = $this->actorKey($target);
        $reservation = $actorState->sealReservations[$ownerKey] ?? null;
        if (is_array($reservation)
            && $reservation['adaptive']
            && $reservation['category'] !== $category
            && ! $targetState->huntCrownRetargetUsed
        ) {
            $reservation['category'] = $category;
            $reservation['adaptive'] = false;
            $actorState->sealReservations[$ownerKey] = $reservation;
            $targetState->huntCrownRetargetUsed = true;
        }
    }

    private function huntingMarkCount(BattleActor $target, BattleActor $owner): int
    {
        return (int) ($target->jobArtV2ProgressionState()->huntingMarks[$this->actorKey($owner)] ?? 0);
    }

    private function addHuntingMark(BattleActor $target, BattleActor $owner): void
    {
        $key = $this->actorKey($owner);
        $state = $target->jobArtV2ProgressionState();
        $state->huntingMarks[$key] = min(3, (int) ($state->huntingMarks[$key] ?? 0) + 1);
    }

    private function consumeHuntingMarks(BattleActor $target, BattleActor $owner, int $count): bool
    {
        $key = $this->actorKey($owner);
        $state = $target->jobArtV2ProgressionState();
        $current = (int) ($state->huntingMarks[$key] ?? 0);
        if ($current < $count) {
            return false;
        }
        $state->huntingMarks[$key] = $current - $count;

        return true;
    }

    private function reserveSeal(
        BattleActor $owner,
        BattleActor $target,
        int $jobId,
        string $category,
        bool $adaptive,
        int $round,
    ): void {
        $ownerKey = $this->actorKey($owner);
        $targetState = $target->jobArtV2ProgressionState();
        if (($targetState->sealCooldowns[$ownerKey][$category] ?? 0) > 0) {
            return;
        }

        $targetState->sealReservations[$ownerKey] = [
            'owner' => $owner,
            'owner_job_id' => $jobId,
            'category' => $category,
            'remaining_rounds' => 3,
            'applied_round' => $round,
            'last_round' => $round,
            'adaptive' => $adaptive,
        ];
    }

    private function breakMarkCount(BattleActor $target, BattleActor $owner): int
    {
        return (int) ($target->jobArtV2ProgressionState()->breakMarks[$this->actorKey($owner)] ?? 0);
    }

    private function addBreakMark(BattleActor $target, BattleActor $owner, bool $crown): void
    {
        $key = $this->actorKey($owner);
        $state = $target->jobArtV2ProgressionState();
        $state->breakMarks[$key] = min(3, (int) ($state->breakMarks[$key] ?? 0) + 1);
        $state->breakMarkOwners[$key] = $owner;
        if ($crown) {
            $state->crownBreakMarks[$key] = min(
                $state->breakMarks[$key],
                (int) ($state->crownBreakMarks[$key] ?? 0) + 1,
            );
        }
    }

    private function consumeBreakMarks(BattleActor $target, BattleActor $owner, int $count): bool
    {
        $key = $this->actorKey($owner);
        $state = $target->jobArtV2ProgressionState();
        $current = (int) ($state->breakMarks[$key] ?? 0);
        if ($current < $count) {
            return false;
        }
        $state->breakMarks[$key] = $current - $count;
        $state->crownBreakMarks[$key] = min((int) ($state->crownBreakMarks[$key] ?? 0), $state->breakMarks[$key]);

        return true;
    }

    private function applyPreparedEffect(
        BattleActor $actor,
        BattleState $state,
        Skill $skill,
        string $key,
        float $multiplier,
        string $lineage,
        array $ranks,
        int $charges,
        int $actionOpportunities,
    ): void {
        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null) {
            return;
        }

        $actor->replaceJobArtV2PreparedEffect(new JobArtV2PreparedEffectState(
            key: $key,
            multiplier: $multiplier,
            appliedRound: $state->turnCount,
            remainingRounds: null,
            charges: $charges,
            sourceActionId: $sourceActionId,
            sourceSkillId: (int) $skill->id,
            targetLineage: $lineage,
            targetRanks: $ranks,
            strictNextAction: false,
            group: $key,
            remainingActionOpportunities: $actionOpportunities,
        ));
    }

    private function hasPreparedEffectForAction(BattleActor $actor, BattleState $state, string $key): bool
    {
        if ($actor->jobArtV2PreparedEffect($key) !== null) {
            return true;
        }

        return in_array($key, (array) ($state->jobArtV2RoleAction()['prepared_effect_keys'] ?? []), true);
    }

    private function hasDefensivePreparation(BattleActor $target): bool
    {
        if ($target->jobArtV2GuardState() !== null || $target->isDefending || $target->damageReductionRate > 0) {
            return true;
        }

        foreach ($target->jobArtV2TimedEffects() as $effect) {
            if (! $effect->isExpired()
                && ((float) ($effect->statModifiers['def'] ?? 0.0) > 0.0
                    || (float) ($effect->statModifiers['spr'] ?? 0.0) > 0.0)
            ) {
                return true;
            }
        }

        return false;
    }

    private function applyAdaptiveAimRoute(
        BattleActor $actor,
        BattleActor $target,
        BattleState $state,
        Skill $sourceSkill,
        Skill $executionSkill,
    ): void {
        $power = max(0, (int) $executionSkill->power);
        $hits = max(1, (int) $executionSkill->hit_count);
        $physical = $this->damageCalculator->estimateJobArtDamage($actor, $target, 'physical', $state->battleType, $power, $hits);
        $magical = $this->damageCalculator->estimateJobArtDamage($actor, $target, 'magical', $state->battleType, $power, $hits);
        $masterRoute = (string) $sourceSkill->damage_type === 'magical' ? 'magical' : 'physical';
        $route = $physical === $magical ? $masterRoute : ($magical > $physical ? 'magical' : 'physical');
        $this->replaceTemplate($executionSkill, $route === 'magical' ? 'MAGICAL_DAMAGE' : 'PHYSICAL_DAMAGE', false);
    }

    private function replaceTemplate(Skill $executionSkill, string $template, bool $clearLegacy): void
    {
        $executionSkill->effect_template = $template;
        $executionSkill->damage_type = JobArtEffectCatalog::damageType($template);
        if (! $clearLegacy) {
            return;
        }

        foreach ([
            'heal_percent', 'mp_recover_percent', 'self_damage_percent', 'damage_reduction_percent',
            'self_buff_percent', 'enemy_atk_down_percent', 'enemy_mag_down_percent',
            'enemy_def_down_percent', 'enemy_spr_down_percent', 'enemy_spd_down_percent',
            'def_ignore_percent', 'gold_bonus_percent', 'drop_bonus_percent',
            'rare_bonus_percent', 'material_bonus_percent',
        ] as $field) {
            $executionSkill->setAttribute($field, 0);
        }
        $executionSkill->setAttribute('drain_hp_rate', 0.0);
        $executionSkill->setAttribute('reward_scope', 'none');
    }
}
