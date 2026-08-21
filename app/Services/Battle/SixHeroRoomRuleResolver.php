<?php

namespace App\Services\Battle;

use App\Enums\SixHeroRoomKey;
use App\Services\Battle\RoomRules\BurningLifePvPRoomRule;
use App\Services\Battle\RoomRules\DivineSpeedPvPRoomRule;
use App\Services\Battle\RoomRules\MiraclePvPRoomRule;
use App\Services\Battle\RoomRules\ReverseTimePvPRoomRule;
use App\Services\Battle\RoomRules\SealBladePvPRoomRule;
use App\Services\Battle\RoomRules\SealMagicPvPRoomRule;

final class SixHeroRoomRuleResolver
{
    public function resolve(SixHeroRoomKey $room): PvPRoomRuleInterface
    {
        return match ($room) {
            SixHeroRoomKey::SEAL_MAGIC => new SealMagicPvPRoomRule,
            SixHeroRoomKey::SEAL_BLADE => new SealBladePvPRoomRule,
            SixHeroRoomKey::BURNING_LIFE => new BurningLifePvPRoomRule,
            SixHeroRoomKey::DIVINE_SPEED => new DivineSpeedPvPRoomRule,
            SixHeroRoomKey::REVERSE_TIME => new ReverseTimePvPRoomRule,
            SixHeroRoomKey::MIRACLE => new MiraclePvPRoomRule,
        };
    }
}
