<?php

namespace App\Services;

use App\Services\Battle\ScopedBattleRandomizer;

class JobArtV2RandomSource
{
    public function percentRoll(): int
    {
        return ScopedBattleRandomizer::percentRoll();
    }
}
