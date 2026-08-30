<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PendingMigrationPreflightService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_character_title_unique_migration_preflight_refuses_existing_duplicates(): void
    {
        DB::table('migrations')
            ->where('migration', '2026_08_30_110000_add_character_title_unique_constraint')
            ->delete();
        Schema::table('character_titles', function (Blueprint $table): void {
            $table->dropUnique('character_titles_character_title_unique');
        });

        $user = User::factory()->create();
        $characterId = DB::table('characters')->insertGetId([
            'user_id' => $user->id,
            'name' => '称号重複監査用冒険者',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('character_titles')->insert([
            ['character_id' => $characterId, 'title_id' => 112],
            ['character_id' => $characterId, 'title_id' => 112],
        ]);

        $result = app(PendingMigrationPreflightService::class)->inspect();

        $this->assertSame(1, $result['characterTitleDuplicatePairs']);
        $this->assertTrue(collect($result['blockers'])->contains(
            static fn (string $blocker): bool => str_contains($blocker, 'プレイヤー所持データを自動削除せず移行を中止します')
        ));
        $this->artisan('valzeria:preflight-pending-migrations')
            ->expectsOutputToContain('称号付与の既存重複: 1組')
            ->assertFailed();
    }
}
