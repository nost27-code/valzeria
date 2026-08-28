<?php

namespace Tests\Feature;

use App\Services\PendingMigrationPreflightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PendingMigrationPreflightServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rank5_v6_migration_is_not_treated_as_a_rewrite_on_an_empty_install(): void
    {
        DB::table('migrations')
            ->where('migration', '2026_08_26_120000_redefine_rank5_job_arts_v6')
            ->delete();
        DB::table('skills')->delete();

        $result = app(PendingMigrationPreflightService::class)->inspect();

        $this->assertFalse($result['rank5V6MasterRewritePending']);
    }

    public function test_rank5_v6_master_rewrite_requires_the_maintenance_approval_option(): void
    {
        DB::table('migrations')
            ->where('migration', '2026_08_26_120000_redefine_rank5_job_arts_v6')
            ->delete();

        $result = app(PendingMigrationPreflightService::class)->inspect();
        $this->assertTrue($result['rank5V6MasterRewritePending']);

        $this->artisan('valzeria:preflight-pending-migrations')
            ->expectsOutputToContain('Rank5 v6.1の94件master更新はmaintenance_requiredで実行してください。')
            ->assertFailed();

        $this->artisan('valzeria:preflight-pending-migrations', [
            '--allow-rank5-v6-master-rewrite' => true,
        ])->assertSuccessful();
    }
}
