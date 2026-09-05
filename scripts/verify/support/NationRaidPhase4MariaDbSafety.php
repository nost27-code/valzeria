<?php

declare(strict_types=1);

/** Verification-only guard. No application route or database mutation belongs here. */
final class NationRaidPhase4MariaDbSafety
{
    public static function settings(string $environment, array $connection, bool $confirmed, bool $cached): void
    {
        self::require($confirmed, 'Explicit isolated-database confirmation is required.');
        self::require($environment === 'testing', 'APP_ENV must be testing.');
        self::require(! $cached, 'Cached application configuration is not allowed.');
        self::require(in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true), 'MariaDB driver required.');
        self::require(preg_match('/\Avalzeria_nation_raid_phase4_[a-z0-9_]+\z/', (string) ($connection['database'] ?? '')) === 1,
            'Only a disposable Phase 4 database is allowed.');
        self::require(empty($connection['read']) && empty($connection['write']) && empty($connection['url'])
            && empty($connection['prefix']), 'Connection redirects, read/write splitting and table prefixes are not allowed.');
    }

    public static function server(string $version, string $database, string $expectedDatabase, array $engines): void
    {
        self::require($database === $expectedDatabase, 'Connected database differs from the approved test database.');
        self::require(preg_match('/^(?:5\.5\.5-)?(\d+\.\d+\.\d+).*MariaDB/i', $version, $matches) === 1
            && version_compare($matches[1], '10.5.13', '>='), 'MariaDB 10.5.13 or newer is required.');
        foreach (self::tables() as $table) {
            self::require(strcasecmp((string) ($engines[$table] ?? ''), 'InnoDB') === 0,
                'Missing/non-InnoDB table: '.$table);
        }
    }

    public static function tables(): array
    {
        return ['users', 'characters', 'nations', 'nation_memberships', 'competition_event_coordinators',
            'nation_raid_events', 'nation_raid_boss_cycles', 'nation_raid_participations', 'nation_raid_daily_usages',
            'nation_raid_battle_results', 'nation_raid_daily_lineage_snapshots', 'nation_raid_coordination_participants',
            'nation_raid_personal_rewards', 'nation_raid_nation_rewards', 'nation_resource_transactions',
            'nation_activity_logs', 'nation_achievements', 'character_notifications', 'character_consumable_items',
            'character_materials', 'materials', 'kiseki_transactions', 'titles', 'character_titles'];
    }

    public static function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
