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
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class SixHeroOperationsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Tokyo',
            'features.six_hero_ui_enabled' => false,
            'six_heroes.operations.expected_database_product' => 'sqlite',
            'six_heroes.operations.minimum_database_version' => '3.0.0',
            'six_heroes.operations.stale_battle_minutes' => 30,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'Asia/Tokyo'));
        $this->season();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_health_check_exits_zero_for_pass_and_warning_only(): void
    {
        $this->artisan('six-heroes:health-check')
            ->expectsOutputToContain('[PASS] Database:')
            ->expectsOutputToContain('PASS 12 / WARNING 0 / FAIL 0')
            ->assertSuccessful();

        $this->failedBattle();

        $this->artisan('six-heroes:health-check')
            ->expectsOutputToContain('[WARNING] 失敗公式戦:')
            ->assertSuccessful();
    }

    public function test_health_check_exits_one_when_a_fail_exists(): void
    {
        $this->ranking(-1);

        $this->artisan('six-heroes:health-check')
            ->expectsOutputToContain('[FAIL] 6部屋Ranking整合性:')
            ->assertFailed();
    }

    public function test_health_json_is_valid_and_contains_no_connection_secrets(): void
    {
        $exitCode = Artisan::call('six-heroes:health-check', ['--json' => true]);
        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('pass', $payload['overall_status']);
        $this->assertCount(12, $payload['items']);
        $this->assertStringNotContainsString('DB_PASSWORD', $output);
        $this->assertStringNotContainsString('DB_HOST', $output);
        $this->assertStringNotContainsString('connection_string', $output);
        $database = collect($payload['items'])->firstWhere('key', 'database');
        $this->assertSame([
            'driver',
            'product',
            'version',
            'expected_product',
            'minimum_version',
            'detected_version',
        ], array_keys($database['metadata']));
    }

    public function test_health_json_exits_one_when_a_fail_exists(): void
    {
        $this->ranking(-1);

        $exitCode = Artisan::call('six-heroes:health-check', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $payload['overall_status']);
        $ranking = collect($payload['items'])->firstWhere('key', 'ranking_invariants');
        $this->assertSame('fail', $ranking['status']);
    }

    public function test_release_check_treats_flag_off_as_ready_and_uses_same_report(): void
    {
        $this->artisan('six-heroes:release-check')
            ->expectsOutputToContain('[PASS] 公開状態: Master switch: OFF')
            ->expectsOutput('READY — feature flagをONにする前提条件を満たしています。')
            ->assertSuccessful();

        $this->ranking(-1);

        $this->artisan('six-heroes:release-check')
            ->expectsOutput('NOT READY — FAIL項目を解消してから再実行してください。')
            ->assertFailed();
    }

    public function test_release_json_reports_ready_and_not_ready_with_matching_exit_codes(): void
    {
        $readyExitCode = Artisan::call('six-heroes:release-check', ['--json' => true]);
        $ready = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $readyExitCode);
        $this->assertSame('ready', $ready['release_status']);

        $this->ranking(-1);

        $notReadyExitCode = Artisan::call('six-heroes:release-check', ['--json' => true]);
        $notReady = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $notReadyExitCode);
        $this->assertSame('not_ready', $notReady['release_status']);
        $this->assertSame('fail', $notReady['overall_status']);
    }

    public function test_health_check_is_scheduled_hourly_with_overlap_protection(): void
    {
        $source = file_get_contents(base_path('routes/console.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString(
            "Schedule::command('six-heroes:health-check --quiet')",
            $source,
        );
        $this->assertStringContainsString('->hourly()', $source);
        $this->assertStringContainsString("->timezone(config('app.timezone'))", $source);
        $this->assertStringContainsString('->withoutOverlapping(10)', $source);
    }

    private function season(): SixHeroSeason
    {
        return SixHeroSeason::query()->create([
            'season_key' => '2026-09',
            'starts_at' => '2026-09-01 00:00:00',
            'ends_at' => '2026-10-01 00:00:00',
            'finalized_at' => null,
            'ranking_initialized_at' => '2026-09-01 00:00:00',
        ]);
    }

    private function character(): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => "Phase7Command{$user->id}",
        ]);
    }

    private function ranking(int $rank): SixHeroRanking
    {
        $season = SixHeroSeason::query()->where('season_key', '2026-09')->firstOrFail();

        return SixHeroRanking::query()->create([
            'season_id' => $season->id,
            'room_key' => SixHeroRoomKey::DIVINE_SPEED,
            'character_id' => $this->character()->id,
            'rank' => $rank,
            'official_attack_wins' => 0,
            'official_attack_losses' => 0,
            'defense_wins' => 0,
            'defense_losses' => 0,
            'registered_at' => $season->starts_at,
            'first_place_since' => null,
        ]);
    }

    private function failedBattle(): SixHeroBattleLog
    {
        $season = SixHeroSeason::query()->where('season_key', '2026-09')->firstOrFail();
        $attacker = $this->character();
        $defender = $this->character();

        return SixHeroBattleLog::query()->create([
            'season_id' => $season->id,
            'room_key' => SixHeroRoomKey::DIVINE_SPEED,
            'battle_mode' => SixHeroBattleLog::MODE_OFFICIAL,
            'status' => SixHeroBattleLog::STATUS_FAILED,
            'attacker_id' => $attacker->id,
            'defender_id' => $defender->id,
            'attacker_rank_at_start' => 2,
            'defender_rank_at_start' => 1,
            'daily_attempt_number' => 1,
            'started_at' => Carbon::now()->subMinute(),
            'failed_at' => Carbon::now(),
            'failure_code' => SixHeroBattleLog::FAILURE_BATTLE_RUNTIME,
        ]);
    }
}
