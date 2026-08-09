<?php

namespace App\Services;

class JobArtV2ParryRandomSource
{
    public function percentRoll(): int
    {
        return random_int(1, 100);
    }
}
