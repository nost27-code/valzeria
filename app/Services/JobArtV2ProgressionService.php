<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\CompetitiveHitPolicy;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\DirectAttackResolution;
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
        private readonly JobArtStatBuffLogFormatter $statBuffLogFormatter,
        private readonly ?JobArtV2DeckRoleResolver $deckRoleResolver = null,
        private readonly ?JobArtV2Rank5V6Catalog $rank5V6Catalog = null,
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
        $progression->cDesignAimMarked = false;
        $state->updateJobArtV2RoleAction($sourceActionId, [
            'progression_actor_key' => $state->actorKey($actor),
            'progression_hp_at_action_start' => $actor->hp,
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
        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null || ! $this->enabledFor($actor)) {
            return;
        }

        $sameLineage = $this->isCurrentOrSameLineage($actor, $skill);
        $metadata = $this->metadataFor($actor, $skill);
        $actorState = $actor->jobArtV2ProgressionState();
        $formationStage = $this->artStage($skill);
        $multiplier = 1.0;
        $attributes = [];

        if ($this->featureGate->usesRank5V6($actor)) {
            if (($this->competitiveHitPolicy())->supports($state->battleType)
                && $actorState->rank5V6NextAimAccuracyBonus > 0.0
                && ($this->lineageCatalog->forArt($skill)['lineage_key'] ?? null) === 'aim'
                && JobArtEffectCatalog::dealsDamage((string) $skill->effect_template)
            ) {
                $attributes['progression_next_aim_accuracy_bonus'] = $actorState->rank5V6NextAimAccuracyBonus;
                $actorState->rank5V6NextAimAccuracyBonus = 0.0;
            }

            if ($actorState->rank5V6CommittedDamageMultiplier > 1.0) {
                $multiplier *= $actorState->rank5V6CommittedDamageMultiplier;
                $actorState->rank5V6CommittedDamageMultiplier = 1.0;
            }

            $lineageKey = $this->lineageCatalog->forArt($skill)['lineage_key'] ?? null;
            if ($lineageKey === 'counter' && $actorState->rank5V6CounterDamageMultiplier > 1.0) {
                $multiplier *= $actorState->rank5V6CounterDamageMultiplier;
                $actorState->rank5V6CounterDamageMultiplier = 1.0;
            }

            if ((int) $skill->job_id === 14
                && (int) $skill->learn_rank === 5
                && (float) ($state->jobArtV2RoleAction($sourceActionId)['progression_hp_rate_at_action_start'] ?? 1.0) <= 0.50
            ) {
                $multiplier *= 1.25;
                $attributes['rank5_v6_low_hp_multiplier'] = true;
            }
        }

        // These states are created by exact crown finishers, but intentionally
        // apply to later arts regardless of whether the later art itself owns
        // ProgressionCatalog metadata. Otherwise ordinary cards could never
        // participate in the documented category chains.
        if ($sameLineage
            && $actorState->trackingCoordinates !== null
            && ($this->lineageCatalog->forArt($skill)['lineage_key'] ?? null) === 'aim'
            && $actorState->trackingCoordinates['charges'] > 0
        ) {
            $extraSp = max(1, (int) floor($actor->maxMp * 0.03));
            if ($actor->consumeMp($extraSp)) {
                $attributes['progression_tracking_sure_hit'] = true;
                $attributes['progression_tracking_extra_sp'] = $extraSp;
                $actorState->trackingCoordinates['charges']--;
                if ($actorState->trackingCoordinates['charges'] <= 0) {
                    $actorState->trackingCoordinates = null;
                }
            }
        }

        if ($actorState->overlordFormation !== null) {
            $different = $actorState->overlordFormation['previous_category'] !== null
                && $formationStage !== $actorState->overlordFormation['previous_category'];
            if ($different && $actorState->overlordFormation['charges'] > 0) {
                $actorState->overlordFormation['charges']--;
            }
            $actorState->overlordFormation['previous_category'] = $formationStage;
            if ($actorState->overlordFormation['charges'] <= 0) {
                $actorState->overlordFormation = null;
            }
        }
        if ($sameLineage && $actorState->eightFormation !== null && $actorState->eightFormation['ready']
            && $this->isCommandArt($skill)
        ) {
            $attributes['progression_eight_formation_boost'] = true;
            $multiplier *= 1.10;
            $actorState->eightFormation['ready'] = false;
            $actorState->eightFormation['count'] = 0;
        }
        if ($sameLineage && $this->isCommandArt($skill) && $actorState->royalFormation !== null
            && $actorState->royalFormation['charges'] > 0
            && $actorState->royalFormation['previous_category'] !== null
            && $formationStage !== $actorState->royalFormation['previous_category']
        ) {
            $attributes['progression_royal_formation_boost'] = true;
        }

        if ($metadata === null) {
            $current = $state->jobArtV2RoleAction($sourceActionId);
            $attributes['progression_damage_multiplier'] = max(
                0.0,
                (float) ($current['progression_damage_multiplier'] ?? 1.0) * $multiplier,
            );
            $state->updateJobArtV2RoleAction($sourceActionId, $attributes);

            return;
        }

        $target = $this->opponent($actor, $state);
        $targetState = $target->jobArtV2ProgressionState();
        $jobId = (int) $skill->job_id;
        $rank = (int) $skill->learn_rank;

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

        if ($sameLineage && $jobId === 37 && $rank === 5) {
            $markCount = $this->huntingMarkCount($target, $actor);
            $attributes['progression_hunt_marks_at_start'] = $markCount;
            $actorState->cDesignAimMarked = $markCount > 0;
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

        if ($sameLineage && $jobId === 51 && $rank === 5) {
            $requested = max(1, (int) floor($actor->maxHp * 0.05));
            if ($actor->hp > $requested) {
                $actor->takeDamage($requested);
                $actorState->nightmareSelfDamage += $requested;
                $multiplier *= 1.15;
                $attributes['progression_nightmare_self_cost'] = $requested;
                $state->addLog('<span class="text-purple-600 font-bold">'.e($actor->name).' は黒炎へ '.e((string) $requested).' のHPを捧げた！</span>');
            }
        }
        if ($sameLineage && $jobId === 51 && $rank === 9) {
            $units = $actor->maxHp > 0
                ? min(4, (int) floor(($actorState->nightmareSelfDamage * 25) / $actor->maxHp))
                : 0;
            if ($units > 0) {
                $multiplier *= 1 + ($units * 0.05);
            }
            $attributes['progression_nightmare_bonus_units'] = $units;
            $actorState->nightmareSelfDamage = 0;
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
        $metadata = $this->metadataFor($actor, $sourceSkill);
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
            $this->replaceTemplate($executionSkill, 'MAGICAL_DAMAGE', false);
        }
        if (! empty($state->jobArtV2RoleAction()['progression_tracking_sure_hit'])) {
            $executionSkill->setAttribute('sure_hit', true);
        }
        if ($jobId === 67) {
            // Crown transmute replaces the generic Gold/Drop fallback. Cross
            // Cross-lineage resource gain/cost is handled by ResourceService; progression-only mechanics remain source-lineage restricted.
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
        if ($sameLineage
            && in_array((int) $sourceSkill->learn_rank, [5, 9], true)
            && ($this->lineageCatalog->forArt($sourceSkill)['lineage_key'] ?? null) === 'pierce'
            && $this->hasPreparedEffectForAction($actor, $state, 'c_design_pierce_adaptive_prep')
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

        $actionCategory = $this->actionCategory($skill);
        $artStage = $this->artStage($skill);
        $this->recordActionCategory($actor, $target, $actionCategory);

        $actorState = $actor->jobArtV2ProgressionState();
        $landed = $hitResult === null || $hitResult->landed();
        $this->advanceFormationOnSuccessfulCategory(
            $actor,
            $artStage,
            $skill,
            $landed,
            ! empty($state->jobArtV2RoleAction()['progression_eight_formation_boost']),
            (int) $skill->job_id === 59 && (int) $skill->learn_rank === 9,
            (int) $skill->job_id === 69 && (int) $skill->learn_rank === 9,
        );

        if (! $this->enabledFor($actor)) {
            return;
        }

        $sameLineage = $this->isCurrentOrSameLineage($actor, $skill);
        $jobId = (int) $skill->job_id;
        $rank = (int) $skill->learn_rank;

        if ($this->featureGate->usesRank5V6($actor) && $rank === 5) {
            $missAccuracyBonus = ($this->rank5V6Catalog ?? app(JobArtV2Rank5V6Catalog::class))
                ->missNextAimAccuracyBonusPoints($skill);
            if (($this->competitiveHitPolicy())->supports($state->battleType)
                && $hitResult === HitResult::MISS
                && $missAccuracyBonus > 0.0
            ) {
                $actorState->rank5V6NextAimAccuracyBonus = max(
                    $actorState->rank5V6NextAimAccuracyBonus,
                    $missAccuracyBonus,
                );
            }

            if ($jobId === 3 && $landed) {
                $this->addHuntingMark($target, $actor);
            } elseif ($jobId === 12) {
                $actorState->rank5V6NextArtActivationBonus = 20;
                $actorState->rank5V6DifferentCategoryFrom = $artStage;
            } elseif ($jobId === 77) {
                $this->extendShortestPositiveTimedEffect($actor, $state, 2);
            } elseif ($jobId === 87) {
                $actorState->rank5V6NextArtActivationBonus = 25;
                $actorState->rank5V6NextArtDamageMultiplier = 1.10;
                $actorState->rank5V6DifferentCategoryFrom = null;
            } elseif ($jobId === 91 && $landed && $this->resourceCatalog->resourcesForActor($target) !== []) {
                $this->applyGoldCorrosion($target->jobArtV2ProgressionState(), $this->actorKey($actor), $actor, 1, $sourceActionId);
            }
        }

        if ($sameLineage && $jobId === 69 && $rank === 1) {
            $actorState->initiativeRerollNextRound = true;
        }
        // 確定先攻はHIT効果ではなく、奥義を実行した報酬。MISS/EVADEでも予約する。
        if ($sameLineage && $jobId === 69 && $rank === 9) {
            $actorState->initiativeForceFirstNextRound = true;
        }

        $metadata = $this->metadataFor($actor, $skill);
        if ($metadata === null) {
            return;
        }

        $targetState = $target->jobArtV2ProgressionState();

        if ($jobId === 79 && $rank === 5 && $sameLineage) {
            $rate = 0.15;
            $modifiers = ['def' => $rate, 'spr' => $rate];
            $actor->replaceJobArtV2TimedEffect(new JobArtV2TimedEffectState(
                key: 'silver_guard_bridge',
                statModifiers: $modifiers,
                appliedRound: $state->turnCount,
                remainingRounds: 2,
                sourceActionId: $sourceActionId,
                sourceSkillId: (int) $skill->id,
                removable: true,
                strength: $rate * 100,
            ));
            $log = $this->statBuffLogFormatter->formatIncrease($actor->name, $modifiers, 2, 'ラウンド');
            if ($log !== null) {
                $state->addLog($log);
            }
        }

        if ($sameLineage && $rank === 1 && $jobId === 22) {
            $this->applyPreparedEffect($actor, $state, $skill, 'magic_aim_prep', 1.0, 'aim', [5, 9], 1, 4);
        }
        if ($sameLineage && $rank === 1 && $jobId === 45) {
            $this->applyPreparedEffect($actor, $state, $skill, 'c_design_pierce_adaptive_prep', 1.0, 'pierce', [5], 1, 3);
        }
        if ($sameLineage && $rank === 1 && $jobId === 33 && $this->hasDefensivePreparation($target)) {
            $this->applyPreparedEffect($actor, $state, $skill, 'break_focus', 1.15, 'break', [5, 9], 1, 3);
        }
        if ($sameLineage && $rank === 1 && $jobId === 98) {
            $this->applyPreparedEffect($actor, $state, $skill, 'split_pierce', 1.0, 'pierce', [5], 2, 5);
        }

        if ($sameLineage && $jobId === 52) {
            if ($rank === 1) {
                $actorState->applyRoundState(self::SUPER_PIERCE_STANCE, 5, $state->turnCount);
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
                // 影縫い乱舞の対奥義cancelと通常の封技予約は択一。
                if (empty($state->jobArtV2RoleAction()['ultimate_counterplay_hunt_cancelled'])) {
                    $category = $targetState->lastActionCategory ?? 'attack';
                    $this->reserveSeal($actor, $target, $jobId, $category, $jobId === 64, $state->turnCount);
                }
            } elseif ($jobId === 64 && $rank === 9) {
                $category = $targetState->firstActionCategory
                    ?? $actorState->observedActionCategory
                    ?? $targetState->lastActionCategory
                    ?? 'attack';
                $this->reserveSeal($actor, $target, 64, $category, false, $state->turnCount);
            }
        }
        if ($sameLineage && $landed && $jobId === 37 && $rank === 1) {
            $this->addHuntingMark($target, $actor);
        }

        if ($sameLineage && $landed && $jobId === 67) {
            $targetHasLineageResource = $this->resourceCatalog->resourcesForActor($target) !== [];
            if ($targetHasLineageResource) {
                $ownerKey = $this->actorKey($actor);
                if ($rank === 1) {
                    $this->applyGoldCorrosion($targetState, $ownerKey, $actor, 1, $sourceActionId);
                } elseif ($rank === 5 && isset($targetState->resourceSuppressions[$ownerKey])) {
                    $targetState->resourceSuppressions[$ownerKey]['compensation_armed'] = true;
                    $targetState->resourceSuppressions[$ownerKey]['compensation_actions'] = 2;
                    $targetState->resourceSuppressions[$ownerKey]['compensation_seen_gain'] = false;
                    $targetState->resourceSuppressions[$ownerKey]['refund_points'] = 2;
                    $targetState->resourceSuppressions[$ownerKey]['created_source_action_id'] = $sourceActionId;
                } elseif ($rank === 9) {
                    $this->applyGoldCorrosion($targetState, $ownerKey, $actor, 2, $sourceActionId);
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
        if ($sameLineage && $landed && $jobId === 33 && $rank === 5) {
            $this->addBreakMark($target, $actor, false);
        }

        if ($sameLineage && $landed && $jobId === 26 && $rank === 5) {
            $this->shortenLongestPositiveTimedEffect($target, $state, 2);
        }
        if ($sameLineage && $landed && $jobId === 46 && $rank === 5) {
            $actorState->immutableRhythmCharges = 1;
            $state->addLog('<span class="text-indigo-700 font-bold">'.e($actor->name).' は不変律を奏でた！</span>');
        }

        $createdEightFormation = false;
        $createdRoyalFormation = false;
        if ($sameLineage && $landed && $rank === 9) {
            if ($jobId === 28) {
                $actorState->musouZanshin = ['remaining' => 4, 'last_round' => $state->turnCount];
            } elseif ($jobId === 36) {
                $startHp = (int) ($state->jobArtV2RoleAction()['progression_hp_at_action_start'] ?? $actor->hp);
                $requested = max(1, (int) floor($actor->maxHp * 0.12));
                $actual = min($requested, max(0, $actor->maxHp - $startHp));
                $overheal = max(0, $requested - $actual);
                if ($overheal > 0) {
                    $actorState->holyWall = [
                        'remaining' => 4,
                        'last_round' => $state->turnCount,
                        'cap' => max(1, (int) floor($actor->maxHp * 0.10)),
                        'amount' => min($overheal, max(1, (int) floor($actor->maxHp * 0.10))),
                        'source_action_id' => null,
                    ];
                }
            } elseif ($jobId === 48) {
                $actorState->overlordFormation = [
                    'remaining' => 4, 'last_round' => $state->turnCount,
                    'charges' => 2, 'previous_category' => $artStage,
                ];
            } elseif ($jobId === 49) {
                $this->shortenAlchemyTimedEffects($actor, $target, $state);
            } elseif ($jobId === 53) {
                // FieldService performs the fixed-field conversion after HIT.
            } elseif ($jobId === 55) {
                $actorState->trackingCoordinates = ['remaining' => 4, 'last_round' => $state->turnCount, 'charges' => 2];
            } elseif ($jobId === 59) {
                $actorState->eightFormation = [
                    'remaining' => 5, 'last_round' => $state->turnCount,
                    'count' => 0, 'previous_category' => $artStage, 'ready' => false,
                ];
                $createdEightFormation = true;
            } elseif ($jobId === 60) {
                $actor->replaceCounterStanceState(new JobArtV2CounterStanceState(5, $state->turnCount, 0.35));
                $actorState->applyRoundState('royal_sword_formation', 5, $state->turnCount);
            } elseif ($jobId === 61) {
                $targetState->blackCrownReversal = ['remaining' => 5, 'last_round' => $state->turnCount];
                $target->conditions['black_crown_reversal'] = ['turns' => 5, 'rate' => 0.50];
            } elseif ($jobId === 69) {
                $actorState->royalFormation = [
                    'remaining' => 5, 'last_round' => $state->turnCount,
                    'charges' => 3, 'previous_category' => $artStage,
                ];
                $createdRoyalFormation = true;
            }
        }

        if ($sameLineage && in_array($jobId, [27, 48], true)) {
            if ($rank === 1) {
                $this->armCommandActivation($actorState, 10, [5], 4);
            } elseif ($jobId === 48 && $rank === 5) {
                $this->armCommandActivation($actorState, 15, [5, 9], 3);
            }
        }

        if ($sameLineage && $landed && $jobId === 59 && $rank !== 9) {
            $actorState->commandLastSuccessfulCategory = $artStage;
            if ($rank === 1) {
                $actorState->commandActivationBonus = 15;
            } elseif ($rank === 5) {
                $actorState->commandDifferentCategoryFrom = $artStage;
                $actorState->commandActivationBonus = 20;
            }
        }
        if ($sameLineage && $jobId === 69 && $rank === 5) {
            if ($landed) {
                $actorState->commandLastSuccessfulCategory = $artStage;
            }
            $actorState->commandActivationBonus = 20;
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

        return max(0, (int) floor(
            ($damage * max(0.0, (float) ($context['progression_damage_multiplier'] ?? 1.0))) + 1.0e-9,
        ));
    }

    public function eligibilityBlockReason(BattleActor $actor, BattleState $state, Skill $skill): ?string
    {
        if (! $this->enabledFor($actor)) {
            return null;
        }

        $deckBlock = $this->roles()->eligibilityBlockReason($actor, $skill);
        if ($deckBlock !== null) {
            return $deckBlock;
        }

        $metadata = $this->metadataFor($actor, $skill);
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
        $actor->markDamageMitigatedSinceOwnAction();
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
        if ($progression->rank5V6DifferentCategoryFrom !== null) {
            $indexed = [];
            foreach ($candidates as $index => $candidate) {
                $indexed[] = [
                    'skill' => $candidate,
                    'priority' => $this->artStage($candidate) !== $progression->rank5V6DifferentCategoryFrom ? 1 : 0,
                    'index' => $index,
                ];
            }
            usort($indexed, static fn (array $left, array $right): int => ($right['priority'] <=> $left['priority']) ?: ($left['index'] <=> $right['index']));
            $candidates = array_values(array_map(static fn (array $row): Skill => $row['skill'], $indexed));
        }

        if ($progression->royalFormation !== null) {
            $indexed = [];
            foreach ($candidates as $index => $candidate) {
                $qualifies = $this->isCommandArt($candidate)
                    && $progression->royalFormation['charges'] > 0
                    && $progression->royalFormation['previous_category'] !== null
                    && $this->artStage($candidate) !== $progression->royalFormation['previous_category'];
                $indexed[] = ['skill' => $candidate, 'priority' => $qualifies ? 1 : 0, 'index' => $index];
            }
            usort($indexed, static fn (array $left, array $right): int => ($right['priority'] <=> $left['priority']) ?: ($left['index'] <=> $right['index']));
            $candidates = array_values(array_map(static fn (array $row): Skill => $row['skill'], $indexed));
        }

        if (! $progression->commandPrioritizeCurrentArt && $progression->commandDifferentCategoryFrom === null) {
            return $candidates;
        }

        $indexed = [];
        foreach ($candidates as $index => $candidate) {
            $isCommand = $this->isCommandArt($candidate);
            $isDifferent = $progression->commandDifferentCategoryFrom !== null
                && $this->artStage($candidate) !== $progression->commandDifferentCategoryFrom;
            $priority = ($progression->commandPrioritizeCurrentArt && $isCommand ? 2 : 0)
                + ($isDifferent && $isCommand ? 1 : 0);
            $indexed[] = ['skill' => $candidate, 'priority' => $priority, 'index' => $index];
        }
        usort($indexed, static fn (array $left, array $right): int => ($right['priority'] <=> $left['priority']) ?: ($left['index'] <=> $right['index']));

        return array_values(array_map(static fn (array $row): Skill => $row['skill'], $indexed));
    }

    public function activationRate(BattleActor $actor, Skill $skill, float $baseRate): float
    {
        if (! $this->enabledFor($actor)) {
            return $baseRate;
        }

        $progression = $actor->jobArtV2ProgressionState();
        if ($progression->rank5V6NextArtActivationBonus > 0) {
            $baseRate = min(100.0, $baseRate + $progression->rank5V6NextArtActivationBonus);
        }
        $formalCommandPrepared = $this->isCurrentOrSameLineage($actor, $skill)
            && $progression->commandActivationRemainingOpportunities > 0
            && $progression->commandActivationTargetLineage === ($this->lineageCatalog->forArt($skill)['lineage_key'] ?? null)
            && in_array((int) $skill->learn_rank, $progression->commandActivationTargetRanks, true);
        if ($formalCommandPrepared) {
            return min(100.0, $baseRate + $progression->cDesignCommandActivationBonus);
        }
        $isCommand = $this->isCommandArt($skill);
        if ($isCommand && $progression->eightFormation !== null && $progression->eightFormation['ready']) {
            return min(100.0, $baseRate + 25.0);
        }
        if ($isCommand && $progression->royalFormation !== null
            && $progression->royalFormation['charges'] > 0
            && $progression->royalFormation['previous_category'] !== null
            && $this->artStage($skill) !== $progression->royalFormation['previous_category']
        ) {
            return min(100.0, $baseRate + 25.0);
        }
        if ($isCommand && $progression->commandGuaranteeNextArt) {
            return 100.0;
        }
        if (! $isCommand || $progression->commandActivationBonus <= 0) {
            return $baseRate;
        }
        if ($progression->commandDifferentCategoryFrom !== null
            && $this->artStage($skill) === $progression->commandDifferentCategoryFrom
        ) {
            return $baseRate;
        }

        return min(100.0, $baseRate + $progression->commandActivationBonus);
    }

    public function finishActivationAttempt(BattleActor $actor, Skill $skill, bool $activated = false): void
    {
        if (! $this->enabledFor($actor)) {
            return;
        }

        $progression = $actor->jobArtV2ProgressionState();
        if ($progression->rank5V6NextArtActivationBonus > 0
            || $progression->rank5V6NextArtDamageMultiplier > 1.0
            || $progression->rank5V6DifferentCategoryFrom !== null
        ) {
            if ($activated && $progression->rank5V6NextArtDamageMultiplier > 1.0) {
                $progression->rank5V6CommittedDamageMultiplier = max(
                    $progression->rank5V6CommittedDamageMultiplier,
                    $progression->rank5V6NextArtDamageMultiplier,
                );
            }
            $progression->rank5V6NextArtActivationBonus = 0;
            $progression->rank5V6NextArtDamageMultiplier = 1.0;
            $progression->rank5V6DifferentCategoryFrom = null;
        }
        if ($progression->commandActivationRemainingOpportunities > 0
            && $progression->commandActivationTargetLineage === ($this->lineageCatalog->forArt($skill)['lineage_key'] ?? null)
            && in_array((int) $skill->learn_rank, $progression->commandActivationTargetRanks, true)
        ) {
            $this->clearCommandActivationPreparation($progression);
            $progression->cDesignAimMarked = false;

            return;
        }
        if (! $this->isCommandArt($skill)) {
            $progression->cDesignAimMarked = false;
            return;
        }
        if ($progression->royalFormation !== null
            && $progression->royalFormation['charges'] > 0
            && $progression->royalFormation['previous_category'] !== null
            && $this->artStage($skill) !== $progression->royalFormation['previous_category']
        ) {
            $progression->royalFormation['charges']--;
        }
        if ($progression->commandGuaranteeNextArt
            || $progression->commandActivationBonus > 0
            || $progression->commandPrioritizeCurrentArt
        ) {
            $progression->commandGuaranteeNextArt = false;
            $progression->commandActivationBonus = 0;
            $progression->commandDifferentCategoryFrom = null;
            $progression->commandPrioritizeCurrentArt = false;
        }
        $progression->cDesignAimMarked = false;
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

    public function adjustedSpCost(BattleActor $actor, Skill $skill, int $baseCost): int
    {
        $formation = $actor->existingJobArtV2ProgressionState()?->overlordFormation;
        if (! $this->enabledFor($actor)
            || $formation === null
            || $formation['charges'] <= 0
            || $formation['previous_category'] === null
            || $this->artStage($skill) === $formation['previous_category']
        ) {
            return max(0, $baseCost);
        }

        return max(1, (int) ceil(max(0, $baseCost) * 0.50));
    }

    public function modifyHpHeal(BattleActor $actor, BattleState $state, int $heal): int
    {
        $heal = max(0, $heal);
        $progression = $actor->existingJobArtV2ProgressionState();
        if ($progression === null) {
            return $heal;
        }

        if ($progression->eightFormation !== null
            && ! empty($state->jobArtV2RoleAction()['progression_eight_formation_boost'])
        ) {
            $heal = (int) floor($heal * 1.10);
        }

        if ($progression->blackCrownReversal !== null && $heal > 0) {
            $reduced = (int) floor($heal * 0.50);
            $prevented = max(0, $heal - $reduced);
            $progression->pendingBlackCrownReversalDamage = min(
                $prevented,
                max(1, (int) floor($actor->maxHp * 0.10)),
            );
            $progression->blackCrownReversal = null;
            unset($actor->conditions['black_crown_reversal']);

            return $reduced;
        }

        return $heal;
    }

    public function completeHpHeal(BattleActor $actor, BattleState $state): void
    {
        $progression = $actor->existingJobArtV2ProgressionState();
        $pending = max(0, (int) ($progression?->pendingBlackCrownReversalDamage ?? 0));
        if ($progression === null || $pending <= 0) {
            return;
        }

        $progression->pendingBlackCrownReversalDamage = 0;
        $damage = min($pending, max(0, $actor->hp - 1));
        if ($damage > 0) {
            $actor->takeDamage($damage);
        }
        $state->addLog('<span class="text-purple-700 font-bold">黒冠反転が回復を反転し、'.e((string) $damage).' の非致死ダメージを与えた！</span>');
    }

    /** @return array{rate:float,key:string}|null */
    public function crownGuardForIncoming(BattleActor $actor, DirectAttackResolution $resolution): ?array
    {
        $state = $actor->existingJobArtV2ProgressionState();
        if ($state === null || $resolution->damageCategory !== 'physical' || $state->musouZanshin === null) {
            return null;
        }

        return ['rate' => 0.20, 'key' => 'musou_zanshin'];
    }

    public function consumeCrownGuard(BattleActor $actor, BattleState $state, string $key, int $prevented): void
    {
        if ($key !== 'musou_zanshin' || $prevented < 1) {
            return;
        }
        $progression = $actor->jobArtV2ProgressionState();
        if ($progression->musouZanshin === null) {
            return;
        }
        $progression->musouZanshin = null;
        $actor->configureResource('sword_momentum', 12);
        $actor->addResource('sword_momentum', 4);
        $state->addLog('<span class="text-cyan-700 font-bold">'.e($actor->name).' は無双残心から剣勢を+4した！</span>');
    }

    public function absorbHolyWall(BattleActor $actor, BattleState $state, DirectAttackResolution $resolution, int $damage): int
    {
        $wall = $actor->existingJobArtV2ProgressionState()?->holyWall;
        if ($wall === null || ! $resolution->direct || $damage <= 0) {
            return $damage;
        }
        if (($wall['source_action_id'] ?? null) !== null
            && (int) $wall['source_action_id'] !== $resolution->sourceActionId
        ) {
            $actor->jobArtV2ProgressionState()->holyWall = null;

            return $damage;
        }
        $wall['source_action_id'] ??= $resolution->sourceActionId;
        $absorbed = min($damage, max(0, (int) $wall['amount']));
        if ($absorbed <= 0) {
            return $damage;
        }
        $wall['amount'] -= $absorbed;
        $actor->jobArtV2ProgressionState()->holyWall = $wall['amount'] > 0 ? $wall : null;
        $state->addLog('<span class="text-blue-700 font-bold">聖壁が '.e((string) $absorbed).' の直接ダメージを肩代わりした！</span>');

        return max(0, $damage - $absorbed);
    }

    public function clearCleansedState(BattleActor $actor, string $key): void
    {
        if ($key === 'black_crown_reversal') {
            $actor->jobArtV2ProgressionState()->blackCrownReversal = null;
        }
    }

    private function applyGoldCorrosion(
        JobArtV2ProgressionState $targetState,
        string $ownerKey,
        BattleActor $owner,
        int $charges,
        int $sourceActionId,
    ): void {
        $existing = $targetState->resourceSuppressions[$ownerKey] ?? null;
        $targetState->resourceSuppressions[$ownerKey] = [
            'owner' => $owner,
            'resource_key' => '*',
            'remaining_gains' => max(
                max(0, (int) ($existing['remaining_gains'] ?? 0)),
                max(0, min(2, $charges)),
            ),
            'compensation_armed' => (bool) ($existing['compensation_armed'] ?? false),
            'compensation_actions' => max(0, (int) ($existing['compensation_actions'] ?? 0)),
            'compensation_seen_gain' => (bool) ($existing['compensation_seen_gain'] ?? false),
            'refund_points' => max(0, (int) ($existing['refund_points'] ?? 0)),
            'created_source_action_id' => (int) ($existing['created_source_action_id'] ?? $sourceActionId),
        ];
    }

    public function modifyIncomingResourceGain(
        BattleActor $actor,
        string $resourceKey,
        int $gain,
        ?BattleState $state = null,
    ): int {
        if (! $this->enabledFor($actor) || $gain <= 0) {
            return max(0, $gain);
        }

        $progression = $actor->jobArtV2ProgressionState();

        $modifiedGain = $gain;
        $goldCorrosionApplied = false;
        foreach ($progression->resourceSuppressions as $ownerKey => $suppression) {
            if (! in_array($suppression['resource_key'], ['*', $resourceKey], true)
                || $suppression['remaining_gains'] <= 0
            ) {
                continue;
            }

            // A configured gain while already at cap is not an actual gain
            // event. Keep both the suppression and Rank5 compensation armed.
            $available = max(0, $actor->resourceCap($resourceKey) - $actor->getResource($resourceKey));
            if (min($available, $modifiedGain) <= 0) {
                return $modifiedGain;
            }

            $sourceActionId = $state?->currentSourceActionId();
            if ($state !== null && $sourceActionId !== null) {
                if (! $state->claimResourceGainModifier(
                    $actor,
                    $resourceKey,
                    'gold_corrosion:'.$ownerKey,
                    $sourceActionId,
                )) {
                    continue;
                }
                $state->claimResourceSuppressionAction($actor, $ownerKey, $sourceActionId);
            } else {
                $suppression['remaining_gains']--;
            }
            $suppression['compensation_seen_gain'] = true;
            if ($state === null && $suppression['remaining_gains'] <= 0) {
                unset($progression->resourceSuppressions[$ownerKey]);
            } else {
                $progression->resourceSuppressions[$ownerKey] = $suppression;
            }

            $modifiedGain = max(1, $modifiedGain - 1);
            $goldCorrosionApplied = true;
            break;
        }

        $counterplay = $actor->existingJobArtV2UltimateCounterplayState();
        if ($counterplay !== null && $counterplay->resourceGainPenaltyCharges > 0) {
            $available = max(0, $actor->resourceCap($resourceKey) - $actor->getResource($resourceKey));
            if (min($available, $modifiedGain) > 0) {
                $counterplay->resourceGainPenaltyCharges--;
                $modifiedGain = max(0, $modifiedGain - 1);
            }
        }

        return $goldCorrosionApplied ? max(1, $modifiedGain) : $modifiedGain;
    }

    /**
     * 獄炎ナイトメアが参照するのは敵からの被ダメージではなく、
     * 戦技によって実際に支払った非致死のHPだけ。
     */
    public function recordNightmareSelfDamage(BattleActor $actor, int $actualDamage): void
    {
        if (! $this->enabledFor($actor) || $actualDamage <= 0) {
            return;
        }

        $actor->jobArtV2ProgressionState()->nightmareSelfDamage += $actualDamage;
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
        if ($progression->commandActivationRemainingOpportunities > 0) {
            $progression->commandActivationRemainingOpportunities--;
            if ($progression->commandActivationRemainingOpportunities <= 0) {
                $this->clearCommandActivationPreparation($progression);
            }
        }

        foreach ($progression->resourceSuppressions as $ownerKey => $suppression) {
            if ($state->hasClaimedResourceSuppressionAction($actor, $ownerKey, $sourceActionId)) {
                $suppression['remaining_gains'] = max(0, (int) $suppression['remaining_gains'] - 1);
                $suppression['compensation_seen_gain'] = true;
            }

            if ($suppression['compensation_armed']
                && $suppression['created_source_action_id'] !== $sourceActionId
            ) {
                if ($suppression['compensation_seen_gain']) {
                    $suppression['compensation_armed'] = false;
                    $suppression['compensation_actions'] = 0;
                    $suppression['refund_points'] = 0;
                } else {
                    $suppression['compensation_actions']--;
                    if ($suppression['compensation_actions'] <= 0) {
                        $owner = $suppression['owner'];
                        $ownerResource = collect($this->resourceCatalog->resourcesForActor($owner))
                            ->firstWhere('resource_key', 'catalyst');
                        if (is_array($ownerResource)) {
                            $owner->configureResource('catalyst', (int) $ownerResource['resource_max_points']);
                            $owner->addResource('catalyst', (int) $suppression['refund_points']);
                        }
                        $suppression['compensation_armed'] = false;
                        $suppression['refund_points'] = 0;
                    }
                }
            }

            if ($suppression['remaining_gains'] <= 0 && ! $suppression['compensation_armed']) {
                unset($progression->resourceSuppressions[$ownerKey]);
                continue;
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
            foreach (['musouZanshin', 'overlordFormation', 'eightFormation', 'royalFormation', 'trackingCoordinates', 'holyWall'] as $property) {
                $value = $progression->{$property};
                if (! is_array($value) || $state->turnCount <= (int) $value['last_round']) {
                    continue;
                }
                $value['last_round'] = $state->turnCount;
                $value['remaining'] = max(0, (int) $value['remaining'] - 1);
                $progression->{$property} = $value['remaining'] > 0 ? $value : null;
            }
            if (is_array($progression->blackCrownReversal)
                && $state->turnCount > (int) $progression->blackCrownReversal['last_round']
            ) {
                $progression->blackCrownReversal['last_round'] = $state->turnCount;
                $progression->blackCrownReversal['remaining']--;
                if ($progression->blackCrownReversal['remaining'] <= 0) {
                    $damage = min(max(1, (int) floor($actor->maxHp * 0.05)), max(0, $actor->hp - 1));
                    if ($damage > 0) {
                        $actor->takeDamage($damage);
                    }
                    $progression->blackCrownReversal = null;
                    unset($actor->conditions['black_crown_reversal']);
                    $state->addLog('<span class="text-purple-700 font-bold">黒冠反転が期限切れとなり、'.e((string) $damage).' の非致死ダメージを与えた！</span>');
                }
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
        if (! $this->isCurrentOrSameLineage($actor, $skill)) {
            return 0.0;
        }

        if ((int) $skill->job_id === 37 && (int) $skill->learn_rank === 5) {
            $target = $actor->jobArtV2ProgressionState()->cDesignAimMarked;

            return $target ? 8.0 : 0.0;
        }
        if ((int) $skill->job_id !== 55) {
            return 0.0;
        }

        return (int) $skill->learn_rank === 5 ? 5.0 : 0.0;
    }

    public function preparedAccuracyDeltaPoints(
        BattleActor $actor,
        Skill $skill,
        ?BattleState $state = null,
    ): float {
        if ($state === null
            || ! ($this->competitiveHitPolicy())->supports($state->battleType)
            || ($this->lineageCatalog->forArt($skill)['lineage_key'] ?? null) !== 'aim'
            || ! JobArtEffectCatalog::dealsDamage((string) $skill->effect_template)
        ) {
            return 0.0;
        }

        return max(
            0.0,
            (float) ($state->jobArtV2RoleAction()['progression_next_aim_accuracy_bonus'] ?? 0.0),
        );
    }

    private function competitiveHitPolicy(): CompetitiveHitPolicy
    {
        return app(CompetitiveHitPolicy::class);
    }

    public function huntingMarkCountFor(BattleActor $target, BattleActor $owner): int
    {
        return $this->huntingMarkCount($target, $owner);
    }

    public function consumeHuntingMarksFor(BattleActor $target, BattleActor $owner, int $count): bool
    {
        if ($count < 1) {
            return false;
        }

        return $this->consumeHuntingMarks($target, $owner, $count);
    }

    public function breakMarkCountFor(BattleActor $target, BattleActor $owner): int
    {
        return $this->breakMarkCount($target, $owner);
    }

    public function consumeBreakMarksFor(BattleActor $target, BattleActor $owner, int $count): bool
    {
        if ($count < 1) {
            return false;
        }

        return $this->consumeBreakMarks($target, $owner, $count);
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

    public function consumeSuperPierceStance(BattleActor $actor): bool
    {
        return $actor->jobArtV2ProgressionState()->consumeRoundState(self::SUPER_PIERCE_STANCE);
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
        // 機神オーバードライブの旧「最大SP5%破壊（1戦1回）」は、
        // L列正本の追尾座標へ置き換えた。SP圧力を残すと説明外の二重効果になる。
        return null;
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

    /** @param list<int> $targetRanks */
    private function armCommandActivation(
        JobArtV2ProgressionState $state,
        int $bonus,
        array $targetRanks,
        int $actionOpportunities,
    ): void {
        $state->cDesignCommandActivationBonus = max($state->cDesignCommandActivationBonus, $bonus);
        $state->commandActivationTargetLineage = 'command';
        $state->commandActivationTargetRanks = array_values(array_unique(array_map('intval', $targetRanks)));
        // The action which creates the preparation finishes immediately after
        // this hook, so keep one extra internal tick. Players still receive
        // exactly the documented number of future own-action opportunities.
        $state->commandActivationRemainingOpportunities = max(1, $actionOpportunities) + 1;
    }

    private function clearCommandActivationPreparation(JobArtV2ProgressionState $state): void
    {
        $state->cDesignCommandActivationBonus = 0;
        $state->commandActivationTargetLineage = null;
        $state->commandActivationTargetRanks = [];
        $state->commandActivationRemainingOpportunities = 0;
    }

    private function isCurrentOrSameLineage(BattleActor $actor, Skill $skill): bool
    {
        if (! $this->enabledFor($actor)) {
            return false;
        }

        $resolution = $this->roles()->resolveActor($actor);
        if ($resolution->active) {
            return in_array(
                $resolution->roleFor($skill),
                [JobArtV2DeckRole::MAIN, JobArtV2DeckRole::SECONDARY],
                true,
            ) && $resolution->blockReasonFor($skill) === null;
        }

        $origin = $this->originFor($actor, $skill);
        if ($origin === 'current') {
            return $this->prototypeCatalog->isTrustedCurrentJobArt($actor->currentJobId, $skill);
        }

        return $origin === 'inherited' && $this->resourceCatalog->isSameLineageInherited($actor, $skill);
    }

    private function isCommandArt(Skill $skill): bool
    {
        return ($this->lineageCatalog->forArt($skill)['lineage_key'] ?? null) === 'command';
    }

    private function isCrossLineageInherited(BattleActor $actor, Skill $skill): bool
    {
        $resolution = $this->roles()->resolveActor($actor);
        if ($resolution->active) {
            return $resolution->roleFor($skill) === JobArtV2DeckRole::TECH;
        }

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

    private function roles(): JobArtV2DeckRoleResolver
    {
        return $this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class);
    }

    /** @return array<string, mixed>|null */
    private function metadataFor(BattleActor $actor, Skill $skill): ?array
    {
        $metadata = $this->catalog->forArt($skill);
        if ($metadata === null || ! $this->catalog->isCDesignOnly($skill)) {
            return $metadata;
        }

        $resolution = $this->roles()->resolveActor($actor);
        if (! $resolution->active
            || $resolution->blockReasonFor($skill) !== null
            || ! in_array(
                $resolution->roleFor($skill),
                [JobArtV2DeckRole::MAIN, JobArtV2DeckRole::SECONDARY],
                true,
            )
        ) {
            return null;
        }

        return $metadata;
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

    private function artStage(Skill $skill): string
    {
        return match ((int) $skill->learn_rank) {
            1 => 'starter',
            5 => 'chain',
            9 => 'ultimate',
            default => 'other',
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

    private function advanceFormationOnSuccessfulCategory(
        BattleActor $actor,
        string $category,
        Skill $skill,
        bool $landed,
        bool $usedEightBoost,
        bool $createdEightFormation,
        bool $createdRoyalFormation,
    ): void {
        if (! $landed || ! $this->isCommandArt($skill)) {
            return;
        }
        $progression = $actor->jobArtV2ProgressionState();
        // The boosted action consumes the ready state before resolution and
        // starts a fresh sequence only from the following successful action.
        if ($progression->eightFormation !== null && ! $createdEightFormation) {
            if (! $usedEightBoost
                && $progression->eightFormation['previous_category'] !== null
                && $category !== $progression->eightFormation['previous_category']
            ) {
                $progression->eightFormation['count'] = min(3, $progression->eightFormation['count'] + 1);
                $progression->eightFormation['ready'] = $progression->eightFormation['count'] >= 3;
            }
            $progression->eightFormation['previous_category'] = $category;
        }
        if ($progression->royalFormation !== null && ! $createdRoyalFormation) {
            $progression->royalFormation['previous_category'] = $category;
        }
    }

    private function extendShortestPositiveTimedEffect(
        BattleActor $actor,
        BattleState $state,
        int $rounds,
    ): bool {
        $positive = array_values(array_filter(
            $actor->jobArtV2TimedEffects(),
            static fn (JobArtV2TimedEffectState $effect): bool => ! $effect->isExpired()
                && array_filter($effect->statModifiers, static fn (float $value): bool => $value > 0.0) !== [],
        ));
        usort($positive, static fn (JobArtV2TimedEffectState $a, JobArtV2TimedEffectState $b): int =>
            ($a->remainingRounds <=> $b->remainingRounds)
            ?: ($a->appliedRound <=> $b->appliedRound)
            ?: strcmp($a->key, $b->key));
        $effect = $positive[0] ?? null;
        if ($effect === null) {
            return false;
        }

        $effect->remainingRounds += max(0, $rounds);
        $state->addLog('<span class="text-indigo-700 font-bold">'.e($actor->name).' の強化《'.e($effect->key).'》が '.e((string) $rounds).'ラウンド延長された！</span>');

        return true;
    }

    private function shortenAlchemyTimedEffects(BattleActor $actor, BattleActor $target, BattleState $state): void
    {
        $ownHarmful = array_values(array_filter(
            $actor->jobArtV2TimedEffects(),
            static fn (JobArtV2TimedEffectState $effect): bool => ! $effect->isExpired()
                && array_filter($effect->statModifiers, static fn (float $value): bool => $value < 0.0) !== [],
        ));
        usort($ownHarmful, static fn (JobArtV2TimedEffectState $a, JobArtV2TimedEffectState $b): int => $b->remainingRounds <=> $a->remainingRounds);
        $first = array_shift($ownHarmful);
        $this->shortenTimedEffect($actor, $first, 3);

        $targetPositive = array_values(array_filter(
            $target->jobArtV2TimedEffects(),
            static fn (JobArtV2TimedEffectState $effect): bool => ! $effect->isExpired()
                && array_filter($effect->statModifiers, static fn (float $value): bool => $value > 0.0) !== [],
        ));
        usort($targetPositive, static fn (JobArtV2TimedEffectState $a, JobArtV2TimedEffectState $b): int => $b->remainingRounds <=> $a->remainingRounds);
        $enemyBuff = array_shift($targetPositive);
        if ($enemyBuff !== null) {
            $this->shortenTimedEffect($target, $enemyBuff, 3, $state, true);
        } else {
            $this->shortenTimedEffect($actor, array_shift($ownHarmful), 3);
        }
    }

    private function shortenLongestPositiveTimedEffect(BattleActor $target, BattleState $state, int $rounds): bool
    {
        $positive = array_values(array_filter(
            $target->jobArtV2TimedEffects(),
            static fn (JobArtV2TimedEffectState $effect): bool => ! $effect->isExpired()
                && $effect->removable
                && array_filter($effect->statModifiers, static fn (float $value): bool => $value > 0.0) !== [],
        ));
        usort($positive, static fn (JobArtV2TimedEffectState $a, JobArtV2TimedEffectState $b): int =>
            ($b->remainingRounds <=> $a->remainingRounds) ?: strcmp($a->key, $b->key));
        $effect = $positive[0] ?? null;
        if ($effect === null) {
            return false;
        }

        return $this->shortenTimedEffect($target, $effect, $rounds, $state, true);
    }

    public function preventTimedBuffReduction(BattleActor $actor, BattleState $state): bool
    {
        $progression = $actor->existingJobArtV2ProgressionState();
        if ($progression === null || $progression->immutableRhythmCharges <= 0) {
            return false;
        }

        $progression->immutableRhythmCharges--;
        $state->addLog('<span class="text-indigo-700 font-bold">不変律が '.e($actor->name).' の強化を守った！</span>');

        return true;
    }

    private function shortenTimedEffect(
        BattleActor $actor,
        ?JobArtV2TimedEffectState $effect,
        int $rounds,
        ?BattleState $state = null,
        bool $positiveBuff = false,
    ): bool
    {
        if ($effect === null) {
            return false;
        }
        if ($positiveBuff && $state !== null && $this->preventTimedBuffReduction($actor, $state)) {
            return false;
        }
        $effect->remainingRounds = max(0, $effect->remainingRounds - max(0, $rounds));
        if ($effect->isExpired()) {
            $actor->removeJobArtV2TimedEffect($effect->key);
        }

        return true;
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
        $physical = $this->damageCalculator->estimateJobArtDamage(
            $actor,
            $target,
            'physical',
            $state->battleType,
            $power,
            $hits,
            minimumDamageGuaranteeEnabled: $state->rankBattleMinimumDamageGuaranteeEnabled,
            baseDamageMultiplier: $state->rankBattleBaseDamageMultiplier,
            additionalDefenseIgnoreRate: $state->speedBreakthroughAdditionalRateForEstimate(),
        );
        $magical = $this->damageCalculator->estimateJobArtDamage(
            $actor,
            $target,
            'magical',
            $state->battleType,
            $power,
            $hits,
            minimumDamageGuaranteeEnabled: $state->rankBattleMinimumDamageGuaranteeEnabled,
            baseDamageMultiplier: $state->rankBattleBaseDamageMultiplier,
            additionalDefenseIgnoreRate: $state->speedBreakthroughAdditionalRateForEstimate(),
        );
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
