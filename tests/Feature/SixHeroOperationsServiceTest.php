<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Models\Character;
use App\Models\SixHeroBattleLog;
use App\Models\SixHeroChampion;
use App\Models\SixHeroDailyUsage;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Models\User;
use App\Services\SixHeroHealthCheckItem;
use App\Services\SixHeroOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SixHeroOperationsServiceTest extends TestCase
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
            'six_heroes.operations.failed_battle_window_hours' => 24,
            'six_heroes.operations.battle_list_limit' => 20,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_healthy_state_is_all_pass_and_the_check_is_database_read_only(): void
    {
        $this->season('2026-09', initialized: true);
        $before = $this->tableCounts();

        $report = app(SixHeroOperationsService::class)->healthReport();

        $this->assertSame(SixHeroHealthCheckItem::STATUS_PASS, $report->overallStatus());
        $this->assertCount(12, $report->items);
        $this->assertSame([
            'pass' => 12,
            'warning' => 0,
            'fail' => 0,
        ], $report->statusCounts());
        $this->assertSame('OFF', str_replace(
            'Master switch: ',
            '',
            $report->item('feature_flag')->message,
        ));
        $this->assertSame($before, $this->tableCounts());
    }

    public function test_database_product_outside_the_release_baseline_is_a_failure(): void
    {
        $this->season('2026-09', initialized: true);
        config(['six_heroes.operations.expected_database_product' => 'mysql']);

        $report = app(SixHeroOperationsService::class)->healthReport();

        $this->assertSame(
            SixHeroHealthCheckItem::STATUS_FAIL,
            $report->item('database')->status,
        );
        $this->assertSame('sqlite', $report->item('database')->metadata['product']);
        $this->assertSame('mysql', $report->item('database')->metadata['expected_product']);
        $this->assertTrue($report->hasFailures());
    }

    public function test_negative_rank_rank_gap_and_attempts_above_limit_are_failures(): void
    {
        $current = $this->season('2026-09', initialized: true);
        $this->ranking($current, $this->character(), SixHeroRoomKey::DIVINE_SPEED, 1);
        $this->ranking($current, $this->character(), SixHeroRoomKey::DIVINE_SPEED, 2);
        $this->ranking($current, $this->character(), SixHeroRoomKey::DIVINE_SPEED, 4);
        $this->ranking($current, $this->character(), SixHeroRoomKey::MIRACLE, -1);
        SixHeroDailyUsage::query()->create([
            'character_id' => $this->character()->id,
            'usage_date' => '2026-09-15',
            'official_attempts' => 6,
            'official_attempts_by_room' => [
                SixHeroRoomKey::DIVINE_SPEED->value => 6,
            ],
        ]);

        $report = app(SixHeroOperationsService::class)->healthReport();

        $this->assertSame(
            SixHeroHealthCheckItem::STATUS_FAIL,
            $report->item('ranking_invariants')->status,
        );
        $this->assertSame(
            SixHeroHealthCheckItem::STATUS_FAIL,
            $report->item('daily_usage')->status,
        );
        $this->assertSame(1, $report->item('daily_usage')->metadata['invalid_count']);
        $this->assertTrue($report->hasFailures());
    }

    public function test_daily_usage_allows_five_attempts_in_each_room_for_total_thirty(): void
    {
        $this->season('2026-09', initialized: true);
        $attemptsByRoom = collect(SixHeroRoomKey::cases())
            ->mapWithKeys(static fn (SixHeroRoomKey $room): array => [$room->value => 5])
            ->all();
        DB::table('six_hero_daily_usages')->insert([
            'character_id' => $this->character()->id,
            'usage_date' => Carbon::now()->toDateString(),
            'official_attempts' => 30,
            'official_attempts_by_room' => json_encode($attemptsByRoom, JSON_THROW_ON_ERROR),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $service = app(SixHeroOperationsService::class);
        $report = $service->healthReport(Carbon::now());
        $dashboard = $service->dashboardData(Carbon::now());

        $this->assertSame(
            SixHeroHealthCheckItem::STATUS_PASS,
            $report->item('daily_usage')->status,
        );
        $this->assertSame(5, $report->item('daily_usage')->metadata['maximum']);
        $this->assertSame(30, $report->item('daily_usage')->metadata['maximum_total']);
        $this->assertSame(30, $dashboard['daily_usage']['attempt_count']);
        $this->assertSame(6, $dashboard['daily_usage']['limit_reached_count']);
    }

    public function test_finalized_season_requires_exactly_six_champion_rows(): void
    {
        $this->season('2026-09', initialized: true);
        $finalized = $this->season('2026-07', finalized: true);
        foreach (array_slice(SixHeroRoomKey::cases(), 0, 5) as $room) {
            $this->vacancy($finalized, $room);
        }

        $operations = app(SixHeroOperationsService::class);
        $this->assertSame(
            SixHeroHealthCheckItem::STATUS_FAIL,
            $operations->healthReport()->item('champions')->status,
        );

        $this->vacancy($finalized, SixHeroRoomKey::MIRACLE);

        $this->assertSame(
            SixHeroHealthCheckItem::STATUS_PASS,
            $operations->healthReport()->item('champions')->status,
        );
    }

    public function test_non_vacant_champion_without_identity_snapshot_is_a_failure(): void
    {
        $this->season('2026-09', initialized: true);
        $finalized = $this->season('2026-07', finalized: true);
        $hero = $this->character();
        foreach (SixHeroRoomKey::cases() as $room) {
            if ($room === SixHeroRoomKey::DIVINE_SPEED) {
                SixHeroChampion::query()->create([
                    'season_id' => $finalized->id,
                    'room_key' => $room,
                    'character_id' => $hero->id,
                    'character_id_snapshot' => null,
                    'character_name_snapshot' => $hero->name,
                    'is_vacant' => false,
                    'vacancy_reason' => null,
                    'registered_count' => 8,
                    'official_battle_count' => 10,
                    'official_attack_wins' => 1,
                    'official_attack_losses' => 0,
                    'defense_wins' => 0,
                    'defense_losses' => 0,
                ]);

                continue;
            }

            $this->vacancy($finalized, $room);
        }

        $report = app(SixHeroOperationsService::class)->healthReport();

        $this->assertSame(
            SixHeroHealthCheckItem::STATUS_PASS,
            $report->item('champions')->status,
        );
        $this->assertSame(
            SixHeroHealthCheckItem::STATUS_FAIL,
            $report->item('historical_identity')->status,
        );
    }

    public function test_recent_pending_is_pass_and_stale_pending_is_warning_without_mutation(): void
    {
        $current = $this->season('2026-09', initialized: true);
        $log = $this->battleLog(
            $current,
            SixHeroBattleLog::STATUS_STARTED,
            Carbon::now()->subMinutes(5),
        );
        $operations = app(SixHeroOperationsService::class);

        $recent = $operations->healthReport();
        $this->assertSame(
            SixHeroHealthCheckItem::STATUS_PASS,
            $recent->item('pending_battles')->status,
        );

        $log->forceFill(['started_at' => Carbon::now()->subMinutes(31)])->save();
        $stale = $operations->healthReport();

        $this->assertSame(
            SixHeroHealthCheckItem::STATUS_WARNING,
            $stale->item('pending_battles')->status,
        );
        $this->assertSame(1, $stale->item('pending_battles')->metadata['stale_count']);
        $this->assertDatabaseHas('six_hero_battle_logs', [
            'id' => $log->id,
            'status' => SixHeroBattleLog::STATUS_STARTED,
            'failed_at' => null,
        ]);
    }

    public function test_stale_pending_that_blocks_ended_season_is_a_failure(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 00:40:00', 'Asia/Tokyo'));
        $this->season('2026-09', initialized: true);
        $previous = $this->season('2026-08');
        $this->battleLog(
            $previous,
            SixHeroBattleLog::STATUS_RESOLVED,
            Carbon::parse('2026-08-31 23:59:00', 'Asia/Tokyo'),
        );

        $report = app(SixHeroOperationsService::class)->healthReport();

        $this->assertSame(
            SixHeroHealthCheckItem::STATUS_FAIL,
            $report->item('pending_battles')->status,
        );
        $this->assertSame(
            1,
            $report->item('pending_battles')->metadata['ended_stale_blocking_count'],
        );
        $this->assertSame(
            SixHeroHealthCheckItem::STATUS_WARNING,
            $report->item('previous_season')->status,
        );
    }

    public function test_previous_unfinalized_without_pending_is_a_failure(): void
    {
        $this->season('2026-09', initialized: true);
        $this->season('2026-08');

        $report = app(SixHeroOperationsService::class)->healthReport();

        $this->assertSame(
            SixHeroHealthCheckItem::STATUS_FAIL,
            $report->item('previous_season')->status,
        );
    }

    public function test_month_boundary_not_ready_with_recent_previous_pending_is_warning_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 00:10:00', 'Asia/Tokyo'));
        $this->season('2026-09');
        $previous = $this->season('2026-08');
        $this->battleLog(
            $previous,
            SixHeroBattleLog::STATUS_STARTED,
            Carbon::parse('2026-08-31 23:59:59', 'Asia/Tokyo'),
        );

        $report = app(SixHeroOperationsService::class)->healthReport();

        $this->assertFalse($report->hasFailures());
        $this->assertSame(
            SixHeroHealthCheckItem::STATUS_WARNING,
            $report->item('ranking_initialization')->status,
        );
        $this->assertSame(
            SixHeroHealthCheckItem::STATUS_WARNING,
            $report->item('ranking_invariants')->status,
        );
        $this->assertSame(
            SixHeroHealthCheckItem::STATUS_WARNING,
            $report->item('previous_season')->status,
        );
        $this->assertSame(
            SixHeroHealthCheckItem::STATUS_PASS,
            $report->item('pending_battles')->status,
        );
    }

    public function test_recent_failed_battle_is_warning_and_is_bounded_in_dashboard(): void
    {
        $current = $this->season('2026-09', initialized: true);
        foreach (range(1, 25) as $index) {
            $this->battleLog(
                $current,
                SixHeroBattleLog::STATUS_FAILED,
                Carbon::now()->subMinutes($index),
            );
        }

        $operations = app(SixHeroOperationsService::class);
        $report = $operations->healthReport();
        $dashboard = $operations->dashboardData();

        $this->assertSame(
            SixHeroHealthCheckItem::STATUS_WARNING,
            $report->item('failed_battles')->status,
        );
        $this->assertSame(25, $report->item('failed_battles')->metadata['failed_count']);
        $this->assertCount(20, $dashboard['failed_battles']);
        $this->assertSame(20, $dashboard['battle_list_limit']);
        $this->assertSame(5, $dashboard['daily_usage']['limit']);
    }

    private function season(
        string $key,
        bool $initialized = false,
        bool $finalized = false,
    ): SixHeroSeason {
        $startsAt = Carbon::parse("{$key}-01 00:00:00", 'Asia/Tokyo');
        $endsAt = $startsAt->copy()->addMonth();

        return SixHeroSeason::query()->create([
            'season_key' => $key,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'finalized_at' => $finalized ? $endsAt->copy()->addMinute() : null,
            'ranking_initialized_at' => $initialized ? $startsAt : null,
        ]);
    }

    private function character(): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => "Phase7診断{$user->id}",
        ]);
    }

    private function ranking(
        SixHeroSeason $season,
        Character $character,
        SixHeroRoomKey $room,
        int $rank,
    ): SixHeroRanking {
        return SixHeroRanking::query()->create([
            'season_id' => $season->id,
            'room_key' => $room,
            'character_id' => $character->id,
            'rank' => $rank,
            'official_attack_wins' => 0,
            'official_attack_losses' => 0,
            'defense_wins' => 0,
            'defense_losses' => 0,
            'registered_at' => $season->starts_at,
            'first_place_since' => $rank === 1 ? $season->starts_at : null,
        ]);
    }

    private function vacancy(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
    ): SixHeroChampion {
        return SixHeroChampion::query()->create([
            'season_id' => $season->id,
            'room_key' => $room,
            'character_id' => null,
            'character_id_snapshot' => null,
            'character_name_snapshot' => null,
            'is_vacant' => true,
            'vacancy_reason' => SixHeroChampion::VACANCY_INSUFFICIENT_PARTICIPANTS,
            'registered_count' => 0,
            'official_battle_count' => 0,
            'official_attack_wins' => null,
            'official_attack_losses' => null,
            'defense_wins' => null,
            'defense_losses' => null,
        ]);
    }

    private function battleLog(
        SixHeroSeason $season,
        string $status,
        Carbon $startedAt,
    ): SixHeroBattleLog {
        $attacker = $this->character();
        $defender = $this->character();
        $isFailed = $status === SixHeroBattleLog::STATUS_FAILED;

        return SixHeroBattleLog::query()->create([
            'season_id' => $season->id,
            'room_key' => SixHeroRoomKey::DIVINE_SPEED,
            'battle_mode' => SixHeroBattleLog::MODE_OFFICIAL,
            'status' => $status,
            'attacker_id' => $attacker->id,
            'defender_id' => $defender->id,
            'attacker_rank_at_start' => 2,
            'defender_rank_at_start' => 1,
            'daily_attempt_number' => 1,
            'started_at' => $startedAt,
            'resolved_at' => $status === SixHeroBattleLog::STATUS_RESOLVED
                ? $startedAt->copy()->addSecond()
                : null,
            'failed_at' => $isFailed ? $startedAt->copy()->addSecond() : null,
            'failure_code' => $isFailed
                ? SixHeroBattleLog::FAILURE_BATTLE_RUNTIME
                : null,
        ]);
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        return [
            'seasons' => SixHeroSeason::query()->count(),
            'rankings' => SixHeroRanking::query()->count(),
            'daily_usages' => SixHeroDailyUsage::query()->count(),
            'battle_logs' => SixHeroBattleLog::query()->count(),
            'champions' => SixHeroChampion::query()->count(),
        ];
    }
}
