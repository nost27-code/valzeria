<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Models\Character;
use App\Models\SixHeroBattleLog;
use App\Models\SixHeroSeason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class SixHeroSeasonFinalizationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Tokyo',
            'six_heroes.champion_recording_starts_from_season' => '2026-01',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-09-01 00:10:00', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_command_skips_pending_season_continues_others_and_retries_without_duplicates(): void
    {
        $june = $this->season(
            '2026-06',
            '2026-06-01 00:00:00',
            '2026-07-01 00:00:00',
        );
        $july = $this->season(
            '2026-07',
            '2026-07-01 00:00:00',
            '2026-08-01 00:00:00',
        );
        [$attacker, $defender] = $this->characters();
        $pending = SixHeroBattleLog::query()->create([
            'season_id' => $june->id,
            'room_key' => SixHeroRoomKey::SEAL_MAGIC,
            'battle_mode' => SixHeroBattleLog::MODE_OFFICIAL,
            'status' => SixHeroBattleLog::STATUS_STARTED,
            'attacker_id' => $attacker->id,
            'defender_id' => $defender->id,
            'attacker_rank_at_start' => 2,
            'defender_rank_at_start' => 1,
            'daily_attempt_number' => 1,
            'started_at' => '2026-06-30 23:59:59',
        ]);

        $this->artisan('six-heroes:finalize-ended-seasons')
            ->expectsOutput('2026-06: pending battle 1件のため保留しました。')
            ->expectsOutput('2026-07: 6部屋を確定しました（英雄0 / 空位6）。')
            ->assertSuccessful();

        $this->assertNull($june->fresh()->finalized_at);
        $julyFinalizedAt = $july->fresh()->finalized_at->copy();
        $this->assertDatabaseCount('six_hero_champions', 6);

        $pending->update([
            'status' => SixHeroBattleLog::STATUS_FAILED,
            'failed_at' => Carbon::now(),
            'failure_code' => SixHeroBattleLog::FAILURE_BATTLE_RUNTIME,
        ]);
        $this->artisan('six-heroes:finalize-ended-seasons')
            ->expectsOutput('2026-06: 6部屋を確定しました（英雄0 / 空位6）。')
            ->assertSuccessful();

        $this->assertNotNull($june->fresh()->finalized_at);
        $this->assertTrue($july->fresh()->finalized_at->equalTo($julyFinalizedAt));
        $this->assertDatabaseCount('six_hero_champions', 12);

        $this->artisan('six-heroes:finalize-ended-seasons')
            ->expectsOutput('確定対象の六英雄戦Seasonはありません。')
            ->assertSuccessful();
        $this->assertDatabaseCount('six_hero_champions', 12);
    }

    public function test_august_preseason_command_reports_no_hero_history_and_keeps_snapshots_empty(): void
    {
        config(['six_heroes.champion_recording_starts_from_season' => '2026-09']);
        $season = $this->season(
            '2026-08',
            '2026-08-01 00:00:00',
            '2026-09-01 00:00:00',
        );

        $this->artisan('six-heroes:finalize-ended-seasons')
            ->expectsOutput('2026-08: プレシーズン順位を確定しました（英雄記録なし）。')
            ->assertSuccessful();

        $this->assertNotNull($season->fresh()->finalized_at);
        $this->assertDatabaseCount('six_hero_champions', 0);
    }

    public function test_command_and_scheduler_delegate_to_the_shared_service_without_waiting(): void
    {
        $commandSource = file_get_contents(app_path(
            'Console/Commands/FinalizeEndedSixHeroSeasons.php',
        ));
        $scheduleSource = file_get_contents(base_path('routes/console.php'));

        $this->assertIsString($commandSource);
        $this->assertStringContainsString('finalizeEndedSeasons()', $commandSource);
        $this->assertStringNotContainsString('sleep(', $commandSource);
        $this->assertStringNotContainsString('SixHeroChampion::', $commandSource);
        $this->assertIsString($scheduleSource);
        $this->assertStringContainsString(
            "Schedule::command('six-heroes:finalize-ended-seasons')",
            $scheduleSource,
        );
        $this->assertStringContainsString('->everyTenMinutes()', $scheduleSource);
        $this->assertStringContainsString(
            "->timezone(config('app.timezone'))",
            $scheduleSource,
        );
        $this->assertStringContainsString('->withoutOverlapping(10)', $scheduleSource);
    }

    private function season(
        string $key,
        string $startsAt,
        string $endsAt,
    ): SixHeroSeason {
        return SixHeroSeason::query()->create([
            'season_key' => $key,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'finalized_at' => null,
        ]);
    }

    /** @return array{Character, Character} */
    private function characters(): array
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        return [
            Character::query()->create([
                'user_id' => $firstUser->id,
                'name' => "六英雄確定Command検証{$firstUser->id}",
            ]),
            Character::query()->create([
                'user_id' => $secondUser->id,
                'name' => "六英雄確定Command検証{$secondUser->id}",
            ]),
        ];
    }
}
