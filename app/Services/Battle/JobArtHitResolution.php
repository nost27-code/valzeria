<?php

namespace App\Services\Battle;

final readonly class JobArtHitResolution
{
    public function __construct(
        public HitResult $hitResult,
        public ?float $rawHitChance,
        public ?float $effectiveHitChance,
        public float $accuracyOverflow,
        public float $vitalHitChance,
        public bool $vitalHit,
        public bool $sureHit,
    ) {}
}
