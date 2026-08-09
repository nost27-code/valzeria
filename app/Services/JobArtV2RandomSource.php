<?php

namespace App\Services;

class JobArtV2RandomSource
{
    public function percentRoll(): int
    {
        return random_int(1, 100);
    }
}
