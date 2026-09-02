<?php

namespace App\Services;

use App\Services\Battle\ScopedBattleRandomizer;

class JobArtV2HitRandomSource
{
    public function percentRoll(): int
    {
        return ScopedBattleRandomizer::percentRoll();
    }
}
