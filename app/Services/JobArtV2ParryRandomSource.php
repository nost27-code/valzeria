<?php

namespace App\Services;

use App\Services\Battle\ScopedBattleRandomizer;

class JobArtV2ParryRandomSource
{
    public function percentRoll(): int
    {
        return ScopedBattleRandomizer::percentRoll();
    }
}
