<?php

namespace App\Services;

/** 奥義予告に応答して得る、対象cycle専用の一回軽減。 */
final class JobArtV2UltimateGuardState
{
    public function __construct(
        public readonly string $targetActorKey,
        public readonly int $targetCycleId,
        public readonly float $rate = 0.35,
        public readonly string $effect = JobArtV2UltimateCounterplayCatalog::ULTIMATE_GUARD,
        public readonly int $responseSkillId = 0,
    ) {}

    public bool $rewardGranted = false;
}
