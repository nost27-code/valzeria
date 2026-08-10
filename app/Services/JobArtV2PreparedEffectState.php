<?php

namespace App\Services;

final class JobArtV2PreparedEffectState
{
    private int $lastProcessedRound;

    /**
     * @param  list<int>  $targetRanks
     */
    public function __construct(
        public readonly string $key,
        public readonly float $multiplier,
        public readonly int $appliedRound,
        public ?int $remainingRounds,
        public int $charges,
        public readonly int $sourceActionId,
        public readonly int $sourceSkillId,
        public readonly string $targetLineage,
        public readonly array $targetRanks,
        public readonly bool $strictNextAction,
        public readonly string $group,
        public ?int $remainingActionOpportunities = null,
    ) {
        $this->lastProcessedRound = $appliedRound;
    }

    public function advanceAtRoundEnd(int $round): bool
    {
        if ($this->remainingRounds === null || $round <= $this->lastProcessedRound) {
            return false;
        }

        $this->lastProcessedRound = $round;
        $this->remainingRounds = max(0, $this->remainingRounds - 1);

        return true;
    }

    public function consumeCharge(): bool
    {
        if ($this->charges <= 0) {
            return false;
        }

        $this->charges--;

        return true;
    }

    public function consumeActionOpportunity(): bool
    {
        if ($this->remainingActionOpportunities === null || $this->remainingActionOpportunities <= 0) {
            return false;
        }

        $this->remainingActionOpportunities--;

        return true;
    }

    public function isExpired(): bool
    {
        return $this->charges <= 0
            || ($this->remainingRounds !== null && $this->remainingRounds <= 0)
            || ($this->remainingActionOpportunities !== null && $this->remainingActionOpportunities <= 0);
    }
}
