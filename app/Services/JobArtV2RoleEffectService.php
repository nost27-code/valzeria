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
        private readonly JobArtV2FieldService $fieldService,
        private readonly JobArtV2DefenseService $defenseService,
        private readonly JobArtV2CleanseService $cleanseService,
        private readonly JobArtV2ResourceService $resourceService,
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

        $physicalAttackReceived = $actor->consumePhysicalAttackReceivedSinceOwnActionSnapshot();
        $state->beginJobArtV2RoleAction($sourceActionId, [
            'actor_key' => $state->actorKey($actor),
            'source_action_id' => $sourceActionId,
            'physical_attack_received_since_own_action' => $physicalAttackReceived,
            'physical_attack_received_since_previous_own_action' => $physicalAttackReceived,
            'role_damage_multiplier' => 1.0,
            'prepared_effect_keys' => [],
            'conditional_multiplier_applied' => false,
        ]);
    }

    public function recordPhysicalAttackReceived(BattleActor $actor): void
    {
        if ($this->enabledFor($actor)) {
            $actor->markPhysicalAttackReceivedSinceOwnAction();
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
        if (is_array($conditional)
            && ($conditional['condition'] ?? null) === 'physical_attack_received_since_previous_own_action'
            && (bool) ($roleContext['physical_attack_received_since_previous_own_action'] ?? false)
        ) {
            $multiplier *= max(0.0, (float) ($conditional['multiplier'] ?? 1.0));
            $conditionalApplied = true;
        }

        $state->updateJobArtV2RoleAction($sourceActionId, [
            'source_skill_id' => (int) $skill->id,
            'source_skill_name' => (string) $skill->name,
            'role_damage_multiplier' => $multiplier,
            'prepared_effect_keys' => $preparedKeys,
            'conditional_multiplier_applied' => $conditionalApplied,
        ]);
    }

    public function markNonJobArtAction(BattleActor $actor, ?BattleState $state = null): void
    {
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
        $metadata = $this->portableMetadata($actor, $sourceSkill);
        if ($metadata === null) {
            return;
        }

        if ((bool) ($metadata['suppress_legacy_effect'] ?? false)) {
            $template = $this->catalog->replacementTemplate($sourceSkill) ?? 'V2_ROLE_EFFECT_ONLY';
            $executionSkill->effect_template = $template;
            $executionSkill->damage_type = JobArtEffectCatalog::damageType($template);
            $this->clearSuppressedLegacySideEffects($executionSkill);
        }

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
    ): void {
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
        $this->applySelfCost($actor, $state, $skill, $metadata);
        $this->applyCleanse($actor, $state, $skill, $metadata, $sourceActionId);
        $this->applyHeal($actor, $state, $skill, $metadata);
        $this->applyGuard($actor, $state, $metadata);
        $this->applyTimedEffect($actor, $state, $skill, $metadata, $positiveEffectRemoved);
        $this->applyPreparedEffect($actor, $state, $skill, $metadata);
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
        if (($context['actor_key'] ?? null) !== $state->actorKey($actor)
            || (int) ($context['source_skill_id'] ?? 0) !== (int) $skill->id
        ) {
            return $damage;
        }

        $multiplier = max(0.0, (float) ($context['role_damage_multiplier'] ?? 1.0));

        return max(0, (int) floor($damage * $multiplier));
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
            if (! $this->enabledFor($actor)) {
                continue;
            }

            foreach ($actor->jobArtV2TimedEffects() as $effect) {
                if (! $effect->advanceAtRoundEnd($state->turnCount)) {
                    continue;
                }

                $expired = $effect->isExpired();
                if ($expired) {
                    $actor->removeJobArtV2TimedEffect($effect->key);
                    $state->addLog('<span class="text-slate-600 font-bold">'.e($actor->name).' の強化効果が切れた。</span>');
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
        if (! $this->enabledFor($actor) || ! $this->catalog->isPortable($skill)) {
            return null;
        }

        return $this->catalog->forArt($skill);
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
        );
        $magical = $this->damageCalculator->estimateJobArtDamage(
            $actor,
            $target,
            'magical',
            $state->battleType,
            $power,
            $hitCount,
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

        $rate = max(0.0, (float) ($actor->jobArtRates[(int) $sourceSkill->id] ?? 1.0));
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

        $target->removeJobArtV2TimedEffect($removed->key);
        $state->addLog('<span class="text-amber-700 font-bold">'.e($target->name).' の強化効果を1つ解除した！</span>');

        return true;
    }

    /** @param array<string, mixed> $metadata */
    private function applySelfCost(BattleActor $actor, BattleState $state, Skill $skill, array $metadata): void
    {
        $cost = $metadata['self_cost'] ?? null;
        if (! is_array($cost) || ($cost['type'] ?? null) !== 'max_hp_rate') {
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
        $this->resourceService->recordSelfDamage($actor, $state, $actual);
        $state->addLog(sprintf(
            '<span class="text-purple-600 font-bold">%s は %d のHPを代償にした！</span>',
            e($actor->name),
            $actual,
        ));
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

        $this->resourceService->recordCleanseSuccess($actor, $state);
        $state->addLog('<span class="text-emerald-700 font-bold">'.e($actor->name).' の有害状態を浄化した！（'.e(implode(' / ', $result->removedStates)).'）</span>');
    }

    /** @param array<string, mixed> $metadata */
    private function applyHeal(BattleActor $actor, BattleState $state, Skill $skill, array $metadata): void
    {
        $heal = $metadata['heal'] ?? null;
        if (! is_array($heal) || ! (bool) ($metadata['suppress_legacy_effect'] ?? false)) {
            return;
        }

        $hpDefinition = is_array($heal['hp'] ?? null) ? $heal['hp'] : $heal;
        $spDefinition = is_array($heal['sp'] ?? null) ? $heal['sp'] : null;
        $hp = $this->hpHealAmount($actor, $state, $skill, $hpDefinition);
        if ($hp > 0) {
            $actual = $actor->healHp($this->fieldService->modifyHpHeal($actor, $state, $hp));
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
        return match ($definition['formula'] ?? null) {
            'existing_spr' => max(1, (int) floor(
                $actor->effectiveSpr()
                * (($state->jobArtV2RoleAction()['execution_power'] ?? $this->fallbackExecutionPower($actor, $skill)) / 100)
                * max(0.0, (float) ($definition['multiplier'] ?? 1.0)),
            )),
            'max_hp_rate' => max(1, (int) floor(
                $actor->maxHp * max(0.0, (float) ($definition['rate'] ?? 0.0)),
            )),
            default => 0,
        };
    }

    /** @param array<string, mixed> $metadata */
    private function applyGuard(BattleActor $actor, BattleState $state, array $metadata): void
    {
        $guard = $metadata['guard'] ?? null;
        if (is_array($guard) && (int) ($guard['charges'] ?? 0) > 0) {
            $this->defenseService->applyGuard(
                $actor,
                $state,
                max(0.0, min(1.0, (float) ($guard['damage_reduction_rate'] ?? 0.0))),
            );
        }
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
        $effect = $metadata['timed_effect'] ?? null;
        if (! is_array($effect)
            || (($effect['requires'] ?? null) === 'positive_effect_removed' && ! $positiveEffectRemoved)
        ) {
            return;
        }

        $modifiers = $this->resolveModifiers($actor, $effect);
        if ($modifiers === []) {
            return;
        }

        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null) {
            return;
        }

        $actor->replaceJobArtV2TimedEffect(new JobArtV2TimedEffectState(
            key: (string) $effect['key'],
            statModifiers: $modifiers,
            appliedRound: $state->turnCount,
            remainingRounds: max(1, (int) ($effect['rounds'] ?? 1)),
            sourceActionId: $sourceActionId,
            sourceSkillId: (int) $skill->id,
            removable: (bool) ($effect['removable'] ?? false),
            strength: max(0.0, (float) ($effect['strength'] ?? 0.0)),
        ));
        $state->addLog('<span class="text-indigo-700 font-bold">'.e($actor->name).' は一時強化を得た！（'.max(1, (int) ($effect['rounds'] ?? 1)).'ラウンド）</span>');
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

    private function fallbackExecutionPower(BattleActor $actor, Skill $skill): int
    {
        $rate = max(0.0, (float) ($actor->jobArtRates[(int) $skill->id] ?? 1.0));

        return max(1, (int) round(max(1, (int) ($skill->power ?: 100)) * $rate));
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
