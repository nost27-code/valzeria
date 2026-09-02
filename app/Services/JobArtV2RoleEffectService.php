<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\HitResult;
use App\Support\JobArtEffectCatalog;

/**
 * Explicit, battle-memory-only role effects for catalogued v2 job arts.
 *
 * Resource generation and costs remain owned by JobArtV2ResourceService. This
 * service only reports real self damage / cleanse events to that existing
 * resource pipeline.
 */
final class JobArtV2RoleEffectService
{
    public function __construct(
        private readonly JobArtV2RoleEffectCatalog $catalog,
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtLineageCatalog $lineageCatalog,
        private readonly DamageCalculator $damageCalculator,
        private readonly JobArtStatBuffLogFormatter $statBuffLogFormatter,
        private readonly JobArtV2FieldService $fieldService,
        private readonly JobArtV2DefenseService $defenseService,
        private readonly JobArtV2CleanseService $cleanseService,
        private readonly JobArtV2ResourceService $resourceService,
        private readonly JobArtV2ProgressionService $progressionService,
        private readonly ?JobArtV2DeckRoleResolver $deckRoleResolver = null,
        private readonly ?JobArtV2CDesignCatalog $cDesignCatalog = null,
        private readonly ?JobArtV2CDesignEffectCatalog $cDesignEffectCatalog = null,
        private readonly ?JobArtV2CrownBalanceCatalog $crownBalanceCatalog = null,
    ) {}

    public function enabledFor(BattleActor $actor): bool
    {
        return $this->featureGate->usesResources($actor);
    }

    /**
     * Apply card-canonical self buffs as removable battle-memory effects in
     * every route. Arts without a canonical entry keep the legacy raw-stat
     * 10/15/20% power tier for backwards compatibility.
     *
     * @return array{
     *     main_label: string,
     *     main_before: int,
     *     main_after: int,
     *     sub_label: string,
     *     sub_before: int,
     *     sub_after: int,
     *     exact_log_written: bool
     * }|null
     */
    public function applySharedSelfBuff(
        BattleActor $actor,
        BattleState $state,
        Skill $skill,
        ?string $damageType = null,
    ): ?array
    {
        if (! $this->enabledFor($actor)) {
            return null;
        }

        $isMagical = match ($damageType) {
            'magical' => true,
            'physical' => false,
            default => (string) $skill->effect_template === 'MAGICAL_DAMAGE_BUFF'
                || $actor->usesMagForNormalAttack(),
        };
        $canonicalModifiers = $this->balances()->selfBuffModifiers($skill, $actor);
        if ($canonicalModifiers !== []) {
            $beforeMain = $isMagical ? $actor->effectiveMag() : $actor->effectiveStr();
            $beforeSub = $isMagical ? $actor->effectiveSpr() : $actor->effectiveDef();
            $this->applyCanonicalSelfBuff($actor, $state, $skill, $canonicalModifiers);

            return [
                'main_label' => $isMagical ? 'MAG' : 'ATK',
                'main_before' => $beforeMain,
                'main_after' => $isMagical ? $actor->effectiveMag() : $actor->effectiveStr(),
                'sub_label' => $isMagical ? 'SPR' : 'DEF',
                'sub_before' => $beforeSub,
                'sub_after' => $isMagical ? $actor->effectiveSpr() : $actor->effectiveDef(),
                'exact_log_written' => true,
            ];
        }

        $power = (int) ($skill->power ?: 100);
        $rate = match (true) {
            $power >= 200 => 0.20,
            $power >= 140 => 0.15,
            default => 0.10,
        };

        if ($isMagical) {
            $beforeMain = $actor->mag;
            $beforeSub = $actor->spr;
            $actor->mag = min(
                (int) floor($actor->baseMag * 1.5),
                $actor->mag + max(1, (int) floor($actor->baseMag * $rate)),
            );
            $actor->spr = min(
                (int) floor($actor->baseSpr * 1.5),
                $actor->spr + max(1, (int) floor($actor->baseSpr * ($rate / 2))),
            );

            return [
                'main_label' => 'MAG',
                'main_before' => $beforeMain,
                'main_after' => $actor->mag,
                'sub_label' => 'SPR',
                'sub_before' => $beforeSub,
                'sub_after' => $actor->spr,
                'exact_log_written' => false,
            ];
        }

        $beforeMain = $actor->str;
        $beforeSub = $actor->def;
        $actor->str = min(
            (int) floor($actor->baseStr * 1.5),
            $actor->str + max(1, (int) floor($actor->baseStr * $rate)),
        );
        $actor->def = min(
            (int) floor($actor->baseDef * 1.5),
            $actor->def + max(1, (int) floor($actor->baseDef * ($rate / 2))),
        );

        return [
            'main_label' => 'ATK',
            'main_before' => $beforeMain,
            'main_after' => $actor->str,
            'sub_label' => 'DEF',
            'sub_before' => $beforeSub,
            'sub_after' => $actor->def,
            'exact_log_written' => false,
        ];
    }

    /**
     * v2の明示的な敵能力低下を、masterのduration_turnsに従う戦闘中状態として適用する。
     * flag OFF・通常職技は呼び出し元のlegacy処理へ戻すためnullを返す。
     *
     * @return array{
     *     duration_turns: int,
     *     changes: list<array{stat: string, label: string, percent: int}>
     * }|null
     */
    public function applyTimedStructuredDebuffs(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        Skill $skill,
        float $rate = 1.0,
    ): ?array {
        if (! $skill->isJobArt() || ! $this->enabledFor($attacker)) {
            return null;
        }

        $skill = $this->balances()->applyToExecution($skill);
        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null) {
            return ['duration_turns' => max(1, (int) $skill->duration_turns), 'changes' => []];
        }

        $fields = [
            'enemy_atk_down_percent' => ['stat' => 'str', 'label' => '攻撃力'],
            'enemy_mag_down_percent' => ['stat' => 'mag', 'label' => '魔法力'],
            'enemy_def_down_percent' => ['stat' => 'def', 'label' => '防御力'],
            'enemy_spr_down_percent' => ['stat' => 'spr', 'label' => '精神力'],
            'enemy_spd_down_percent' => ['stat' => 'agi', 'label' => '素早さ'],
        ];
        $modifiers = [];
        $changes = [];
        $rate = max(0.0, $rate);

        foreach ($fields as $field => $config) {
            $configured = (int) ($skill->{$field} ?? 0);
            if ($configured <= 0) {
                continue;
            }

            $percent = max(1, (int) floor($configured * $rate));
            $modifiers[$config['stat']] = -($percent / 100);
            $changes[] = [
                'stat' => $config['stat'],
                'label' => $config['label'],
                'percent' => $percent,
            ];
        }

        $durationTurns = max(1, (int) $skill->duration_turns);
        if ($modifiers === []) {
            return ['duration_turns' => $durationTurns, 'changes' => []];
        }

        $defender->replaceJobArtV2TimedEffect(new JobArtV2TimedEffectState(
            key: implode(':', [
                'master_stat_debuff',
                (int) $skill->job_id,
                (int) $skill->learn_rank,
            ]),
            statModifiers: $modifiers,
            appliedRound: $state->turnCount,
            remainingRounds: $durationTurns,
            sourceActionId: $sourceActionId,
            sourceSkillId: (int) $skill->id,
            removable: false,
            strength: max(array_map(static fn (float $modifier): float => abs($modifier), $modifiers)),
        ));

        return ['duration_turns' => $durationTurns, 'changes' => $changes];
    }

    public function beginAction(BattleActor $actor, BattleState $state, int $sourceActionId): void
    {
        if (! $this->enabledFor($actor)) {
            return;
        }

        // The shared meaning of these effects is "until the next own action".
        // PvE/PvP/NPC already clear this in their route, while Champ did not;
        // clear it at the common v2 lifecycle boundary so every route agrees.
        $actor->damageReductionRate = 0;
        if ($actor->jobArtV2GuardState()?->expiresAtNextOwnAction) {
            $actor->replaceJobArtV2GuardState(null);
        }
        $directAttackDamageReceived = $actor->consumeDirectAttackDamageReceivedSinceOwnActionSnapshot();
        $parrySucceeded = $actor->consumeParrySucceededSinceOwnActionSnapshot();
        $damageMitigated = $actor->consumeDamageMitigatedSinceOwnActionSnapshot();
        $state->beginJobArtV2RoleAction($sourceActionId, [
            'actor_key' => $state->actorKey($actor),
            'source_action_id' => $sourceActionId,
            'direct_attack_damage_received_since_own_action' => $directAttackDamageReceived,
            'direct_attack_damage_received_since_previous_own_action' => $directAttackDamageReceived,
            'parry_success_since_previous_own_action' => $parrySucceeded,
            'damage_mitigated_since_previous_own_action' => $damageMitigated,
            'role_damage_multiplier' => 1.0,
            'prepared_effect_keys' => [],
            'conditional_multiplier_applied' => false,
        ]);
        $this->progressionService->beginAction($actor, $state, $sourceActionId);
    }

    public function recordDirectAttackDamageReceived(BattleActor $actor): void
    {
        if ($this->enabledFor($actor)) {
            $actor->markDirectAttackDamageReceivedSinceOwnAction();
        }
    }

    public function beginJobArtCast(BattleActor $actor, BattleState $state, Skill $skill): void
    {
        $sourceActionId = $state->currentSourceActionId();
        if (! $this->enabledFor($actor)
            || $sourceActionId === null
            || ! $state->claimJobArtV2RoleEffect($actor, 'begin_job_art_cast', $sourceActionId)
        ) {
            return;
        }

        if (! empty($state->jobArtV2RoleAction($sourceActionId)['ultimate_counterplay_lineage_suppressed'])) {
            $state->updateJobArtV2RoleAction($sourceActionId, [
                'source_skill_id' => (int) $skill->id,
                'source_skill_name' => (string) $skill->name,
                'role_damage_multiplier' => 1.0,
                'prepared_effect_keys' => [],
                'conditional_multiplier_applied' => false,
            ]);

            return;
        }

        $lineage = $this->lineageCatalog->forArt($skill)['lineage_key'] ?? null;
        $rank = (int) $skill->learn_rank;
        $metadata = $this->portableMetadata($actor, $skill);
        $rejectedPreparedEffects = is_array($metadata['rejects_prepared_effects'] ?? null)
            ? $metadata['rejects_prepared_effects']
            : [];
        $multiplier = 1.0;
        $preparedKeys = [];

        foreach ($actor->jobArtV2PreparedEffects() as $prepared) {
            if ($prepared->isExpired()) {
                $actor->removeJobArtV2PreparedEffect($prepared->key);
                continue;
            }

            $matchesTrigger = $skill->isJobArt()
                && $lineage === $prepared->targetLineage
                && in_array($rank, $prepared->targetRanks, true);
            if ($matchesTrigger) {
                if (! in_array($prepared->key, $rejectedPreparedEffects, true)) {
                    $multiplier *= max(0.0, $prepared->multiplier);
                    $preparedKeys[] = $prepared->key;
                }
                $prepared->consumeCharge();
                $prepared->consumeActionOpportunity();
                if ($prepared->isExpired()) {
                    $actor->removeJobArtV2PreparedEffect($prepared->key);
                }
                continue;
            }

            if ($prepared->strictNextAction) {
                $actor->removeJobArtV2PreparedEffect($prepared->key);
                continue;
            }

            $this->countPreparedEffectActionOpportunity($actor, $prepared);
        }

        $roleContext = $state->jobArtV2RoleAction($sourceActionId);
        $conditionalApplied = false;
        $conditional = $metadata['conditional_damage_multiplier'] ?? null;
        $target = $actor === $state->player ? $state->enemy : $state->player;
        if (is_array($conditional) && $this->matchesConditionalDamage(
            $actor,
            $target,
            $state,
            $conditional,
            $roleContext,
        )) {
            $huntingMarksToConsume = max(0, (int) ($conditional['consume_target_hunting_marks'] ?? 0));
            $consumed = $huntingMarksToConsume === 0
                || $this->progressionService->consumeHuntingMarksFor(
                    $target,
                    $actor,
                    $huntingMarksToConsume,
                );
            if ($consumed) {
                $multiplier *= max(0.0, (float) ($conditional['multiplier'] ?? 1.0));
                $conditionalApplied = true;
            }
        }

        $state->updateJobArtV2RoleAction($sourceActionId, [
            'source_skill_id' => (int) $skill->id,
            'source_skill_name' => (string) $skill->name,
            'role_damage_multiplier' => $multiplier,
            'prepared_effect_keys' => $preparedKeys,
            'conditional_multiplier_applied' => $conditionalApplied,
        ]);
        $this->progressionService->beginJobArtCast($actor, $state, $skill);
    }

    public function markNonJobArtAction(BattleActor $actor, ?BattleState $state = null): void
    {
        if ($state !== null) {
            $this->progressionService->markNonJobArtAction($actor, $state);
        }
        if (! $this->enabledFor($actor)) {
            return;
        }

        $sourceActionId = $state?->currentSourceActionId();
        if ($state !== null
            && ($sourceActionId === null
                || ! $state->claimJobArtV2RoleEffect($actor, 'prepared_non_job_art_action', $sourceActionId))
        ) {
            return;
        }

        foreach ($actor->jobArtV2PreparedEffects() as $prepared) {
            if ($prepared->strictNextAction) {
                $actor->removeJobArtV2PreparedEffect($prepared->key);
                continue;
            }

            $this->countPreparedEffectActionOpportunity($actor, $prepared);
        }
    }

    private function countPreparedEffectActionOpportunity(
        BattleActor $actor,
        JobArtV2PreparedEffectState $prepared,
    ): void {
        if (! $prepared->consumeActionOpportunity()) {
            return;
        }

        if ($prepared->isExpired()) {
            $actor->removeJobArtV2PreparedEffect($prepared->key);
        }
    }

    public function applyForExecution(
        BattleActor $actor,
        BattleActor $target,
        BattleState $state,
        Skill $sourceSkill,
        Skill $executionSkill,
    ): void {
        $resolvedCrownPiercePower = $this->resolvedCrownPiercePower(
            $actor,
            $sourceSkill,
            $executionSkill,
        );
        $resolvedCrownGuardReduction = $this->resolvedCrownGuardReduction(
            $sourceSkill,
            $executionSkill,
        );
        if ($this->enabledFor($actor)) {
            $this->balances()->applyToExistingExecution($executionSkill);
        }
        $this->restoreExecutionDamageReduction($executionSkill, $resolvedCrownGuardReduction);

        if (! empty($state->jobArtV2RoleAction()['ultimate_counterplay_lineage_suppressed'])) {
            return;
        }
        $this->restoreExecutionPower($executionSkill, $resolvedCrownPiercePower);
        $this->progressionService->applyForExecution($actor, $target, $state, $sourceSkill, $executionSkill);
        $metadata = $this->portableMetadata($actor, $sourceSkill);
        if ($metadata === null) {
            return;
        }

        if ((bool) ($metadata['suppress_legacy_effect'] ?? false)) {
            $template = is_string($metadata['replacement_template'] ?? null)
                ? (string) $metadata['replacement_template']
                : ($this->catalog->replacementTemplate($sourceSkill) ?? 'V2_ROLE_EFFECT_ONLY');
            $executionSkill->effect_template = $template;
            $executionSkill->damage_type = JobArtEffectCatalog::damageType($template);
            $this->clearSuppressedLegacySideEffects($executionSkill);
        }

        $this->applyExecutionPower($actor, $sourceSkill, $executionSkill, $metadata);
        $this->applyNormalAttackDamageType($actor, $executionSkill, $metadata);
        $this->applyDamageStatRoute($executionSkill, $metadata);
        $this->applyStructuredDebuffOverride($executionSkill, $metadata);
        // Legacy role metadata still supplies routes and special mechanics,
        // but the spreadsheet-backed crown balance owns the final base
        // power/debuff values. Apply it before adaptive multipliers so those
        // mechanics can still scale the canonical value (for example 王者の秘薬).
        $this->balances()->reapplyCoreExecutionValues($executionSkill);
        $this->restoreExecutionPower($executionSkill, $resolvedCrownPiercePower);
        $this->applyConditionalTargetMultiplier($target, $executionSkill, $metadata);
        $this->applyRewardPolicy($actor, $state, $sourceSkill, $executionSkill, $metadata);
        $this->applyAdaptiveRoute($actor, $target, $state, $sourceSkill, $executionSkill, $metadata);
        $this->applyAdaptiveSustain($actor, $executionSkill, $metadata);

        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId !== null) {
            $state->updateJobArtV2RoleAction($sourceActionId, [
                'execution_power' => $this->executionPower($executionSkill),
                'execution_template' => (string) $executionSkill->effect_template,
                'execution_damage_type' => (string) $executionSkill->damage_type,
            ]);
        }
    }

    public function completeJobArtCast(
        BattleActor $actor,
        BattleActor $target,
        BattleState $state,
        Skill $skill,
        ?HitResult $hitResult,
        ?\Closure $hpHealingResolver = null,
    ): void {
        if (! empty($state->jobArtV2RoleAction()['ultimate_counterplay_lineage_suppressed'])) {
            return;
        }
        $this->progressionService->completeJobArtCast($actor, $target, $state, $skill, $hitResult);
        $this->fieldService->completeJobArtCast($actor, $state, $skill, $hitResult === null || $hitResult->landed());
        $metadata = $this->portableMetadata($actor, $skill);
        $sourceActionId = $state->currentSourceActionId();
        if ($metadata === null
            || $sourceActionId === null
            || ! $state->claimJobArtV2RoleEffect($actor, 'complete_job_art_cast', $sourceActionId)
        ) {
            return;
        }

        $positiveEffectRemoved = $this->removePositiveTimedEffect($target, $state, $metadata);
        $this->applyAppraisal($target, $state, $metadata);
        $this->applySelfCost($actor, $state, $skill, $metadata, $hitResult);
        $this->applyCleanse($actor, $state, $skill, $metadata, $sourceActionId);
        $this->applyHeal($actor, $state, $skill, $metadata, $hpHealingResolver);
        $this->applyGuard($actor, $state, $skill, $metadata);
        $this->applyUntilNextActionDamageReduction($actor, $state, $metadata);
        $this->applyTimedEffect($actor, $state, $skill, $metadata, $positiveEffectRemoved);
        $this->applyPreparedEffect($actor, $state, $skill, $metadata);
        $this->applyCanonicalSelfBuff($actor, $state, $skill);
    }

    public function modifyJobArtDamage(
        BattleActor $actor,
        BattleState $state,
        Skill $skill,
        int $damage,
    ): int {
        if (! $this->enabledFor($actor) || $damage <= 0) {
            return max(0, $damage);
        }

        $context = $state->jobArtV2RoleAction();
        if (! empty($context['ultimate_counterplay_lineage_suppressed'])) {
            return max(0, $damage);
        }
        if (($context['actor_key'] ?? null) !== $state->actorKey($actor)
            || (int) ($context['source_skill_id'] ?? 0) !== (int) $skill->id
        ) {
            return $damage;
        }

        $multiplier = max(0.0, (float) ($context['role_damage_multiplier'] ?? 1.0));
        $targetMultiplier = max(0.0, (float) ($skill->getAttribute('job_art_v2_target_damage_multiplier') ?? 1.0));

        return $this->progressionService->modifyJobArtDamage(
            $actor,
            $state,
            $skill,
            max(0, (int) floor(($damage * $multiplier * $targetMultiplier) + 1.0e-9)),
        );
    }

    /** @return array{attack: ?int, def: ?int, spr: ?int, applied_ignore_rate: float} */
    public function damageStatOverrides(BattleActor $attacker, BattleActor $defender, Skill $skill): array
    {
        if (! $skill->isJobArt() || ! $this->enabledFor($attacker)) {
            return ['attack' => null, 'def' => null, 'spr' => null, 'applied_ignore_rate' => 0.0];
        }

        $attackStat = $skill->getAttribute('job_art_v2_attack_stat');
        $defenseStat = $skill->getAttribute('job_art_v2_defense_stat');
        $attack = is_string($attackStat) ? $this->effectiveStat($attacker, $attackStat) : null;
        $defense = is_string($defenseStat) ? $this->effectiveStat($defender, $defenseStat) : null;
        $ignoreRate = min(
            0.50,
            max(0.0, (float) $skill->getAttribute('job_art_v2_defense_ignore_percent') / 100),
        );
        if ($defense !== null && $ignoreRate > 0.0) {
            $defense = (int) floor($defense * (1 - $ignoreRate));
        }
        $appliedIgnoreRate = $defense !== null ? $ignoreRate : 0.0;

        return match ($defenseStat) {
            'def' => ['attack' => $attack, 'def' => $defense, 'spr' => $defense, 'applied_ignore_rate' => $appliedIgnoreRate],
            'spr' => ['attack' => $attack, 'def' => $defense, 'spr' => $defense, 'applied_ignore_rate' => $appliedIgnoreRate],
            default => ['attack' => $attack, 'def' => null, 'spr' => null, 'applied_ignore_rate' => 0.0],
        };
    }

    public function criticalBonusPoints(BattleActor $actor, Skill $skill): float
    {
        $metadata = $this->portableMetadata($actor, $skill);

        return $metadata !== null && is_numeric($metadata['critical_delta_points'] ?? null)
            ? max(0.0, (float) $metadata['critical_delta_points'])
            : 0.0;
    }

    /** @return list<array{actor_key:string,type:string,key:string,event:string,remaining_rounds:int|null}> */
    public function endRound(BattleState $state): array
    {
        $events = [];
        foreach ([$state->player, $state->enemy] as $actor) {
            $actorUsesRoleEffects = $this->enabledFor($actor);
            foreach ($actor->jobArtV2TimedEffects() as $effect) {
                if (! $actorUsesRoleEffects && ! str_starts_with($effect->key, 'master_stat_debuff:')) {
                    continue;
                }
                if (! $effect->advanceAtRoundEnd($state->turnCount)) {
                    continue;
                }

                $expired = $effect->isExpired();
                if ($expired) {
                    $actor->removeJobArtV2TimedEffect($effect->key);
                    $hasNegativeModifier = array_filter(
                        $effect->statModifiers,
                        static fn (float $modifier): bool => $modifier < 0.0,
                    ) !== [];
                    $effectLabel = $hasNegativeModifier ? '弱体効果' : '強化効果';
                    $state->addLog('<span class="text-slate-600 font-bold">'.e($actor->name).' の'.$effectLabel.'が切れた。</span>');
                }
                $events[] = [
                    'actor_key' => $state->actorKey($actor),
                    'type' => 'timed',
                    'key' => $effect->key,
                    'event' => $expired ? 'expired' : 'advanced',
                    'remaining_rounds' => $effect->remainingRounds,
                ];
            }

            foreach ($actor->jobArtV2PreparedEffects() as $effect) {
                if (! $actorUsesRoleEffects) {
                    continue;
                }
                if (! $effect->advanceAtRoundEnd($state->turnCount)) {
                    continue;
                }

                $expired = $effect->isExpired();
                if ($expired) {
                    $actor->removeJobArtV2PreparedEffect($effect->key);
                    $state->addLog('<span class="text-slate-600 font-bold">'.e($actor->name).' の準備効果が切れた。</span>');
                }
                $events[] = [
                    'actor_key' => $state->actorKey($actor),
                    'type' => 'prepared',
                    'key' => $effect->key,
                    'event' => $expired ? 'expired' : 'advanced',
                    'remaining_rounds' => $effect->remainingRounds,
                ];
            }
        }

        array_push($events, ...$this->progressionService->endRound($state));

        return $events;
    }

    public function supportEffectCanBeMeaningful(BattleActor $actor, Skill $skill): bool
    {
        $metadata = $this->portableMetadata($actor, $skill);
        if ($metadata === null) {
            return false;
        }

        $eligibility = $metadata['support_eligibility'] ?? null;
        if (is_array($eligibility)) {
            foreach ($eligibility as $condition) {
                if ($condition === 'hp_recoverable' && $actor->hp < $actor->maxHp) {
                    return true;
                }
                if ($condition === 'sp_recoverable' && $actor->mp < $actor->maxMp) {
                    return true;
                }
                if ($condition === 'template_cleanse_possible'
                    && (string) $skill->effect_template === 'HEAL_CLEANSE'
                    && $this->cleanseService->canCleanse($actor)
                ) {
                    return true;
                }
            }

            return false;
        }

        $reward = $metadata['reward'] ?? null;
        $hasRewardEffect = is_array($reward) && in_array('preserve_master', $reward, true);
        if (is_array($metadata['guard'] ?? null)
            || is_array($metadata['timed_effect'] ?? null)
            || is_array($metadata['prepared_effect'] ?? null)
            || is_array($metadata['self_cost'] ?? null)
            || $hasRewardEffect
            || is_array($metadata['remove_positive_effect'] ?? null)
            || is_array($metadata['field'] ?? null)
        ) {
            return true;
        }

        $canHeal = is_array($metadata['heal'] ?? null)
            && ($this->metadataHealsHp($metadata['heal']) && $actor->hp < $actor->maxHp
                || $this->metadataHealsSp($metadata['heal']) && $actor->mp < $actor->maxMp);
        $canCleanse = is_array($metadata['cleanse'] ?? null) && $this->cleanseService->canCleanse($actor);

        return $canHeal || $canCleanse;
    }

    /** @return array<string, mixed>|null */
    private function portableMetadata(BattleActor $actor, Skill $skill): ?array
    {
        if (! $this->enabledFor($actor)) {
            return null;
        }

        $resolution = $this->roles()->resolveActor($actor);
        if ($resolution->active && $resolution->blockReasonFor($skill) !== null) {
            return null;
        }
        // プレイヤー向けの主系譜／副系譜／出張区分は廃止済み。
        // 習得してセットした戦技は、所属職に関係なくカードに記載した
        // 効果を常にすべて実行する。portable は旧分類との互換情報として
        // catalog に残すが、実行可否の判定には使用しない。
        $roleMetadata = $this->catalog->forArt($skill);
        if ($this->featureGate->usesRank5V6($actor)) {
            $rank5V6Metadata = $this->catalog->rank5V6MetadataForArt($skill);
            if ($rank5V6Metadata !== null) {
                $roleMetadata = array_replace_recursive($roleMetadata ?? [], $rank5V6Metadata);
            }
        }
        if ($this->featureGate->usesRank5V6($actor)
            && (int) $skill->job_id === 56
            && (int) $skill->learn_rank === 5
        ) {
            // v6.1の《聖域結界》は旧版の能力上昇を引き継がず、
            // DefenseServiceが管理する一時軽減へ完全に置き換える。
            $roleMetadata = [];
        }
        // Spreadsheet-canonical self buffs are valid runtime effects even
        // when the older role catalog had no entry for that exact art. An
        // empty metadata scaffold lets completeJobArtCast apply the canonical
        // timed effect without restoring any removed prototype side effect.
        if ($roleMetadata === null && $this->balances()->hasSelfBuff($skill)) {
            $roleMetadata = [];
        }
        if (! $resolution->active) {
            return $roleMetadata;
        }

        $cDesignMetadata = ($this->cDesignEffectCatalog ?? app(JobArtV2CDesignEffectCatalog::class))
            ->forArt($skill);
        if ($roleMetadata === null) {
            return $cDesignMetadata;
        }
        if ($cDesignMetadata === null) {
            return $roleMetadata;
        }

        return array_replace_recursive($roleMetadata, $cDesignMetadata);
    }

    private function roles(): JobArtV2DeckRoleResolver
    {
        return $this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class);
    }

    private function balances(): JobArtV2CrownBalanceCatalog
    {
        return $this->crownBalanceCatalog ?? app(JobArtV2CrownBalanceCatalog::class);
    }

    /**
     * @param array<string, mixed> $conditional
     * @param array<string, mixed> $roleContext
     */
    private function matchesConditionalDamage(
        BattleActor $actor,
        BattleActor $target,
        BattleState $state,
        array $conditional,
        array $roleContext,
    ): bool {
        return match ((string) ($conditional['condition'] ?? '')) {
            'direct_attack_damage_received_since_previous_own_action' => (bool) (
                $roleContext['direct_attack_damage_received_since_previous_own_action'] ?? false
            ),
            'parry_success_since_previous_own_action' => (bool) (
                $roleContext['parry_success_since_previous_own_action'] ?? false
            ),
            'damage_mitigated_since_previous_own_action' => (bool) (
                $roleContext['damage_mitigated_since_previous_own_action'] ?? false
            ),
            'actor_hp_ratio_at_most' => (float) (
                $roleContext['progression_hp_rate_at_action_start']
                    ?? ($actor->maxHp > 0 ? $actor->hp / $actor->maxHp : 0.0)
            ) <= max(0.0, min(1.0, (float) ($conditional['maximum'] ?? 0.0))),
            'target_hunting_mark_at_least' => $this->progressionService->huntingMarkCountFor(
                $target,
                $actor,
            ) >= max(1, (int) ($conditional['minimum'] ?? 1)),
            default => false,
        };
    }

    /** @param array<string, mixed> $metadata */
    private function applyNormalAttackDamageType(
        BattleActor $actor,
        Skill $executionSkill,
        array $metadata,
    ): void {
        if (! (bool) ($metadata['use_normal_attack_damage_type'] ?? false)) {
            return;
        }

        $template = $actor->usesMagForNormalAttack() ? 'MAGICAL_DAMAGE' : 'PHYSICAL_DAMAGE';
        $executionSkill->effect_template = $template;
        $executionSkill->damage_type = JobArtEffectCatalog::damageType($template);
    }

    /** @param array<string, mixed> $metadata */
    private function applyExecutionPower(
        BattleActor $actor,
        Skill $sourceSkill,
        Skill $executionSkill,
        array $metadata,
    ): void
    {
        $configured = $metadata['execution_power'] ?? null;
        $basePower = is_numeric($configured)
            ? max(0, (int) $configured)
            : $this->catalog->executionPower($sourceSkill);
        if ($basePower === null) {
            return;
        }

        $power = max(0, $basePower);
        $executionSkill->power = $power;
        $executionSkill->power_multiplier = $power / 100;
    }

    /** @param array<string, mixed> $metadata */
    private function applyDamageStatRoute(Skill $executionSkill, array $metadata): void
    {
        $route = $metadata['damage_stat_route'] ?? null;
        if (! is_array($route)) {
            return;
        }

        $category = (string) ($route['damage_category'] ?? '');
        if (in_array($category, ['physical', 'magical'], true)) {
            $template = $category === 'magical' ? 'MAGICAL_DAMAGE' : 'PHYSICAL_DAMAGE';
            $executionSkill->effect_template = $template;
            $executionSkill->damage_type = $category;
        }
        $executionSkill->setAttribute('job_art_v2_attack_stat', (string) ($route['attack_stat'] ?? ''));
        $executionSkill->setAttribute('job_art_v2_defense_stat', (string) ($route['defense_stat'] ?? ''));
        $executionSkill->setAttribute(
            'job_art_v2_defense_ignore_percent',
            max(0, min(50, (int) ($route['defense_ignore_percent'] ?? 0))),
        );
    }

    /** @param array<string, mixed> $metadata */
    private function applyStructuredDebuffOverride(Skill $executionSkill, array $metadata): void
    {
        $debuff = $metadata['structured_debuff'] ?? null;
        if (! is_array($debuff)) {
            return;
        }

        foreach ([
            'enemy_atk_down_percent',
            'enemy_mag_down_percent',
            'enemy_def_down_percent',
            'enemy_spr_down_percent',
            'enemy_spd_down_percent',
        ] as $field) {
            $executionSkill->setAttribute($field, max(0, (int) ($debuff[$field] ?? 0)));
        }
        $executionSkill->setAttribute('duration_turns', max(1, (int) ($debuff['duration_turns'] ?? 1)));
    }

    /** @param array<string, mixed> $metadata */
    private function applyConditionalTargetMultiplier(
        BattleActor $target,
        Skill $executionSkill,
        array $metadata,
    ): void {
        $conditional = $metadata['conditional_target_multiplier'] ?? null;
        if (! is_array($conditional)) {
            return;
        }

        $speciesKeys = array_values(array_filter(
            (array) ($conditional['species_keys'] ?? []),
            static fn (mixed $key): bool => is_string($key) && $key !== '',
        ));
        $matchesSpecies = array_intersect($speciesKeys, $target->speciesKeys) !== [];
        $matchesMagicalType = (bool) ($conditional['include_magical_normal_attack'] ?? false)
            && $target->usesMagForNormalAttack();
        $multiplier = $matchesSpecies || $matchesMagicalType
            ? max(1.0, (float) ($conditional['multiplier'] ?? 1.0))
            : 1.0;

        $executionSkill->setAttribute('job_art_v2_target_damage_multiplier', $multiplier);
    }

    /** @param array<string, mixed> $metadata */
    private function applyAdaptiveRoute(
        BattleActor $actor,
        BattleActor $target,
        BattleState $state,
        Skill $sourceSkill,
        Skill $executionSkill,
        array $metadata,
    ): void {
        $adaptive = $metadata['adaptive_route'] ?? null;
        if (! is_array($adaptive)) {
            return;
        }

        $power = $this->executionPower($executionSkill);
        $hitCount = max(1, (int) ($executionSkill->hit_count ?: 1));
        $physical = $this->damageCalculator->estimateJobArtDamage(
            $actor,
            $target,
            'physical',
            $state->battleType,
            $power,
            $hitCount,
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
            $hitCount,
            minimumDamageGuaranteeEnabled: $state->rankBattleMinimumDamageGuaranteeEnabled,
            baseDamageMultiplier: $state->rankBattleBaseDamageMultiplier,
            additionalDefenseIgnoreRate: $state->speedBreakthroughAdditionalRateForEstimate(),
        );
        // Compare the same pre-application point used by real Job Art damage:
        // route calculation first, current action's field snapshot second.
        $physical = $this->fieldService->modifyDamage($actor, $state, $physical, DamageSourceType::JOB_ART, 'physical');
        if (in_array($state->battleType, ['pve', 'boss', 'tower'], true)) {
            $criticalPhysical = $this->damageCalculator->estimateJobArtDamage(
                $actor,
                $target,
                'physical',
                $state->battleType,
                $power,
                $hitCount,
                true,
            );
            $criticalPhysical = $this->fieldService->modifyDamage(
                $actor,
                $state,
                $criticalPhysical,
                DamageSourceType::JOB_ART,
                'physical',
            );
            $criticalRate = $this->damageCalculator->criticalChance(
                $actor,
                $target,
                $this->criticalBonusPoints($actor, $sourceSkill),
            ) / 100;
            $physical = ($physical * (1 - $criticalRate)) + ($criticalPhysical * $criticalRate);
        }
        $magical = $this->fieldService->modifyDamage($actor, $state, $magical, DamageSourceType::JOB_ART, 'magical');
        $masterRoute = JobArtEffectCatalog::damageType((string) $sourceSkill->effect_template);
        $route = $physical === $magical && in_array($masterRoute, ['physical', 'magical'], true)
            ? $masterRoute
            : ($magical > $physical ? 'magical' : 'physical');

        $executionSkill->effect_template = $route === 'magical' ? 'MAGICAL_DAMAGE' : 'PHYSICAL_DAMAGE';
        $executionSkill->damage_type = $route;
        $executionSkill->setAttribute('job_art_v2_adaptive_route', $route);
    }

    /** @param array<string, mixed> $metadata */
    private function applyRewardPolicy(
        BattleActor $actor,
        BattleState $state,
        Skill $sourceSkill,
        Skill $executionSkill,
        array $metadata,
    ): void {
        $reward = $metadata['reward'] ?? null;
        if (! is_array($reward)) {
            return;
        }

        if (! $state->jobArtRewardBonusesEnabled()) {
            foreach ([
                'gold_bonus_percent',
                'drop_bonus_percent',
                'rare_bonus_percent',
                'material_bonus_percent',
            ] as $field) {
                $executionSkill->setAttribute($field, 0);
            }
            $executionSkill->setAttribute('reward_scope', 'none');

            return;
        }

        if (($reward['gold'] ?? false) === false) {
            $executionSkill->gold_bonus_percent = 0;
        }
        if (($reward['drop'] ?? false) === false) {
            $executionSkill->drop_bonus_percent = 0;
            $executionSkill->rare_bonus_percent = 0;
            $executionSkill->material_bonus_percent = 0;
        }

        if (! (bool) ($metadata['suppress_legacy_effect'] ?? false)) {
            return;
        }

        $rate = 1.0;
        $base = max(1, (int) floor(max(1, (int) ($sourceSkill->power ?: 100)) / 20));
        $fallback = max(1, (int) floor($base * $rate));
        $changed = false;
        if (($reward['gold'] ?? false) === 'preserve_master') {
            $bonus = $this->rewardBonus((int) $sourceSkill->gold_bonus_percent, $fallback, $rate);
            $before = $state->goldBonusPercent;
            $state->goldBonusPercent = min(10, max($state->goldBonusPercent, $bonus));
            $changed = $changed || $state->goldBonusPercent !== $before;
        }
        if (($reward['drop'] ?? false) === 'preserve_master') {
            $bonus = $this->rewardBonus((int) $sourceSkill->drop_bonus_percent, $fallback, $rate);
            $before = [$state->dropBonusPercent, $state->rareBonusPercent];
            $state->dropBonusPercent = min(8, max($state->dropBonusPercent, $bonus));
            $rare = (int) $sourceSkill->rare_bonus_percent > 0
                ? $this->rewardBonus((int) $sourceSkill->rare_bonus_percent, $fallback, $rate)
                : (int) floor($bonus / 2);
            $state->rareBonusPercent = min(8, max($state->rareBonusPercent, $rare));
            $changed = $changed || $before !== [$state->dropBonusPercent, $state->rareBonusPercent];
        }

        $sourceActionId = $state->currentSourceActionId();
        if ($changed
            && $sourceActionId !== null
            && $state->claimJobArtV2RoleEffect($actor, 'role_reward', $sourceActionId)
        ) {
            $state->addLog('<span class="text-amber-700 font-bold">探索勝利時の報酬判定が少し良くなった！</span>');
        }
    }

    private function rewardBonus(int $configured, int $fallback, float $rate): int
    {
        return $configured > 0
            ? max(1, (int) floor($configured * $rate))
            : $fallback;
    }

    private function clearSuppressedLegacySideEffects(Skill $executionSkill): void
    {
        foreach ([
            'heal_percent',
            'mp_recover_percent',
            'self_damage_percent',
            'damage_reduction_percent',
            'self_buff_percent',
            'enemy_atk_down_percent',
            'enemy_mag_down_percent',
            'enemy_def_down_percent',
            'enemy_spr_down_percent',
            'enemy_spd_down_percent',
            'def_ignore_percent',
            'gold_bonus_percent',
            'drop_bonus_percent',
            'rare_bonus_percent',
            'material_bonus_percent',
        ] as $field) {
            $executionSkill->setAttribute($field, 0);
        }

        $executionSkill->setAttribute('drain_hp_rate', 0.0);
        $executionSkill->setAttribute('reward_scope', 'none');
    }

    /** @param array<string, mixed> $metadata */
    private function applyAppraisal(BattleActor $target, BattleState $state, array $metadata): void
    {
        if (! (bool) ($metadata['appraisal']['apply_to_target'] ?? false)) {
            return;
        }

        $target->markJobArtV2Appraised();
        $state->addLog('<span class="text-amber-700 font-bold">'.e($target->name).' を鑑定した！</span>');
    }

    /** @param array<string, mixed> $metadata */
    private function removePositiveTimedEffect(BattleActor $target, BattleState $state, array $metadata): bool
    {
        if (! is_array($metadata['remove_positive_effect'] ?? null)) {
            return false;
        }

        $candidates = array_values(array_filter(
            $target->jobArtV2TimedEffects(),
            static fn (JobArtV2TimedEffectState $effect): bool => ! $effect->isExpired()
                && $effect->removable
                && array_filter($effect->statModifiers, static fn (float $value): bool => $value > 0.0) !== [],
        ));
        usort($candidates, static function (JobArtV2TimedEffectState $left, JobArtV2TimedEffectState $right): int {
            $strength = $right->strength <=> $left->strength;

            return $strength !== 0 ? $strength : strcmp($left->key, $right->key);
        });
        $removed = $candidates[0] ?? null;
        if ($removed === null) {
            return false;
        }

        if ($this->progressionService->preventTimedBuffReduction($target, $state)) {
            return false;
        }

        $target->removeJobArtV2TimedEffect($removed->key);
        $state->addLog('<span class="text-amber-700 font-bold">'.e($target->name).' の強化効果を1つ解除した！</span>');

        return true;
    }

    /** @param array<string, mixed> $metadata */
    private function applySelfCost(
        BattleActor $actor,
        BattleState $state,
        Skill $skill,
        array $metadata,
        ?HitResult $hitResult,
    ): void
    {
        $cost = $metadata['self_cost'] ?? null;
        if (! is_array($cost)
            || ($cost['type'] ?? null) !== 'max_hp_rate'
            || ((bool) ($cost['requires_landed'] ?? false) && ! $hitResult?->landed())
        ) {
            return;
        }

        $requested = max(1, (int) floor($actor->maxHp * max(0.0, (float) ($cost['rate'] ?? 0.0))));
        $actual = (bool) ($cost['non_lethal'] ?? false)
            ? min($requested, max(0, $actor->hp - 1))
            : min($requested, max(0, $actor->hp));
        if ($actual <= 0) {
            return;
        }

        $actor->takeDamage($actual);
        $state->addLog(sprintf(
            '<span class="text-purple-600 font-bold">%s は %d のHPを代償にした！</span>',
            e($actor->name),
            $actual,
        ));
        if ((bool) ($cost['record_resource_event'] ?? true)) {
            $this->resourceService->recordSelfDamage($actor, $state, $actual);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function applyCleanse(
        BattleActor $actor,
        BattleState $state,
        Skill $skill,
        array $metadata,
        int $sourceActionId,
    ): void {
        $cleanse = $metadata['cleanse'] ?? null;
        $templateAuthority = ($metadata['cleanse_authority'] ?? null) === 'effect_template'
            && (string) $skill->effect_template === 'HEAL_CLEANSE';
        if (! is_array($cleanse) && ! $templateAuthority) {
            return;
        }

        $removeAll = is_array($cleanse)
            ? ($cleanse['maximum_states'] ?? null) === 'all'
            : $templateAuthority;
        $result = $this->cleanseService->cleanse($actor, $state, $sourceActionId, $removeAll);
        if (! $result->success) {
            return;
        }

        $state->addLog('<span class="text-emerald-700 font-bold">'.e($actor->name).' の有害状態を浄化した！（'.e(implode(' / ', $result->removedStates)).'）</span>');
        $this->resourceService->recordCleanseSuccess($actor, $state);
    }

    /** @param array<string, mixed> $metadata */
    private function applyHeal(
        BattleActor $actor,
        BattleState $state,
        Skill $skill,
        array $metadata,
        ?\Closure $hpHealingResolver = null,
    ): void {
        $heal = $metadata['heal'] ?? null;
        if (! is_array($heal) || ! (bool) ($metadata['suppress_legacy_effect'] ?? false)) {
            return;
        }

        $hpDefinition = is_array($heal['hp'] ?? null) ? $heal['hp'] : $heal;
        $spDefinition = is_array($heal['sp'] ?? null) ? $heal['sp'] : null;
        $conversionRefund = (bool) ($hpDefinition['refund_conversion_hp_loss'] ?? false)
            ? $this->conversionHpRefundAmount($actor, $state)
            : null;
        $hp = $conversionRefund ?? $this->hpHealAmount($actor, $state, $skill, $hpDefinition);
        if ($hp > 0) {
            // Conversion refunds restore exactly the HP paid by this action.
            // They are not ordinary healing, so field healing bonuses must not
            // turn the intended ±0 exchange into a net HP gain.
            $actual = $hpHealingResolver !== null
                ? $hpHealingResolver($actor, $state, $skill, $hp, $conversionRefund === null)
                : ($conversionRefund !== null
                    ? $actor->healHp($hp)
                    : $this->fieldService->applyHpHeal($actor, $state, $hp));
            $state->addLog('<span class="text-emerald-600 font-bold">HPが '.e((string) $actual).' 回復した！</span>');
        }

        if ($spDefinition !== null && ($spDefinition['formula'] ?? null) === 'max_sp_rate') {
            $recover = max(1, (int) floor($actor->maxMp * max(0.0, (float) ($spDefinition['rate'] ?? 0.0))));
            $recover = max(0, (int) floor($recover * (1 - $actor->conditionRate('recovery_block'))));
            $before = $actor->mp;
            $actor->mp = min($actor->maxMp, $actor->mp + $recover);
            $actual = $actor->mp - $before;
            if ($actual > 0) {
                $state->addLog('<span class="text-blue-500 font-bold">SPが '.e((string) $actual).' 回復した！</span>');
            }
        }
    }

    /** @param array<string, mixed> $definition */
    private function hpHealAmount(BattleActor $actor, BattleState $state, Skill $skill, array $definition): int
    {
        $executionPower = (int) ($state->jobArtV2RoleAction()['execution_power'] ?? 0);
        if ($executionPower <= 0) {
            $executionPower = $this->fallbackExecutionPower($actor, $skill);
        }

        return match ($definition['formula'] ?? null) {
            'existing_spr' => max(1, (int) floor(
                $actor->effectiveSpr()
                * ($executionPower / 100)
                * ($this->balances()->healSprPercent($skill) !== null
                    ? 1.0
                    : max(0.0, (float) ($definition['multiplier'] ?? 1.0))),
            )),
            'max_hp_rate' => max(1, (int) floor(
                $actor->maxHp * max(0.0, (float) ($definition['rate'] ?? 0.0)),
            )),
            default => 0,
        };
    }

    private function conversionHpRefundAmount(BattleActor $actor, BattleState $state): ?int
    {
        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null) {
            return null;
        }

        $actorKey = $state->actorKey($actor);
        foreach (array_reverse($state->conversionResults()) as $result) {
            if ($result->sourceActionId === $sourceActionId
                && $result->actorKey === $actorKey
                && $result->success
                && $result->actualHpLoss > 0
            ) {
                return $result->actualHpLoss;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $metadata */
    private function applyGuard(BattleActor $actor, BattleState $state, Skill $skill, array $metadata): void
    {
        $guard = $metadata['guard'] ?? null;
        if (is_array($guard) && (int) ($guard['charges'] ?? 0) > 0) {
            $canonicalPercent = $this->balances()->reductionPercent($skill);
            $this->defenseService->applyGuard(
                $actor,
                $state,
                $canonicalPercent !== null
                    ? $canonicalPercent / 100
                    : max(0.0, min(1.0, (float) ($guard['damage_reduction_rate'] ?? 0.0))),
            );
        }
    }

    /** @param array<string, mixed> $metadata */
    private function applyUntilNextActionDamageReduction(
        BattleActor $actor,
        BattleState $state,
        array $metadata,
    ): void {
        $percent = max(0, min(100, (int) ($metadata['next_action_damage_reduction_percent'] ?? 0)));
        if ($percent <= 0) {
            return;
        }

        $actor->damageReductionRate = max($actor->damageReductionRate, $percent);
        $state->addLog(sprintf(
            '<span class="text-blue-700 font-bold">%s は次の自分の行動開始まで、受けるダメージを %d%% 軽減する！</span>',
            e($actor->name),
            $percent,
        ));
    }

    /** @param array<string, mixed> $metadata */
    private function applyAdaptiveSustain(BattleActor $actor, Skill $executionSkill, array $metadata): void
    {
        $adaptive = $metadata['adaptive_sustain'] ?? null;
        if (! is_array($adaptive)) {
            return;
        }

        $hpRatio = $actor->maxHp > 0 ? $actor->hp / $actor->maxHp : 1.0;
        $spRatio = $actor->maxMp > 0 ? $actor->mp / $actor->maxMp : 1.0;
        $target = $hpRatio <= $spRatio ? 'hp' : 'sp';
        $multiplier = max(1.0, (float) ($adaptive['lower_ratio_multiplier'] ?? 1.0));

        if ($target === 'hp') {
            $power = (int) ($executionSkill->power ?: round((float) $executionSkill->power_multiplier * 100));
            $power = max(0, (int) round($power * $multiplier));
            $executionSkill->power = $power;
            $executionSkill->power_multiplier = $power / 100;
        } else {
            $executionSkill->mp_recover_percent = max(
                0,
                (int) round((int) $executionSkill->mp_recover_percent * $multiplier),
            );
        }

        $executionSkill->setAttribute('job_art_v2_adaptive_sustain_target', $target);
    }

    /** @param array<string, mixed> $metadata */
    private function applyTimedEffect(
        BattleActor $actor,
        BattleState $state,
        Skill $skill,
        array $metadata,
        bool $positiveEffectRemoved,
    ): void {
        if ($this->balances()->hasSelfBuff($skill)) {
            return;
        }

        $effect = $metadata['timed_effect'] ?? null;
        if (! is_array($effect)
            || (($effect['requires'] ?? null) === 'positive_effect_removed' && ! $positiveEffectRemoved)
        ) {
            return;
        }
        if (($effect['requires'] ?? null) === 'owned_primary_field_active') {
            $field = $state->primaryField();
            if ($field === null || $field->ownerActorKey !== $state->actorKey($actor)) {
                return;
            }
        }

        $modifiers = $this->resolveModifiers($actor, $effect);
        if ($modifiers === []) {
            return;
        }

        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null) {
            return;
        }

        $durationRounds = max(1, (int) ($effect['rounds'] ?? 1));
        $actor->replaceJobArtV2TimedEffect(new JobArtV2TimedEffectState(
            key: (string) $effect['key'],
            statModifiers: $modifiers,
            appliedRound: $state->turnCount,
            remainingRounds: $durationRounds,
            sourceActionId: $sourceActionId,
            sourceSkillId: (int) $skill->id,
            removable: (bool) ($effect['removable'] ?? false),
            strength: max(0.0, (float) ($effect['strength'] ?? 0.0)),
        ));
        $this->addStatBuffLog($actor, $state, $modifiers, $durationRounds, 'ラウンド');
    }

    /** @param array<string, float>|null $modifiers */
    private function applyCanonicalSelfBuff(
        BattleActor $actor,
        BattleState $state,
        Skill $skill,
        ?array $modifiers = null,
        bool $logChange = true,
    ): void
    {
        $modifiers ??= $this->balances()->selfBuffModifiers($skill, $actor);
        $sourceActionId = $state->currentSourceActionId();
        if ($modifiers === [] || $sourceActionId === null) {
            return;
        }

        $key = 'canonical_self_buff:'.(int) $skill->job_id.':'.(int) $skill->learn_rank;
        if ($actor->jobArtV2TimedEffect($key)?->sourceActionId === $sourceActionId) {
            return;
        }

        $durationTurns = $this->balances()->durationTurns($skill);
        $actor->replaceJobArtV2TimedEffect(new JobArtV2TimedEffectState(
            key: $key,
            statModifiers: $modifiers,
            appliedRound: $state->turnCount,
            remainingRounds: $durationTurns,
            sourceActionId: $sourceActionId,
            sourceSkillId: (int) $skill->id,
            removable: true,
            strength: max(array_map('abs', $modifiers)),
        ));

        if ($logChange) {
            $this->logCanonicalSelfBuff($actor, $state, $modifiers, $durationTurns);
        }
    }

    /** @param  array<string, float>  $modifiers */
    private function logCanonicalSelfBuff(
        BattleActor $actor,
        BattleState $state,
        array $modifiers,
        int $durationTurns,
    ): void
    {
        $this->addStatBuffLog($actor, $state, $modifiers, $durationTurns, 'ターン');
    }

    /** @param array<string, float> $modifiers */
    private function addStatBuffLog(
        BattleActor $actor,
        BattleState $state,
        array $modifiers,
        int $duration,
        string $durationUnit,
    ): void {
        $log = $this->statBuffLogFormatter->formatIncrease(
            $actor->name,
            $modifiers,
            $duration,
            $durationUnit,
        );
        if ($log !== null) {
            $state->addLog($log);
        }
    }

    /** @param array<string, mixed> $effect @return array<string, float> */
    private function resolveModifiers(BattleActor $actor, array $effect): array
    {
        $modifiers = $effect['modifiers'] ?? null;
        if (is_array($modifiers)) {
            $resolved = [];
            foreach ($modifiers as $stat => $rate) {
                if (is_string($stat) && is_numeric($rate)) {
                    $resolved[$stat] = (float) $rate;
                }
            }

            return $resolved;
        }

        $dynamic = $effect['dynamic_modifier'] ?? null;
        if (! is_array($dynamic) || ! is_numeric($dynamic['rate'] ?? null)) {
            return [];
        }

        $stats = is_array($dynamic['stats'] ?? null) ? array_values($dynamic['stats']) : [];
        if ($stats === []) {
            return [];
        }
        $selection = (string) ($dynamic['select'] ?? 'lowest_current_stat');
        if ($selection === 'lowest_current_stat' && is_array($dynamic['tie_break_order'] ?? null)) {
            $stats = array_values($dynamic['tie_break_order']);
        }

        $selected = null;
        $selectedValue = null;
        foreach ($stats as $stat) {
            if (! is_string($stat)) {
                continue;
            }
            $value = $this->effectiveStat($actor, $stat);
            if ($value === null) {
                continue;
            }
            if ($selected === null
                || ($selection === 'higher_current_stat' && $value > $selectedValue)
                || ($selection !== 'higher_current_stat' && $value < $selectedValue)
            ) {
                $selected = $stat;
                $selectedValue = $value;
            }
        }

        return $selected !== null ? [$selected => (float) $dynamic['rate']] : [];
    }

    private function effectiveStat(BattleActor $actor, string $stat): ?int
    {
        return match ($stat) {
            'str' => $actor->effectiveStr(),
            'def' => $actor->effectiveDef(),
            'agi' => $actor->effectiveAgi(),
            'mag' => $actor->effectiveMag(),
            'spr' => $actor->effectiveSpr(),
            'luk' => $actor->effectiveLuk(),
            default => null,
        };
    }

    /** @param array<string, mixed> $metadata */
    private function applyPreparedEffect(BattleActor $actor, BattleState $state, Skill $skill, array $metadata): void
    {
        $effect = $metadata['prepared_effect'] ?? null;
        $sourceActionId = $state->currentSourceActionId();
        if (! is_array($effect) || $sourceActionId === null) {
            return;
        }

        $trigger = is_array($effect['trigger'] ?? null) ? $effect['trigger'] : [];
        $key = (string) ($effect['key'] ?? '');
        $lineage = (string) ($trigger['lineage_key'] ?? '');
        $ranks = array_values(array_map('intval', is_array($trigger['learn_ranks'] ?? null) ? $trigger['learn_ranks'] : []));
        if ($key === '' || $lineage === '' || $ranks === []) {
            return;
        }

        $group = (string) ($effect['stack_group'] ?? $key);
        if ((bool) ($effect['replace_same_group'] ?? true)) {
            foreach ($actor->jobArtV2PreparedEffects() as $existing) {
                if ($existing->group === $group) {
                    $actor->removeJobArtV2PreparedEffect($existing->key);
                }
            }
        }

        $actor->replaceJobArtV2PreparedEffect(new JobArtV2PreparedEffectState(
            key: $key,
            multiplier: max(0.0, (float) ($effect['damage_multiplier'] ?? 1.0)),
            appliedRound: $state->turnCount,
            remainingRounds: isset($effect['rounds']) ? max(1, (int) $effect['rounds']) : null,
            charges: max(1, (int) ($effect['charges'] ?? 1)),
            sourceActionId: $sourceActionId,
            sourceSkillId: (int) $skill->id,
            targetLineage: $lineage,
            targetRanks: $ranks,
            strictNextAction: (bool) ($effect['expire_on_next_executed_non_trigger_action'] ?? false),
            group: $group,
            remainingActionOpportunities: isset($effect['action_opportunities'])
                ? max(1, (int) $effect['action_opportunities'])
                : null,
        ));
        $state->addLog('<span class="text-indigo-700 font-bold">'.e($actor->name).' は次の一撃に備えた！</span>');
    }

    private function executionPower(Skill $skill): int
    {
        if ((int) $skill->power > 0) {
            return (int) $skill->power;
        }

        return max(0, (int) round((float) $skill->power_multiplier * 100));
    }

    private function resolvedCrownPiercePower(
        BattleActor $actor,
        Skill $sourceSkill,
        Skill $executionSkill,
    ): ?int {
        if (! $this->enabledFor($actor)
            || ! $sourceSkill->isJobArt()
            || (int) $sourceSkill->job_id !== 62
            || (int) $sourceSkill->learn_rank !== 9
            || ! $this->progressionService->crownPierceRankFiveUsed($actor)
        ) {
            return null;
        }

        // PowerResolver has already applied the battle-only 470% branch here.
        // Keep that resolved value when the static L-column base is reapplied.
        return $this->executionPower($executionSkill);
    }

    private function restoreExecutionPower(Skill $executionSkill, ?int $power): void
    {
        if ($power === null) {
            return;
        }

        $executionSkill->power = $power;
        $executionSkill->power_multiplier = $power / 100;
    }

    private function resolvedCrownGuardReduction(Skill $sourceSkill, Skill $executionSkill): ?int
    {
        if ((int) $sourceSkill->job_id !== JobArtV2DefenseService::GUARD_JOB_ID
            || ! in_array((int) $sourceSkill->learn_rank, [1, 5, 9], true)
            || (string) $executionSkill->effect_template !== 'MAGICAL_DAMAGE'
        ) {
            return null;
        }

        // EffectSemanticsResolver has replaced the legacy self-buff with the
        // structured one-shot Guard. Preserve its explicit zero across the
        // later static CrownBalance reapplication.
        return (int) $executionSkill->damage_reduction_percent;
    }

    private function restoreExecutionDamageReduction(Skill $executionSkill, ?int $reduction): void
    {
        if ($reduction === null) {
            return;
        }

        $executionSkill->damage_reduction_percent = $reduction;
    }

    private function fallbackExecutionPower(BattleActor $actor, Skill $skill): int
    {
        return max(1, (int) ($skill->power ?: 100));
    }

    /** @param array<string, mixed> $heal */
    private function metadataHealsHp(array $heal): bool
    {
        $definition = is_array($heal['hp'] ?? null) ? $heal['hp'] : $heal;

        return in_array($definition['formula'] ?? null, ['existing_spr', 'max_hp_rate'], true);
    }

    /** @param array<string, mixed> $heal */
    private function metadataHealsSp(array $heal): bool
    {
        return is_array($heal['sp'] ?? null)
            && ($heal['sp']['formula'] ?? null) === 'max_sp_rate';
    }
}
