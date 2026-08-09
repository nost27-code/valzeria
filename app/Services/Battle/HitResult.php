<?php

namespace App\Services\Battle;

enum HitResult: string
{
    case HIT = 'HIT';
    case MISS = 'MISS';
    case EVADE = 'EVADE';

    public function landed(): bool
    {
        return $this === self::HIT;
    }
}
