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
    ) {
        $this->resourceService = $resourceService ?? app(JobArtV2ResourceService::class);
        $this->fieldService = $fieldService ?? app(JobArtV2FieldService::class);
        $this->slotConditions = $slotConditions ?? app(JobArtV2SlotConditionCatalog::class);
        $this->resourceCatalog = $resourceCatalog ?? app(JobArtV2ResourceCatalog::class);
        $this->cleanseService = $cleanseService ?? app(JobArtV2CleanseService::class);
        $this->roleEffectCatalog = $roleEffectCatalog ?? app(JobArtV2RoleEffectCatalog::class);
        $this->roleEffectService = $roleEffectService ?? app(JobArtV2RoleEffectService::class);
        $this->progressionService = $progressionService ?? app(JobArtV2ProgressionService::class);
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
                continue;
            }
            if ($this->progressionService->consumeSealIfBlocked($actor, $state, $skill)) {
                $blockedReasons[(int) $skill->id] = 'blocked_by_seal';
                continue;
            }

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
            $this->progressionService->finishActivationAttempt($actor, $skill);

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

        if (($state->jobArtCooldowns[$stateKey] ?? 0) > 0) {
            return 'blocked_by_cooldown';
        }

        if ($skill->max_uses_per_battle !== null
            && ($state->jobArtUseCounts[$stateKey] ?? 0) >= (int) $skill->max_uses_per_battle
        ) {
            return 'blocked_by_use_limit';
        }

        $spCost = $this->spCostCalculator->forActor($actor, $skill);
        $policy = (string) ($actor->jobArtPolicies[(int) $skill->id] ?? $actor->jobArtActivationPolicy);
        if (!$this->canActivateByPolicy($actor, $spCost, $policy)) {
            return 'blocked_by_sp_or_policy';
        }

        if (!$this->canActivateRecoveryArt($actor, $skill)) {
            return 'blocked_by_support_condition';
        }

        return $this->progressionService->eligibilityBlockReason($actor, $state, $skill)
            ?? $this->resourceService->eligibilityBlockReason($actor, $skill, $state);
    }

    /** @return array{0: array<int, Skill>, 1: bool} */
    private function orderedCandidates(
        BattleActor $actor,
        BattleState $state,
        Closure $stateKeyForSkill,
    ): array
    {
        $candidates = array_values(array_filter(
            $actor->jobArts,
            static fn ($skill): bool => $skill instanceof Skill,
        ));
        $candidates = $this->progressionService->orderCandidates($actor, $candidates);

        if (!$this->resourceService->enabledFor($actor)) {
            return [$candidates, false];
        }

        // current-job finisherを先に監査し、使用不能な場合だけ同系譜継承finisherへ進む。
        foreach (['current', 'same_lineage_inherited'] as $priorityGroup) {
            foreach ($candidates as $index => $skill) {
                $origin = $this->originFor($actor, $skill);
                $matchesGroup = $priorityGroup === 'current'
                    ? $origin === 'current'
                    : $origin === 'inherited' && $this->resourceCatalog->isSameLineageInherited($actor, $skill);
                if (! $matchesGroup
                    || (int) $skill->learn_rank !== 9
                    || ! $this->finisherCondition->isSatisfied($actor, $state, $skill)
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

    private function canActivateRecoveryArt(BattleActor $actor, Skill $skill): bool
    {
        if ($this->resourceService->supportEffectCanBeMeaningful($actor, $skill)) {
            return true;
        }

        if ($this->roleEffectService->enabledFor($actor)) {
            $roleMetadata = $this->roleEffectCatalog->forArt($skill);
            if ($this->roleEffectCatalog->isPortable($skill)) {
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
}
