<?php

namespace App\Support;

final class SixHeroCompetitionRules
{
    /** Maximum official battles in one Room per app-timezone day. */
    public const DAILY_OFFICIAL_ATTEMPT_LIMIT = 5;

    public const MINIMUM_REGISTERED_COUNT = 8;

    public const MINIMUM_OFFICIAL_BATTLE_COUNT = 10;

    public static function remainingOfficialAttempts(int $used): int
    {
        return max(0, self::DAILY_OFFICIAL_ATTEMPT_LIMIT - max(0, $used));
    }
}
