<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Livewire\AdventurerCardModal;
use App\Livewire\StarTreeTowerRankingWidget;
use App\Models\Character;
use App\Models\KisekiTransaction;
use App\Models\User;
use App\Models\WeeklyWinRankingRecord;
use App\Models\WeeklyWinRankingSeason;
use App\Services\CharacterNotificationService;
use App\Services\TownRankingService;
use App\Services\WeeklyWinRankingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class WeeklyWinRankingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 7, 27, 9, 10, 0, 'Asia/Tokyo'));
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_reward_table_matches_the_approved_non_additive_tiers(): void
    {
        $service = app(WeeklyWinRankingService::class);

        foreach ([
            [1, 1, 20, '今週の武勇一番'],
            [2, 1, 15, '今週の武勇三傑'],
            [3, 1, 10, '今週の武勇三傑'],
            [4, 1, 8, '今週の武勇十傑'],
            [10, 1, 8, '今週の武勇十傑'],
            [11, 1, 5, null],
            [20, 1, 5, null],
            [21, 1, 3, null],
            [30, 1, 3, null],
            [31, 1, 2, null],
            [50, 1, 2, null],
            [51, 10, 1, null],
            [51, 9, 0, null],
        ] as [$rank, $wins, $amount, $badge]) {
            $reward = $service->rewardFor($rank, $wins);
            $this->assertSame($amount, $reward['free_kiseki'], "rank={$rank}, wins={$wins}");
            $this->assertSame($badge, $reward['badge_label'], "rank={$rank}, wins={$wins}");
        }
    }

    public function test_week_runs_from_monday_nine_until_the_next_monday_before_nine(): void
    {
        $service = app(WeeklyWinRankingService::class);

        $beforeBoundary = $service->currentPeriod(
            Carbon::create(2026, 7, 27, 8, 59, 59, 'Asia/Tokyo')
        );
        $this->assertSame('2026-07-20', $beforeBoundary['key']);
        $this->assertSame('2026-07-20 09:00:00', $beforeBoundary['start_at']);
        $this->assertSame('2026-07-27 09:00:00', $beforeBoundary['end_at']);
        $this->assertSame('2026年7月20日 9:00〜7月27日 8:59', $beforeBoundary['label']);

        $atBoundary = $service->currentPeriod(
            Carbon::create(2026, 7, 27, 9, 0, 0, 'Asia/Tokyo')
        );
        $this->assertSame('2026-07-27', $atBoundary['key']);
        $this->assertSame('2026-07-27 09:00:00', $atBoundary['start_at']);
        $this->assertSame('2026-08-03 09:00:00', $atBoundary['end_at']);
        $this->assertSame('2026年7月27日 9:00〜8月3日 8:59', $atBoundary['label']);

        $manualPeriod = $service->periodForWeekStart('2026-07-27');
        $this->assertSame('2026-07-27 09:00:00', $manualPeriod['start_at']);
        $this->assertSame('2026-08-03 09:00:00', $manualPeriod['end_at']);
    }

    public function test_town_board_cache_key_changes_exactly_at_monday_nine(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 27, 8, 59, 59, 'Asia/Tokyo'));
        $beforeBoundary = TownRankingService::cacheKey();

        Carbon::setTestNow(Carbon::create(2026, 7, 27, 9, 0, 0, 'Asia/Tokyo'));
        $atBoundary = TownRankingService::cacheKey();

        $this->assertSame('town_ranking_boards_v9:2026-07-20', $beforeBoundary);
        $this->assertSame('town_ranking_boards_v9:2026-07-27', $atBoundary);
        $this->assertNotSame($beforeBoundary, $atBoundary);
    }

    public function test_prelaunch_week_is_hidden_and_skipped_without_writes(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 27, 8, 59, 59, 'Asia/Tokyo'));
        [$areaId, $enemyId] = $this->battleMasterIds();
        $user = User::factory()->create();
        $character = $this->createCharacter($user, '旧週首位', 2, 3);
        $this->addWins(
            $character,
            10,
            Carbon::create(2026, 7, 20, 12, 0, 0, 'Asia/Tokyo'),
            $areaId,
            $enemyId
        );

        $service = app(WeeklyWinRankingService::class);

        $this->assertFalse($service->availability()['is_started']);
        $this->assertSame('2026-07-27', $service->currentPeriodSummary()['key']);
        $this->assertTrue($service->currentRows()->isEmpty());
        $this->assertNull($service->currentStatusFor($character));

        $result = $service->finalizePeriod(
            $service->periodForWeekStart('2026-07-20')
        );
        $this->assertTrue($result['skipped']);
        $this->assertSame('2026-07-20', $result['season_key']);
        $this->assertSame(0, $result['participant_count']);
        $this->assertSame(0, $result['rewarded_count']);
        $this->assertSame(0, $result['total_free_kiseki']);

        $this->artisan('ranking:finalize-weekly-wins', [
            '--week-start' => '2026-07-20',
            '--dry-run' => true,
        ])
            ->expectsOutput('2026-07-20週は報酬開始前のため対象外です。初回対象は2026-07-27週です。')
            ->expectsOutput('参加者: 0人')
            ->expectsOutput('報酬対象: 0人')
            ->expectsOutput('無償輝石合計: 0個')
            ->assertSuccessful();

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id]);

        Livewire::test(StarTreeTowerRankingWidget::class)
            ->assertSee('週間番付は7月27日 9:00から始まります')
            ->assertSee('それ以前の勝利は集計・報酬の対象外です')
            ->assertDontSee('旧週首位')
            ->assertDontSee('あなたの進捗');

        $this->withoutMiddleware(CheckCharacterSelected::class)
            ->get(route('ranking.index', ['board' => 'weekly_wins']))
            ->assertOk()
            ->assertSee('第1回集計期間')
            ->assertSee('週間番付は7月27日 9:00から始まります')
            ->assertSee('次シーズンの開始をお待ちください')
            ->assertDontSee('あなたの今週');

        Carbon::setTestNow(Carbon::create(2026, 7, 27, 9, 10, 0, 'Asia/Tokyo'));
        $scheduledResult = $service->finalizePreviousWeek();
        $this->assertTrue($scheduledResult['skipped']);
        $this->assertSame('2026-07-20', $scheduledResult['season_key']);
        $pendingResult = $service->finalizePendingPeriods();
        $this->assertSame(0, $pendingResult['processed_count']);

        $this->artisan('ranking:finalize-weekly-wins')
            ->expectsOutput('未確定の終了済み週はありません。')
            ->assertSuccessful();

        $character->refresh();
        $this->assertSame(2, $character->free_kiseki);
        $this->assertSame(3, $character->paid_kiseki);
        $this->assertSame(5, $character->kiseki);
        $this->assertDatabaseCount('weekly_win_ranking_seasons', 0);
        $this->assertDatabaseCount('weekly_win_ranking_records', 0);
        $this->assertDatabaseMissing('kiseki_transactions', [
            'transaction_type' => WeeklyWinRankingService::TRANSACTION_TYPE,
        ]);
        $this->assertDatabaseMissing('character_notifications', [
            'type' => WeeklyWinRankingService::NOTIFICATION_TYPE,
        ]);

        Carbon::setTestNow(Carbon::create(2026, 7, 27, 9, 0, 0, 'Asia/Tokyo'));
        Cache::flush();
        $this->addWins($character, 1, now(), $areaId, $enemyId);

        $this->assertTrue($service->availability()['is_started']);
        $this->assertSame(1, $service->currentRows()->first()['score']);
    }

    public function test_live_weekly_rows_use_a_short_period_scoped_cache(): void
    {
        config()->set('weekly_win_ranking.live_cache_seconds', 30);
        [$areaId, $enemyId] = $this->battleMasterIds();
        $character = $this->createCharacter(User::factory()->create(), '短時間キャッシュ');
        $at = Carbon::create(2026, 7, 27, 9, 10, 0, 'Asia/Tokyo');
        $this->addWins($character, 1, $at, $areaId, $enemyId);

        $service = app(WeeklyWinRankingService::class);
        $this->assertSame(1, $service->currentRows()->first()['score']);
        $cacheKey = 'weekly_win_ranking_live_rows_v2:2026-07-27';
        $this->assertIsArray(Cache::get($cacheKey));

        Cache::put($cacheKey, collect([['score' => 999]]), now()->addMinute());
        $this->assertSame(1, $service->currentRows()->first()['score']);
        $this->assertIsArray(Cache::get($cacheKey));

        $this->addWins($character, 1, $at->copy()->addSecond(), $areaId, $enemyId);
        $this->assertSame(1, $service->currentRows()->first()['score']);

        Carbon::setTestNow($at->copy()->addSeconds(31));
        $this->assertSame(2, $service->currentRows()->first()['score']);
    }

    public function test_scheduled_finalization_recovers_all_missing_periods_in_oldest_first_order(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 17, 9, 10, 0, 'Asia/Tokyo'));
        [$areaId, $enemyId] = $this->battleMasterIds();
        $character = $this->createCharacter(User::factory()->create(), '未確定週回収');

        $this->addWins(
            $character,
            1,
            Carbon::create(2026, 7, 27, 12, 0, 0, 'Asia/Tokyo'),
            $areaId,
            $enemyId
        );
        $this->addWins(
            $character,
            2,
            Carbon::create(2026, 8, 10, 12, 0, 0, 'Asia/Tokyo'),
            $areaId,
            $enemyId
        );

        $this->artisan('ranking:finalize-weekly-wins')
            ->expectsOutput('2026-07-27週の週間勝利数番付を確定しました。')
            ->expectsOutput('2026-08-03週の週間勝利数番付を確定しました。')
            ->expectsOutput('2026-08-10週の週間勝利数番付を確定しました。')
            ->expectsOutput('対象週: 3週')
            ->expectsOutput('参加者合計: 2人')
            ->expectsOutput('報酬対象合計: 2人')
            ->expectsOutput('無償輝石合計: 40個')
            ->assertSuccessful();

        $seasons = WeeklyWinRankingSeason::query()
            ->orderBy('week_started_at')
            ->get();

        $this->assertSame(
            ['2026-07-27', '2026-08-03', '2026-08-10'],
            $seasons->pluck('season_key')->all()
        );
        $this->assertSame([1, 0, 1], $seasons->pluck('participant_count')->all());
        $this->assertSame(40, $character->fresh()->free_kiseki);
        $this->assertSame(2, KisekiTransaction::query()
            ->where('transaction_type', WeeklyWinRankingService::TRANSACTION_TYPE)
            ->count());
        $this->assertSame(2, DB::table('character_notifications')
            ->where('type', WeeklyWinRankingService::NOTIFICATION_TYPE)
            ->count());
        $this->assertStringNotContainsString(
            '今週の冒険者カードに表示されます',
            (string) DB::table('character_notifications')
                ->where('character_id', $character->id)
                ->where('type', WeeklyWinRankingService::NOTIFICATION_TYPE)
                ->orderBy('id')
                ->value('body')
        );

        $this->artisan('ranking:finalize-weekly-wins')
            ->expectsOutput('未確定の終了済み週はありません。')
            ->assertSuccessful();

        $this->assertSame(40, $character->fresh()->free_kiseki);
        $this->assertSame(3, WeeklyWinRankingSeason::query()->count());
        $this->assertSame(2, KisekiTransaction::query()
            ->where('transaction_type', WeeklyWinRankingService::TRANSACTION_TYPE)
            ->count());
    }

    public function test_pending_finalization_recovers_missing_and_unfinalized_middle_seasons(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 24, 9, 10, 0, 'Asia/Tokyo'));
        $service = app(WeeklyWinRankingService::class);

        $service->finalizePeriod($service->periodForWeekStart('2026-07-27'));
        $service->finalizePeriod($service->periodForWeekStart('2026-08-17'));

        DB::table('weekly_win_ranking_seasons')->insert([
            'season_key' => '2026-08-10',
            'week_started_at' => '2026-08-10 09:00:00',
            'week_ended_at' => '2026-08-17 09:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $service->finalizePendingPeriods();

        $this->assertSame(2, $result['processed_count']);
        $this->assertSame(
            ['2026-08-03', '2026-08-10'],
            collect($result['period_results'])->pluck('season_key')->all()
        );
        $this->assertNotNull(WeeklyWinRankingSeason::query()
            ->where('season_key', '2026-08-03')
            ->value('finalized_at'));
        $this->assertNotNull(WeeklyWinRankingSeason::query()
            ->where('season_key', '2026-08-10')
            ->value('finalized_at'));
    }

    public function test_pending_dry_run_reports_all_missing_periods_without_writes(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 10, 9, 10, 0, 'Asia/Tokyo'));
        [$areaId, $enemyId] = $this->battleMasterIds();
        $character = $this->createCharacter(User::factory()->create(), '未確定週試算', 2, 3);

        $this->addWins(
            $character,
            1,
            Carbon::create(2026, 7, 27, 12, 0, 0, 'Asia/Tokyo'),
            $areaId,
            $enemyId
        );
        $this->addWins(
            $character,
            1,
            Carbon::create(2026, 8, 3, 12, 0, 0, 'Asia/Tokyo'),
            $areaId,
            $enemyId
        );

        $this->artisan('ranking:finalize-weekly-wins', ['--dry-run' => true])
            ->expectsOutput('2026-07-27週を試算しました。')
            ->expectsOutput('2026-08-03週を試算しました。')
            ->expectsOutput('試算のため報酬は付与していません。')
            ->expectsOutput('対象週: 2週')
            ->expectsOutput('参加者合計: 2人')
            ->expectsOutput('報酬対象合計: 2人')
            ->expectsOutput('無償輝石合計: 40個')
            ->assertSuccessful();

        $character->refresh();
        $this->assertSame(2, $character->free_kiseki);
        $this->assertSame(3, $character->paid_kiseki);
        $this->assertSame(5, $character->kiseki);
        $this->assertDatabaseCount('weekly_win_ranking_seasons', 0);
        $this->assertDatabaseCount('weekly_win_ranking_records', 0);
        $this->assertDatabaseMissing('kiseki_transactions', [
            'transaction_type' => WeeklyWinRankingService::TRANSACTION_TYPE,
        ]);
        $this->assertDatabaseMissing('character_notifications', [
            'type' => WeeklyWinRankingService::NOTIFICATION_TYPE,
        ]);
    }

    public function test_current_board_uses_competition_ranking_and_keeps_all_ties_at_the_cutoff(): void
    {
        config()->set('weekly_win_ranking.ranking_limit', 3);
        [$areaId, $enemyId] = $this->battleMasterIds();
        $at = Carbon::create(2026, 7, 27, 9, 5, 0, 'Asia/Tokyo');

        $first = $this->createCharacter(User::factory()->create(), '一番手');
        $second = $this->createCharacter(User::factory()->create(), '二番手');
        $third = $this->createCharacter(User::factory()->create(), '三番手A');
        $thirdTie = $this->createCharacter(User::factory()->create(), '三番手B');

        $this->addWins($first, 5, $at, $areaId, $enemyId);
        $this->addWins($second, 4, $at, $areaId, $enemyId);
        $this->addWins($third, 3, $at, $areaId, $enemyId);
        $this->addWins($thirdTie, 3, $at, $areaId, $enemyId);

        $rows = app(WeeklyWinRankingService::class)->currentRows();

        $this->assertSame([1, 2, 3, 3], $rows->pluck('rank')->all());
        $this->assertSame(
            ['一番手', '二番手', '三番手A', '三番手B'],
            $rows->pluck('name')->all()
        );
    }

    public function test_guests_are_displayed_but_admins_and_testers_are_excluded(): void
    {
        [$areaId, $enemyId] = $this->battleMasterIds();
        $at = Carbon::create(2026, 7, 27, 9, 5, 0, 'Asia/Tokyo');

        $player = $this->createCharacter(User::factory()->create(), '通常冒険者');
        $guestUser = User::query()->create([
            'name' => 'ゲスト',
            'email' => 'guest_11111111-1111-1111-1111-111111111111@example.com',
            'password' => null,
            'google_id' => null,
            'role' => 'user',
        ]);
        $guest = $this->createCharacter($guestUser, '表示ゲスト');
        $admin = $this->createCharacter(
            User::factory()->create(['role' => 'admin']),
            '運営冒険者'
        );
        $tester = $this->createCharacter(
            User::factory()->create([
                'email' => 'tester_weekly_rank@valzeria.local',
                'role' => 'user',
            ]),
            '検証冒険者'
        );

        $this->addWins($player, 5, $at, $areaId, $enemyId);
        $this->addWins($guest, 4, $at, $areaId, $enemyId);
        $this->addWins($admin, 30, $at, $areaId, $enemyId);
        $this->addWins($tester, 25, $at, $areaId, $enemyId);

        $rows = app(WeeklyWinRankingService::class)->currentRows();

        $this->assertSame(['通常冒険者', '表示ゲスト'], $rows->pluck('name')->all());
        $guestRow = $rows->firstWhere('character_id', $guest->id);
        $this->assertFalse($guestRow['is_account_eligible']);
        $this->assertFalse($guestRow['is_reward_eligible']);
        $this->assertSame(0, $guestRow['reward_free_kiseki']);
        $this->assertSame(15, $guestRow['potential_reward_free_kiseki']);
    }

    public function test_sub_area_victory_result_is_counted_as_a_weekly_win(): void
    {
        [$areaId, $enemyId] = $this->battleMasterIds();
        $character = $this->createCharacter(User::factory()->create(), '亜域冒険者');

        $this->addWins(
            $character,
            2,
            Carbon::create(2026, 7, 27, 9, 5, 0, 'Asia/Tokyo'),
            $areaId,
            $enemyId,
            'victory',
            'sub_area'
        );

        $row = app(WeeklyWinRankingService::class)
            ->currentRows()
            ->firstWhere('character_id', $character->id);

        $this->assertNotNull($row);
        $this->assertSame(2, $row['score']);
        $this->assertSame(1, $row['rank']);
    }

    public function test_non_combat_events_and_timeouts_saved_as_win_are_not_counted(): void
    {
        [$areaId, $enemyId] = $this->battleMasterIds();
        $character = $this->createCharacter(User::factory()->create(), '実戦判定');
        $at = Carbon::create(2026, 7, 27, 9, 5, 0, 'Asia/Tokyo');

        $this->addWins(
            $character,
            2,
            $at,
            $areaId,
            $enemyId,
            'win',
            'normal',
            0
        );
        $this->addWins(
            $character,
            1,
            $at->copy()->addMinutes(1),
            $areaId,
            $enemyId,
            'win',
            'exploration_map'
        );

        $row = app(WeeklyWinRankingService::class)
            ->currentRows()
            ->firstWhere('character_id', $character->id);

        $this->assertNotNull($row);
        $this->assertSame(1, $row['score']);
    }

    public function test_finalization_grants_free_kiseki_ledger_notifications_and_weekly_honors_once(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 3, 9, 10, 0, 'Asia/Tokyo'));
        [$areaId, $enemyId] = $this->battleMasterIds();
        $previousWeek = Carbon::create(2026, 7, 27, 9, 0, 0, 'Asia/Tokyo');

        $winner = $this->createCharacter(User::factory()->create(), '優勝者', 3, 5);
        $second = $this->createCharacter(User::factory()->create(), '準優勝者');
        $guestUser = User::query()->create([
            'name' => 'ゲスト',
            'email' => 'guest_22222222-2222-2222-2222-222222222222@example.com',
            'password' => null,
            'google_id' => null,
            'role' => 'user',
        ]);
        $guest = $this->createCharacter($guestUser, '報酬外ゲスト');
        $fourth = $this->createCharacter(User::factory()->create(), '十傑');
        $admin = $this->createCharacter(
            User::factory()->create(['role' => 'admin']),
            '運営'
        );
        $tester = $this->createCharacter(
            User::factory()->create([
                'email' => 'tester_weekly_reward@valzeria.local',
                'role' => 'user',
            ]),
            '検証'
        );

        $this->addWins($winner, 12, $previousWeek, $areaId, $enemyId);
        $this->addWins($second, 11, $previousWeek->copy()->addHour(), $areaId, $enemyId);
        $this->addWins($guest, 10, $previousWeek->copy()->addHours(2), $areaId, $enemyId);
        $this->addWins($fourth, 9, $previousWeek->copy()->addHours(3), $areaId, $enemyId);
        $this->addWins($admin, 30, $previousWeek->copy()->addHours(4), $areaId, $enemyId);
        $this->addWins($tester, 25, $previousWeek->copy()->addHours(5), $areaId, $enemyId);

        // 週の直前・直後は前週確定へ含めない。
        $this->addWins(
            $winner,
            1,
            $previousWeek->copy()->subSecond(),
            $areaId,
            $enemyId
        );
        $this->addWins(
            $winner,
            1,
            $previousWeek->copy()->addWeek(),
            $areaId,
            $enemyId
        );

        $service = app(WeeklyWinRankingService::class);
        $result = $service->finalizePreviousWeek();

        $this->assertSame('2026-07-27', $result['season_key']);
        $this->assertSame(4, $result['participant_count']);
        $this->assertSame(3, $result['rewarded_count']);
        $this->assertSame(43, $result['total_free_kiseki']);
        $this->assertFalse($result['already_finalized']);

        $winner->refresh();
        $this->assertSame(23, $winner->free_kiseki);
        $this->assertSame(5, $winner->paid_kiseki);
        $this->assertSame(28, $winner->kiseki);

        $season = WeeklyWinRankingSeason::query()
            ->where('season_key', '2026-07-27')
            ->firstOrFail();
        $winnerRecord = WeeklyWinRankingRecord::query()
            ->where('season_id', $season->id)
            ->where('character_id', $winner->id)
            ->firstOrFail();
        $guestRecord = WeeklyWinRankingRecord::query()
            ->where('season_id', $season->id)
            ->where('character_id', $guest->id)
            ->firstOrFail();

        $this->assertSame(12, $winnerRecord->wins);
        $this->assertSame(1, $winnerRecord->rank);
        $this->assertSame(20, $winnerRecord->reward_free_kiseki);
        $this->assertSame('今週の武勇一番', $winnerRecord->badge_label);
        $this->assertNotNull($winnerRecord->rewarded_at);
        $this->assertSame(3, $guestRecord->rank);
        $this->assertSame(0, $guestRecord->reward_free_kiseki);
        $this->assertFalse($guestRecord->is_reward_eligible);

        $this->assertDatabaseMissing('weekly_win_ranking_records', [
            'season_id' => $season->id,
            'character_id' => $admin->id,
        ]);
        $this->assertDatabaseMissing('weekly_win_ranking_records', [
            'season_id' => $season->id,
            'character_id' => $tester->id,
        ]);
        $this->assertDatabaseHas('kiseki_transactions', [
            'character_id' => $winner->id,
            'kiseki_type' => 'free',
            'amount' => 20,
            'transaction_type' => WeeklyWinRankingService::TRANSACTION_TYPE,
            'source_type' => WeeklyWinRankingService::TRANSACTION_SOURCE_TYPE,
            'source_id' => $winnerRecord->id,
        ]);
        $this->assertDatabaseHas('character_notifications', [
            'character_id' => $winner->id,
            'type' => WeeklyWinRankingService::NOTIFICATION_TYPE,
            'title' => '週間勝利数番付の報酬',
            'action_label' => '番付を見る',
        ]);
        $this->assertStringContainsString(
            '今週の冒険者カードに表示されます',
            (string) DB::table('character_notifications')
                ->where('character_id', $winner->id)
                ->where('type', WeeklyWinRankingService::NOTIFICATION_TYPE)
                ->value('body')
        );

        $badge = $service->latestBadgeFor($winner);
        $this->assertSame('今週の武勇一番', $badge['label']);
        $this->assertSame(1, $badge['rank']);
        $this->assertSame(12, $badge['wins']);
        $this->assertNull($service->latestBadgeFor($guest));

        $duplicate = $service->finalizePreviousWeek();
        $this->assertTrue($duplicate['already_finalized']);
        $this->assertSame(3, KisekiTransaction::query()
            ->where('transaction_type', WeeklyWinRankingService::TRANSACTION_TYPE)
            ->count());
        $this->assertSame(3, DB::table('character_notifications')
            ->where('type', WeeklyWinRankingService::NOTIFICATION_TYPE)
            ->count());
        $this->assertSame(23, $winner->fresh()->free_kiseki);

        Carbon::setTestNow(Carbon::create(2026, 8, 10, 9, 1, 0, 'Asia/Tokyo'));
        $this->assertNull($service->latestBadgeFor($winner));
    }

    public function test_dry_run_reports_rewards_without_writing_balances_or_ledgers(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 3, 9, 10, 0, 'Asia/Tokyo'));
        [$areaId, $enemyId] = $this->battleMasterIds();
        $character = $this->createCharacter(User::factory()->create(), '試算確認', 2, 3);
        $this->addWins(
            $character,
            10,
            Carbon::create(2026, 7, 27, 12, 0, 0, 'Asia/Tokyo'),
            $areaId,
            $enemyId
        );

        $this->artisan('ranking:finalize-weekly-wins', [
            '--week-start' => '2026-07-27',
            '--dry-run' => true,
        ])
            ->expectsOutput('2026-07-27週の試算です。報酬は付与していません。')
            ->expectsOutput('参加者: 1人')
            ->expectsOutput('報酬対象: 1人')
            ->expectsOutput('無償輝石合計: 20個')
            ->assertSuccessful();

        $character->refresh();
        $this->assertSame(2, $character->free_kiseki);
        $this->assertSame(3, $character->paid_kiseki);
        $this->assertSame(5, $character->kiseki);
        $this->assertDatabaseCount('weekly_win_ranking_seasons', 0);
        $this->assertDatabaseCount('weekly_win_ranking_records', 0);
        $this->assertDatabaseMissing('kiseki_transactions', [
            'transaction_type' => WeeklyWinRankingService::TRANSACTION_TYPE,
        ]);
    }

    public function test_ranking_page_shows_the_weekly_board_rewards_and_current_status(): void
    {
        [$areaId, $enemyId] = $this->battleMasterIds();
        $user = User::factory()->create();
        $character = $this->createCharacter($user, '画面確認者');
        $this->addWins(
            $character,
            4,
            Carbon::create(2026, 7, 27, 9, 5, 0, 'Asia/Tokyo'),
            $areaId,
            $enemyId
        );

        $this->withoutMiddleware(CheckCharacterSelected::class)
            ->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('ranking.index', ['board' => 'weekly_wins']))
            ->assertOk()
            ->assertSee('週間勝利数増分番付')
            ->assertSee('月曜9:00から翌月曜8:59まで')
            ->assertSee('2026年7月27日 9:00〜8月3日 8:59')
            ->assertSee('月曜9:05に前週分を確定')
            ->assertSee('無償輝石 20個')
            ->assertSee('あなたの今週')
            ->assertSee('4勝')
            ->assertSee('現在の報酬 無償輝石20個')
            ->assertSee('画面確認者の冒険者カードを見る')
            ->assertSee("Livewire.dispatch('open-adventurer-card'", false)
            ->assertSee('&quot;name&quot;:&quot;adventurer-card-modal&quot;', false);
    }

    public function test_shared_progress_widget_shows_weekly_wins_and_current_progress(): void
    {
        [$areaId, $enemyId] = $this->battleMasterIds();
        $viewer = User::factory()->create();
        $viewerCharacter = $this->createCharacter($viewer, '進捗確認者');
        $leader = $this->createCharacter(User::factory()->create(), '週間首位');
        $at = Carbon::create(2026, 7, 27, 9, 5, 0, 'Asia/Tokyo');

        $this->addWins($leader, 5, $at, $areaId, $enemyId);
        $this->addWins($viewerCharacter, 4, $at, $areaId, $enemyId);

        $this->actingAs($viewer)
            ->withSession(['current_character_id' => $viewerCharacter->id]);

        Livewire::test(StarTreeTowerRankingWidget::class)
            ->assertSee('週間勝利')
            ->assertSee('あなたの進捗')
            ->assertSee('4勝')
            ->assertSee('2位')
            ->assertSee('見込み 無償輝石15個')
            ->assertSee('週間首位')
            ->assertSee('週間首位の冒険者カードを見る')
            ->assertSee("Livewire.dispatch('open-adventurer-card'", false)
            ->assertDontSee('wire:click="openWeeklyWinPlayerModal', false)
            ->assertSee('週間勝利数番付を見る');
    }

    public function test_shared_progress_widget_accepts_the_pre_deploy_card_click_action(): void
    {
        $viewer = User::factory()->create();
        $viewerCharacter = $this->createCharacter($viewer, '互換確認者');
        $target = $this->createCharacter(User::factory()->create(), '旧画面表示対象');

        $this->actingAs($viewer)
            ->withSession(['current_character_id' => $viewerCharacter->id]);

        Livewire::test(StarTreeTowerRankingWidget::class)
            ->call('openWeeklyWinPlayerModal', $target->id)
            ->assertDispatchedTo(AdventurerCardModal::class, 'open-adventurer-card');
    }

    public function test_shared_progress_widget_shows_guest_and_empty_week_states(): void
    {
        $guest = User::query()->create([
            'name' => 'ゲスト',
            'email' => 'guest_33333333-3333-3333-3333-333333333333@example.com',
            'password' => null,
            'google_id' => null,
            'role' => 'user',
        ]);
        $character = $this->createCharacter($guest, '未参加ゲスト');

        $this->actingAs($guest)
            ->withSession(['current_character_id' => $character->id]);

        Livewire::test(StarTreeTowerRankingWidget::class)
            ->assertSee('あなたの進捗')
            ->assertSee('0勝')
            ->assertSee('未参加')
            ->assertSee('表示のみ・報酬対象外')
            ->assertSee('今週の勝利はまだありません');
    }

    public function test_reward_finalization_rolls_back_balances_and_ledgers_when_notification_fails(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 3, 9, 10, 0, 'Asia/Tokyo'));
        [$areaId, $enemyId] = $this->battleMasterIds();
        $character = $this->createCharacter(User::factory()->create(), '巻き戻し確認', 2, 3);
        $this->addWins(
            $character,
            10,
            Carbon::create(2026, 7, 27, 12, 0, 0, 'Asia/Tokyo'),
            $areaId,
            $enemyId
        );

        $notifications = \Mockery::mock(CharacterNotificationService::class);
        $notifications->shouldReceive('create')->once()->andThrow(new RuntimeException('通知作成失敗'));
        $service = new WeeklyWinRankingService($notifications);

        try {
            $service->finalizePreviousWeek();
            $this->fail('通知作成失敗が例外として伝播しませんでした。');
        } catch (RuntimeException $e) {
            $this->assertSame('通知作成失敗', $e->getMessage());
        }

        $character->refresh();
        $this->assertSame(2, $character->free_kiseki);
        $this->assertSame(3, $character->paid_kiseki);
        $this->assertSame(5, $character->kiseki);
        $this->assertDatabaseCount('weekly_win_ranking_seasons', 0);
        $this->assertDatabaseCount('weekly_win_ranking_records', 0);
        $this->assertDatabaseMissing('kiseki_transactions', [
            'transaction_type' => WeeklyWinRankingService::TRANSACTION_TYPE,
        ]);
    }

    /** @return array{int, int} */
    private function battleMasterIds(): array
    {
        $areaId = (int) (DB::table('areas')->value('id')
            ?? DB::table('areas')->insertGetId([
                'name' => '週間番付テスト地域',
                'slug' => 'weekly-ranking-test-area',
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        $enemyId = (int) (DB::table('enemies')->where('area_id', $areaId)->value('id')
            ?? DB::table('enemies')->insertGetId([
                'area_id' => $areaId,
                'name' => '週間番付テスト敵',
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        return [$areaId, $enemyId];
    }

    private function createCharacter(
        User $user,
        string $name,
        int $freeKiseki = 0,
        int $paidKiseki = 0
    ): Character {
        return Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'free_kiseki' => $freeKiseki,
            'paid_kiseki' => $paidKiseki,
            'kiseki' => $freeKiseki + $paidKiseki,
        ]);
    }

    private function addWins(
        Character $character,
        int $count,
        Carbon $at,
        int $areaId,
        int $enemyId,
        string $result = 'win',
        string $battleType = 'normal',
        int $expGained = 1
    ): void {
        $rows = [];
        for ($index = 0; $index < $count; $index++) {
            $timestamp = $at->copy()->addSeconds($index);
            $rows[] = [
                'character_id' => $character->id,
                'area_id' => $areaId,
                'enemy_id' => $enemyId,
                'battle_type' => $battleType,
                'result' => $result,
                'exp_gained' => $expGained,
                'gold_gained' => 0,
                'job_exp_gained' => 1,
                'level_up_count' => 0,
                'log_text' => '週間番付テスト勝利',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('battle_logs')->insert($chunk);
        }
    }
}
