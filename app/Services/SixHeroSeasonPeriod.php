<?php

namespace App\Services;

use Carbon\CarbonImmutable;

final readonly class SixHeroSeasonPeriod
{
    public function __construct(
        public string $key,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}
}
