<?php

namespace App\Services;

use App\Models\SixHeroSeason;

final readonly class SixHeroRankingInitializationResult
{
    public function __construct(
        public SixHeroSeason $season,
        public bool $initialized,
        public bool $alreadyInitialized,
        public bool $waitingForPreviousFinalization,
        public ?SixHeroSeason $sourceSeason,
        public int $copiedRankingCount,
    ) {}
}
