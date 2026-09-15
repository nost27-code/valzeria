<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;

final class SixHeroCompetitionRules
{
    /** 六英雄戦では通常攻撃を表示威力100%の基準行動として扱う。 */
    public const NORMAL_ATTACK_POWER = 100;

    /** 六英雄戦の基準ダメージを従来式の50%へ抑える。 */
    public const BASE_DAMAGE_MULTIPLIER = 0.5;

    /** Maximum official battles in one Room per app-timezone day. */
    public const DAILY_OFFICIAL_ATTEMPT_LIMIT = 5;

    public const MINIMUM_REGISTERED_COUNT = 8;

    public const MINIMUM_OFFICIAL_BATTLE_COUNT = 10;

    public const LEGACY_ARENA_STOPS_AT = '2026-09-01 00:00:00';

    /** 通常闘技場を六英雄戦と再び併設する日時。 */
    public const LEGACY_ARENA_REOPENS_AT = '2026-10-01 00:00:00';

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
        $current = self::atInAppTimezone($at);

        return $current->lessThan(self::legacyArenaStopsAt())
            || $current->greaterThanOrEqualTo(self::legacyArenaReopensAt());
    }

    public static function legacyArenaNpcAutoBattlesAvailable(?CarbonInterface $at = null): bool
    {
        // 六英雄戦との併設再開後も、受動的な順位低下を起こすNPC自動戦は休止を維持する。
        return self::atInAppTimezone($at)->lessThan(self::legacyArenaStopsAt());
    }

    public static function legacyArenaReopensAt(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::LEGACY_ARENA_REOPENS_AT, (string) config('app.timezone'));
    }

    private static function legacyArenaStopsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::LEGACY_ARENA_STOPS_AT, (string) config('app.timezone'));
    }

    private static function atInAppTimezone(?CarbonInterface $at): CarbonImmutable
    {
        $timezone = (string) config('app.timezone');

        return $at === null
            ? CarbonImmutable::now($timezone)
            : CarbonImmutable::instance($at)->setTimezone($timezone);
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
