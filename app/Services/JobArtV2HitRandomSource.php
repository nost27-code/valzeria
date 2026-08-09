<?php

namespace App\Services;

class JobArtV2HitRandomSource
{
    public function percentRoll(): int
    {
        return random_int(1, 100);
    }
}
