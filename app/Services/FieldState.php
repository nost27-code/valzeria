<?php

namespace App\Services;

final class FieldState
{
    public function __construct(
        public readonly string $key,
        public readonly string $ownerActorKey,
        public readonly int $remainingRounds,
        public readonly int $sourceSkillId,
        public readonly int $sourceActionId,
        public readonly int $createdRound,
        public readonly int $extends = 0,
        public readonly int $overwriteLockRemainingRounds = 0,
        public readonly ?string $overwriteLockOwnerActorKey = null,
        public readonly ?int $overwriteLockCreatedRound = null,
    ) {
    }

    public function isOverwriteLocked(): bool
    {
        return $this->overwriteLockRemainingRounds > 0;
    }
}
