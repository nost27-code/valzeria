<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActionResult;
use App\Services\Battle\BattleActionType;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\HitResult;
use App\Services\Battle\NormalAttackResolution;

class JobArtV2ResourceService
{
    public const BLOCKED_BY_CAP = 'blocked_by_cap';
    public const BLOCKED_BY_RESOURCE = 'blocked_by_resource';
    public const BLOCKED_BY_RESOURCE_METADATA = 'blocked_by_resource_metadata';
    public const BLOCKED_BY_UNSUPPORTED_LINEAGE_RESOURCE = 'blocked_by_unsupported_lineage_resource';
    public const BLOCKED_BY_FEATURE_DEPENDENCY = 'blocked_by_feature_dependency';
    public const BLOCKED_BY_DIRECT_RESOURCE_OPERATION = 'blocked_by_direct_resource_operation';

    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2ResourceCatalog $catalog,
        private readonly JobArtV2FieldService $fieldService,
        private readonly JobArtV2BattleHudService $battleHud,
        private readonly JobArtV2ConversionService $conversionService,
        private readonly JobArtV2ProgressionService $progressionService,
        private readonly ?JobArtV2Rank5V6Catalog $rank5V6Catalog = null,
    ) {
    }

    public function enabledFor(BattleActor $actor): bool
    {
        return $this->featureGate->usesResources($actor);
    }

    public function beginAction(BattleActor $actor, BattleState $state): ?int
    {
        $target = $actor === $state->player ? $state->enemy : $state->player;
        if (!$this->enabledFor($actor)
            && !$this->enabledFor($target)
            && !$this->fieldService->enabledFor($state)
        ) {
            return null;
        }

        $sourceActionId = $state->beginSourceAction();
        $this->battleHud->beginAction($actor, $state, $sourceActionId);
        $this->fieldService->beginAction($actor, $state, $sourceActionId);

        return $sourceActionId;
    }

    public function markCurrentJobSkillAction(BattleActor $actor, BattleState $state, Skill $skill): void
    {
        if (! $this->enabledFor($actor) || $skill->isJobArt()) {
            return;
        }

        $this->recordBattleAction($actor, $state, BattleActionType::CURRENT_JOB_SKILL);
        $this->battleHud->markCurrentJobSkill($actor, $state, $skill);
    }

    public function eligibilityBlockReason(BattleActor $actor, Skill $skill, ?BattleState $state = null): ?string
    {
        if (!$this->enabledFor($actor)) {
            return null;
        }

        if ($state !== null) {
            $fieldBlock = $this->fieldService->eligibilityBlockReason($actor, $state, $skill);
            if ($fieldBlock !== null) {
                return $fieldBlock;
            }
        }

        $art = $this->catalog->forActorArt($actor, $skill);
        if ($art === null) {
            // v2 profile外の継承戦技だけはlegacy適格性を維持する。
            if ($this->catalog->isInheritedOrigin($actor, $skill)) {
                return null;
            }

            return (int) $skill->learn_rank === 9 ? self::BLOCKED_BY_RESOURCE_METADATA : null;
        }

        $conversionBlock = $this->conversionService->eligibilityBlockReason($actor, $skill);
        if ($conversionBlock !== null) {
            return $conversionBlock;
        }

        if ((bool) ($art['requires_trusted_field'] ?? false)) {
            return $state === null ? self::BLOCKED_BY_FEATURE_DEPENDENCY : null;
        }

        $resourceKey = (string) $art['resource_key'];
        $points = $actor->getResource($resourceKey);
        $role = ResourceRole::from((string) $art['resource_role']);
        if ($role === ResourceRole::PRODUCER
            && (int) $art['resource_gain_points'] > 0
            && $points >= (int) $art['resource_max_points']
            && ! $this->hasEquippedFinisherForResource($actor, $resourceKey)
        ) {
            return self::BLOCKED_BY_CAP;
        }

        $baseCost = max(0, (int) $art['resource_cost_points']);
        $cost = max(0, $this->progressionService->resourceCost(
            $actor,
            $state,
            $skill,
            $baseCost,
        ));
        $minimum = max(0, (int) $art['minimum_resource_points']);
        if ($cost < $baseCost) {
            $minimum = min($minimum, $cost);
        }
        if ($points < $minimum || $points < $cost) {
            return self::BLOCKED_BY_RESOURCE;
        }

        return null;
    }

    private function hasEquippedFinisherForResource(BattleActor $actor, string $resourceKey): bool
    {
        foreach ($actor->jobArts as $skill) {
            if (! $skill instanceof Skill) {
                continue;
            }

            $art = $this->catalog->forActorArt($actor, $skill);
            if ($art !== null
                && ResourceRole::from((string) $art['resource_role']) === ResourceRole::FINISHER
                && (string) $art['resource_key'] === $resourceKey
            ) {
                return true;
            }
        }

        return false;
    }

    public function canCommitCast(BattleActor $actor, Skill $skill, ?BattleState $state = null): bool
    {
        return $this->eligibilityBlockReason($actor, $skill, $state) === null;
    }

    public function isFinisherReady(BattleActor $actor, Skill $skill): bool
    {
        if (!$this->enabledFor($actor)
            || $this->catalog->roleForActorArt($actor, $skill) !== ResourceRole::FINISHER
            || !$this->catalog->usesBattleResource($actor, $skill)
        ) {
            return false;
        }

        $art = $this->catalog->forActorArt($actor, $skill);
        if ($art === null) {
            return false;
        }
        $required = max(
            0,
            (int) $art['resource_cost_points'],
            (int) $art['minimum_resource_points'],
        );

        return $required > 0
            && $actor->getResource((string) $art['resource_key']) >= $required;
    }

    public function supportEffectCanBeMeaningful(BattleActor $actor, Skill $skill): bool
    {
        $art = $this->catalog->forActorArt($actor, $skill);

        return $this->enabledFor($actor)
            && (int) ($art['job_id'] ?? 0) === 24
            && (int) ($art['learn_rank'] ?? 0) === 9
            && (int) $skill->damage_reduction_percent > 0;
    }

    public function applyJobArtCast(BattleActor $actor, BattleState $state, Skill $skill): ResourceChangeResult
    {
        if (!$this->enabledFor($actor)) {
            return ResourceChangeResult::unchanged();
        }

        $this->recordBattleAction($actor, $state, BattleActionType::JOB_ART);
        $this->battleHud->markJobArt($actor, $state, $skill);

        $art = $this->catalog->forActorArt($actor, $skill);
        if ($art !== null
            && $this->featureGate->usesRank5V6($actor)
            && (int) $skill->learn_rank === 9
        ) {
            $actor->jobArtV2Rank5CycleState()->clearResource((string) $art['resource_key']);
        }
        if ($art === null || !$this->catalog->usesBattleResource($actor, $skill)) {
            if (! $this->lineageEffectsSuppressed($state)) {
                $this->fieldService->applyJobArtCast($actor, $state, $skill);
            }

            return ResourceChangeResult::unchanged();
        }

        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null) {
            return ResourceChangeResult::unchanged('missing_source_action_id');
        }
        $resourceKey = (string) $art['resource_key'];
        $resourceName = (string) $art['resource_name'];
        $cap = (int) $art['resource_max_points'];
        $actor->configureResource($resourceKey, $cap);
        if ($this->declaresDirectResourceOperation($art)
            && ! (bool) ($art['allow_lineage_common_gain'] ?? false)
        ) {
            $state->markJobArtV2DirectResourceOperation($actor, $resourceKey, $sourceActionId);
        }
        if (!$state->claimResourceEvent($actor, $resourceKey, ResourceEvent::JOB_ART_CAST->value, $sourceActionId)) {
            return ResourceChangeResult::unchanged('duplicate_resource_event');
        }

        $fieldResult = null;
        if (! $this->lineageEffectsSuppressed($state)
            && max(0, (int) ($art['resource_gain_on_field_overwrite_points'] ?? 0)) > 0
        ) {
            $fieldResult = $this->fieldService->applyJobArtCast($actor, $state, $skill);
        }

        $conversion = $this->conversionService->applyAfterArtSpCost($actor, $state, $skill);
        $conversionGain = null;
        if ($conversion !== null) {
            $this->battleHud->recordConversionResult($actor, $state, $conversion);
            if ($conversion->success) {
                $conversionGain = $this->applyGainEvent(
                    $actor,
                    $state,
                    $art,
                    ResourceEvent::HP_SP_CONVERSION_SUCCESS,
                    max(0, (int) $art['resource_gain_points']),
                );
            }
        }

        $before = $actor->getResource($resourceKey);
        $role = ResourceRole::from((string) $art['resource_role']);
        $configuredDelta = match ($role) {
            ResourceRole::PRODUCER => $this->gainEventFor($art) === ResourceEvent::JOB_ART_CAST
                ? $this->modifyResourceGainForAction(
                    $actor,
                    $state,
                    $resourceKey,
                    max(0, (int) $art['resource_gain_points'])
                        + ($fieldResult?->event === FieldEvent::OVERWRITTEN
                            ? max(0, (int) ($art['resource_gain_on_field_overwrite_points'] ?? 0))
                            : 0),
                )
                : 0,
            ResourceRole::CONSUMER, ResourceRole::FINISHER => -max(0, $this->progressionService->resourceCost(
                $actor,
                $state,
                $skill,
                (int) $art['resource_cost_points'],
            )),
            ResourceRole::NEUTRAL => 0,
        };

        if ($configuredDelta < 0 && !$actor->spendResource($resourceKey, abs($configuredDelta))) {
            return ResourceChangeResult::unchanged(self::BLOCKED_BY_RESOURCE);
        }
        if ($configuredDelta > 0) {
            $actor->addResource(
                $resourceKey,
                $this->progressionService->modifyIncomingResourceGain($actor, $resourceKey, $configuredDelta, $state),
            );
        }

        $after = $actor->getResource($resourceKey);
        $result = new ResourceChangeResult(
            applied: $before !== $after,
            resourceKey: $resourceKey,
            resourceName: $resourceName,
            delta: $after - $before,
            before: $before,
            after: $after,
            cap: $cap,
            event: ResourceEvent::JOB_ART_CAST,
            sourceActionId: $sourceActionId,
        );
        $this->appendLog($state, $result);
        if ($fieldResult === null && ! $this->lineageEffectsSuppressed($state)) {
            $this->fieldService->applyJobArtCast($actor, $state, $skill);
        }

        return $conversionGain?->applied ? $conversionGain : $result;
    }

    private function lineageEffectsSuppressed(BattleState $state): bool
    {
        return ! empty($state->jobArtV2RoleAction()['ultimate_counterplay_lineage_suppressed']);
    }

    public function recordJobArtHit(BattleActor $actor, BattleState $state, Skill $skill): ResourceChangeResult
    {
        if (!$this->enabledFor($actor)) {
            return ResourceChangeResult::unchanged();
        }

        $art = $this->catalog->forActorArt($actor, $skill);
        $sourceActionId = $state->currentSourceActionId();
        if ($art === null
            || $sourceActionId === null
            || !$this->catalog->usesBattleResource($actor, $skill)
            || $this->gainEventFor($art) !== ResourceEvent::JOB_ART_HIT
        ) {
            return ResourceChangeResult::unchanged($sourceActionId === null ? 'missing_source_action_id' : null);
        }

        $resourceKey = (string) $art['resource_key'];
        $resourceName = (string) $art['resource_name'];
        $cap = (int) $art['resource_max_points'];
        $actor->configureResource($resourceKey, $cap);
        if ($this->declaresDirectResourceOperation($art)
            && ! (bool) ($art['allow_lineage_common_gain'] ?? false)
        ) {
            $state->markJobArtV2DirectResourceOperation($actor, $resourceKey, $sourceActionId);
        }
        if (!$state->claimResourceEvent($actor, $resourceKey, ResourceEvent::JOB_ART_HIT->value, $sourceActionId)) {
            return ResourceChangeResult::unchanged('duplicate_resource_event');
        }

        $before = $actor->getResource($resourceKey);
        $gain = $this->modifyResourceGainForAction(
            $actor,
            $state,
            $resourceKey,
            max(0, (int) $art['resource_gain_points']),
        );
        $actor->addResource($resourceKey, $this->progressionService->modifyIncomingResourceGain($actor, $resourceKey, $gain, $state));
        $after = $actor->getResource($resourceKey);
        $result = new ResourceChangeResult(
            applied: $before !== $after,
            resourceKey: $resourceKey,
            resourceName: $resourceName,
            delta: $after - $before,
            before: $before,
            after: $after,
            cap: $cap,
            event: ResourceEvent::JOB_ART_HIT,
            sourceActionId: $sourceActionId,
        );
        $this->appendLog($state, $result);

        return $result;
    }

    public function recordSelfDamage(BattleActor $actor, BattleState $state, int $actualDamage): ResourceChangeResult
    {
        if (!$this->enabledFor($actor) || $actualDamage <= 0) {
            return ResourceChangeResult::unchanged();
        }

        $this->progressionService->recordNightmareSelfDamage($actor, $actualDamage);

        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null) {
            return ResourceChangeResult::unchanged($sourceActionId === null ? 'missing_source_action_id' : null);
        }

        return $this->applyConfiguredGainToAll(
            $actor,
            $state,
            ResourceEvent::SELF_DAMAGE,
            'self_damage_gain_points',
        );
    }

    public function recordNormalAttackHit(BattleActor $actor, BattleState $state): ResourceChangeResult
    {
        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null) {
            return ResourceChangeResult::unchanged($sourceActionId === null ? 'missing_source_action_id' : null);
        }

        $target = $actor === $state->player ? $state->enemy : $state->player;

        return $this->recordNormalAttackResolution(
            $actor,
            $target,
            $state,
            HitResult::HIT,
        );
    }

    public function recordDirectAttackDamageReceived(
        BattleActor $actor,
        BattleState $state,
        bool $deferLogAfterDamage = true,
    ): ResourceChangeResult
    {
        return $this->recordConfiguredGain(
            $actor,
            $state,
            ResourceEvent::DIRECT_ATTACK_DAMAGE_RECEIVED,
            'direct_attack_damage_received_gain_points',
            $deferLogAfterDamage,
        );
    }

    public function recordParrySuccess(BattleActor $actor, BattleState $state): ResourceChangeResult
    {
        return $this->recordConfiguredGain(
            $actor,
            $state,
            ResourceEvent::PARRY_SUCCESS,
            'parry_success_gain_points',
            true,
        );
    }

    public function recordDamageMitigated(BattleActor $actor, BattleState $state): ResourceChangeResult
    {
        $this->progressionService->recordQualifyingDamageMitigated($actor, $state);

        return $this->recordConfiguredGain(
            $actor,
            $state,
            ResourceEvent::DAMAGE_MITIGATED,
            'damage_mitigated_gain_points',
            true,
        );
    }

    public function recordCleanseSuccess(BattleActor $actor, BattleState $state): ResourceChangeResult
    {
        return $this->recordConfiguredGain(
            $actor,
            $state,
            ResourceEvent::CLEANSE_SUCCESS,
            'cleanse_success_gain_points',
        );
    }

    public function recordNormalAttackResolution(
        BattleActor $actor,
        BattleActor $target,
        BattleState $state,
        HitResult $hitResult,
    ): ResourceChangeResult {
        if (! $this->enabledFor($actor)) {
            return ResourceChangeResult::unchanged();
        }

        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null) {
            return ResourceChangeResult::unchanged('missing_source_action_id');
        }

        $resolution = new NormalAttackResolution(
            sourceActionId: $sourceActionId,
            attackerKey: $state->actorKey($actor),
            targetKey: $state->actorKey($target),
            hitResult: $hitResult,
            resolvedActionType: BattleActionType::NORMAL_ATTACK,
            damageCategory: $actor->usesMagForNormalAttack() ? 'magical' : 'physical',
        );
        $state->recordBattleActionResult(new BattleActionResult(
            sourceActionId: $resolution->sourceActionId,
            actorKey: $resolution->attackerKey,
            targetKey: $resolution->targetKey,
            actionType: $resolution->resolvedActionType,
        ));
        $this->battleHud->markNormalAttackResolution($actor, $state, $resolution);

        $event = match ($hitResult) {
            HitResult::HIT => ResourceEvent::NORMAL_ATTACK_HIT,
            HitResult::MISS => ResourceEvent::NORMAL_ATTACK_MISS,
            HitResult::EVADE => null,
        };
        if ($event === null) {
            return ResourceChangeResult::unchanged();
        }

        $metadataKey = $event === ResourceEvent::NORMAL_ATTACK_HIT
            ? 'normal_attack_hit_gain_points'
            : 'normal_attack_miss_gain_points';

        return $this->applyConfiguredGainToAll($actor, $state, $event, $metadataKey);
    }

    public function finishAction(BattleActor $actor, BattleState $state): void
    {
        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId !== null && $this->enabledFor($actor)) {
            $result = $state->battleActionResult($sourceActionId);
            if ($result === null) {
                $target = $actor === $state->player ? $state->enemy : $state->player;
                $result = new BattleActionResult(
                    sourceActionId: $sourceActionId,
                    actorKey: $state->actorKey($actor),
                    targetKey: $state->actorKey($target),
                    actionType: BattleActionType::NO_ACTION,
                );
                $state->recordBattleActionResult($result);
            }

            if (in_array($result->actionType, [BattleActionType::NORMAL_ATTACK, BattleActionType::CURRENT_JOB_SKILL], true)) {
                $this->applyConfiguredGainToAll(
                    $actor,
                    $state,
                    ResourceEvent::NON_JOB_ART_ACTION,
                    'non_job_art_action_gain_points',
                );
            }
        }

        $this->progressionService->finishAction($actor, $state);
        $this->finishNaturalRankFiveCycles($actor, $state);
        $target = $actor === $state->player ? $state->enemy : $state->player;
        $this->finishNaturalRankFiveCycles($target, $state);
        $this->battleHud->finishAction($actor, $state);
    }

    private function finishNaturalRankFiveCycles(BattleActor $actor, BattleState $state): void
    {
        if (! $this->featureGate->usesRank5V6($actor)) {
            return;
        }

        $rank5Catalog = $this->rank5V6Catalog ?? app(JobArtV2Rank5V6Catalog::class);
        $cycle = $actor->jobArtV2Rank5CycleState();
        foreach ($this->catalog->resourcesForActor($actor) as $resource) {
            $resourceKey = (string) $resource['resource_key'];
            $cap = max(0, (int) $resource['resource_max_points']);
            $actor->configureResource($resourceKey, $cap);
            if ($cap <= 0
                || $actor->getResource($resourceKey) < $cap
                || $this->hasEquippedFinisherForResource($actor, $resourceKey)
            ) {
                continue;
            }

            $scheduledSkillIds = [];
            $reactiveSkillIds = [];
            $scheduledOrdinal = 0;
            foreach ($actor->jobArts as $skill) {
                if (! $skill instanceof Skill || $rank5Catalog->forSkill($skill) === null) {
                    continue;
                }

                $art = $this->catalog->forActorArt($actor, $skill);
                if (($art['resource_key'] ?? null) !== $resourceKey) {
                    continue;
                }

                if ($rank5Catalog->isReactive($skill)) {
                    $reactiveSkillIds[] = (int) $skill->id;

                    continue;
                }

                $scheduledOrdinal++;
                $required = $rank5Catalog->requiredResourcePoints(
                    $skill,
                    $scheduledOrdinal,
                    (int) ($art['minimum_resource_points'] ?? 4),
                );
                if ($required !== null && $required <= $cap) {
                    $scheduledSkillIds[] = (int) $skill->id;
                }
            }

            if ($scheduledSkillIds !== []) {
                $cycleComplete = true;
                foreach ($scheduledSkillIds as $skillId) {
                    if (! $cycle->hasUsed($resourceKey, $skillId)) {
                        $cycleComplete = false;
                        break;
                    }
                }
            } else {
                $cycleComplete = false;
                foreach ($reactiveSkillIds as $skillId) {
                    if ($cycle->hasUsed($resourceKey, $skillId)) {
                        $cycleComplete = true;
                        break;
                    }
                }
            }

            if (! $cycleComplete) {
                continue;
            }

            $actor->setResource($resourceKey, 0);
            $cycle->clearResource($resourceKey);
            $resourceName = (string) $resource['resource_name'];
            $state->addLog(
                '<span class="text-sky-700 font-bold">'.e($actor->name).' の '.e($resourceName)
                .' が満ち、連携の巡りが新たに始まった！（'.e($resourceName).' 0/'.$cap.'）</span>',
            );
        }
    }

    /** @param array<string, int|float|string|bool> $resource */
    private function applyGainEvent(
        BattleActor $actor,
        BattleState $state,
        array $resource,
        ResourceEvent $event,
        int $gain,
        bool $deferLogAfterDamage = false,
    ): ResourceChangeResult {
        $sourceActionId = $state->currentSourceActionId();
        if ($gain <= 0 || $sourceActionId === null) {
            return ResourceChangeResult::unchanged($sourceActionId === null ? 'missing_source_action_id' : null);
        }

        $resourceKey = (string) $resource['resource_key'];
        $cap = (int) $resource['resource_max_points'];
        $actor->configureResource($resourceKey, $cap);
        if (! $state->claimResourceEvent($actor, $resourceKey, $event->value, $sourceActionId)) {
            return ResourceChangeResult::unchanged('duplicate_resource_event');
        }

        $before = $actor->getResource($resourceKey);
        $gain = $this->modifyResourceGainForAction($actor, $state, $resourceKey, $gain);
        $actor->addResource($resourceKey, $this->progressionService->modifyIncomingResourceGain($actor, $resourceKey, $gain, $state));
        $after = $actor->getResource($resourceKey);
        $result = new ResourceChangeResult(
            applied: $before !== $after,
            resourceKey: $resourceKey,
            resourceName: (string) $resource['resource_name'],
            delta: $after - $before,
            before: $before,
            after: $after,
            cap: $cap,
            event: $event,
            sourceActionId: $sourceActionId,
        );
        $this->appendLog($state, $result, $deferLogAfterDamage);

        return $result;
    }

    private function recordBattleAction(
        BattleActor $actor,
        BattleState $state,
        BattleActionType $actionType,
    ): void {
        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null) {
            return;
        }

        $target = $actor === $state->player ? $state->enemy : $state->player;
        $state->recordBattleActionResult(new BattleActionResult(
            sourceActionId: $sourceActionId,
            actorKey: $state->actorKey($actor),
            targetKey: $state->actorKey($target),
            actionType: $actionType,
        ));
    }

    private function recordConfiguredGain(
        BattleActor $actor,
        BattleState $state,
        ResourceEvent $event,
        string $metadataKey,
        bool $deferLogAfterDamage = false,
    ): ResourceChangeResult {
        if (! $this->enabledFor($actor)) {
            return ResourceChangeResult::unchanged();
        }

        return $this->applyConfiguredGainToAll(
            $actor,
            $state,
            $event,
            $metadataKey,
            $deferLogAfterDamage,
        );
    }

    private function applyConfiguredGainToAll(
        BattleActor $actor,
        BattleState $state,
        ResourceEvent $event,
        string $metadataKey,
        bool $deferLogAfterDamage = false,
    ): ResourceChangeResult {
        $firstApplied = null;
        $firstBlocked = null;
        $sourceActionId = $state->currentSourceActionId();
        foreach ($this->catalog->resourcesForActor($actor) as $resource) {
            $resourceKey = (string) $resource['resource_key'];
            $gain = max(0, (int) ($resource[$metadataKey] ?? 0));
            if ($gain > 0
                && $sourceActionId !== null
                && $state->hasJobArtV2DirectResourceOperation($actor, $resourceKey, $sourceActionId)
            ) {
                $firstBlocked ??= ResourceChangeResult::unchanged(self::BLOCKED_BY_DIRECT_RESOURCE_OPERATION);
                continue;
            }

            $result = $this->applyGainEvent(
                $actor,
                $state,
                $resource,
                $event,
                $gain,
                $deferLogAfterDamage,
            );
            if ($firstApplied === null && $result->applied) {
                $firstApplied = $result;
            }
            if ($firstBlocked === null && $result->blockedReason !== null) {
                $firstBlocked = $result;
            }
        }

        return $firstApplied ?? $firstBlocked ?? ResourceChangeResult::unchanged();
    }

    private function appendLog(
        BattleState $state,
        ResourceChangeResult $result,
        bool $deferLogAfterDamage = false,
    ): void
    {
        $message = $result->logMessage();
        if ($message !== null) {
            $formatted = "<span class=\"text-sky-700 font-bold\">{$message}</span>";
            if ($deferLogAfterDamage) {
                $state->deferLogAfterDamage($formatted, $result->sourceActionId);
            } else {
                $state->addLog($formatted);
            }
        }
    }

    private function modifyResourceGainForAction(
        BattleActor $actor,
        BattleState $state,
        string $resourceKey,
        int $gain,
    ): int {
        $gain = max(0, $gain);
        $sourceActionId = $state->currentSourceActionId();
        if ($gain <= 0
            || $sourceActionId === null
            || ! $state->claimResourceGainModifier($actor, $resourceKey, 'field', $sourceActionId)
        ) {
            return $gain;
        }

        return $this->fieldService->modifyResourceGain($actor, $state, $gain);
    }

    /** @param array<string, int|float|string|bool> $art */
    private function gainEventFor(array $art): ResourceEvent
    {
        return ResourceEvent::tryFrom((string) ($art['resource_gain_event'] ?? ResourceEvent::JOB_ART_CAST->value))
            ?? ResourceEvent::JOB_ART_CAST;
    }

    /** @param array<string, int|float|string|bool> $art */
    private function declaresDirectResourceOperation(array $art): bool
    {
        return max(0, (int) ($art['resource_gain_points'] ?? 0)) > 0
            || max(0, (int) ($art['resource_gain_on_field_overwrite_points'] ?? 0)) > 0
            || max(0, (int) ($art['resource_cost_points'] ?? 0)) > 0;
    }
}
