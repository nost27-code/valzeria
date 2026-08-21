<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Exceptions\SixHeroRankingNotReadyException;
use App\Models\Character;
use App\Models\SixHeroBattleLog;
use App\Models\SixHeroDailyUsage;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Models\User;
use App\Services\Battle\BattleResult;
use App\Services\Battle\PvPBattleExecutionContext;
use App\Services\Battle\PvPBattleResolution;
use App\Services\Battle\RoomRules\BurningLifePvPRoomRule;
use App\Services\Battle\RoomRules\DivineSpeedPvPRoomRule;
use App\Services\Battle\RoomRules\MiraclePvPRoomRule;
use App\Services\Battle\RoomRules\ReverseTimePvPRoomRule;
use App\Services\Battle\RoomRules\SealBladePvPRoomRule;
use App\Services\Battle\RoomRules\SealMagicPvPRoomRule;
use App\Services\Battle\SixHeroBattleContextFactory;
use App\Services\Battle\SixHeroRoomRuleResolver;
use App\Services\PvPBattleService;
use App\Services\PublicLogService;
use App\Services\SixHeroOfficialBattleService;
use App\Services\SixHeroRankingService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class SixHeroOfficialBattleServiceTest extends TestCase
{
    use RefreshDatabase;

    private SixHeroRankingService $rankingService;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Tokyo']);
        Carbon::setTestNow(Carbon::parse('2026-08-19 12:00:00', 'Asia/Tokyo'));
        $this->rankingService = new SixHeroRankingService;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_victory_runs_resolve_outside_the_preflight_transaction_and_completes_the_audit_log(): void
    {
        $season = $this->season();
        $characters = $this->characters(4);
        $rankings = $this->registerMany(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $characters,
        );
        $battleResult = new BattleResult;
        $battleResult->logs = [str_repeat('巨大な戦闘ログ', 2000)];
        $resolution = new PvPBattleResolution(
            result: $battleResult,
            attackerWon: true,
            turnCount: 7,
            attackerHp: 800,
            attackerMaxHp: 1000,
            defenderHp: 200,
            defenderMaxHp: 1000,
        );
        $transactionLevelBeforeBattle = DB::transactionLevel();
        $sawResolvedBeforeRanking = false;
        $rankingUpdateEvent = 'eloquent.updating: '.SixHeroRanking::class;
        Event::listen($rankingUpdateEvent, function () use (&$sawResolvedBeforeRanking): void {
            $sawResolvedBeforeRanking = SixHeroBattleLog::query()
                ->where('status', SixHeroBattleLog::STATUS_RESOLVED)
                ->exists();
        });
        $service = $this->serviceWithBattle(
            function (
                Character $attacker,
                Character $defender,
                PvPBattleExecutionContext $context,
            ) use (
                $characters,
                $resolution,
                $transactionLevelBeforeBattle,
            ): PvPBattleResolution {
                $this->assertSame($transactionLevelBeforeBattle, DB::transactionLevel());
                $this->assertTrue($attacker->is($characters[3]));
                $this->assertTrue($defender->is($characters[1]));
                $this->assertSame('神速の間', $context->displayLabel);
                $this->assertSame('champ', $context->jobArtContext);
                $this->assertInstanceOf(DivineSpeedPvPRoomRule::class, $context->roomRule);
                $this->assertDatabaseHas('six_hero_daily_usages', [
                    'character_id' => $characters[3]->id,
                    'usage_date' => '2026-08-19',
                    'official_attempts' => 1,
                ]);
                $this->assertDatabaseHas('six_hero_battle_logs', [
                    'status' => SixHeroBattleLog::STATUS_STARTED,
                    'attacker_id' => $characters[3]->id,
                    'defender_id' => $characters[1]->id,
                    'attacker_rank_at_start' => 4,
                    'defender_rank_at_start' => 2,
                    'daily_attempt_number' => 1,
                ]);

                return $resolution;
            },
        );

        try {
            $result = $service->execute(
                $season,
                SixHeroRoomKey::DIVINE_SPEED,
                $characters[3],
                $characters[1],
            );
        } finally {
            Event::forget($rankingUpdateEvent);
        }

        $this->assertTrue($sawResolvedBeforeRanking);
        $this->assertSame($resolution, $result->resolution);
        $this->assertNotNull($result->rankChange);
        $this->assertTrue($result->rankChange->attackerWon);
        $this->assertTrue($result->rankChange->rankChanged);
        $this->assertSame([4, 2, 2, 3], [
            $result->rankChange->attackerOldRank,
            $result->rankChange->attackerNewRank,
            $result->rankChange->defenderOldRank,
            $result->rankChange->defenderNewRank,
        ]);
        $this->assertSame(1, $result->officialAttemptsUsed);
        $this->assertSame(4, $result->officialAttemptsRemaining);
        $this->assertSame([
            $characters[0]->id,
            $characters[3]->id,
            $characters[1]->id,
            $characters[2]->id,
        ], $this->orderedCharacterIds($season, SixHeroRoomKey::DIVINE_SPEED));
        $this->assertSame([1, 0, 0, 0], $this->counters($rankings[3]->fresh()));
        $this->assertSame([0, 0, 0, 1], $this->counters($rankings[1]->fresh()));

        $log = $result->battleLog->fresh();
        $this->assertSame(SixHeroBattleLog::STATUS_COMPLETED, $log->status);
        $this->assertSame(SixHeroBattleLog::MODE_OFFICIAL, $log->battle_mode);
        $this->assertSame(SixHeroRoomKey::DIVINE_SPEED, $log->room_key);
        $this->assertTrue($log->is_attacker_win);
        $this->assertTrue($log->rank_changed);
        $this->assertSame([4, 2, 2, 3], [
            $log->attacker_old_rank,
            $log->attacker_new_rank,
            $log->defender_old_rank,
            $log->defender_new_rank,
        ]);
        $this->assertSame(7, $log->turn_count);
        $this->assertEqualsWithDelta(0.8, $log->attacker_hp_ratio, 0.00000001);
        $this->assertEqualsWithDelta(0.2, $log->defender_hp_ratio, 0.00000001);
        $this->assertNotNull($log->started_at);
        $this->assertNotNull($log->resolved_at);
        $this->assertNotNull($log->completed_at);
        $this->assertNull($log->failed_at);
        $this->assertNull($log->failure_code);
        $this->assertNull($log->metadata);
        $this->assertTrue($log->season->is($season));
        $this->assertTrue($log->attacker->is($characters[3]));
        $this->assertTrue($log->defender->is($characters[1]));
        $this->assertDatabaseCount('arena_logs', 0);
        $this->assertDatabaseCount('arena_rankings', 0);
        $this->assertDatabaseHas('public_logs', [
            'type' => 'arena',
            'character_id' => $characters[3]->id,
            'importance' => 2,
            'message' => "【六極殿】神速の間で、{$characters[3]->name}さんが{$characters[1]->name}さんを破り、4位から2位へ駆け上がりました！",
        ]);
    }

    public function test_pending_previous_month_blocks_official_battle_before_usage_or_log_creation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 00:10:00', 'Asia/Tokyo'));
        $previous = $this->season();
        $target = $this->season(
            '2026-09',
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
            false,
        );
        [$attacker, $defender] = $this->characters(2);
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

        try {
            $this->serviceWithoutBattle()->execute(
                $target,
                SixHeroRoomKey::DIVINE_SPEED,
                $attacker,
                $defender,
            );
            $this->fail('An official battle started before ranking initialization was ready.');
        } catch (SixHeroRankingNotReadyException) {
            $this->assertNull($target->fresh()->ranking_initialized_at);
        }

        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 1);
        $this->assertDatabaseMissing('six_hero_rankings', [
            'season_id' => $target->id,
        ]);
    }

    public function test_public_log_failure_does_not_change_a_completed_official_battle(): void
    {
        $season = $this->season();
        $characters = $this->characters(2);
        $this->registerMany($season, SixHeroRoomKey::DIVINE_SPEED, $characters);

        $publicLogs = Mockery::mock(PublicLogService::class);
        $publicLogs->shouldReceive('addLog')
            ->once()
            ->andThrow(new RuntimeException('public log unavailable'));
        $this->app->instance(PublicLogService::class, $publicLogs);

        $result = $this->serviceWithBattle(
            fn (): PvPBattleResolution => $this->resolution(attackerWon: true),
        )->execute(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $characters[1],
            $characters[0],
        );

        $this->assertTrue($result->rankChange?->rankChanged);
        $this->assertSame(1, $result->rankChange?->attackerNewRank);
        $this->assertSame(SixHeroBattleLog::STATUS_COMPLETED, $result->battleLog->fresh()->status);
        $this->assertSame([
            $characters[1]->id,
            $characters[0]->id,
        ], $this->orderedCharacterIds($season, SixHeroRoomKey::DIVINE_SPEED));
    }

    public function test_loss_consumes_an_attempt_keeps_ranks_and_updates_only_loss_counters(): void
    {
        $season = $this->season();
        $characters = $this->characters(4);
        $rankings = $this->registerMany($season, SixHeroRoomKey::MIRACLE, $characters);
        $service = $this->serviceWithBattle(
            fn (): PvPBattleResolution => $this->resolution(false, 11, 0, 1000),
        );

        $result = $service->execute(
            $season,
            SixHeroRoomKey::MIRACLE,
            $characters[3],
            $characters[1],
        );

        $this->assertFalse($result->resolution->attackerWon);
        $this->assertFalse($result->rankChange?->rankChanged);
        $this->assertSame([1, 2, 3, 4], $this->roomRanks($season, SixHeroRoomKey::MIRACLE));
        $this->assertSame([0, 1, 0, 0], $this->counters($rankings[3]->fresh()));
        $this->assertSame([0, 0, 1, 0], $this->counters($rankings[1]->fresh()));
        $this->assertSame(SixHeroBattleLog::STATUS_COMPLETED, $result->battleLog->status);
        $this->assertFalse($result->battleLog->is_attacker_win);
        $this->assertFalse($result->battleLog->rank_changed);
        $this->assertSame(1, $result->officialAttemptsUsed);
        $this->assertSame(4, $result->officialAttemptsRemaining);
    }

    public function test_each_room_has_its_own_daily_five_battle_limit(): void
    {
        $season = $this->season();
        [$defender, $attacker] = $this->characters(2);
        $this->registerMany($season, SixHeroRoomKey::SEAL_MAGIC, [$defender, $attacker]);
        $this->registerMany($season, SixHeroRoomKey::SEAL_BLADE, [$defender, $attacker]);
        $service = $this->serviceWithBattle(
            fn (): PvPBattleResolution => $this->resolution(false),
            6,
        );

        $sealMagicRemaining = [];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $sealMagicRemaining[] = $service->execute(
                $season,
                SixHeroRoomKey::SEAL_MAGIC,
                $attacker,
                $defender,
            )->officialAttemptsRemaining;
        }

        $sealBlade = $service->execute(
            $season,
            SixHeroRoomKey::SEAL_BLADE,
            $attacker,
            $defender,
        );

        $this->assertSame([4, 3, 2, 1, 0], $sealMagicRemaining);
        $this->assertSame(1, $sealBlade->officialAttemptsUsed);
        $this->assertSame(4, $sealBlade->officialAttemptsRemaining);
        $this->assertDomainFailure(fn () => $service->execute(
            $season,
            SixHeroRoomKey::SEAL_MAGIC,
            $attacker,
            $defender,
        ));
        $dailyUsage = SixHeroDailyUsage::query()->sole();
        $this->assertSame(6, $dailyUsage->official_attempts);
        $this->assertSame(5, $dailyUsage->official_attempts_by_room[SixHeroRoomKey::SEAL_MAGIC->value]);
        $this->assertSame(1, $dailyUsage->official_attempts_by_room[SixHeroRoomKey::SEAL_BLADE->value]);
        $this->assertSame(6, SixHeroBattleLog::query()->count());
        $this->assertSame(
            [1, 2, 3, 4, 5, 1],
            SixHeroBattleLog::query()->orderBy('id')->pluck('daily_attempt_number')->all(),
        );
    }

    public function test_daily_usage_is_independent_for_each_attacking_character(): void
    {
        $season = $this->season();
        [$defender, $firstAttacker, $secondAttacker] = $this->characters(3);
        $this->registerMany(
            $season,
            SixHeroRoomKey::SEAL_MAGIC,
            [$defender, $firstAttacker, $secondAttacker],
        );
        $this->insertDailyUsage($firstAttacker, '2026-08-19', 5);
        $service = $this->serviceWithBattle(
            fn (): PvPBattleResolution => $this->resolution(false),
        );

        $result = $service->execute(
            $season,
            SixHeroRoomKey::SEAL_MAGIC,
            $secondAttacker,
            $firstAttacker,
        );

        $this->assertSame(1, $result->officialAttemptsUsed);
        $this->assertSame(4, $result->officialAttemptsRemaining);
        $this->assertDatabaseHas('six_hero_daily_usages', [
            'character_id' => $firstAttacker->id,
            'usage_date' => '2026-08-19',
            'official_attempts' => 5,
        ]);
        $this->assertDatabaseHas('six_hero_daily_usages', [
            'character_id' => $secondAttacker->id,
            'usage_date' => '2026-08-19',
            'official_attempts' => 1,
        ]);
    }

    public function test_legacy_daily_usage_reconstructs_room_counts_from_official_logs(): void
    {
        $season = $this->season();
        [$defender, $attacker] = $this->characters(2);
        $this->registerMany($season, SixHeroRoomKey::SEAL_MAGIC, [$defender, $attacker]);
        $this->registerMany($season, SixHeroRoomKey::SEAL_BLADE, [$defender, $attacker]);
        DB::table('six_hero_daily_usages')->insert([
            'character_id' => $attacker->id,
            'usage_date' => '2026-08-19',
            'official_attempts' => 2,
            'official_attempts_by_room' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([SixHeroRoomKey::SEAL_MAGIC, SixHeroRoomKey::SEAL_BLADE] as $room) {
            SixHeroBattleLog::query()->create([
                'season_id' => $season->id,
                'room_key' => $room,
                'battle_mode' => SixHeroBattleLog::MODE_OFFICIAL,
                'status' => SixHeroBattleLog::STATUS_COMPLETED,
                'attacker_id' => $attacker->id,
                'defender_id' => $defender->id,
                'attacker_rank_at_start' => 2,
                'defender_rank_at_start' => 1,
                'daily_attempt_number' => 1,
                'started_at' => now()->subMinute(),
                'completed_at' => now()->subMinute(),
            ]);
        }
        $service = $this->serviceWithBattle(
            fn (): PvPBattleResolution => $this->resolution(false),
        );

        $result = $service->execute(
            $season,
            SixHeroRoomKey::SEAL_MAGIC,
            $attacker,
            $defender,
        );

        $usage = SixHeroDailyUsage::query()->sole();
        $this->assertSame(2, $result->officialAttemptsUsed);
        $this->assertSame(3, $result->officialAttemptsRemaining);
        $this->assertSame(3, $usage->official_attempts);
        $this->assertSame(2, $usage->official_attempts_by_room[SixHeroRoomKey::SEAL_MAGIC->value]);
        $this->assertSame(1, $usage->official_attempts_by_room[SixHeroRoomKey::SEAL_BLADE->value]);
    }

    public function test_usage_resets_by_creating_a_new_row_at_the_app_timezone_date_boundary(): void
    {
        $season = $this->season();
        [$defender, $attacker] = $this->characters(2);
        $this->registerMany($season, SixHeroRoomKey::SEAL_BLADE, [$defender, $attacker]);
        $service = $this->serviceWithBattle(
            fn (): PvPBattleResolution => $this->resolution(false),
            2,
        );

        Carbon::setTestNow(Carbon::parse('2026-08-19 14:59:59', 'UTC'));
        $first = $service->execute(
            $season,
            SixHeroRoomKey::SEAL_BLADE,
            $attacker,
            $defender,
        );
        Carbon::setTestNow(Carbon::parse('2026-08-19 15:00:00', 'UTC'));
        $second = $service->execute(
            $season,
            SixHeroRoomKey::SEAL_BLADE,
            $attacker,
            $defender,
        );

        $this->assertSame(1, $first->officialAttemptsUsed);
        $this->assertSame(1, $second->officialAttemptsUsed);
        $this->assertSame([
            ['usage_date' => '2026-08-19', 'official_attempts' => 1],
            ['usage_date' => '2026-08-20', 'official_attempts' => 1],
        ], SixHeroDailyUsage::query()
            ->where('character_id', $attacker->id)
            ->orderBy('usage_date')
            ->get()
            ->map(fn (SixHeroDailyUsage $usage): array => [
                'usage_date' => $usage->usage_date->toDateString(),
                'official_attempts' => $usage->official_attempts,
            ])
            ->all());
    }

    public function test_validation_failures_create_neither_usage_nor_battle_log(): void
    {
        $service = $this->serviceWithoutBattle();
        $activeSeason = $this->season();
        $characters = $this->characters(7);
        $activeRankings = $this->registerMany(
            $activeSeason,
            SixHeroRoomKey::DIVINE_SPEED,
            array_slice($characters, 0, 5),
        );

        $this->assertDomainFailure(fn () => $service->execute(
            $activeSeason,
            SixHeroRoomKey::DIVINE_SPEED,
            $characters[0],
            $characters[0],
        ));
        $this->assertDomainFailure(fn () => $service->execute(
            $activeSeason,
            SixHeroRoomKey::DIVINE_SPEED,
            $characters[4],
            $characters[5],
        ));
        $this->assertDomainFailure(fn () => $service->execute(
            $activeSeason,
            SixHeroRoomKey::DIVINE_SPEED,
            $characters[4],
            $characters[0],
        ));

        $futureSeason = $this->season(
            '2026-09',
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
        );
        $futureRankings = $this->registerMany(
            $futureSeason,
            SixHeroRoomKey::DIVINE_SPEED,
            [$characters[5], $characters[6]],
        );
        $this->assertDomainFailure(fn () => $service->execute(
            $futureSeason,
            SixHeroRoomKey::DIVINE_SPEED,
            $futureRankings[1]->character,
            $futureRankings[0]->character,
        ));

        $finalizedSeason = $this->season(
            '2026-07',
            '2026-08-01 00:00:00',
            '2026-09-01 00:00:00',
        );
        $finalizedRankings = $this->registerMany(
            $finalizedSeason,
            SixHeroRoomKey::DIVINE_SPEED,
            [$characters[5], $characters[6]],
        );
        SixHeroSeason::query()->whereKey($finalizedSeason->id)->update([
            'finalized_at' => '2026-08-19 11:00:00',
        ]);
        $this->assertNull($finalizedSeason->finalized_at);
        $this->assertDomainFailure(fn () => $service->execute(
            $finalizedSeason,
            SixHeroRoomKey::DIVINE_SPEED,
            $finalizedRankings[1]->character,
            $finalizedRankings[0]->character,
        ));

        $this->assertCount(5, $activeRankings);
        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 0);
    }

    public function test_season_start_is_inclusive_and_end_is_exclusive(): void
    {
        $season = $this->season(
            '2026-08',
            '2026-08-19 12:00:00',
            '2026-08-19 13:00:00',
        );
        [$defender, $attacker] = $this->characters(2);
        $this->registerMany($season, SixHeroRoomKey::REVERSE_TIME, [$defender, $attacker]);
        $service = $this->serviceWithBattle(
            fn (): PvPBattleResolution => $this->resolution(false),
        );

        Carbon::setTestNow(Carbon::parse('2026-08-19 12:00:00', 'Asia/Tokyo'));
        $service->execute(
            $season,
            SixHeroRoomKey::REVERSE_TIME,
            $attacker,
            $defender,
        );
        Carbon::setTestNow(Carbon::parse('2026-08-19 13:00:00', 'Asia/Tokyo'));
        $this->assertDomainFailure(fn () => $service->execute(
            $season,
            SixHeroRoomKey::REVERSE_TIME,
            $attacker,
            $defender,
        ));

        $this->assertDatabaseHas('six_hero_daily_usages', [
            'character_id' => $attacker->id,
            'official_attempts' => 1,
        ]);
        $this->assertDatabaseCount('six_hero_battle_logs', 1);
    }

    public function test_battle_exception_keeps_the_consumed_attempt_and_marks_the_log_failed(): void
    {
        $season = $this->season();
        [$defender, $attacker] = $this->characters(2);
        $rankings = $this->registerMany(
            $season,
            SixHeroRoomKey::BURNING_LIFE,
            [$defender, $attacker],
        );
        $service = $this->serviceWithBattle(
            static function (): never {
                throw new RuntimeException('simulated battle failure with private detail');
            },
        );

        try {
            $service->execute(
                $season,
                SixHeroRoomKey::BURNING_LIFE,
                $attacker,
                $defender,
            );
            $this->fail('The battle exception was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'simulated battle failure with private detail',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('six_hero_daily_usages', [
            'character_id' => $attacker->id,
            'official_attempts' => 1,
        ]);
        $log = SixHeroBattleLog::query()->sole();
        $this->assertSame(SixHeroBattleLog::STATUS_FAILED, $log->status);
        $this->assertSame(SixHeroBattleLog::FAILURE_BATTLE_RUNTIME, $log->failure_code);
        $this->assertNotSame('simulated battle failure with private detail', $log->failure_code);
        $this->assertNotNull($log->failed_at);
        $this->assertNull($log->resolved_at);
        $this->assertSame([0, 0, 0, 0], $this->counters($rankings[0]->fresh()));
        $this->assertSame([0, 0, 0, 0], $this->counters($rankings[1]->fresh()));
    }

    public function test_started_log_failure_rolls_back_attempt_consumption_and_never_runs_battle(): void
    {
        $season = $this->season();
        [$defender, $attacker] = $this->characters(2);
        $this->registerMany($season, SixHeroRoomKey::DIVINE_SPEED, [$defender, $attacker]);
        $this->insertDailyUsage(
            $attacker,
            '2026-08-19',
            2,
            SixHeroRoomKey::DIVINE_SPEED,
        );
        $eventName = 'eloquent.creating: '.SixHeroBattleLog::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('forced started log failure');
        });
        $service = $this->serviceWithoutBattle();

        try {
            $service->execute(
                $season,
                SixHeroRoomKey::DIVINE_SPEED,
                $attacker,
                $defender,
            );
            $this->fail('The forced log failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced started log failure', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertDatabaseHas('six_hero_daily_usages', [
            'character_id' => $attacker->id,
            'usage_date' => '2026-08-19',
            'official_attempts' => 2,
        ]);
        $this->assertDatabaseCount('six_hero_battle_logs', 0);
    }

    public function test_resolved_log_failure_keeps_the_attempt_and_transitions_started_to_failed(): void
    {
        $season = $this->season();
        [$defender, $attacker] = $this->characters(2);
        $rankings = $this->registerMany(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            [$defender, $attacker],
        );
        $eventName = 'eloquent.updating: '.SixHeroBattleLog::class;
        Event::listen($eventName, static function (SixHeroBattleLog $log): void {
            if ($log->status === SixHeroBattleLog::STATUS_RESOLVED) {
                throw new RuntimeException('forced resolved log failure');
            }
        });
        $service = $this->serviceWithBattle(
            fn (): PvPBattleResolution => $this->resolution(true),
        );

        try {
            $service->execute(
                $season,
                SixHeroRoomKey::DIVINE_SPEED,
                $attacker,
                $defender,
            );
            $this->fail('The forced resolved-log failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced resolved log failure', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertDatabaseHas('six_hero_daily_usages', [
            'character_id' => $attacker->id,
            'official_attempts' => 1,
        ]);
        $log = SixHeroBattleLog::query()->sole();
        $this->assertSame(SixHeroBattleLog::STATUS_FAILED, $log->status);
        $this->assertSame(SixHeroBattleLog::FAILURE_RESOLUTION_LOG, $log->failure_code);
        $this->assertNotNull($log->failed_at);
        $this->assertNull($log->resolved_at);
        $this->assertSame([0, 0, 0, 0], $this->counters($rankings[0]->fresh()));
        $this->assertSame([0, 0, 0, 0], $this->counters($rankings[1]->fresh()));
    }

    public function test_ranking_exception_marks_the_resolved_log_failed_and_rolls_back_counters(): void
    {
        $season = $this->season();
        [$defender, $attacker] = $this->characters(2);
        $rankings = $this->registerMany(
            $season,
            SixHeroRoomKey::MIRACLE,
            [$defender, $attacker],
        );
        $eventName = 'eloquent.updating: '.SixHeroRanking::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('forced ranking failure');
        });
        $service = $this->serviceWithBattle(
            fn (): PvPBattleResolution => $this->resolution(false, 9, 100, 700),
        );

        try {
            $service->execute(
                $season,
                SixHeroRoomKey::MIRACLE,
                $attacker,
                $defender,
            );
            $this->fail('The forced ranking failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced ranking failure', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertDatabaseHas('six_hero_daily_usages', [
            'character_id' => $attacker->id,
            'official_attempts' => 1,
        ]);
        $log = SixHeroBattleLog::query()->sole();
        $this->assertSame(SixHeroBattleLog::STATUS_FAILED, $log->status);
        $this->assertSame(SixHeroBattleLog::FAILURE_RANKING_OUTCOME, $log->failure_code);
        $this->assertNotNull($log->resolved_at);
        $this->assertFalse($log->is_attacker_win);
        $this->assertSame(9, $log->turn_count);
        $this->assertSame([0, 0, 0, 0], $this->counters($rankings[0]->fresh()));
        $this->assertSame([0, 0, 0, 0], $this->counters($rankings[1]->fresh()));
    }

    public function test_completed_log_failure_does_not_roll_back_the_authoritative_ranking_result(): void
    {
        $season = $this->season();
        [$defender, $attacker] = $this->characters(2);
        $rankings = $this->registerMany(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            [$defender, $attacker],
        );
        $eventName = 'eloquent.updating: '.SixHeroBattleLog::class;
        Event::listen($eventName, static function (SixHeroBattleLog $log): void {
            if ($log->status === SixHeroBattleLog::STATUS_COMPLETED) {
                throw new RuntimeException('forced completed log failure');
            }
        });
        $service = $this->serviceWithBattle(
            fn (): PvPBattleResolution => $this->resolution(true),
        );

        try {
            $service->execute(
                $season,
                SixHeroRoomKey::DIVINE_SPEED,
                $attacker,
                $defender,
            );
            $this->fail('The forced completed-log failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced completed log failure', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertDatabaseHas('six_hero_daily_usages', [
            'character_id' => $attacker->id,
            'official_attempts' => 1,
        ]);
        $log = SixHeroBattleLog::query()->sole();
        $this->assertSame(SixHeroBattleLog::STATUS_RESOLVED, $log->status);
        $this->assertNotNull($log->resolved_at);
        $this->assertNull($log->completed_at);
        $this->assertSame(1, $rankings[1]->fresh()->rank);
        $this->assertSame(2, $rankings[0]->fresh()->rank);
        $this->assertSame([1, 0, 0, 0], $this->counters($rankings[1]->fresh()));
        $this->assertSame([0, 0, 0, 1], $this->counters($rankings[0]->fresh()));
    }

    public function test_battle_started_before_the_deadline_completes_after_it_with_ranking_and_counters(): void
    {
        $season = $this->season(
            '2026-08',
            '2026-08-01 00:00:00',
            '2026-08-19 12:00:01',
        );
        [$defender, $attacker] = $this->characters(2);
        $rankings = $this->registerMany(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            [$defender, $attacker],
        );
        $service = $this->serviceWithBattle(
            function (): PvPBattleResolution {
                Carbon::setTestNow(Carbon::parse('2026-08-19 12:00:01', 'Asia/Tokyo'));

                return $this->resolution(true, 4, 500, 0);
            },
        );

        $result = $service->execute(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $attacker,
            $defender,
        );

        $this->assertNotNull($result->rankChange);
        $this->assertTrue($result->rankChange->rankChanged);
        $this->assertSame(1, $result->officialAttemptsUsed);
        $this->assertSame(4, $result->officialAttemptsRemaining);
        $log = $result->battleLog->fresh();
        $this->assertSame(SixHeroBattleLog::STATUS_COMPLETED, $log->status);
        $this->assertTrue($log->is_attacker_win);
        $this->assertTrue($log->rank_changed);
        $this->assertNotNull($log->resolved_at);
        $this->assertNotNull($log->completed_at);
        $this->assertNull($log->failed_at);
        $this->assertSame([1, 2], $this->roomRanks($season, SixHeroRoomKey::DIVINE_SPEED));
        $this->assertSame($attacker->id, SixHeroRanking::query()
            ->where('season_id', $season->id)
            ->where('room_key', SixHeroRoomKey::DIVINE_SPEED->value)
            ->where('rank', 1)
            ->value('character_id'));
        $this->assertSame([0, 0, 0, 1], $this->counters($rankings[0]->fresh()));
        $this->assertSame([1, 0, 0, 0], $this->counters($rankings[1]->fresh()));
    }

    public function test_every_room_injects_its_exact_rule_and_never_uses_the_arena_facade(): void
    {
        $season = $this->season();
        $ruleClasses = [
            SixHeroRoomKey::SEAL_MAGIC->value => SealMagicPvPRoomRule::class,
            SixHeroRoomKey::SEAL_BLADE->value => SealBladePvPRoomRule::class,
            SixHeroRoomKey::BURNING_LIFE->value => BurningLifePvPRoomRule::class,
            SixHeroRoomKey::DIVINE_SPEED->value => DivineSpeedPvPRoomRule::class,
            SixHeroRoomKey::REVERSE_TIME->value => ReverseTimePvPRoomRule::class,
            SixHeroRoomKey::MIRACLE->value => MiraclePvPRoomRule::class,
        ];
        $expectedByAttacker = [];
        $battles = [];
        foreach (SixHeroRoomKey::cases() as $room) {
            [$defender, $attacker] = $this->characters(2);
            $this->registerMany($season, $room, [$defender, $attacker]);
            $expectedByAttacker[$attacker->id] = [$room, $ruleClasses[$room->value]];
            $battles[] = [$room, $attacker, $defender];
        }
        $service = $this->serviceWithBattle(
            function (
                Character $attacker,
                Character $defender,
                PvPBattleExecutionContext $context,
            ) use ($expectedByAttacker): PvPBattleResolution {
                [$room, $ruleClass] = $expectedByAttacker[$attacker->id];
                $this->assertNotSame($attacker->id, $defender->id);
                $this->assertSame($room->label(), $context->displayLabel);
                $this->assertSame('champ', $context->jobArtContext);
                $this->assertInstanceOf($ruleClass, $context->roomRule);

                return $this->resolution(false);
            },
            6,
        );

        foreach ($battles as [$room, $attacker, $defender]) {
            $service->execute($season, $room, $attacker, $defender);
        }

        $this->assertSame(6, SixHeroBattleLog::query()->count());
        $this->assertTrue(SixHeroBattleLog::query()->get()->every(
            fn (SixHeroBattleLog $log): bool => $log->status === SixHeroBattleLog::STATUS_COMPLETED,
        ));
    }

    public function test_intervening_rank_change_can_complete_a_victory_without_another_rank_change(): void
    {
        $season = $this->season();
        $characters = $this->characters(4);
        $rankings = $this->registerMany(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $characters,
        );
        $service = $this->serviceWithBattle(
            function () use ($rankings): PvPBattleResolution {
                $this->rankingService->applyRankedBattleOutcome(
                    $rankings[3],
                    $rankings[1],
                    true,
                );

                return $this->resolution(true);
            },
        );

        $result = $service->execute(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $characters[3],
            $characters[1],
        );

        $this->assertTrue($result->resolution->attackerWon);
        $this->assertNotNull($result->rankChange);
        $this->assertFalse($result->rankChange->rankChanged);
        $this->assertSame([2, 2, 3, 3], [
            $result->rankChange->attackerOldRank,
            $result->rankChange->attackerNewRank,
            $result->rankChange->defenderOldRank,
            $result->rankChange->defenderNewRank,
        ]);
        $log = $result->battleLog->fresh();
        $this->assertSame(4, $log->attacker_rank_at_start);
        $this->assertSame(2, $log->defender_rank_at_start);
        $this->assertTrue($log->is_attacker_win);
        $this->assertFalse($log->rank_changed);
        $this->assertSame(2, $log->attacker_old_rank);
        $this->assertSame(3, $log->defender_old_rank);
        $this->assertSame(SixHeroBattleLog::STATUS_COMPLETED, $log->status);
    }

    public function test_first_place_victory_updates_champion_timestamps_through_the_ranking_service(): void
    {
        $season = $this->season();
        [$defender, $attacker] = $this->characters(2);
        $rankings = $this->registerMany(
            $season,
            SixHeroRoomKey::MIRACLE,
            [$defender, $attacker],
        );
        $oldChampionSince = $rankings[0]->first_place_since->copy();
        Carbon::setTestNow(Carbon::parse('2026-08-19 18:30:00', 'Asia/Tokyo'));
        $service = $this->serviceWithBattle(
            fn (): PvPBattleResolution => $this->resolution(true),
        );

        $result = $service->execute(
            $season,
            SixHeroRoomKey::MIRACLE,
            $attacker,
            $defender,
        );

        $this->assertTrue($result->rankChange?->rankChanged);
        $this->assertSame(1, $rankings[1]->fresh()->rank);
        $this->assertTrue($rankings[1]->fresh()->first_place_since->equalTo(Carbon::now()));
        $this->assertSame(2, $rankings[0]->fresh()->rank);
        $this->assertNull($rankings[0]->fresh()->first_place_since);
        $this->assertFalse($rankings[1]->fresh()->first_place_since->equalTo($oldChampionSince));
    }

    public function test_daily_usage_database_key_is_character_and_date_with_room_counts_inside_the_row(): void
    {
        [$first, $second] = $this->characters(2);
        $this->insertDailyUsage($first, '2026-08-19', 5);
        $this->insertDailyUsage($second, '2026-08-19', 1);
        $this->insertDailyUsage($first, '2026-08-20', 0);

        $usage = SixHeroDailyUsage::query()->where([
            'character_id' => $first->id,
            'usage_date' => '2026-08-19',
        ])->firstOrFail();
        $this->assertSame(5, $usage->official_attempts);
        $this->assertSame([
            SixHeroRoomKey::SEAL_MAGIC->value => 5,
        ], $usage->official_attempts_by_room);
        $this->assertSame('2026-08-19', $usage->usage_date->toDateString());
        $this->assertTrue($usage->character->is($first));
        $this->assertFalse(Schema::hasColumn('six_hero_daily_usages', 'room_key'));
        $this->assertDatabaseCount('six_hero_daily_usages', 3);

        try {
            DB::table('six_hero_daily_usages')->insert([
                'character_id' => $first->id,
                'usage_date' => '2026-08-19',
                'official_attempts' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The character/date unique constraint was not enforced.');
        } catch (QueryException) {
            $this->assertDatabaseCount('six_hero_daily_usages', 3);
        }
    }

    public function test_phase_4c_migration_can_roll_back_and_reapply(): void
    {
        $migration = require base_path(
            'database/migrations/2026_08_19_130000_create_six_hero_official_battle_tables.php',
        );

        $migration->down();
        try {
            $this->assertFalse(Schema::hasTable('six_hero_battle_logs'));
            $this->assertFalse(Schema::hasTable('six_hero_daily_usages'));
        } finally {
            $migration->up();
        }

        $this->assertTrue(Schema::hasTable('six_hero_daily_usages'));
        $this->assertTrue(Schema::hasTable('six_hero_battle_logs'));
        $this->assertTrue(Schema::hasColumns('six_hero_battle_logs', [
            'season_id',
            'room_key',
            'battle_mode',
            'status',
            'attacker_id',
            'defender_id',
            'attacker_rank_at_start',
            'defender_rank_at_start',
            'daily_attempt_number',
            'started_at',
            'resolved_at',
            'completed_at',
            'failed_at',
            'failure_code',
            'metadata',
        ]));
        $this->assertTrue(Schema::hasColumn(
            'six_hero_daily_usages',
            'official_attempts_by_room',
        ));
    }

    public function test_room_attempt_migration_can_roll_back_and_reapply(): void
    {
        $migration = require base_path(
            'database/migrations/2026_08_20_130000_add_room_attempts_to_six_hero_daily_usages.php',
        );

        $migration->down();
        try {
            $this->assertFalse(Schema::hasColumn(
                'six_hero_daily_usages',
                'official_attempts_by_room',
            ));
        } finally {
            $migration->up();
        }

        $this->assertTrue(Schema::hasColumn(
            'six_hero_daily_usages',
            'official_attempts_by_room',
        ));
    }

    private function serviceWithBattle(callable $battle, int $times = 1): SixHeroOfficialBattleService
    {
        $pvpBattleService = Mockery::mock(PvPBattleService::class);
        $pvpBattleService->shouldNotReceive('executeBattle');
        $pvpBattleService->shouldReceive('resolveBattle')
            ->times($times)
            ->andReturnUsing($battle);

        return new SixHeroOfficialBattleService(
            $pvpBattleService,
            new SixHeroBattleContextFactory(new SixHeroRoomRuleResolver),
            $this->rankingService,
        );
    }

    private function serviceWithoutBattle(): SixHeroOfficialBattleService
    {
        $pvpBattleService = Mockery::mock(PvPBattleService::class);
        $pvpBattleService->shouldNotReceive('executeBattle');
        $pvpBattleService->shouldNotReceive('resolveBattle');

        return new SixHeroOfficialBattleService(
            $pvpBattleService,
            new SixHeroBattleContextFactory(new SixHeroRoomRuleResolver),
            $this->rankingService,
        );
    }

    private function resolution(
        bool $attackerWon,
        int $turnCount = 3,
        int $attackerHp = 500,
        int $defenderHp = 400,
    ): PvPBattleResolution {
        return new PvPBattleResolution(
            result: new BattleResult,
            attackerWon: $attackerWon,
            turnCount: $turnCount,
            attackerHp: $attackerHp,
            attackerMaxHp: 1000,
            defenderHp: $defenderHp,
            defenderMaxHp: 1000,
        );
    }

    private function season(
        string $key = '2026-08',
        string $startsAt = '2026-08-01 00:00:00',
        string $endsAt = '2026-09-01 00:00:00',
        bool $initialized = true,
    ): SixHeroSeason {
        return SixHeroSeason::query()->create([
            'season_key' => $key,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'finalized_at' => null,
            'ranking_initialized_at' => $initialized ? $startsAt : null,
        ]);
    }

    /** @return array<int, Character> */
    private function characters(int $count): array
    {
        $characters = [];
        for ($index = 1; $index <= $count; $index++) {
            $user = User::factory()->create();
            $characters[] = Character::query()->create([
                'user_id' => $user->id,
                'name' => "六英雄公式戦検証{$user->id}",
            ]);
        }

        return $characters;
    }

    /**
     * @param  array<int, Character>  $characters
     * @return array<int, SixHeroRanking>
     */
    private function registerMany(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
        array $characters,
    ): array {
        return array_map(
            fn (Character $character): SixHeroRanking => $this->rankingService->register(
                $season,
                $room,
                $character,
            ),
            $characters,
        );
    }

    /** @return array{int,int,int,int} */
    private function counters(SixHeroRanking $ranking): array
    {
        return [
            $ranking->official_attack_wins,
            $ranking->official_attack_losses,
            $ranking->defense_wins,
            $ranking->defense_losses,
        ];
    }

    /** @return array<int, int> */
    private function roomRanks(SixHeroSeason $season, SixHeroRoomKey $room): array
    {
        return SixHeroRanking::query()
            ->where('season_id', $season->id)
            ->where('room_key', $room->value)
            ->orderBy('rank')
            ->pluck('rank')
            ->all();
    }

    /** @return array<int, int> */
    private function orderedCharacterIds(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
    ): array {
        return SixHeroRanking::query()
            ->where('season_id', $season->id)
            ->where('room_key', $room->value)
            ->orderBy('rank')
            ->pluck('character_id')
            ->all();
    }

    private function assertDomainFailure(callable $operation): void
    {
        try {
            $operation();
            $this->fail('A DomainException was not thrown.');
        } catch (DomainException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    private function insertDailyUsage(
        Character $character,
        string $usageDate,
        int $officialAttempts,
        SixHeroRoomKey $room = SixHeroRoomKey::SEAL_MAGIC,
    ): void {
        DB::table('six_hero_daily_usages')->insert([
            'character_id' => $character->id,
            'usage_date' => $usageDate,
            'official_attempts' => $officialAttempts,
            'official_attempts_by_room' => json_encode([
                $room->value => $officialAttempts,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
