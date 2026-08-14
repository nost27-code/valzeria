<?php

namespace App\Services;

/** PvP系戦闘の装備中奥義準備。戦闘終了時に破棄する。 */
final class JobArtV2UltimatePreparationState
{
    public const PHASE_PREPARING = 'preparing';
    public const PHASE_READY = 'ready';

    public function __construct(
        public readonly int $cycleId,
        public readonly string $mainLineage,
        public readonly string $resourceKey,
        public readonly int $preparedSourceActionId,
        public string $phase = self::PHASE_PREPARING,
        public int $delayOwnActionsRemaining = 0,
        public bool $delayApplied = false,
        public readonly int $requiredPoints = 12,
    ) {}

    public function isPreparing(): bool
    {
        return $this->phase === self::PHASE_PREPARING;
    }

    public function isReady(): bool
    {
        return $this->phase === self::PHASE_READY;
    }

    public function markReady(): void
    {
        $this->phase = self::PHASE_READY;
    }

    public function applyOneActionDelay(): bool
    {
        if ($this->delayApplied) {
            return false;
        }

        $this->delayApplied = true;
        $this->delayOwnActionsRemaining = 1;

        return true;
    }
}
