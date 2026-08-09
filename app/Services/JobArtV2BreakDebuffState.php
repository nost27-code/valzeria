<?php

namespace App\Services;

final class JobArtV2BreakDebuffState
{
    private int $lastProcessedRound;

    public function __construct(
        public readonly float $rate,
        public int $remainingRounds,
        public readonly int $appliedRound,
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
