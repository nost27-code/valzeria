<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\CleanseResult;
use App\Services\Battle\DamageTrace;
use App\Services\Battle\HitResult;
use App\Services\Battle\NormalAttackResolution;
use App\Services\Battle\ParryResult;

/**
 * 奥義v2の戦闘状態を表示用に観測する。
 *
 * 記録した値は戦闘判定へ戻さず、DBにも保存しない。
 */
final class JobArtV2BattleHudService
{
    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
        private readonly JobArtV2ResourceCatalog $resourceCatalog,
        private readonly JobArtV2FieldCatalog $fieldCatalog,
        private readonly JobArtV2PowerResolver $powerResolver,
        private readonly ?JobArtV2UltimateCounterplayService $ultimateCounterplayService = null,
    ) {}

    public function beginAction(BattleActor $actor, BattleState $state, int $sourceActionId): void
    {
        $target = $actor === $state->player ? $state->enemy : $state->player;
        $actorEnabled = $this->featureGate->usesResources($actor);
        $targetEnabled = $this->featureGate->usesResources($target);
        if (! $actorEnabled && ! $targetEnabled) {
            return;
        }

        $state->beginJobArtV2HudAction($sourceActionId, [
            'source_action_id' => $sourceActionId,
            'turn' => $state->turnCount,
            'actor_key' => $state->actorKey($actor),
            'actor_name' => $actor->name,
            'target_key' => $state->actorKey($target),
            'target_name' => $target->name,
            'action_kind' => null,
            'action_name' => null,
            'skill_id' => null,
            'rank' => null,
            'hit_result' => null,
            'penetration_rate' => null,
            'field_overwrite_power' => null,
            'sp_pressure' => null,
            'conversion_result' => null,
            'before' => $actorEnabled ? $this->stateSnapshot($actor, $state) : null,
            'target_before' => $targetEnabled ? $this->stateSnapshot($target, $state) : null,
            'after' => null,
            'target_after' => null,
            'completed' => false,
        ]);
    }

    public function markJobArt(BattleActor $actor, BattleState $state, Skill $skill): void
    {
        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null || ! $this->featureGate->usesResources($actor)) {
            return;
        }

        $metadata = $this->prototypeCatalog->artResourceMetadata($skill);
        $usesTrustedPenetration = $this->featureGate->usesPenetration($actor)
            && $this->prototypeCatalog->isTrustedArtProfile($skill);
        $fieldOverwritePower = $this->powerResolver->fieldOverwriteBranchForExecution($actor, $skill, $state);
        $state->updateJobArtV2HudAction($sourceActionId, [
            'action_kind' => 'job_art',
            'action_name' => (string) $skill->name,
            'skill_id' => (int) $skill->id,
            'rank' => (int) $skill->learn_rank,
            'penetration_rate' => $usesTrustedPenetration && isset($metadata['penetration_rate'])
                ? (float) $metadata['penetration_rate']
                : null,
            'field_overwrite_power' => $fieldOverwritePower,
        ]);
    }

    public function markCurrentJobSkill(BattleActor $actor, BattleState $state, Skill $skill): void
    {
        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null || ! $this->featureGate->usesResources($actor)) {
            return;
        }

        $state->updateJobArtV2HudAction($sourceActionId, [
            'action_kind' => 'current_job_skill',
            'action_name' => (string) $skill->name,
            'skill_id' => (int) $skill->id,
        ]);
    }

    public function markNormalAttackResolution(
        BattleActor $actor,
        BattleState $state,
        NormalAttackResolution $resolution,
    ): void {
        if (! $this->featureGate->usesResources($actor)) {
            return;
        }

        $state->updateJobArtV2HudAction($resolution->sourceActionId, [
            'action_kind' => 'normal_attack',
            'action_name' => '通常攻撃',
            'hit_result' => $resolution->hitResult->value,
        ]);
    }

    public function recordSpPressure(
        BattleActor $actor,
        BattleState $state,
        JobArtV2SpPressureResult $result,
    ): void {
        if ($result->sourceActionId === null || ! $this->featureGate->usesResources($actor)) {
            return;
        }

        $state->updateJobArtV2HudAction($result->sourceActionId, [
            'sp_pressure' => $result->toArray(),
        ]);
    }

    public function recordConversionResult(
        BattleActor $actor,
        BattleState $state,
        ConversionResult $result,
    ): void {
        if (! $this->featureGate->usesResources($actor)) {
            return;
        }

        $state->updateJobArtV2HudAction($result->sourceActionId, [
            'conversion_result' => $result->toArray(),
        ]);
    }

    public function recordHitResult(
        BattleActor $actor,
        BattleState $state,
        ?HitResult $hitResult,
    ): void {
        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null || $hitResult === null || ! $this->featureGate->usesResources($actor)) {
            return;
        }

        $state->updateJobArtV2HudAction($sourceActionId, [
            'hit_result' => $hitResult->value,
        ]);
    }

    public function finishAction(BattleActor $actor, BattleState $state): void
    {
        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null || $state->jobArtV2HudAction($sourceActionId) === null) {
            return;
        }

        $target = $actor === $state->player ? $state->enemy : $state->player;
        $actorEnabled = $this->featureGate->usesResources($actor);
        $targetEnabled = $this->featureGate->usesResources($target);

        $state->updateJobArtV2HudAction($sourceActionId, [
            'after' => $actorEnabled ? $this->stateSnapshot($actor, $state) : null,
            'target_after' => $targetEnabled ? $this->stateSnapshot($target, $state) : null,
            'penetration_applied' => $this->featureGate->usesPenetrationStance($actor)
                ? $this->penetrationApplied($actor, $state, $sourceActionId)
                : $this->featureGate->usesPenetration($actor),
            'stance_events' => $this->stanceEvents($actor, $state, $sourceActionId),
            'break_debuff_events' => $this->breakDebuffEvents($state, $sourceActionId),
            'completed' => true,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function present(BattleState $state): ?array
    {
        $actors = array_values(array_filter([
            $this->presentActor($state->player, $state, '自分'),
            $this->presentActor($state->enemy, $state, '相手'),
        ]));
        if ($actors === []) {
            return null;
        }

        $actions = [];
        foreach ($state->jobArtV2HudActions() as $action) {
            if (empty($action['completed'])
                || (! is_array($action['after'] ?? null) && ! is_array($action['target_after'] ?? null))
            ) {
                continue;
            }

            $actionKind = $action['action_kind'] ?? null;
            if (! in_array($actionKind, ['job_art', 'normal_attack', 'current_job_skill', 'incoming_attack'], true)) {
                continue;
            }

            $changes = [];
            if (is_array($action['before'] ?? null) && is_array($action['after'] ?? null)) {
                $changes = $this->changes(
                    (array) $action['before'],
                    (array) $action['after'],
                    (array) ($action['stance_events'] ?? []),
                    (string) $action['actor_key'],
                );
            }
            if (is_array($action['target_before'] ?? null) && is_array($action['target_after'] ?? null)) {
                $changes = array_merge($changes, $this->changes(
                    (array) $action['target_before'],
                    (array) $action['target_after'],
                    [],
                    (string) ($action['target_key'] ?? 'enemy'),
                ));
            }
            $changes = array_merge(
                $changes,
                $this->defenseChanges($state, (int) $action['source_action_id']),
                $this->counterStanceChanges($state, (int) $action['source_action_id']),
            );
            $spPressure = $action['sp_pressure'] ?? null;
            if (is_array($spPressure) && (int) ($spPressure['actual_loss'] ?? 0) > 0) {
                $changes[] = [
                    'type' => 'target_sp',
                    'before' => (int) ($spPressure['sp_before'] ?? 0),
                    'after' => (int) ($spPressure['sp_after'] ?? 0),
                    'actual_loss' => (int) ($spPressure['actual_loss'] ?? 0),
                ];
            }
            $conversion = $action['conversion_result'] ?? null;
            if (is_array($conversion) && ! empty($conversion['success'])) {
                $changes[] = [
                    'type' => 'conversion',
                    'hp_before' => (int) ($conversion['hp_before'] ?? 0),
                    'hp_after' => (int) ($conversion['hp_after'] ?? 0),
                    'actual_hp_loss' => (int) ($conversion['actual_hp_loss'] ?? 0),
                    'sp_before' => (int) ($conversion['sp_before_conversion'] ?? 0),
                    'sp_after' => (int) ($conversion['sp_after_conversion'] ?? 0),
                    'actual_sp_gain' => (int) ($conversion['actual_sp_gain'] ?? 0),
                ];
            }
            foreach ((array) ($action['break_debuff_events'] ?? []) as $breakEvent) {
                $changes[] = [
                    'type' => 'break_debuff',
                    'event' => (string) ($breakEvent['event'] ?? ''),
                    'rate_percent' => (float) ($breakEvent['rate'] ?? 0.0) * 100,
                    'remaining_rounds' => (int) ($breakEvent['remaining_rounds'] ?? 0),
                    'applied' => (bool) ($breakEvent['applied'] ?? false),
                ];
            }
            if ($actionKind !== 'job_art' && $changes === []) {
                continue;
            }

            $penetrationRate = ! empty($action['penetration_applied'])
                && ($action['hit_result'] ?? null) === HitResult::HIT->value
                ? ($action['penetration_rate'] ?? null)
                : null;
            $actions[] = [
                'source_action_id' => (int) $action['source_action_id'],
                'turn' => (int) $action['turn'],
                'actor_key' => (string) $action['actor_key'],
                'actor_label' => $action['actor_key'] === 'player' ? '自分' : '相手',
                'actor_name' => (string) $action['actor_name'],
                'action_kind' => (string) $actionKind,
                'action_name' => (string) ($action['action_name'] ?? '行動'),
                'rank_label' => $this->rankLabel(isset($action['rank']) ? (int) $action['rank'] : null),
                'hit_result' => $action['hit_result'] ?? null,
                'penetration_percent' => $penetrationRate !== null
                    ? (int) round((float) $penetrationRate * 100)
                    : null,
                'field_overwrite_power' => is_array($action['field_overwrite_power'] ?? null)
                    ? $action['field_overwrite_power']
                    : null,
                'changes' => $changes,
            ];
        }

        return [
            'actors' => $actors,
            'actions' => $actions,
            'round_events' => $this->roundEvents($state),
        ];
    }

    /** @return array<string, mixed>|null */
    private function presentActor(BattleActor $actor, BattleState $state, string $label): ?array
    {
        if (! $this->featureGate->usesResources($actor)) {
            return null;
        }

        $resources = $this->resourceSnapshots($actor);
        if ($resources === []) {
            return null;
        }

        $ultimate = ($this->ultimateCounterplayService
            ?? app(JobArtV2UltimateCounterplayService::class))->hudSnapshot($actor, $state);
        if ($ultimate !== null) {
            foreach ($resources as &$resourceSummary) {
                if ((string) $resourceSummary['key'] === (string) ($ultimate['resource_key'] ?? '')) {
                    $resourceSummary['status_label'] = $ultimate['status_label'];
                }
            }
            unset($resourceSummary);
        }

        $resource = $this->primaryResourceSnapshot($resources);
        $actorKey = $state->actorKey($actor);

        return [
            'actor_key' => $actorKey,
            'actor_label' => $label,
            'actor_name' => $actor->name,
            // resourceは既存表示契約用の先頭資源。resourcesに装備中の全系譜資源を含める。
            'resource' => $resource,
            'resources' => $resources,
            'field' => $this->presentField($state->primaryField(), $actorKey),
            'overlay' => $this->presentField($state->fieldOverlay(), $actorKey),
            'echo' => $this->presentField($state->fieldEchoFor($actor), $actorKey),
            'field_overwrite_count' => $this->hasEquippedJobArt($actor, 63)
                ? $state->fieldOverwriteCountFor($actor)
                : null,
            'stance' => $this->hasEquippedJobArt($actor, 62) && $this->featureGate->usesPenetrationStance($actor)
                ? ['name' => '貫通構え', 'active' => $actor->hasPiercingStance()]
                : ($actor->counterStanceState() !== null
                    ? [
                        'name' => '剣冠の構え',
                        'active' => $actor->counterStanceState() !== null,
                        'remaining_rounds' => $actor->counterStanceState()?->remainingRounds,
                        'rate_percent' => (int) round(($actor->counterStanceState()?->parryRate ?? 0.0) * 100),
                    ]
                    : null),
            'guard' => $actor->jobArtV2GuardState() !== null
                ? [
                    'active' => $actor->jobArtV2GuardState() !== null,
                    'rate_percent' => (int) round(($actor->jobArtV2GuardState()?->rate ?? 0.0) * 100),
                    'charges' => $actor->jobArtV2GuardState()?->charges ?? 0,
                ]
                : null,
            'debuff' => $actor->breakDebuffState() !== null
                ? [
                    'name' => '崩し',
                    'rate_percent' => $actor->breakDebuffState()->rate * 100,
                    'remaining_rounds' => $actor->breakDebuffState()->remainingRounds,
                ]
                : null,
            'progression' => $this->presentProgression($actor, $state),
            'ultimate' => $ultimate,
        ];
    }

    /** @return list<string> */
    private function presentProgression(BattleActor $actor, BattleState $state): array
    {
        $target = $actor === $state->player ? $state->enemy : $state->player;
        $actorState = $actor->existingJobArtV2ProgressionState();
        $targetState = $target->existingJobArtV2ProgressionState();
        $ownerKey = 'actor:'.spl_object_id($actor);
        $labels = [];

        foreach ($actor->jobArtV2PreparedEffects() as $prepared) {
            if ($prepared->isExpired()) {
                continue;
            }
            $name = match ($prepared->key) {
                'magic_aim_prep' => '魔矢装填',
                'break_focus' => '崩し集中',
                'split_pierce' => '分割貫通',
                default => null,
            };
            if ($name === null) {
                continue;
            }
            $parts = ["{$name}：残り{$prepared->charges}回"];
            if ($prepared->remainingActionOpportunities !== null) {
                $parts[] = "期限{$prepared->remainingActionOpportunities}行動";
            }
            $labels[] = implode(' / ', $parts);
        }

        if ($actorState?->hasRoundState('super_pierce_stance')) {
            $remaining = (int) ($actorState->roundStates['super_pierce_stance']['remaining'] ?? 0);
            $labels[] = "蒼天構え：残り{$remaining}R";
        }

        $huntMarks = (int) ($targetState?->huntingMarks[$ownerKey] ?? 0);
        if ($huntMarks > 0) {
            $labels[] = "標的印：{$huntMarks}/3";
        }
        $seal = $targetState?->sealReservations[$ownerKey] ?? null;
        if (is_array($seal)) {
            $labels[] = '封技予約：'.(string) ($seal['category'] ?? '行動')
                .' / 残り'.(int) ($seal['remaining_rounds'] ?? 0).'R';
        }

        $breakMarks = (int) ($targetState?->breakMarks[$ownerKey] ?? 0);
        if ($breakMarks > 0) {
            $labels[] = "崩し印：{$breakMarks}/3";
        }
        if ($actorState?->zanshinAvailable) {
            $labels[] = '残心：再接続可能';
        }

        $suppression = $targetState?->resourceSuppressions[$ownerKey] ?? null;
        if (is_array($suppression)) {
            $label = '獲得抑制：残り'.(int) ($suppression['remaining_gains'] ?? 0).'回';
            if (! empty($suppression['compensation_armed'])) {
                $label .= ' / 補償判定まで'.(int) ($suppression['compensation_actions'] ?? 0).'行動';
            }
            $labels[] = $label;
        }

        if ($actorState?->initiativeRerollNextRound) {
            $labels[] = '次ラウンド：後攻時のみ先後を再抽選';
        }
        if ($actorState?->initiativeForceFirstNextRound) {
            $labels[] = '次ラウンド：先行確定';
        }
        return $labels;
    }

    /** @return array<string, mixed> */
    private function stateSnapshot(BattleActor $actor, BattleState $state): array
    {
        $resources = $this->resourceSnapshots($actor);
        $resourceSnapshot = $this->primaryResourceSnapshot($resources);

        return [
            'sp' => $actor->mp,
            'resource' => $resourceSnapshot,
            'resources' => $resources,
            'field' => $this->rawField($state->primaryField()),
            'overlay' => $this->rawField($state->fieldOverlay()),
            'echo' => $this->rawField($state->fieldEchoFor($actor)),
            'field_overwrite_count' => $this->hasEquippedJobArt($actor, 63)
                ? $state->fieldOverwriteCountFor($actor)
                : null,
            'stance' => $this->hasEquippedJobArt($actor, 62) && $this->featureGate->usesPenetrationStance($actor)
                ? $actor->hasPiercingStance()
                : null,
            'counter_stance' => $actor->counterStanceState() !== null
                ? [
                    'active' => $actor->counterStanceState() !== null,
                    'remaining_rounds' => $actor->counterStanceState()?->remainingRounds ?? 0,
                    'rate_percent' => (int) round(($actor->counterStanceState()?->parryRate ?? 0.0) * 100),
                ]
                : null,
            'guard' => $actor->jobArtV2GuardState() !== null
                ? [
                    'active' => $actor->jobArtV2GuardState() !== null,
                    'rate_percent' => (int) round(($actor->jobArtV2GuardState()?->rate ?? 0.0) * 100),
                    'charges' => $actor->jobArtV2GuardState()?->charges ?? 0,
                ]
                : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function rawField(FieldState|FieldOverlayState|null $field): ?array
    {
        if ($field === null) {
            return null;
        }

        return [
            'key' => $field->key,
            'name' => $this->fieldCatalog->name($field->key),
            'owner_actor_key' => $field->ownerActorKey,
            'remaining_rounds' => $field->remainingRounds,
            'lock_remaining_rounds' => $field instanceof FieldState
                ? $field->overwriteLockRemainingRounds
                : 0,
        ];
    }

    /** @return array<string, mixed>|null */
    private function presentField(FieldState|FieldOverlayState|null $field, string $viewerActorKey): ?array
    {
        $raw = $this->rawField($field);
        if ($raw === null) {
            return null;
        }

        $raw['owner_label'] = $raw['owner_actor_key'] === $viewerActorKey ? '自分' : '相手';

        return $raw;
    }

    /** @return list<array<string, mixed>> */
    private function changes(array $before, array $after, array $stanceEvents = [], string $viewerActorKey = 'player'): array
    {
        $changes = [];
        $beforeResources = $this->indexedResourceSnapshots($before);
        $afterResources = $this->indexedResourceSnapshots($after);
        foreach (array_unique([...array_keys($beforeResources), ...array_keys($afterResources)]) as $resourceKey) {
            $beforeResource = $beforeResources[$resourceKey] ?? null;
            $afterResource = $afterResources[$resourceKey] ?? null;
            if (! is_array($beforeResource) || ! is_array($afterResource)
                || (int) $beforeResource['points'] === (int) $afterResource['points']
            ) {
                continue;
            }

            $delta = (int) $afterResource['points'] - (int) $beforeResource['points'];
            $changes[] = [
                'type' => 'resource',
                'name' => (string) $afterResource['name'],
                'before' => (int) $beforeResource['points'],
                'after' => (int) $afterResource['points'],
                'delta' => $delta,
                'delta_label' => $delta > 0 ? "+{$delta}" : (string) $delta,
                'cap' => (int) $afterResource['cap'],
                'is_full' => (int) $afterResource['points'] >= (int) $afterResource['cap'],
            ];
        }

        foreach ([['field', 'main_field'], ['overlay', 'overlay'], ['echo', 'field_echo']] as [$key, $type]) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changes[] = [
                    'type' => $type,
                    'before' => $this->decorateFieldOwner($before[$key] ?? null, $viewerActorKey),
                    'after' => $this->decorateFieldOwner($after[$key] ?? null, $viewerActorKey),
                ];
            }
        }

        if (($before['field_overwrite_count'] ?? null) !== ($after['field_overwrite_count'] ?? null)
            && $after['field_overwrite_count'] !== null
        ) {
            $changes[] = [
                'type' => 'field_overwrite_count',
                'before' => (int) ($before['field_overwrite_count'] ?? 0),
                'after' => (int) $after['field_overwrite_count'],
            ];
        }

        $stanceEventLabel = $this->stanceEventLabel($stanceEvents);
        if ($stanceEventLabel === null
            && ($before['stance'] ?? null) !== ($after['stance'] ?? null)
            && ($before['stance'] ?? null) !== null
            && ($after['stance'] ?? null) !== null
        ) {
            $changes[] = [
                'type' => 'stance',
                'before' => (bool) $before['stance'],
                'after' => (bool) $after['stance'],
            ];
        }

        if ($stanceEventLabel !== null) {
            $changes[] = [
                'type' => 'stance_event',
                'label' => $stanceEventLabel,
            ];
        }

        foreach ([['counter_stance', 'counter_stance_state'], ['guard', 'guard_state']] as [$key, $type]) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changes[] = [
                    'type' => $type,
                    'before' => $before[$key] ?? null,
                    'after' => $after[$key] ?? null,
                ];
            }
        }

        if ((int) ($before['sp'] ?? 0) !== (int) ($after['sp'] ?? 0)) {
            $changes[] = [
                'type' => 'sp',
                'before' => (int) ($before['sp'] ?? 0),
                'after' => (int) ($after['sp'] ?? 0),
            ];
        }

        return $changes;
    }

    /** @return list<array<string, int|string|bool>> */
    private function resourceSnapshots(BattleActor $actor): array
    {
        return array_values(array_map(function (array $resource) use ($actor): array {
            $key = (string) $resource['resource_key'];
            $points = $actor->getResource($key);
            $cap = (int) $resource['resource_max_points'];

            return [
                'key' => $key,
                'name' => (string) $resource['resource_name'],
                'points' => $points,
                'cap' => $cap,
                'remaining' => max(0, $cap - $points),
                'is_full' => $points >= $cap,
                'percent' => $cap > 0 ? min(100, (int) floor(($points / $cap) * 100)) : 0,
                'is_primary' => (bool) ($resource['is_primary_resource'] ?? false),
            ];
        }, $this->resourceCatalog->resourcesForActor($actor)));
    }

    /** @param list<array<string, mixed>> $resources */
    private function primaryResourceSnapshot(array $resources): ?array
    {
        foreach ($resources as $resource) {
            if (! empty($resource['is_primary'])) {
                return $resource;
            }
        }

        return $resources[0] ?? null;
    }

    private function hasEquippedJobArt(BattleActor $actor, int $jobId): bool
    {
        foreach ($actor->jobArts as $skill) {
            if ($skill instanceof Skill
                && (int) $skill->job_id === $jobId
                && $this->prototypeCatalog->isTrustedArtProfile($skill)
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array<string, mixed>> */
    private function indexedResourceSnapshots(array $snapshot): array
    {
        $resources = is_array($snapshot['resources'] ?? null)
            ? $snapshot['resources']
            : (is_array($snapshot['resource'] ?? null) ? [$snapshot['resource']] : []);
        $indexed = [];
        foreach ($resources as $resource) {
            if (! is_array($resource) || ! isset($resource['key'])) {
                continue;
            }
            $indexed[(string) $resource['key']] = $resource;
        }

        return $indexed;
    }

    /** @return array<string, mixed>|null */
    private function decorateFieldOwner(mixed $field, string $viewerActorKey): ?array
    {
        if (! is_array($field)) {
            return null;
        }

        $field['owner_label'] = ($field['owner_actor_key'] ?? null) === $viewerActorKey ? '自分' : '相手';

        return $field;
    }

    private function penetrationApplied(BattleActor $actor, BattleState $state, int $sourceActionId): bool
    {
        $actorKey = $state->actorKey($actor);
        foreach ($state->piercingStanceEvents() as $event) {
            if (($event['actor_key'] ?? null) === $actorKey
                && (int) ($event['source_action_id'] ?? 0) === $sourceActionId
                && ($event['event'] ?? null) === JobArtV2PenetrationStanceService::EVENT_CONSUMED
                && ! empty($event['had_stance'])
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function stanceEvents(BattleActor $actor, BattleState $state, int $sourceActionId): array
    {
        $actorKey = $state->actorKey($actor);

        return array_values(array_map(
            static fn (array $event): string => (string) $event['event'],
            array_filter(
                $state->piercingStanceEvents(),
                static fn (array $event): bool => ($event['actor_key'] ?? null) === $actorKey
                    && (int) ($event['source_action_id'] ?? 0) === $sourceActionId,
            ),
        ));
    }

    /** @return list<array<string, bool|float|int|string>> */
    private function breakDebuffEvents(BattleState $state, int $sourceActionId): array
    {
        return array_values(array_map(
            static fn (JobArtV2BreakDebuffResult $result): array => $result->toArray(),
            array_filter(
                $state->breakDebuffResults(),
                static fn (JobArtV2BreakDebuffResult $result): bool => $result->sourceActionId === $sourceActionId,
            ),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function defenseChanges(BattleState $state, int $sourceActionId): array
    {
        $changes = [];
        foreach ($state->parryResults() as $result) {
            if (! $result instanceof ParryResult
                || $result->sourceActionId !== $sourceActionId
                || ! $result->eligible
            ) {
                continue;
            }

            $changes[] = [
                'type' => 'parry',
                'success' => $result->success,
                'rate_percent' => (int) round($result->rate * 100),
                'damage_before' => $result->damageBeforeParry,
                'damage_after' => $result->damageAfterParry,
                'counter_power' => $result->counterPower,
                'counter_damage' => $result->counterDamage,
            ];
        }
        foreach ($state->damageTraces() as $trace) {
            if (! $trace instanceof DamageTrace || $trace->sourceActionId !== $sourceActionId) {
                continue;
            }

            $changes[] = [
                'type' => 'active_guard',
                'rate_percent' => (int) round($trace->guardRate * 100),
                'damage_before' => $trace->damageBeforeActiveGuard,
                'damage_after' => $trace->damageAfterActiveGuard,
                'prevented_damage' => $trace->preventedDamage,
                'consumed' => $trace->guardConsumed,
            ];
        }
        foreach ($state->cleanseResults() as $result) {
            if (! $result instanceof CleanseResult || $result->sourceActionId !== $sourceActionId) {
                continue;
            }

            $changes[] = [
                'type' => 'cleanse',
                'success' => $result->success,
                'removed_states' => $result->removedStates,
                'removed_count' => $result->removedCount,
            ];
        }

        return $changes;
    }

    /** @return list<array<string, mixed>> */
    private function counterStanceChanges(BattleState $state, int $sourceActionId): array
    {
        return array_values(array_map(
            static fn (array $event): array => [
                'type' => 'counter_stance',
                'event' => (string) ($event['event'] ?? ''),
                'remaining_rounds' => (int) ($event['remaining_rounds'] ?? 0),
            ],
            array_filter(
                $state->counterStanceEvents(),
                static fn (array $event): bool => is_int($event['source_action_id'] ?? null)
                    && (int) $event['source_action_id'] === $sourceActionId,
            ),
        ));
    }

    /** @param list<string> $events */
    private function stanceEventLabel(array $events): ?string
    {
        if (in_array(JobArtV2PenetrationStanceService::EVENT_CONSUMED, $events, true)
            && in_array(JobArtV2PenetrationStanceService::EVENT_REFORMED, $events, true)
        ) {
            return '貫通構えを利用 → 再形成';
        }
        if (in_array(JobArtV2PenetrationStanceService::EVENT_CONSUMED, $events, true)) {
            return '貫通構え ON → OFF';
        }
        if (in_array(JobArtV2PenetrationStanceService::EVENT_ACQUIRED, $events, true)) {
            return '貫通構え ON';
        }
        if (in_array(JobArtV2PenetrationStanceService::EVENT_REFORMED, $events, true)) {
            return '貫通構えを再形成';
        }

        return null;
    }

    /** @return list<array{turn:int,label:string}> */
    private function roundEvents(BattleState $state): array
    {
        $events = [];
        foreach ($state->fieldEvents() as $event) {
            if (! $event->applied || ! is_string($event->sourceActionId)) {
                continue;
            }
            if (! preg_match('/^round:(\d+):/', $event->sourceActionId, $matches)) {
                continue;
            }

            $name = $event->fieldKey !== null ? $this->fieldCatalog->name($event->fieldKey) : '場';
            $label = match ($event->event) {
                FieldEvent::EXPIRED => "主場：{$name}が消滅",
                FieldEvent::OVERLAY_EXPIRED => "副場：{$name}が消滅",
                FieldEvent::ECHO_EXPIRED => "残響：{$name}が消滅",
                default => null,
            };
            if ($label !== null) {
                $events[] = ['turn' => (int) $matches[1], 'label' => $label];
            }
        }

        foreach ($state->breakDebuffResults() as $result) {
            if ($result->event !== JobArtV2BreakDebuffService::EVENT_EXPIRED
                || ! is_string($result->sourceActionId)
                || ! preg_match('/^round:(\d+):/', $result->sourceActionId, $matches)
            ) {
                continue;
            }

            $events[] = ['turn' => (int) $matches[1], 'label' => '崩しが解除'];
        }

        foreach ($state->counterStanceEvents() as $event) {
            if (($event['event'] ?? null) !== JobArtV2DefenseService::COUNTER_EVENT_EXPIRED
                || ! is_string($event['source_action_id'] ?? null)
                || ! preg_match('/^round:(\d+):/', (string) $event['source_action_id'], $matches)
            ) {
                continue;
            }

            $events[] = ['turn' => (int) $matches[1], 'label' => '剣冠の構えが解除'];
        }

        return $events;
    }

    private function rankLabel(?int $rank): ?string
    {
        return match ($rank) {
            1 => '始動',
            5 => '連携',
            9 => '奥義',
            default => null,
        };
    }
}
