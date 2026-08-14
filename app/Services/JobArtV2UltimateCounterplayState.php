<?php

namespace App\Services;

/** 対奥義prototypeのアクター別battle-memory-only状態。 */
final class JobArtV2UltimateCounterplayState
{
    public bool $mainRankFiveEstablished = false;
    /** 互換名mainRankFiveEstablishedが成立した、装備中奥義と同じ系譜。 */
    public ?string $establishedUltimateLineage = null;
    public int $nextCycleId = 1;
    public ?JobArtV2UltimatePreparationState $preparation = null;
    public ?JobArtV2UltimateGuardState $ultimateGuard = null;
    /** @var array<int, JobArtV2UltimateGuardState> source action id => consumed guard */
    public array $consumedUltimateGuards = [];
    public ?JobArtV2UltimateCycleEffectState $eclipseBacklash = null;
    public ?JobArtV2UltimateCycleEffectState $lineageSuppression = null;
    public ?JobArtV2UltimateCycleEffectState $pendingResourceSlow = null;
    public int $resourceGainPenaltyCharges = 0;
    public bool $huntCancelResistance = false;
}
