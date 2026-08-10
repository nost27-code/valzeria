<?php

namespace App\Services;

final class JobArtV2TimedEffectState
{
    private int $lastProcessedRound;

    /**
     * @param  array<string, float>  $statModifiers
     */
    public function __construct(
        public readonly string $key,
        public readonly array $statModifiers,
        public readonly int $appliedRound,
        public int $remainingRounds,
        public readonly int $sourceActionId,
        public readonly int $sourceSkillId,
        public readonly bool $removable,
        public readonly float $strength,
    ) {
        $this->lastProcessedRound = $appliedRound;
    }

    public function advanceAtRoundEnd(int $round): bool
    {
        if ($round <= $this->lastProcessedRound) {
            return false;
        }

        $this->lastProcessedRound = $round;
        $this->remainingRounds = max(0, $this->remainingRounds - 1);

        return true;
    }

    public function isExpired(): bool
    {
        return $this->remainingRounds <= 0;
    }
}
