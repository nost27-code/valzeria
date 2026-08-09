<?php

namespace App\Services;

final class FieldOverlayState
{
    public function __construct(
        public readonly string $key,
        public readonly string $ownerActorKey,
        public readonly int $remainingRounds,
        public readonly int $sourceSkillId,
        public readonly int $sourceActionId,
        public readonly int $createdRound,
    ) {
    }
}
