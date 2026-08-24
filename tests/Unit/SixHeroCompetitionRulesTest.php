<?php

namespace Tests\Unit;

use App\Support\SixHeroCompetitionRules;
use Carbon\CarbonImmutable;
use LogicException;
use Tests\TestCase;

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

    public function test_august_is_playable_preseason_and_hero_recording_begins_in_september(): void
    {
        config([
            'app.timezone' => 'Asia/Tokyo',
            'six_heroes.champion_recording_starts_from_season' => '2026-09',
        ]);

        $this->assertFalse(SixHeroCompetitionRules::recordsChampionHistory('2026-08'));
        $this->assertTrue(SixHeroCompetitionRules::recordsChampionHistory('2026-09'));
        $this->assertTrue(SixHeroCompetitionRules::legacyArenaAvailable(
            CarbonImmutable::parse('2026-08-31 23:59:59', 'Asia/Tokyo'),
        ));
        $this->assertFalse(SixHeroCompetitionRules::legacyArenaAvailable(
            CarbonImmutable::parse('2026-09-01 00:00:00', 'Asia/Tokyo'),
        ));
    }

    public function test_invalid_recording_start_season_is_rejected(): void
    {
        config(['six_heroes.champion_recording_starts_from_season' => 'September']);

        $this->expectException(LogicException::class);
        SixHeroCompetitionRules::recordsChampionHistory('2026-09');
    }
}
