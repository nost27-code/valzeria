<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;

final class SixHeroCompetitionRules
{
    /** Maximum official battles in one Room per app-timezone day. */
    public const DAILY_OFFICIAL_ATTEMPT_LIMIT = 5;

    public const MINIMUM_REGISTERED_COUNT = 8;

    public const MINIMUM_OFFICIAL_BATTLE_COUNT = 10;

    public const LEGACY_ARENA_STOPS_AT = '2026-09-01 00:00:00';

    public static function championRecordingStartsFromSeasonKey(): string
    {
        $seasonKey = (string) config(
            'six_heroes.champion_recording_starts_from_season',
            '2026-09',
        );
        self::assertSeasonKey($seasonKey);

        return $seasonKey;
    }

    public static function recordsChampionHistory(string $seasonKey): bool
    {
        self::assertSeasonKey($seasonKey);

        return strcmp($seasonKey, self::championRecordingStartsFromSeasonKey()) >= 0;
    }

    public static function legacyArenaAvailable(?CarbonInterface $at = null): bool
    {
        $timezone = (string) config('app.timezone');
        $current = $at === null
            ? CarbonImmutable::now($timezone)
            : CarbonImmutable::instance($at)->setTimezone($timezone);
        $stopsAt = CarbonImmutable::parse(self::LEGACY_ARENA_STOPS_AT, $timezone);

        return $current->lessThan($stopsAt);
    }

    public static function remainingOfficialAttempts(int $used): int
    {
        return max(0, self::DAILY_OFFICIAL_ATTEMPT_LIMIT - max(0, $used));
    }

    private static function assertSeasonKey(string $seasonKey): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $seasonKey) !== 1) {
            throw new LogicException("Invalid Six Heroes season key: {$seasonKey}.");
        }
    }
}
