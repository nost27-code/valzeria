<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use Closure;

class JobArtV2SelectionService
{
    private readonly JobArtV2ResourceService $resourceService;
    private readonly JobArtV2FieldService $fieldService;
    private readonly JobArtV2SlotConditionCatalog $slotConditions;
    private readonly JobArtV2ResourceCatalog $resourceCatalog;
    private readonly JobArtV2CleanseService $cleanseService;
    private readonly JobArtV2RoleEffectCatalog $roleEffectCatalog;
    private readonly JobArtV2RoleEffectService $roleEffectService;
    private readonly JobArtV2ProgressionService $progressionService;
    private readonly JobArtV2DeckRoleResolver $deckRoleResolver;
    private readonly JobArtV2UltimateCounterplayService $ultimateCounterplayService;
    private readonly JobArtV2CrownBalanceCatalog $crownBalanceCatalog;
    private readonly JobArtV2FeatureGate $featureGate;
    private readonly JobArtV2Rank5V6Catalog $rank5V6Catalog;

    public function __construct(
        private readonly JobArtV2RandomSource $random,
        private readonly JobArtV2FinisherConditionProvider $finisherCondition,
        private readonly JobArtV2SpCostCalculator $spCostCalculator,
        private readonly JobArtV2BattleRules $battleRules,
        ?JobArtV2ResourceService $resourceService = null,
        ?JobArtV2FieldService $fieldService = null,
        ?JobArtV2SlotConditionCatalog $slotConditions = null,
        ?JobArtV2ResourceCatalog $resourceCatalog = null,
        ?JobArtV2CleanseService $cleanseService = null,
        ?JobArtV2RoleEffectCatalog $roleEffectCatalog = null,
        ?JobArtV2RoleEffectService $roleEffectService = null,
        ?JobArtV2ProgressionService $progressionService = null,
        ?JobArtV2DeckRoleResolver $deckRoleResolver = null,
        ?JobArtV2UltimateCounterplayService $ultimateCounterplayService = null,
        ?JobArtV2CrownBalanceCatalog $crownBalanceCatalog = null,
        ?JobArtV2FeatureGate $featureGate = null,
        ?JobArtV2Rank5V6Catalog $rank5V6Catalog = null,
    ) {
        $this->resourceService = $resourceService ?? app(JobArtV2ResourceService::class);
        $this->fieldService = $fieldService ?? app(JobArtV2FieldService::class);
        $this->slotConditions = $slotConditions ?? app(JobArtV2SlotConditionCatalog::class);
        $this->resourceCatalog = $resourceCatalog ?? app(JobArtV2ResourceCatalog::class);
        $this->cleanseService = $cleanseService ?? app(JobArtV2CleanseService::class);
        $this->roleEffectCatalog = $roleEffectCatalog ?? app(JobArtV2RoleEffectCatalog::class);
        $this->roleEffectService = $roleEffectService ?? app(JobArtV2RoleEffectService::class);
        $this->progressionService = $progressionService ?? app(JobArtV2ProgressionService::class);
        $this->deckRoleResolver = $deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class);
        $this->ultimateCounterplayService = $ultimateCounterplayService
            ?? app(JobArtV2UltimateCounterplayService::class);
        $this->crownBalanceCatalog = $crownBalanceCatalog
            ?? app(JobArtV2CrownBalanceCatalog::class);
        $this->featureGate = $featureGate ?? app(JobArtV2FeatureGate::class);
        $this->rank5V6Catalog = $rank5V6Catalog ?? app(JobArtV2Rank5V6Catalog::class);
    }

    public function selectForTurn(
        BattleActor $actor,
        BattleState $state,
        ?Closure $stateKeyForSkill = null,
    ): JobArtV2SelectionResult {
        $stateKeyForSkill ??= static fn (Skill $skill): int => (int) $skill->id;
        [$candidates, $rankNinePrioritized] = $this->orderedCandidates($actor, $state, $stateKeyForSkill);
        $blockedReasons = [];

        foreach ($candidates as $skill) {
            $stateKey = $stateKeyForSkill($skill);
            $blockedReason = $this->eligibilityFailureReason($actor, $state, $skill, $stateKey);
            if ($blockedReason !== null) {
                $blockedReasons[(int) $skill->id] = $blockedReason;
                $this->logProgressionGateFailure($actor, $state, $skill, $blockedReason);
                continue;
            }
            if ($this->progressionService->consumeSealIfBlocked($actor, $state, $skill)) {
                $blockedReasons[(int) $skill->id] = 'blocked_by_seal';
                continue;
            }

            $this->markRank5V6Attempted($actor, $skill);
            $activationRate = $this->progressionService->activationRate($actor, $skill, $this->fieldService->activationRate(
                $actor,
                $state,
                $this->battleRules->activationRateFor(
                    $skill,
                    $actor->currentJobId,
                    $this->originFor($actor, $skill),
                ),
            ));
            $activated = $this->random->percentRoll() <= $activationRate;
            $this->progressionService->finishActivationAttempt($actor, $skill, $activated);
            if ($rankNinePrioritized && (int) $skill->learn_rank === 9) {
                $actor->markJobArtV2UltimatePriorityAttempted((int) $skill->id);
            }
            $this->advanceCDesignCursor($actor, $skill);
            if (! $activated && $rankNinePrioritized && (int) $skill->learn_rank === 9) {
                $state->addLog(
                    '<span class="text-amber-700 font-bold">'
                    .e($actor->name).' の《'.e($skill->name).'》は発動しなかった！（発動率'.$activationRate.'%）'
                    .'次の行動は通常の候補順に戻る。</span>',
                );
            }

            return new JobArtV2SelectionResult(
                skill: $activated ? $skill : null,
                candidateSkillId: (int) $skill->id,
                activationRate: $activationRate,
                activated: $activated,
                retriedAfterMiss: false,
                rankNinePrioritized: $rankNinePrioritized,
                blockedReasons: $blockedReasons,
            );
        }

        return new JobArtV2SelectionResult(
            skill: null,
            candidateSkillId: null,
            activationRate: null,
            activated: false,
            retriedAfterMiss: false,
            rankNinePrioritized: $rankNinePrioritized,
            blockedReasons: $blockedReasons,
        );
    }

    public function isEligible(BattleActor $actor, BattleState $state, Skill $skill, int|string $stateKey): bool
    {
        return $this->eligibilityFailureReason($actor, $state, $skill, $stateKey) === null;
    }

    public function eligibilityFailureReason(
        BattleActor $actor,
        BattleState $state,
        Skill $skill,
        int|string $stateKey,
    ): ?string
    {
        $condition = (string) ($actor->jobArtConditions[(int) $skill->id]
            ?? $skill->getAttribute('job_art_slot_condition')
            ?? JobArtV2SlotConditionCatalog::ALWAYS);
        if (! $this->slotConditions->matches($condition, $actor, $state)) {
            return 'blocked_by_condition';
        }

        $spCost = $this->spCostCalculator->forActor($actor, $skill);
        $policy = (string) ($actor->jobArtPolicies[(int) $skill->id] ?? $actor->jobArtActivationPolicy);
        if (!$this->canActivateByPolicy($actor, $spCost, $policy)) {
            return 'blocked_by_sp_or_policy';
        }

        if (!$this->canActivateRecoveryArt($actor, $skill)) {
            return 'blocked_by_support_condition';
        }

        $rank5V6Block = $this->rank5V6BlockReason($actor, $state, $skill);
        if ($rank5V6Block !== null) {
            return $rank5V6Block;
        }

        return $this->ultimateCounterplayService->eligibilityBlockReason($actor, $state, $skill)
            ?? $this->progressionService->eligibilityBlockReason($actor, $state, $skill)
            ?? $this->resourceService->eligibilityBlockReason($actor, $skill, $state);
    }

    /** @return array{0: array<int, Skill>, 1: bool} */
    private function orderedCandidates(
        BattleActor $actor,
        BattleState $state,
        Closure $stateKeyForSkill,
    ): array
    {
        $slotCandidates = array_values(array_filter(
            $actor->jobArts,
            static fn ($skill): bool => $skill instanceof Skill,
        ));
        $candidates = $slotCandidates;
        $candidates = $this->progressionService->orderCandidates($actor, $candidates);
        $ultimatePriorityCache = [];
        $shouldPrioritizeUltimate = function (Skill $skill) use ($actor, $state, &$ultimatePriorityCache): bool {
            $skillId = (int) $skill->id;

            return $ultimatePriorityCache[$skillId]
                ??= $this->shouldPrioritizeUltimate($actor, $state, $skill);
        };

        if (!$this->resourceService->enabledFor($actor)) {
            return [$candidates, false];
        }

        $deckRoles = $this->deckRoleResolver->resolveActor($actor);
        if ($deckRoles->active) {
            if ($this->ultimateCounterplayService->enabledFor($state)
                || $this->ultimateCounterplayService->pveTelegraphAvailable($actor, $state)
            ) {
                // 発動可能な主奥義と対奥義連携を同じ優先層へ置き、
                // その層の中ではプレイヤーが設定したslot順を守る。
                $priority = [];
                foreach ($slotCandidates as $skill) {
                    if (! ($shouldPrioritizeUltimate($skill)
                            || $this->ultimateCounterplayService->isResponseCandidate($actor, $state, $skill))
                        || ! $this->isEligible($actor, $state, $skill, $stateKeyForSkill($skill))
                    ) {
                        continue;
                    }

                    $priority[] = $skill;
                }

                if ($priority !== []) {
                    $priorityIds = array_fill_keys(array_map(
                        static fn (Skill $skill): int => (int) $skill->id,
                        $priority,
                    ), true);
                    $remaining = array_values(array_filter(
                        $candidates,
                        static fn (Skill $skill): bool => ! isset($priorityIds[(int) $skill->id]),
                    ));

                    return [
                        [...$priority, ...$remaining],
                        $shouldPrioritizeUltimate($priority[0]),
                    ];
                }

                return [$this->rotateFromCDesignCursor($actor, $candidates), false];
            }

            // 装備中の奥義は、そのカード自身の資源と準備条件が成立した時に優先する。
            foreach ($candidates as $index => $skill) {
                if ($deckRoles->roleFor($skill) !== JobArtV2DeckRole::MAIN
                    || (int) $skill->learn_rank !== 9
                    || ! $shouldPrioritizeUltimate($skill)
                    || ! $this->isEligible($actor, $state, $skill, $stateKeyForSkill($skill))
                ) {
                    continue;
                }

                unset($candidates[$index]);
                array_unshift($candidates, $skill);

                return [array_values($candidates), true];
            }

            return [$this->rotateFromCDesignCursor($actor, $candidates), false];
        }

        // 資源周期ごとの初回だけ、満タンになった奥義を優先する。
        foreach (['current', 'same_lineage_inherited', 'cross_lineage_inherited'] as $priorityGroup) {
            foreach ($candidates as $index => $skill) {
                $origin = $this->originFor($actor, $skill);
                $matchesGroup = match ($priorityGroup) {
                    'current' => $origin === 'current',
                    'same_lineage_inherited' => $origin === 'inherited'
                        && $this->resourceCatalog->isSameLineageInherited($actor, $skill),
                    'cross_lineage_inherited' => $origin === 'inherited'
                        && $this->resourceCatalog->isCrossLineageInherited($actor, $skill),
                    default => false,
                };
                if (! $matchesGroup
                    || (int) $skill->learn_rank !== 9
                    || ! $shouldPrioritizeUltimate($skill)
                    || ! $this->isEligible($actor, $state, $skill, $stateKeyForSkill($skill))
                ) {
                    continue;
                }

                unset($candidates[$index]);
                array_unshift($candidates, $skill);

                return [array_values($candidates), true];
            }
        }

        return [$candidates, false];
    }

    /** @param list<Skill> $candidates @return list<Skill> */
    private function rotateFromCDesignCursor(BattleActor $actor, array $candidates): array
    {
        $count = count($candidates);
        if ($count < 2) {
            return $candidates;
        }

        $cursor = $actor->jobArtV2SelectionCursor($count);

        return array_values(array_merge(
            array_slice($candidates, $cursor),
            array_slice($candidates, 0, $cursor),
        ));
    }

    private function advanceCDesignCursor(BattleActor $actor, Skill $attempted): void
    {
        if (! $this->deckRoleResolver->resolveActor($actor)->active) {
            return;
        }

        $candidates = array_values(array_filter(
            $actor->jobArts,
            static fn ($skill): bool => $skill instanceof Skill,
        ));
        foreach ($candidates as $index => $candidate) {
            if ((int) $candidate->id === (int) $attempted->id) {
                $actor->setJobArtV2SelectionCursor($index + 1, count($candidates));

                return;
            }
        }
    }

    private function canActivateByPolicy(BattleActor $actor, int $spCost, string $policy): bool
    {
        if ($actor->mp < $spCost) {
            return false;
        }

        $spRate = $actor->maxMp > 0 ? $actor->mp / $actor->maxMp : 0.0;

        return match ($policy) {
            'aggressive' => true,
            'conserve' => $spRate >= $this->battleRules->conserveThresholdFor($actor),
            default => $spRate >= 0.30,
        };
    }

    private function logProgressionGateFailure(
        BattleActor $actor,
        BattleState $state,
        Skill $skill,
        string $blockedReason,
    ): void {
        if (! in_array($blockedReason, ['blocked_by_hunting_mark', 'blocked_by_break_mark'], true)) {
            return;
        }

        $target = $state->player === $actor ? $state->enemy : $state->player;
        $rank = (int) $skill->learn_rank;
        if ($blockedReason === 'blocked_by_hunting_mark') {
            $required = $rank === 9 ? 2 : 1;
            $current = $this->progressionService->huntingMarkCountFor($target, $actor);
            $label = '標的印';
        } else {
            $required = 3;
            $current = $this->progressionService->breakMarkCountFor($target, $actor);
            $label = '崩し印';
        }

        $reportKey = (int) $skill->id.':'.$blockedReason.':'.$current;
        $progression = $actor->jobArtV2ProgressionState();
        if (isset($progression->reportedEligibilityGates[$reportKey])) {
            return;
        }
        $progression->reportedEligibilityGates[$reportKey] = true;
        $state->addLog(
            '<span class="text-slate-600">'.e((string) $skill->name)
            .' は '.e($label).' が不足しているため発動できない（必要 '
            .e((string) $required).'／現在 '.e((string) $current).'）。</span>',
        );
    }

    private function canActivateRecoveryArt(BattleActor $actor, Skill $skill): bool
    {
        $skill = $this->crownBalanceCatalog->applyToExecution($skill);
        if ($this->resourceService->supportEffectCanBeMeaningful($actor, $skill)) {
            return true;
        }

        if ($this->roleEffectService->enabledFor($actor)) {
            $roleMetadata = $this->roleEffectCatalog->forArt($skill);
            if ($this->allowsRoleMetadata($actor, $skill)
                && $this->roleEffectCatalog->isPortable($skill)
            ) {
                if ($this->roleEffectService->supportEffectCanBeMeaningful($actor, $skill)) {
                    return true;
                }
                if (is_array($roleMetadata['heal'] ?? null)
                    || is_array($roleMetadata['cleanse'] ?? null)
                    || is_array($roleMetadata['support_eligibility'] ?? null)
                ) {
                    return false;
                }
            }
        }

        if ((string) $skill->effect_template === 'HEAL_CLEANSE'
            && $this->cleanseService->canCleanse($actor)
        ) {
            return true;
        }

        $needsHp = $skill->isHealArt()
            || in_array((string) $skill->effect_template, ['HEAL', 'HEAL_CLEANSE'], true)
            || ((string) $skill->effect_template === 'DRAIN' && (float) $skill->drain_hp_rate > 0)
            || (int) $skill->heal_percent > 0;
        $needsSp = (int) $skill->mp_recover_percent > 0;
        if ($needsHp || $needsSp) {
            return ($needsHp && $actor->maxHp > 0 && $actor->hp < $actor->maxHp)
                || ($needsSp && $actor->maxMp > 0 && $actor->mp < $actor->maxMp);
        }

        return true;
    }

    private function originFor(BattleActor $actor, Skill $skill): string
    {
        return (string) ($actor->jobArtOrigins[(int) $skill->id]
            ?? ((int) $skill->job_id === (int) $actor->currentJobId ? 'current' : 'inherited'));
    }

    private function allowsRoleMetadata(BattleActor $actor, Skill $skill): bool
    {
        $resolution = $this->deckRoleResolver->resolveActor($actor);
        if (! $resolution->active) {
            return true;
        }

        return in_array(
            $resolution->roleFor($skill),
            [JobArtV2DeckRole::MAIN, JobArtV2DeckRole::SECONDARY],
            true,
        ) && $resolution->blockReasonFor($skill) === null;
    }

    private function isReadyUltimate(BattleActor $actor, BattleState $state, Skill $skill): bool
    {
        if ((int) $skill->learn_rank !== 9) {
            return false;
        }

        if ($this->ultimateCounterplayService->enabledFor($state)) {
            return $this->ultimateCounterplayService->isReadyMainRankNine($actor, $state, $skill);
        }

        return $this->finisherCondition->isSatisfied($actor, $state, $skill);
    }

    private function shouldPrioritizeUltimate(BattleActor $actor, BattleState $state, Skill $skill): bool
    {
        if ((int) $skill->learn_rank !== 9) {
            return false;
        }

        if (! $this->isReadyUltimate($actor, $state, $skill)) {
            $actor->resetJobArtV2UltimatePriorityAttempt((int) $skill->id);

            return false;
        }

        return $actor->isJobArtV2UltimatePriorityPending();
    }

    private function rank5V6BlockReason(BattleActor $actor, BattleState $state, Skill $skill): ?string
    {
        if (! $this->featureGate->usesRank5V6($actor) || $this->rank5V6Catalog->forSkill($skill) === null) {
            return null;
        }

        $art = $this->resourceCatalog->forActorArt($actor, $skill);
        if ($art === null) {
            return null;
        }

        $resourceKey = (string) $art['resource_key'];
        if ($actor->jobArtV2Rank5CycleState()->hasUsed($resourceKey, (int) $skill->id)) {
            return 'blocked_by_rank5_cycle';
        }

        if ($this->rank5V6Catalog->isReactive($skill)) {
            if (in_array((int) $skill->job_id, [15, 28, 48, 49, 93], true)
                && ! $this->ultimateCounterplayService->isResponseCandidate($actor, $state, $skill)
            ) {
                return 'blocked_by_reactive_condition';
            }

            $required = max((int) ($art['minimum_resource_points'] ?? 4), 4);

            return $actor->getResource($resourceKey) < $required
                ? JobArtV2ResourceService::BLOCKED_BY_RESOURCE
                : null;
        }

        $ordinal = 0;
        foreach ($actor->jobArts as $candidate) {
            if (! $candidate instanceof Skill
                || $this->rank5V6Catalog->forSkill($candidate) === null
                || $this->rank5V6Catalog->isReactive($candidate)
            ) {
                continue;
            }
            $candidateArt = $this->resourceCatalog->forActorArt($actor, $candidate);
            if (($candidateArt['resource_key'] ?? null) !== $resourceKey) {
                continue;
            }
            $ordinal++;
            if ((int) $candidate->id === (int) $skill->id) {
                break;
            }
        }

        $required = max((int) ($art['minimum_resource_points'] ?? 4), 4 * max(1, $ordinal));

        return $actor->getResource($resourceKey) < $required
            ? JobArtV2ResourceService::BLOCKED_BY_RESOURCE
            : null;
    }

    private function markRank5V6Attempted(BattleActor $actor, Skill $skill): void
    {
        if (! $this->featureGate->usesRank5V6($actor) || $this->rank5V6Catalog->forSkill($skill) === null) {
            return;
        }

        $art = $this->resourceCatalog->forActorArt($actor, $skill);
        if ($art !== null) {
            $actor->jobArtV2Rank5CycleState()->markUsed((string) $art['resource_key'], (int) $skill->id);
        }
    }
}
