<?php

namespace App\Services;

use Illuminate\Support\Collection;

final readonly class SixHeroCharacterHeroSummary
{
    /**
     * @param  array<string, int>  $heroCountsByRoom
     * @param  array<string, int>  $longestStreaksByRoom
     * @param  array<string, int>  $currentStreaksByRoom
     * @param  Collection<int, SixHeroCrownSeasonSummary>  $crownSeasons
     */
    public function __construct(
        public int $heroCount,
        public int $conqueredRoomCount,
        public int $maxCrownsInSeason,
        public array $heroCountsByRoom,
        public array $longestStreaksByRoom,
        public array $currentStreaksByRoom,
        public Collection $crownSeasons,
        public ?string $latestHeroSeasonKey,
    ) {}
}
