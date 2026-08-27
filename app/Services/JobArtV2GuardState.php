<?php

namespace App\Services;

final readonly class JobArtV2GuardState
{
    public function __construct(
        public float $rate,
        public int $charges = 1,
        public bool $cleanseOnMitigation = false,
        public bool $expiresAtNextOwnAction = false,
    ) {}
}
