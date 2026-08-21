<?php

namespace Tests\Feature;

use App\Models\SixHeroSeason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class SixHeroSeasonCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Tokyo']);
        Carbon::setTestNow(Carbon::parse('2026-09-01 00:01:00', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_command_ensures_the_current_season_idempotently_without_finalizing_previous_month(): void
    {
        $previous = SixHeroSeason::query()->create([
            'season_key' => '2026-08',
            'starts_at' => '2026-08-01 00:00:00',
            'ends_at' => '2026-09-01 00:00:00',
            'finalized_at' => null,
        ]);

        for ($run = 0; $run < 2; $run++) {
            $this->artisan('six-heroes:ensure-current-season')
                ->expectsOutput('六英雄戦Season 2026-09 を確認しました。')
                ->expectsOutput('期間: 2026-09-01 00:00 ～ 2026-10-01 00:00')
                ->assertSuccessful();
        }

        $this->assertNull($previous->fresh()->finalized_at);
        $this->assertDatabaseCount('six_hero_seasons', 2);
        $this->assertDatabaseHas('six_hero_seasons', [
            'season_key' => '2026-09',
            'starts_at' => '2026-09-01 00:00:00',
            'ends_at' => '2026-10-01 00:00:00',
            'finalized_at' => null,
        ]);
        $this->assertDatabaseCount('six_hero_rankings', 0);
        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 0);
    }

    public function test_command_and_scheduler_delegate_only_to_the_shared_ensure_service(): void
    {
        $commandSource = file_get_contents(app_path(
            'Console/Commands/EnsureSixHeroCurrentSeason.php',
        ));
        $scheduleSource = file_get_contents(base_path('routes/console.php'));

        $this->assertIsString($commandSource);
        $this->assertStringContainsString('currentSeason()', $commandSource);
        $this->assertStringNotContainsString('insertOrIgnore(', $commandSource);
        $this->assertStringNotContainsString('finalizePrevious', $commandSource);
        $this->assertStringNotContainsString('finalizeEnded', $commandSource);
        $this->assertIsString($scheduleSource);
        $this->assertStringContainsString(
            "Schedule::command('six-heroes:ensure-current-season')",
            $scheduleSource,
        );
        $this->assertStringContainsString("->dailyAt('00:05')", $scheduleSource);
        $this->assertStringContainsString(
            "->timezone(config('app.timezone'))",
            $scheduleSource,
        );
        $this->assertStringContainsString('->withoutOverlapping(10)', $scheduleSource);
    }
}
