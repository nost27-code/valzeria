<?php

namespace App\Services;

use App\Models\SixHeroBattleLog;
use App\Services\Battle\PvPBattleResolution;

final readonly class SixHeroOfficialBattleResult
{
    public function __construct(
        public PvPBattleResolution $resolution,
        public ?SixHeroRankChangeResult $rankChange,
        public SixHeroBattleLog $battleLog,
        public int $officialAttemptsUsed,
        public int $officialAttemptsRemaining,
    ) {}
}
