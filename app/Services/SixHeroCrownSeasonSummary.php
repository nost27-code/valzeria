<?php

namespace App\Services;

use App\Enums\SixHeroRoomKey;

final readonly class SixHeroCrownSeasonSummary
{
    /**
     * @param  array<int, SixHeroRoomKey>  $rooms
     */
    public function __construct(
        public string $seasonKey,
        public int $crownCount,
        public array $rooms,
        public bool $isSixCrown,
    ) {}
}
