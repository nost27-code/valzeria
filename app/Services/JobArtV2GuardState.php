<?php

namespace App\Services;

final readonly class JobArtV2GuardState
{
    public function __construct(
        public float $rate,
        public int $charges = 1,
    ) {}
}
