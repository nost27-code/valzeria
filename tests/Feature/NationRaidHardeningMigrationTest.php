<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class NationRaidHardeningMigrationTest extends TestCase
{
    public function test_forward_migration_preserves_refund_counts_and_adds_empty_projections(): void
    {
        // Dedicated in-memory connection, never the configured/local player database.
        $default = DB::getDefaultConnection();
        config(['database.connections.raid_migration_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('raid_migration_test');
        try {
            Schema::create('nation_raid_daily_usages', function (Blueprint $table): void {
                $table->id();
                $table->unsignedTinyInteger('refunded_count')->default(0);
            });
            Schema::create('nation_raid_participations', fn (Blueprint $table) => $table->id());
            DB::table('nation_raid_daily_usages')->insert([['refunded_count' => 0], ['refunded_count' => 255]]);
            DB::table('nation_raid_participations')->insert(['id' => 1]);
            $migration = require database_path('migrations/2026_09_04_235000_harden_nation_raid_history_and_refund_counts.php');
            $migration->up();
            $this->assertSame([0, 255], DB::table('nation_raid_daily_usages')->orderBy('id')->pluck('refunded_count')->all());
            $this->assertTrue(Schema::hasColumns('nation_raid_participations', ['final_result_snapshot', 'final_result_hash']));
            $this->assertNull(DB::table('nation_raid_participations')->value('final_result_snapshot'));
            DB::table('nation_raid_daily_usages')->where('id', 2)->increment('refunded_count');
            try {
                $migration->down();
                $this->fail('Rollback must preserve counts above 255.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('forward migration', $exception->getMessage());
            }
            $this->assertSame(256, DB::table('nation_raid_daily_usages')->where('id', 2)->value('refunded_count'));
        } finally {
            DB::purge('raid_migration_test');
            DB::setDefaultConnection($default);
        }
    }
}
