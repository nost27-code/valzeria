<?php

namespace Tests\Unit;

use App\Support\SixHeroCompetitionRules;
use PHPUnit\Framework\TestCase;

final class SixHeroCompetitionRulesTest extends TestCase
{
    public function test_competition_limits_keep_the_configured_values(): void
    {
        $this->assertSame(5, SixHeroCompetitionRules::DAILY_OFFICIAL_ATTEMPT_LIMIT);
        $this->assertSame(8, SixHeroCompetitionRules::MINIMUM_REGISTERED_COUNT);
        $this->assertSame(10, SixHeroCompetitionRules::MINIMUM_OFFICIAL_BATTLE_COUNT);
        $this->assertSame(3, SixHeroCompetitionRules::remainingOfficialAttempts(2));
        $this->assertSame(0, SixHeroCompetitionRules::remainingOfficialAttempts(8));
    }
}
