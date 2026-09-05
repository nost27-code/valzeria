<?php

namespace Tests\Unit\Services\Nation\Raid;

use NationRaidPhase4MariaDbSafety as Safety;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

require_once __DIR__.'/../../../../../scripts/verify/support/NationRaidPhase4MariaDbSafety.php';

final class NationRaidPhase4MariaDbSafetyTest extends TestCase
{
    private const CONNECTION = ['driver' => 'mysql', 'database' => 'valzeria_nation_raid_phase4_ci'];

    #[DataProvider('unsafeSettings')]
    public function test_rejects_unsafe_settings_before_connecting(string $environment, array $override, bool $confirmed, bool $cached): void
    {
        $this->expectException(\RuntimeException::class);
        Safety::settings($environment, array_replace(self::CONNECTION, $override), $confirmed, $cached);
    }

    public static function unsafeSettings(): array
    {
        return [
            ['production', [], true, false], ['local', [], true, false],
            ['testing', [], false, false], ['testing', [], true, true],
            ['testing', ['driver' => 'sqlite'], true, false],
            ['testing', ['database' => 'production'], true, false],
            ['testing', ['database' => 'valzeria_nation_raid_phase4_'], true, false],
            ['testing', ['database' => 'valzeria_nation_raid_phase4_ci; other'], true, false],
            ['testing', ['url' => 'mysql://example.invalid/production'], true, false],
            ['testing', ['read' => ['host' => 'example.invalid']], true, false],
            ['testing', ['write' => ['database' => 'production']], true, false],
            ['testing', ['prefix' => 'other_'], true, false],
        ];
    }

    #[DataProvider('unsafeServers')]
    public function test_rejects_wrong_product_version_database_or_engine(string $version, string $database, array $override): void
    {
        $this->expectException(\RuntimeException::class);
        Safety::server($version, $database, self::CONNECTION['database'],
            array_replace(array_fill_keys(Safety::tables(), 'InnoDB'), $override));
    }

    public static function unsafeServers(): array
    {
        $database = self::CONNECTION['database'];

        return [
            ['8.0.42-MySQL', $database, []], ['10.5.12-MariaDB', $database, []],
            ['unknown', $database, []], ['10.5.13-MariaDB', 'production', []],
            ['10.5.13-MariaDB', $database, ['nation_raid_events' => null]],
            ['10.5.13-MariaDB', $database, ['nation_raid_battle_results' => 'MyISAM']],
            ['10.5.13-MariaDB', $database, ['nation_raid_personal_rewards' => null]],
            ['10.5.13-MariaDB', $database, ['nation_raid_nation_rewards' => 'MyISAM']],
            ['10.5.13-MariaDB', $database, ['kiseki_transactions' => 'MyISAM']],
            ['10.5.13-MariaDB', $database, ['character_consumable_items' => 'MyISAM']],
            ['10.5.13-MariaDB', $database, ['character_materials' => null]],
            ['10.5.13-MariaDB', $database, ['character_notifications' => 'MyISAM']],
        ];
    }

    public function test_accepts_only_confirmed_uncached_isolated_innodb_mariadb(): void
    {
        Safety::settings('testing', self::CONNECTION, true, false);
        foreach (['10.5.13-MariaDB', '5.5.5-10.5.13-MariaDB', '10.11.8-MariaDB'] as $version) {
            Safety::server($version, self::CONNECTION['database'], self::CONNECTION['database'],
                array_fill_keys(Safety::tables(), 'InnoDB'));
        }
        $this->addToAssertionCount(4);
    }
}
