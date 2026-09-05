<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class NationRaidUnlimitedMigrationTest extends TestCase
{
    public function test_all_seven_counters_widen_without_reset_and_down_refuses_large_values(): void
    {
        $default = DB::getDefaultConnection();
        config(['database.connections.raid_unlimited_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('raid_unlimited_test');
        $tables = [
            'nation_raid_daily_usages' => ['used_count', 'resolved_count'],
            'nation_raid_participations' => ['resolved_sorties'],
            'nation_raid_battle_results' => ['day_sortie_no', 'event_sortie_no'],
            'nation_raid_battle_telemetry' => ['day_sortie_no', 'event_sortie_no'],
        ];
        try {
            foreach ($tables as $table => $columns) {
                Schema::create($table, function (Blueprint $blueprint) use ($columns): void {
                    $blueprint->id();
                    $blueprint->string('preserved')->unique();
                    foreach ($columns as $column) {
                        $blueprint->unsignedTinyInteger($column)->default(0);
                    }
                });
                DB::table($table)->insert(['preserved' => 'untouched', ...array_fill_keys($columns, 255)]);
            }
            $migration = require database_path('migrations/2026_09_05_120000_widen_nation_raid_sortie_counts.php');
            $migration->up();
            foreach ($tables as $table => $columns) {
                $row = DB::table($table)->sole();
                $this->assertSame('untouched', $row->preserved);
                foreach ($columns as $column) {
                    $this->assertSame(255, $row->$column);
                    DB::table($table)->where('id', 1)->increment($column);
                    $this->assertSame(256, DB::table($table)->value($column));
                }
                $this->assertTrue(collect(Schema::getIndexes($table))->contains(fn ($index) => $index['unique'] && $index['columns'] === ['preserved']));
            }
            try {
                $migration->down();
                $this->fail('Narrowing must refuse data loss.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('forward migration', $e->getMessage());
            }
            foreach ($tables as $table => $columns) {
                foreach ($columns as $column) {
                    $this->assertSame(256, DB::table($table)->value($column));
                }
            }
        } finally {
            DB::purge('raid_unlimited_test');
            DB::setDefaultConnection($default);
        }
    }
}
