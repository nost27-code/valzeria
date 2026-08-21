<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Models\Character;
use App\Models\SixHeroBattleLog;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class SixHeroRankingInitializationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Tokyo']);
        Carbon::setTestNow(Carbon::parse('2026-09-01 00:10:00', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_command_initializes_current_rankings_and_reports_idempotent_rerun(): void
    {
        $previous = $this->season('2026-08', finalized: true);
        $character = $this->character();
        $this->ranking($previous, $character);

        $this->artisan('six-heroes:initialize-current-rankings')
            ->expectsOutput('六英雄戦 2026-09 ランキングを初期化しました。')
            ->expectsOutput('引継ぎ元: 2026-08')
            ->expectsOutput('引継ぎ: 1件')
            ->assertSuccessful();

        $target = SixHeroSeason::query()
            ->where('season_key', '2026-09')
            ->firstOrFail();
        $this->assertNotNull($target->ranking_initialized_at);
        $this->assertDatabaseHas('six_hero_rankings', [
            'season_id' => $target->id,
            'room_key' => SixHeroRoomKey::DIVINE_SPEED->value,
            'character_id' => $character->id,
            'rank' => 1,
        ]);

        $this->artisan('six-heroes:initialize-current-rankings')
            ->expectsOutput('2026-09 は既に初期化済みです。')
            ->assertSuccessful();
        $this->assertSame(1, SixHeroRanking::query()
            ->where('season_id', $target->id)
            ->count());
    }

    public function test_command_reports_pending_without_current_ranking_side_effects(): void
    {
        $previous = $this->season('2026-08');
        [$attacker, $defender] = [$this->character(), $this->character()];
        SixHeroBattleLog::query()->create([
            'season_id' => $previous->id,
            'room_key' => SixHeroRoomKey::DIVINE_SPEED,
            'battle_mode' => SixHeroBattleLog::MODE_OFFICIAL,
            'status' => SixHeroBattleLog::STATUS_STARTED,
            'attacker_id' => $attacker->id,
            'defender_id' => $defender->id,
            'attacker_rank_at_start' => 2,
            'defender_rank_at_start' => 1,
            'daily_attempt_number' => 1,
            'started_at' => '2026-08-31 23:59:59',
        ]);

        $this->artisan('six-heroes:initialize-current-rankings')
            ->expectsOutput('2026-08 に未完了公式戦があるため')
            ->expectsOutput('2026-09 のランキング初期化を保留しました。')
            ->assertSuccessful();

        $target = SixHeroSeason::query()
            ->where('season_key', '2026-09')
            ->firstOrFail();
        $this->assertNull($target->ranking_initialized_at);
        $this->assertDatabaseMissing('six_hero_rankings', [
            'season_id' => $target->id,
        ]);
        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 1);
    }

    public function test_command_and_scheduler_only_delegate_to_the_shared_initializer(): void
    {
        $commandSource = file_get_contents(app_path(
            'Console/Commands/InitializeSixHeroCurrentRankings.php',
        ));
        $scheduleSource = file_get_contents(base_path('routes/console.php'));

        $this->assertIsString($commandSource);
        $this->assertStringContainsString('$seasonService->currentSeason()', $commandSource);
        $this->assertStringContainsString('$initializationService->initialize(', $commandSource);
        $this->assertStringNotContainsString('SixHeroRanking::', $commandSource);
        $this->assertStringNotContainsString('finalizeSeason(', $commandSource);
        $this->assertStringNotContainsString('DB::', $commandSource);
        $this->assertIsString($scheduleSource);
        $this->assertStringContainsString(
            "Schedule::command('six-heroes:initialize-current-rankings')",
            $scheduleSource,
        );
        $this->assertStringContainsString('->everyTenMinutes()', $scheduleSource);
        $this->assertStringContainsString("->timezone(config('app.timezone'))", $scheduleSource);
        $this->assertStringContainsString('->withoutOverlapping(10)', $scheduleSource);
    }

    private function season(string $key, bool $finalized = false): SixHeroSeason
    {
        $startsAt = Carbon::parse("{$key}-01 00:00:00", 'Asia/Tokyo');
        $endsAt = $startsAt->copy()->addMonth();

        return SixHeroSeason::query()->create([
            'season_key' => $key,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'finalized_at' => $finalized ? $endsAt->copy()->addMinute() : null,
            'ranking_initialized_at' => null,
        ]);
    }

    private function character(): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => "六英雄初期化Command検証{$user->id}",
        ]);
    }

    private function ranking(SixHeroSeason $season, Character $character): SixHeroRanking
    {
        return SixHeroRanking::query()->create([
            'season_id' => $season->id,
            'room_key' => SixHeroRoomKey::DIVINE_SPEED,
            'character_id' => $character->id,
            'rank' => 1,
            'official_attack_wins' => 12,
            'official_attack_losses' => 3,
            'defense_wins' => 4,
            'defense_losses' => 5,
            'registered_at' => $season->starts_at,
            'first_place_since' => $season->starts_at,
        ]);
    }
}
