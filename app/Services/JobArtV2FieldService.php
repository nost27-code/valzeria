<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageSourceType;
use App\Support\JobArtEffectCatalog;

final class JobArtV2FieldService
{
    public const BLOCKED_BY_FIELD = 'blocked_by_field';
    public const BLOCKED_BY_FEATURE_DEPENDENCY = 'blocked_by_feature_dependency';
    public const BLOCKED_BY_FIELD_LOCK = 'blocked_by_field_lock';

    private const BASE_DURATION = 3;
    private const MAX_DURATION = 5;
    private const OVERLAY_DURATION = 1;

    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2FieldCatalog $catalog,
        private readonly JobArtV2FieldModifierResolver $modifiers,
    ) {
    }

    public function enabledFor(BattleState $state): bool
    {
        return $this->featureGate->usesFields($state);
    }

    public function beginAction(BattleActor $actor, BattleState $state, int $sourceActionId): void
    {
        if (!$this->enabledFor($state)) {
            return;
        }

        $state->setFieldActionContext(
            $actor,
            new FieldSnapshot($state->primaryField(), $state->fieldOverlay(), $state->fieldEchoes()),
            'normal_attack',
            $actor->usesMagForNormalAttack() ? 'magical' : 'physical',
        );
    }

    public function markSkillAction(BattleActor $actor, BattleState $state, Skill $skill): void
    {
        if (!$this->enabledFor($state)) {
            return;
        }

        $state->setFieldActionContext(
            $actor,
            $state->currentFieldSnapshot(),
            $skill->isJobArt() ? 'job_art' : 'job_skill',
            $this->damageScope($skill),
        );
    }

    public function eligibilityBlockReason(BattleActor $actor, BattleState $state, Skill $skill): ?string
    {
        if (!$this->isTrustedFieldArt($actor, $skill)) {
            return null;
        }

        $metadata = $this->catalog->forArt($skill);
        if (!(bool) ($metadata['requires_trusted_field'] ?? false)) {
            return null;
        }
        if (!$this->enabledFor($state)) {
            return self::BLOCKED_BY_FEATURE_DEPENDENCY;
        }

        return $state->primaryField() === null ? self::BLOCKED_BY_FIELD : null;
    }

    public function isFieldOnlyArt(BattleActor $actor, BattleState $state, Skill $skill): bool
    {
        $metadata = $this->catalog->forArt($skill);

        return $this->enabledFor($state)
            && $this->isTrustedCurrentFieldArt($actor, $skill)
            && (int) ($metadata['job_id'] ?? 0) === 85
            && (int) ($metadata['learn_rank'] ?? 0) === 5
            && (string) ($metadata['field_operation'] ?? '') === 'lock';
    }

    public function applyJobArtCast(BattleActor $actor, BattleState $state, Skill $skill): FieldOperationResult
    {
        if (!$this->enabledFor($state)) {
            return FieldOperationResult::unchanged();
        }

        $this->markSkillAction($actor, $state, $skill);
        if (!$this->isTrustedFieldArt($actor, $skill)) {
            return FieldOperationResult::unchanged('current_job_only');
        }

        $metadata = $this->catalog->forArt($skill);
        $sourceActionId = $state->currentSourceActionId();
        if ($metadata === null || $sourceActionId === null) {
            return FieldOperationResult::unchanged();
        }

        return match ((string) ($metadata['field_operation'] ?? 'none')) {
            'deploy' => $this->deployFromMetadata($actor, $state, $skill, $metadata, $sourceActionId),
            'extend' => $this->extendPrimary(
                $actor,
                $state,
                (int) $skill->id,
                $sourceActionId,
                max(1, (int) ($metadata['field_extend_rounds'] ?? 1)),
            ),
            'lock' => $this->lockPrimary($actor, $state, (int) $skill->id, $sourceActionId),
            'echo_previous_overwritten' => $this->holdPreviousOverwrittenField($actor, $state, (int) $skill->id, $sourceActionId),
            'overlay' => $actor->currentJobId === 85
                ? $this->createOverlay($actor, $state, (string) $metadata['field_key'], (int) $skill->id, $sourceActionId)
                : FieldOperationResult::unchanged('current_job_only'),
            default => FieldOperationResult::unchanged(),
        };
    }

    public function deployPrimary(
        BattleActor $actor,
        BattleState $state,
        string $fieldKey,
        int $sourceSkillId,
        int $sourceActionId,
    ): FieldOperationResult {
        if (!$this->enabledFor($state) || $this->catalog->field($fieldKey) === null) {
            return FieldOperationResult::unchanged();
        }

        $actorKey = $state->actorKey($actor);
        $current = $state->primaryField();
        if ($current === null) {
            $next = new FieldState($fieldKey, $actorKey, self::BASE_DURATION, $sourceSkillId, $sourceActionId, $state->turnCount);
            $state->replacePrimaryField($next);

            return $this->emit($state, $actorKey, FieldEvent::CREATED, $next, $sourceActionId);
        }

        if ($current->isOverwriteLocked()
            && ($current->key !== $fieldKey || $current->ownerActorKey !== $actorKey)
        ) {
            return $this->emit($state, $actorKey, FieldEvent::OVERWRITE_BLOCKED, $current, $sourceActionId, self::BLOCKED_BY_FIELD_LOCK);
        }

        if ($current->key === $fieldKey) {
            $next = new FieldState(
                $fieldKey,
                $actorKey,
                self::BASE_DURATION,
                $sourceSkillId,
                $sourceActionId,
                $state->turnCount,
                $current->extends,
                $current->overwriteLockRemainingRounds,
                $current->overwriteLockOwnerActorKey,
                $current->overwriteLockCreatedRound,
            );
            $state->replacePrimaryField($next);

            return $this->emit($state, $actorKey, FieldEvent::REFRESHED, $next, $sourceActionId);
        }

        $next = new FieldState($fieldKey, $actorKey, self::BASE_DURATION, $sourceSkillId, $sourceActionId, $state->turnCount);
        $state->recordFieldOverwrite($actorKey, $current);
        $state->replacePrimaryField($next);

        return $this->emit($state, $actorKey, FieldEvent::OVERWRITTEN, $next, $sourceActionId);
    }

    public function extendPrimary(
        BattleActor $actor,
        BattleState $state,
        int $sourceSkillId,
        int $sourceActionId,
        int $rounds = 1,
    ): FieldOperationResult {
        if (!$this->enabledFor($state)) {
            return FieldOperationResult::unchanged();
        }
        $current = $state->primaryField();
        if ($current === null || $current->extends >= 1 || $current->remainingRounds >= self::MAX_DURATION) {
            return FieldOperationResult::unchanged();
        }

        $next = new FieldState(
            $current->key,
            $current->ownerActorKey,
            min(self::MAX_DURATION, $current->remainingRounds + max(1, $rounds)),
            $current->sourceSkillId,
            $current->sourceActionId,
            $current->createdRound,
            $current->extends + 1,
            $current->overwriteLockRemainingRounds,
            $current->overwriteLockOwnerActorKey,
            $current->overwriteLockCreatedRound,
        );
        $state->replacePrimaryField($next);

        return $this->emit($state, $state->actorKey($actor), FieldEvent::EXTENDED, $next, $sourceActionId);
    }

    public function lockPrimary(
        BattleActor $actor,
        BattleState $state,
        int $sourceSkillId,
        int $sourceActionId,
    ): FieldOperationResult {
        if (!$this->enabledFor($state) || ($current = $state->primaryField()) === null) {
            return FieldOperationResult::unchanged(self::BLOCKED_BY_FIELD);
        }

        $event = $current->isOverwriteLocked() ? FieldEvent::LOCK_REFRESHED : FieldEvent::LOCKED;
        $next = new FieldState(
            $current->key,
            $current->ownerActorKey,
            $current->remainingRounds,
            $current->sourceSkillId,
            $current->sourceActionId,
            $current->createdRound,
            $current->extends,
            2,
            $current->ownerActorKey,
            $state->turnCount,
        );
        $state->replacePrimaryField($next);

        return $this->emit($state, $state->actorKey($actor), $event, $next, $sourceActionId);
    }

    public function createOverlay(
        BattleActor $actor,
        BattleState $state,
        string $fieldKey,
        int $sourceSkillId,
        int $sourceActionId,
    ): FieldOperationResult {
        if (!$this->enabledFor($state) || $this->catalog->field($fieldKey) === null) {
            return FieldOperationResult::unchanged();
        }

        $actorKey = $state->actorKey($actor);
        $overlay = new FieldOverlayState($fieldKey, $actorKey, self::OVERLAY_DURATION, $sourceSkillId, $sourceActionId, $state->turnCount);
        $state->replaceFieldOverlay($overlay);

        return $this->emitOverlay($state, $actorKey, FieldEvent::OVERLAY_CREATED, $overlay, $sourceActionId);
    }

    /** @return array<int, FieldOperationResult> */
    public function endRound(BattleState $state): array
    {
        if (!$this->enabledFor($state)) {
            return [];
        }

        $results = [];
        $field = $state->primaryField();
        if ($field !== null) {
            $remaining = $field->createdRound < $state->turnCount
                ? max(0, $field->remainingRounds - 1)
                : $field->remainingRounds;
            $lockRemaining = $field->overwriteLockRemainingRounds;
            if ($field->overwriteLockCreatedRound !== null && $field->overwriteLockCreatedRound < $state->turnCount) {
                $lockRemaining = max(0, $lockRemaining - 1);
            }
            if ($remaining === 0) {
                $state->replacePrimaryField(null);
                $results[] = $this->emit($state, 'system', FieldEvent::EXPIRED, $field, "round:{$state->turnCount}:primary");
            } elseif ($remaining !== $field->remainingRounds || $lockRemaining !== $field->overwriteLockRemainingRounds) {
                $state->replacePrimaryField(new FieldState(
                    $field->key,
                    $field->ownerActorKey,
                    $remaining,
                    $field->sourceSkillId,
                    $field->sourceActionId,
                    $field->createdRound,
                    $field->extends,
                    $lockRemaining,
                    $lockRemaining > 0 ? $field->overwriteLockOwnerActorKey : null,
                    $lockRemaining > 0 ? $field->overwriteLockCreatedRound : null,
                ));
            }
        }

        $overlay = $state->fieldOverlay();
        if ($overlay !== null && $overlay->createdRound < $state->turnCount) {
            $remaining = max(0, $overlay->remainingRounds - 1);
            if ($remaining === 0) {
                $state->replaceFieldOverlay(null);
                $results[] = $this->emitOverlay($state, 'system', FieldEvent::OVERLAY_EXPIRED, $overlay, "round:{$state->turnCount}:overlay");
            } else {
                $state->replaceFieldOverlay(new FieldOverlayState(
                    $overlay->key,
                    $overlay->ownerActorKey,
                    $remaining,
                    $overlay->sourceSkillId,
                    $overlay->sourceActionId,
                    $overlay->createdRound,
                ));
            }
        }

        foreach ($state->fieldEchoes() as $echo) {
            if ($echo->createdRound >= $state->turnCount) {
                continue;
            }
            $remaining = max(0, $echo->remainingRounds - 1);
            if ($remaining === 0) {
                $state->replaceFieldEcho($echo->ownerActorKey, null);
                $results[] = $this->emit($state, 'system', FieldEvent::ECHO_EXPIRED, $echo, "round:{$state->turnCount}:echo:{$echo->ownerActorKey}");
                continue;
            }
            $state->replaceFieldEcho($echo->ownerActorKey, new FieldState(
                $echo->key,
                $echo->ownerActorKey,
                $remaining,
                $echo->sourceSkillId,
                $echo->sourceActionId,
                $echo->createdRound,
            ));
        }

        return array_values(array_filter($results, static fn (FieldOperationResult $result): bool => $result->applied));
    }

    public function activationRate(BattleActor $actor, BattleState $state, int $baseRate): int
    {
        $delta = $this->modifier($actor, $state, 'activation_rate_delta', 'job_art');

        return max(0, min(100, $baseRate + (int) round($delta)));
    }

    public function accuracyDelta(BattleActor $actor, BattleState $state): float
    {
        return $this->modifier($actor, $state, 'accuracy_delta');
    }

    public function modifyDamage(
        BattleActor $actor,
        BattleState $state,
        int $damage,
        DamageSourceType $sourceType,
        ?string $damageScope = null,
    ): int
    {
        if (!in_array($sourceType, [DamageSourceType::NORMAL_ATTACK, DamageSourceType::JOB_SKILL, DamageSourceType::JOB_ART], true)
            || !$this->isCurrentActionActor($actor, $state)
            || ($damageScope ?? $state->currentActionDamageScope()) !== 'magical'
        ) {
            return $damage;
        }
        $delta = $this->modifier($actor, $state, 'damage_multiplier', 'magical');

        return max(0, (int) round($damage * (1 + $delta)));
    }

    public function modifyHpHeal(BattleActor $actor, BattleState $state, int $heal): int
    {
        if (!$this->isCurrentActionActor($actor, $state)) {
            return $heal;
        }
        $delta = $this->modifier($actor, $state, 'heal_multiplier');

        return max(0, (int) floor($heal * (1 + $delta)));
    }

    public function modifyResourceGain(BattleActor $actor, BattleState $state, int $baseGain): int
    {
        if (!$this->isCurrentActionActor($actor, $state)) {
            return $baseGain;
        }
        $delta = (int) round($this->modifier($actor, $state, 'resource_gain_delta'));

        return max(0, $baseGain + $delta);
    }

    private function modifier(BattleActor $actor, BattleState $state, string $axis, string $scope = 'all'): float
    {
        if (!$this->enabledFor($state)) {
            return 0.0;
        }

        return $this->modifiers->resolve($state->currentFieldSnapshot(), $state->actorKey($actor), $axis, $scope);
    }

    private function isCurrentActionActor(BattleActor $actor, BattleState $state): bool
    {
        return $this->enabledFor($state)
            && $state->currentActionActorKey() === $state->actorKey($actor);
    }

    private function isTrustedCurrentFieldArt(BattleActor $actor, Skill $skill): bool
    {
        $origin = (string) ($actor->jobArtOrigins[(int) $skill->id]
            ?? ((int) $skill->job_id === (int) $actor->currentJobId ? 'current' : 'inherited'));

        return $origin === 'current'
            && $this->catalog->isTrustedCurrentJobArt($actor->currentJobId, $skill);
    }

    private function isTrustedFieldArt(BattleActor $actor, Skill $skill): bool
    {
        if ($this->isTrustedCurrentFieldArt($actor, $skill)) {
            return true;
        }

        $origin = (string) ($actor->jobArtOrigins[(int) $skill->id]
            ?? ((int) $skill->job_id === (int) $actor->currentJobId ? 'current' : 'inherited'));

        return $origin === 'inherited'
            && $this->catalog->isPortableFieldArt($actor->currentJobId, $skill);
    }

    /** @param array<string, mixed> $metadata */
    private function deployFromMetadata(
        BattleActor $actor,
        BattleState $state,
        Skill $skill,
        array $metadata,
        int $sourceActionId,
    ): FieldOperationResult {
        $fieldKey = (string) ($metadata['field_key'] ?? '');
        if ((string) ($metadata['field_selection_mode'] ?? 'fixed') === 'next_cycle') {
            $keys = $this->catalog->cycleKeys();
            $currentKey = $state->primaryField()?->key;
            $currentIndex = $currentKey !== null ? array_search($currentKey, $keys, true) : false;
            $fieldKey = $currentIndex === false
                ? $keys[0]
                : $keys[($currentIndex + 1) % count($keys)];
        }

        return $fieldKey === ''
            ? FieldOperationResult::unchanged('missing_field_key')
            : $this->deployPrimary($actor, $state, $fieldKey, (int) $skill->id, $sourceActionId);
    }

    private function holdPreviousOverwrittenField(
        BattleActor $actor,
        BattleState $state,
        int $sourceSkillId,
        int $sourceActionId,
    ): FieldOperationResult {
        $previous = $state->lastOverwrittenFieldFor($actor);
        if ($previous === null) {
            return FieldOperationResult::unchanged('no_overwritten_field');
        }

        $echo = new FieldState(
            $previous->key,
            $state->actorKey($actor),
            1,
            $sourceSkillId,
            $sourceActionId,
            $state->turnCount,
        );
        $state->replaceFieldEcho($actor, $echo);

        return $this->emit($state, $state->actorKey($actor), FieldEvent::ECHO_CREATED, $echo, $sourceActionId);
    }

    private function damageScope(Skill $skill): string
    {
        if ($skill->isJobArt()) {
            return JobArtEffectCatalog::damageType((string) $skill->effect_template);
        }

        return (string) $skill->damage_type === 'magical' ? 'magical' : (string) $skill->damage_type;
    }

    private function emit(
        BattleState $state,
        string $actorKey,
        FieldEvent $event,
        FieldState $field,
        int|string $sourceActionId,
        ?string $blockedReason = null,
    ): FieldOperationResult {
        if (!$state->claimFieldEvent($actorKey, $event, $sourceActionId)) {
            return FieldOperationResult::unchanged('duplicate_field_event');
        }
        $result = new FieldOperationResult(true, $event, $field->key, $field->remainingRounds, $sourceActionId, $blockedReason);
        $state->recordFieldEvent($result);
        $this->appendLog($state, $result);

        return $result;
    }

    private function emitOverlay(
        BattleState $state,
        string $actorKey,
        FieldEvent $event,
        FieldOverlayState $field,
        int|string $sourceActionId,
    ): FieldOperationResult {
        if (!$state->claimFieldEvent($actorKey, $event, $sourceActionId)) {
            return FieldOperationResult::unchanged('duplicate_field_event');
        }
        $result = new FieldOperationResult(true, $event, $field->key, $field->remainingRounds, $sourceActionId);
        $state->recordFieldEvent($result);
        $this->appendLog($state, $result);

        return $result;
    }

    private function appendLog(BattleState $state, FieldOperationResult $result): void
    {
        $name = $result->fieldKey !== null ? $this->catalog->name($result->fieldKey) : '場';
        $remaining = $result->remainingRounds !== null ? "（残り{$result->remainingRounds}）" : '';
        $message = match ($result->event) {
            FieldEvent::CREATED => "{$name}が展開された{$remaining}",
            FieldEvent::REFRESHED => "{$name}が更新された{$remaining}",
            FieldEvent::EXTENDED => "場が延長された{$remaining}",
            FieldEvent::OVERWRITTEN => "{$name}へ場が上書きされた{$remaining}",
            FieldEvent::EXPIRED => "{$name}が消滅した",
            FieldEvent::OVERLAY_CREATED => "{$name}の副場が成立した{$remaining}",
            FieldEvent::OVERLAY_EXPIRED => "{$name}の副場が消滅した",
            FieldEvent::ECHO_CREATED => "{$name}の残響を1ラウンド保持した{$remaining}",
            FieldEvent::ECHO_EXPIRED => "{$name}の残響が消滅した",
            FieldEvent::OVERWRITE_BLOCKED => '場の上書きが防がれた',
            FieldEvent::LOCKED => '場が2ラウンド上書き不可になった',
            FieldEvent::LOCK_REFRESHED => '場の上書き不可が2ラウンドへ更新された',
            null => null,
        };
        if ($message !== null) {
            $state->addLog("<span class=\"text-violet-700 font-bold\">{$message}</span>");
        }
    }
}
