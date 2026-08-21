<?php

namespace App\Services;

use App\Models\SixHeroChampion;
use App\Models\SixHeroSeason;
use Illuminate\Support\Collection;

final readonly class SixHeroSeasonFinalizationResult
{
    /**
     * @param  Collection<int, SixHeroChampion>  $champions
     */
    public function __construct(
        public SixHeroSeason $season,
        public bool $finalized,
        public bool $alreadyFinalized,
        public bool $pendingBattles,
        public int $pendingBattleCount,
        public Collection $champions,
    ) {}
}
