<?php

namespace App\Services;

use App\Enums\SixHeroRoomKey;
use App\Services\Battle\PvPBattleResolution;

final readonly class SixHeroPracticeBattleResult
{
    public function __construct(
        public PvPBattleResolution $resolution,
        public SixHeroRoomKey $room,
    ) {}
}
