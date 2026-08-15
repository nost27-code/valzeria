<?php

namespace App\Services\Battle;

use App\Services\FieldEvent;
use App\Services\FieldOperationResult;
use App\Services\FieldOverlayState;
use App\Services\FieldSnapshot;
use App\Services\FieldState;
use App\Services\ConversionResult;
use App\Services\JobArtV2BreakDebuffResult;
use App\Services\Battle\CleanseResult;
use App\Services\Battle\DamageTrace;
use App\Services\Battle\ParryResult;

class BattleState
{
    private const DEFAULT_MAX_TURNS = 50;
    public const PVP_MAX_TURNS = 100;

    public BattleActor $player;
    public BattleActor $enemy;

    public int $turnCount = 0;
    public int $maxTurns;

    public array $logs = [];

    /** @var array<int, list<string>> 被ダメージ表示の直後へ送る派生ログ。 */
    private array $deferredDamageLogs = [];

    public int $goldBonusPercent = 0;
    public int $dropBonusPercent = 0;
    public int $rareBonusPercent = 0;
    public int $materialBonusPercent = 0;
    public array $jobArtCooldowns = [];
    public array $jobArtUseCounts = [];
    public bool $valmonAssistRolled = false;
    public bool $valmonAssistUsed = false;
    public string $battleType;
    public array $enemyActionUseTurns = [];
    public array $enemyActionUseCounts = [];
    public ?int $pendingEnemyActionId = null;
    public int $pendingEnemyActionTurns = 0;
    /** @var array<string, mixed>|null */
    public ?array $enemyTelegraphContext = null;
    public int $enemyTelegraphSequence = 1;
    public ?array $explorationSupportSnapshot = null;

    private int $sourceActionSequence = 0;
    private ?int $currentSourceActionId = null;

    /** @var array<int, array<string, mixed>> source_action_id単位の役割効果スナップショット。 */
    private array $jobArtV2RoleActionContexts = [];

    /** @var array<string, true> */
    private array $claimedRoleEffectEvents = [];

    /** @var array<string, true> */
    private array $claimedResourceEvents = [];

    /** @var array<string, true> actor・resource・modifier・source action単位の獲得補正。 */
    private array $claimedResourceGainModifiers = [];

    /** @var array<string, true> actor・抑制owner・source action単位の金蝕消費予約。 */
    private array $claimedResourceSuppressionActions = [];

    /** @var array<string, true> actor・resource・source action単位の戦技固有リソース操作。 */
    private array $jobArtV2DirectResourceOperations = [];

    /** @var array<int, BattleActionResult> */
    private array $battleActionResults = [];

    /** @var array<string, true> */
    private array $claimedSpPressureEvents = [];

    /** @var list<\App\Services\JobArtV2SpPressureResult> */
    private array $spPressureResults = [];

    /** @var array<string, bool> */
    private array $piercingStanceSnapshots = [];

    /** @var array<string, true> */
    private array $claimedPiercingStanceEvents = [];

    /** @var list<array{actor_key: string, event: string, skill_id: int, source_action_id: int, had_stance: bool}> */
    private array $piercingStanceEvents = [];

    private ?FieldState $primaryField = null;
    private ?FieldOverlayState $fieldOverlay = null;

    /** @var array<string, FieldState> */
    private array $fieldEchoesByOwnerActorKey = [];

    /** @var array<string, FieldState> */
    private array $lastOverwrittenFieldsByOwnerActorKey = [];

    /** @var array<string, int> */
    private array $fieldOverwriteCountsByActor = [];
    private FieldSnapshot $currentFieldSnapshot;
    private ?string $currentActionActorKey = null;
    private string $currentActionKind = 'normal_attack';
    private string $currentActionDamageScope = 'physical';

    /** @var array<string, true> */
    private array $claimedFieldEvents = [];

    /** @var array<int, FieldOperationResult> */
    private array $fieldEvents = [];

    /** @var array<int, array<string, mixed>> 表示専用。戦闘判定には使用しない。 */
    private array $jobArtV2HudActions = [];

    /** @var array<string, true> */
    private array $claimedConversionActions = [];

    /** @var list<ConversionResult> */
    private array $conversionResults = [];

    /** @var array<string, true> */
    private array $claimedBreakDebuffEvents = [];

    /** @var list<JobArtV2BreakDebuffResult> */
    private array $breakDebuffResults = [];

    /** @var array<string, ParryResult> */
    private array $parryResults = [];

    /** @var array<string, DamageTrace> */
    private array $damageTraces = [];

    /** @var list<CleanseResult> */
    private array $cleanseResults = [];

    /** @var list<array{actor_key:string,event:string,remaining_rounds:int,source_action_id:int|string}> */
    private array $counterStanceEvents = [];

    public function __construct(BattleActor $player, BattleActor $enemy, string $battleType = 'pve')
    {
        $this->player = $player;
        $this->enemy = $enemy;
        $this->battleType = $battleType;
        $this->currentFieldSnapshot = FieldSnapshot::empty();
        $this->maxTurns = $battleType === 'pvp'
            ? self::PVP_MAX_TURNS
            : self::DEFAULT_MAX_TURNS;
    }

    public function addLog(string $message): void
    {
        $this->logs[] = $message;
    }

    /** @return list<string> */
    public function pullLogs(): array
    {
        $logs = $this->logs;
        $this->logs = [];

        return $logs;
    }

    public function deferLogAfterDamage(string $message, ?int $sourceActionId = null): void
    {
        $sourceActionId ??= $this->currentSourceActionId;
        if ($sourceActionId === null) {
            $this->addLog($message);

            return;
        }

        $this->deferredDamageLogs[$sourceActionId] ??= [];
        $this->deferredDamageLogs[$sourceActionId][] = $message;
    }

    public function addDamageLog(string $message, ?int $sourceActionId = null): void
    {
        $this->addLog($message);
        foreach ($this->pullDeferredDamageLogs($sourceActionId) as $deferredLog) {
            $this->addLog($deferredLog);
        }
    }

    /** @return list<string> */
    public function pullDeferredDamageLogs(?int $sourceActionId = null): array
    {
        $sourceActionId ??= $this->currentSourceActionId;
        if ($sourceActionId === null) {
            return [];
        }

        $logs = $this->deferredDamageLogs[$sourceActionId] ?? [];
        unset($this->deferredDamageLogs[$sourceActionId]);

        return $logs;
    }

    public function beginSourceAction(): int
    {
        $this->currentSourceActionId = ++$this->sourceActionSequence;

        return $this->currentSourceActionId;
    }

    public function currentSourceActionId(): ?int
    {
        return $this->currentSourceActionId;
    }

    /** @param array<string, mixed> $context */
    public function beginJobArtV2RoleAction(int $sourceActionId, array $context): void
    {
        $this->jobArtV2RoleActionContexts[$sourceActionId] = $context;
    }

    /** @param array<string, mixed> $attributes */
    public function updateJobArtV2RoleAction(int $sourceActionId, array $attributes): void
    {
        $this->jobArtV2RoleActionContexts[$sourceActionId] = array_replace(
            $this->jobArtV2RoleActionContexts[$sourceActionId] ?? [],
            $attributes,
        );
    }

    /** @return array<string, mixed> */
    public function jobArtV2RoleAction(?int $sourceActionId = null): array
    {
        $sourceActionId ??= $this->currentSourceActionId;

        return $sourceActionId !== null
            ? ($this->jobArtV2RoleActionContexts[$sourceActionId] ?? [])
            : [];
    }

    public function claimJobArtV2RoleEffect(
        BattleActor $actor,
        string $effectKey,
        int $sourceActionId,
    ): bool {
        $key = implode(':', [$this->actorKey($actor), $effectKey, $sourceActionId]);
        if (isset($this->claimedRoleEffectEvents[$key])) {
            return false;
        }

        $this->claimedRoleEffectEvents[$key] = true;

        return true;
    }

    public function claimResourceEvent(
        BattleActor $actor,
        string $resourceKey,
        string $event,
        int $sourceActionId,
    ): bool {
        $actorKey = $actor === $this->player
            ? 'player'
            : ($actor === $this->enemy ? 'enemy' : 'actor:' . spl_object_id($actor));
        $key = implode(':', [$actorKey, $resourceKey, $event, $sourceActionId]);
        if (isset($this->claimedResourceEvents[$key])) {
            return false;
        }

        $this->claimedResourceEvents[$key] = true;

        return true;
    }

    public function claimResourceGainModifier(
        BattleActor $actor,
        string $resourceKey,
        string $modifierKey,
        int $sourceActionId,
    ): bool {
        $key = implode(':', [$this->actorKey($actor), $resourceKey, $modifierKey, $sourceActionId]);
        if (isset($this->claimedResourceGainModifiers[$key])) {
            return false;
        }

        $this->claimedResourceGainModifiers[$key] = true;

        return true;
    }

    public function claimResourceSuppressionAction(
        BattleActor $actor,
        string $ownerKey,
        int $sourceActionId,
    ): void {
        $this->claimedResourceSuppressionActions[
            implode(':', [$this->actorKey($actor), $ownerKey, $sourceActionId])
        ] = true;
    }

    public function hasClaimedResourceSuppressionAction(
        BattleActor $actor,
        string $ownerKey,
        int $sourceActionId,
    ): bool {
        return isset($this->claimedResourceSuppressionActions[
            implode(':', [$this->actorKey($actor), $ownerKey, $sourceActionId])
        ]);
    }

    public function markJobArtV2DirectResourceOperation(
        BattleActor $actor,
        string $resourceKey,
        int $sourceActionId,
    ): void {
        $this->jobArtV2DirectResourceOperations[
            implode(':', [$this->actorKey($actor), $resourceKey, $sourceActionId])
        ] = true;
    }

    public function hasJobArtV2DirectResourceOperation(
        BattleActor $actor,
        string $resourceKey,
        int $sourceActionId,
    ): bool {
        return isset($this->jobArtV2DirectResourceOperations[
            implode(':', [$this->actorKey($actor), $resourceKey, $sourceActionId])
        ]);
    }

    public function actorKey(BattleActor $actor): string
    {
        return $actor === $this->player
            ? 'player'
            : ($actor === $this->enemy ? 'enemy' : 'actor:' . spl_object_id($actor));
    }

    public function claimConversionAction(BattleActor $actor, int $sourceActionId): bool
    {
        $key = $this->actorKey($actor).':'.$sourceActionId;
        if (isset($this->claimedConversionActions[$key])) {
            return false;
        }

        $this->claimedConversionActions[$key] = true;

        return true;
    }

    public function recordConversionResult(ConversionResult $result): void
    {
        $this->conversionResults[] = $result;
    }

    /** @return list<ConversionResult> */
    public function conversionResults(): array
    {
        return $this->conversionResults;
    }

    public function claimBreakDebuffEvent(BattleActor $target, int $sourceActionId): bool
    {
        $key = $this->actorKey($target).':'.$sourceActionId;
        if (isset($this->claimedBreakDebuffEvents[$key])) {
            return false;
        }

        $this->claimedBreakDebuffEvents[$key] = true;

        return true;
    }

    public function recordBreakDebuffResult(JobArtV2BreakDebuffResult $result): void
    {
        $this->breakDebuffResults[] = $result;
    }

    /** @return list<JobArtV2BreakDebuffResult> */
    public function breakDebuffResults(): array
    {
        return $this->breakDebuffResults;
    }

    public function recordParryResult(BattleActor $target, ParryResult $result): void
    {
        $this->parryResults[$this->defenseResultKey($target, $result->sourceActionId)] = $result;
    }

    public function parryResult(BattleActor $target, int $sourceActionId): ?ParryResult
    {
        return $this->parryResults[$this->defenseResultKey($target, $sourceActionId)] ?? null;
    }

    /** @return list<ParryResult> */
    public function parryResults(): array
    {
        return array_values($this->parryResults);
    }

    public function recordDamageTrace(BattleActor $target, DamageTrace $trace): void
    {
        $this->damageTraces[$this->defenseResultKey($target, $trace->sourceActionId)] = $trace;
    }

    public function damageTrace(BattleActor $target, int $sourceActionId): ?DamageTrace
    {
        return $this->damageTraces[$this->defenseResultKey($target, $sourceActionId)] ?? null;
    }

    /** @return list<DamageTrace> */
    public function damageTraces(): array
    {
        return array_values($this->damageTraces);
    }

    public function recordCleanseResult(CleanseResult $result): void
    {
        $this->cleanseResults[] = $result;
    }

    /** @return list<CleanseResult> */
    public function cleanseResults(): array
    {
        return $this->cleanseResults;
    }

    public function recordCounterStanceEvent(
        BattleActor $actor,
        string $event,
        int $remainingRounds,
        int|string $sourceActionId,
    ): void {
        $this->counterStanceEvents[] = [
            'actor_key' => $this->actorKey($actor),
            'event' => $event,
            'remaining_rounds' => $remainingRounds,
            'source_action_id' => $sourceActionId,
        ];
    }

    /** @return list<array{actor_key:string,event:string,remaining_rounds:int,source_action_id:int|string}> */
    public function counterStanceEvents(): array
    {
        return $this->counterStanceEvents;
    }

    public function recordBattleActionResult(BattleActionResult $result): bool
    {
        if (isset($this->battleActionResults[$result->sourceActionId])) {
            return false;
        }

        $this->battleActionResults[$result->sourceActionId] = $result;

        return true;
    }

    public function battleActionResult(int $sourceActionId): ?BattleActionResult
    {
        return $this->battleActionResults[$sourceActionId] ?? null;
    }

    /** @return list<BattleActionResult> */
    public function battleActionResults(): array
    {
        return array_values($this->battleActionResults);
    }

    public function claimSpPressureEvent(
        BattleActor $attacker,
        BattleActor $target,
        int $sourceActionId,
    ): bool {
        $key = implode(':', [$this->actorKey($attacker), $this->actorKey($target), $sourceActionId]);
        if (isset($this->claimedSpPressureEvents[$key])) {
            return false;
        }

        $this->claimedSpPressureEvents[$key] = true;

        return true;
    }

    public function recordSpPressureResult(\App\Services\JobArtV2SpPressureResult $result): void
    {
        $this->spPressureResults[] = $result;
    }

    /** @return list<\App\Services\JobArtV2SpPressureResult> */
    public function spPressureResults(): array
    {
        return $this->spPressureResults;
    }

    public function snapshotPiercingStance(BattleActor $actor, int $sourceActionId, bool $hadStance): bool
    {
        $key = implode(':', [$this->actorKey($actor), $sourceActionId]);
        if (array_key_exists($key, $this->piercingStanceSnapshots)) {
            return false;
        }

        $this->piercingStanceSnapshots[$key] = $hadStance;

        return true;
    }

    public function piercingStanceSnapshot(BattleActor $actor, int $sourceActionId): ?bool
    {
        $key = implode(':', [$this->actorKey($actor), $sourceActionId]);

        return $this->piercingStanceSnapshots[$key] ?? null;
    }

    public function claimPiercingStanceEvent(BattleActor $actor, string $event, int $sourceActionId): bool
    {
        $key = implode(':', [$this->actorKey($actor), $event, $sourceActionId]);
        if (isset($this->claimedPiercingStanceEvents[$key])) {
            return false;
        }

        $this->claimedPiercingStanceEvents[$key] = true;

        return true;
    }

    public function recordPiercingStanceEvent(
        BattleActor $actor,
        string $event,
        int $skillId,
        int $sourceActionId,
        bool $hadStance,
    ): void {
        $this->piercingStanceEvents[] = [
            'actor_key' => $this->actorKey($actor),
            'event' => $event,
            'skill_id' => $skillId,
            'source_action_id' => $sourceActionId,
            'had_stance' => $hadStance,
        ];
    }

    /** @return list<array{actor_key: string, event: string, skill_id: int, source_action_id: int, had_stance: bool}> */
    public function piercingStanceEvents(): array
    {
        return $this->piercingStanceEvents;
    }

    public function primaryField(): ?FieldState
    {
        return $this->primaryField;
    }

    public function fieldOverlay(): ?FieldOverlayState
    {
        return $this->fieldOverlay;
    }

    /** Internal mutation point used only by JobArtV2FieldService. */
    public function replacePrimaryField(?FieldState $field): void
    {
        $this->primaryField = $field;
    }

    /** Internal mutation point used only by JobArtV2FieldService. */
    public function replaceFieldOverlay(?FieldOverlayState $overlay): void
    {
        $this->fieldOverlay = $overlay;
    }

    public function recordFieldOverwrite(string $overwritingActorKey, FieldState $overwrittenField): void
    {
        $this->fieldOverwriteCountsByActor[$overwritingActorKey]
            = (int) ($this->fieldOverwriteCountsByActor[$overwritingActorKey] ?? 0) + 1;
        $this->lastOverwrittenFieldsByOwnerActorKey[$overwrittenField->ownerActorKey] = $overwrittenField;
    }

    public function fieldOverwriteCountFor(BattleActor|string $actor): int
    {
        $actorKey = $actor instanceof BattleActor ? $this->actorKey($actor) : $actor;

        return (int) ($this->fieldOverwriteCountsByActor[$actorKey] ?? 0);
    }

    public function lastOverwrittenFieldFor(BattleActor|string $actor): ?FieldState
    {
        $actorKey = $actor instanceof BattleActor ? $this->actorKey($actor) : $actor;

        return $this->lastOverwrittenFieldsByOwnerActorKey[$actorKey] ?? null;
    }

    public function replaceFieldEcho(BattleActor|string $actor, ?FieldState $echo): void
    {
        $actorKey = $actor instanceof BattleActor ? $this->actorKey($actor) : $actor;
        if ($echo === null) {
            unset($this->fieldEchoesByOwnerActorKey[$actorKey]);
            return;
        }

        $this->fieldEchoesByOwnerActorKey[$actorKey] = $echo;
    }

    public function fieldEchoFor(BattleActor|string $actor): ?FieldState
    {
        $actorKey = $actor instanceof BattleActor ? $this->actorKey($actor) : $actor;

        return $this->fieldEchoesByOwnerActorKey[$actorKey] ?? null;
    }

    /** @return list<FieldState> */
    public function fieldEchoes(): array
    {
        return array_values($this->fieldEchoesByOwnerActorKey);
    }

    public function setFieldActionContext(
        BattleActor $actor,
        FieldSnapshot $snapshot,
        string $kind,
        string $damageScope,
    ): void {
        $this->currentActionActorKey = $this->actorKey($actor);
        $this->currentFieldSnapshot = $snapshot;
        $this->currentActionKind = $kind;
        $this->currentActionDamageScope = $damageScope;
    }

    public function currentFieldSnapshot(): FieldSnapshot
    {
        return $this->currentFieldSnapshot;
    }

    public function currentActionActorKey(): ?string
    {
        return $this->currentActionActorKey;
    }

    public function currentActionKind(): string
    {
        return $this->currentActionKind;
    }

    public function currentActionDamageScope(): string
    {
        return $this->currentActionDamageScope;
    }

    /** @param array<string, mixed> $snapshot */
    public function beginJobArtV2HudAction(int $sourceActionId, array $snapshot): bool
    {
        if (isset($this->jobArtV2HudActions[$sourceActionId])) {
            return false;
        }

        $this->jobArtV2HudActions[$sourceActionId] = $snapshot;

        return true;
    }

    /** @param array<string, mixed> $attributes */
    public function updateJobArtV2HudAction(int $sourceActionId, array $attributes): void
    {
        if (!isset($this->jobArtV2HudActions[$sourceActionId])) {
            return;
        }

        $this->jobArtV2HudActions[$sourceActionId] = array_replace(
            $this->jobArtV2HudActions[$sourceActionId],
            $attributes,
        );
    }

    /** @return array<string, mixed>|null */
    public function jobArtV2HudAction(int $sourceActionId): ?array
    {
        return $this->jobArtV2HudActions[$sourceActionId] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function jobArtV2HudActions(): array
    {
        return array_values($this->jobArtV2HudActions);
    }

    public function claimFieldEvent(string $actorKey, FieldEvent $event, int|string $sourceActionId): bool
    {
        $key = implode(':', [$actorKey, $event->value, $sourceActionId]);
        if (isset($this->claimedFieldEvents[$key])) {
            return false;
        }
        $this->claimedFieldEvents[$key] = true;

        return true;
    }

    public function recordFieldEvent(FieldOperationResult $result): void
    {
        $this->fieldEvents[] = $result;
    }

    /** @return array<int, FieldOperationResult> */
    public function fieldEvents(): array
    {
        return $this->fieldEvents;
    }

    public function isBattleEnded(): bool
    {
        return $this->player->isDead() || $this->enemy->isDead() || $this->turnCount >= $this->maxTurns;
    }

    private function defenseResultKey(BattleActor $target, int $sourceActionId): string
    {
        return $this->actorKey($target).':'.$sourceActionId;
    }
}
