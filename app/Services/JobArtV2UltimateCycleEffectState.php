<?php

namespace App\Services;

/** A counterplay effect that follows one announced-ultimate cycle. */
final class JobArtV2UltimateCycleEffectState
{
    public function __construct(
        public readonly string $sourceActorKey,
        public readonly int $targetCycleId,
        public readonly string $effect,
        public readonly float $rate = 0.0,
    ) {}
}
