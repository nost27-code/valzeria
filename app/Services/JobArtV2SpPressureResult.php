<?php

namespace App\Services;

final class JobArtV2SpPressureResult
{
    public function __construct(
        public readonly bool $applied,
        public readonly int $requested = 0,
        public readonly int $spBefore = 0,
        public readonly int $spAfter = 0,
        public readonly int $actualLoss = 0,
        public readonly int $battleCap = 0,
        public readonly int $remainingCap = 0,
        public readonly ?int $sourceActionId = null,
        public readonly ?string $blockedReason = null,
    ) {
    }

    public static function unchanged(?string $blockedReason = null): self
    {
        return new self(false, blockedReason: $blockedReason);
    }

    /** @return array<string, int|bool|null> */
    public function toArray(): array
    {
        return [
            'applied' => $this->applied,
            'requested' => $this->requested,
            'sp_before' => $this->spBefore,
            'sp_after' => $this->spAfter,
            'actual_loss' => $this->actualLoss,
            'battle_cap' => $this->battleCap,
            'remaining_cap' => $this->remainingCap,
            'source_action_id' => $this->sourceActionId,
        ];
    }
}
