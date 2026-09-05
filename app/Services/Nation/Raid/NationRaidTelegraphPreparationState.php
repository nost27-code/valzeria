<?php

namespace App\Services\Nation\Raid;

use InvalidArgumentException;

/**
 * raidだけが保持する予告準備。既存BattleActorのguard/counter/timed stateへ接続しない。
 */
final class NationRaidTelegraphPreparationState
{
    private const CLEAR_REASONS = [
        'executed',
        'executed_after_destroy',
        'replacement',
        'suppressed',
        'battle_end',
    ];

    private bool $destroyed = false;

    private ?string $clearedReason = null;

    public function __construct(
        public readonly string $preparationId,
        public readonly string $pendingEnemyActionId,
        public readonly string $kind,
        public readonly string $sourceCycleId,
        public readonly int $createdTurn,
        public readonly int $expiresOn,
    ) {
        if (! in_array($kind, ['reflect', 'cleanse_guard'], true)) {
            throw new InvalidArgumentException('Unknown raid preparation kind.');
        }
        if ($preparationId === '' || $pendingEnemyActionId === '' || $sourceCycleId === '') {
            throw new InvalidArgumentException('Raid preparation identifiers must not be empty.');
        }
        if ($createdTurn < 1 || $expiresOn < $createdTurn) {
            throw new InvalidArgumentException('Raid preparation lifetime is invalid.');
        }
    }

    public function isActive(): bool
    {
        return ! $this->destroyed && $this->clearedReason === null;
    }

    public function destroy(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $this->destroyed = true;

        return true;
    }

    public function clear(string $reason): void
    {
        if (! in_array($reason, self::CLEAR_REASONS, true)) {
            throw new InvalidArgumentException('Unknown raid preparation cleanup reason.');
        }
        if ($this->clearedReason === null) {
            $this->clearedReason = $reason;
        }
    }

    public function destroyed(): bool
    {
        return $this->destroyed;
    }

    public function clearedReason(): ?string
    {
        return $this->clearedReason;
    }

    /** @return array{preparation_id:string,pending_enemy_action_id:string,kind:string,source_cycle_id:string,created_turn:int,expires_on:int,destroyed:bool,active:bool,cleared_reason:?string} */
    public function toArray(): array
    {
        return [
            'preparation_id' => $this->preparationId,
            'pending_enemy_action_id' => $this->pendingEnemyActionId,
            'kind' => $this->kind,
            'source_cycle_id' => $this->sourceCycleId,
            'created_turn' => $this->createdTurn,
            'expires_on' => $this->expiresOn,
            'destroyed' => $this->destroyed,
            'active' => $this->isActive(),
            'cleared_reason' => $this->clearedReason,
        ];
    }
}
