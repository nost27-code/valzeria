<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Exceptions\SixHeroRankingNotReadyException;
use App\Models\ArenaLog;
use App\Models\ArenaRanking;
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
use App\Services\SixHeroOfficialBattleService;
use App\Services\SixHeroPracticeBattleService;
use App\Services\SixHeroRankingService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class SixHeroPracticeBattleServiceTest extends TestCase
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

    public function test_any_other_character_registered_in_the_same_season_and_room_can_practice(): void
    {
        $season = $this->season();
        $characters = $this->characters(10);
        $rankings = $this->registerMany(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $characters,
        );
        $service = $this->practiceServiceWithBattle(
            fn (): PvPBattleResolution => $this->resolution(false),
            7,
        );

        foreach ([0, 1, 4, 8] as $defenderIndex) {
            $result = $service->execute(
                $season,
                SixHeroRoomKey::DIVINE_SPEED,
                $characters[9],
                $characters[$defenderIndex],
            );
            $this->assertSame(SixHeroRoomKey::DIVINE_SPEED, $result->room);
        }

        foreach ([5, 9] as $defenderIndex) {
            $service->execute(
                $season,
                SixHeroRoomKey::DIVINE_SPEED,
                $characters[4],
                $characters[$defenderIndex],
            );
        }

        $service->execute(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $characters[0],
            $characters[9],
        );

        $this->assertSame(range(1, 10), array_map(
            static fn (SixHeroRanking $ranking): int => (int) $ranking->fresh()->rank,
            $rankings,
        ));
        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 0);
    }

    public function test_pending_previous_month_blocks_practice_without_competitive_side_effects(): void
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
            $this->practiceServiceWithoutBattle()->execute(
                $target,
                SixHeroRoomKey::DIVINE_SPEED,
                $attacker,
                $defender,
            );
            $this->fail('A practice battle started before ranking initialization was ready.');
        } catch (SixHeroRankingNotReadyException) {
            $this->assertNull($target->fresh()->ranking_initialized_at);
        }

        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 1);
        $this->assertDatabaseMissing('six_hero_rankings', [
            'season_id' => $target->id,
        ]);
    }

    public function test_validation_rejects_self_unregistered_other_room_other_season_and_inactive_seasons(): void
    {
        $active = $this->season('active');
        [
            $attacker,
            $defender,
            $unregisteredAttacker,
            $unregisteredDefender,
            $otherRoomDefender,
            $otherSeasonDefender,
            $scheduledAttacker,
            $scheduledDefender,
            $endedAttacker,
            $endedDefender,
            $finalizedAttacker,
            $finalizedDefender,
        ] = $this->characters(12);
        $this->registerMany(
            $active,
            SixHeroRoomKey::DIVINE_SPEED,
            [$attacker, $defender],
        );
        $this->registerMany(
            $active,
            SixHeroRoomKey::MIRACLE,
            [$otherRoomDefender],
        );

        $otherSeason = $this->season('other-season');
        $this->registerMany(
            $otherSeason,
            SixHeroRoomKey::DIVINE_SPEED,
            [$otherSeasonDefender],
        );

        $scheduled = $this->season(
            'scheduled',
            '2026-08-20 00:00:00',
            '2026-09-01 00:00:00',
        );
        $this->registerMany(
            $scheduled,
            SixHeroRoomKey::DIVINE_SPEED,
            [$scheduledAttacker, $scheduledDefender],
        );

        $ended = $this->season(
            'ended',
            '2026-07-01 00:00:00',
            '2026-08-19 12:00:00',
        );
        $this->registerMany(
            $ended,
            SixHeroRoomKey::DIVINE_SPEED,
            [$endedAttacker, $endedDefender],
        );

        $finalized = $this->season('finalized');
        $this->registerMany(
            $finalized,
            SixHeroRoomKey::DIVINE_SPEED,
            [$finalizedAttacker, $finalizedDefender],
        );
        SixHeroSeason::query()->whereKey($finalized->id)->update([
            'finalized_at' => Carbon::now(),
        ]);

        $service = $this->practiceServiceWithoutBattle();
        $operations = [
            fn () => $service->execute(
                $active,
                SixHeroRoomKey::DIVINE_SPEED,
                $attacker,
                $attacker,
            ),
            fn () => $service->execute(
                $active,
                SixHeroRoomKey::DIVINE_SPEED,
                $unregisteredAttacker,
                $defender,
            ),
            fn () => $service->execute(
                $active,
                SixHeroRoomKey::DIVINE_SPEED,
                $attacker,
                $unregisteredDefender,
            ),
            fn () => $service->execute(
                $active,
                SixHeroRoomKey::DIVINE_SPEED,
                $attacker,
                $otherRoomDefender,
            ),
            fn () => $service->execute(
                $active,
                SixHeroRoomKey::DIVINE_SPEED,
                $attacker,
                $otherSeasonDefender,
            ),
            fn () => $service->execute(
                $scheduled,
                SixHeroRoomKey::DIVINE_SPEED,
                $scheduledAttacker,
                $scheduledDefender,
            ),
            fn () => $service->execute(
                $ended,
                SixHeroRoomKey::DIVINE_SPEED,
                $endedAttacker,
                $endedDefender,
            ),
            fn () => $service->execute(
                $finalized,
                SixHeroRoomKey::DIVINE_SPEED,
                $finalizedAttacker,
                $finalizedDefender,
            ),
        ];

        foreach ($operations as $operation) {
            $this->assertDomainFailure($operation);
        }

        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 0);
    }

    public function test_season_start_is_inclusive_and_a_battle_may_finish_after_the_deadline(): void
    {
        $season = $this->season(
            'boundary',
            '2026-08-19 12:00:00',
            '2026-08-19 12:00:01',
        );
        [$attacker, $defender] = $this->characters(2);
        $this->registerMany(
            $season,
            SixHeroRoomKey::MIRACLE,
            [$attacker, $defender],
        );
        $service = $this->practiceServiceWithBattle(function (): PvPBattleResolution {
            Carbon::setTestNow(Carbon::parse('2026-08-19 12:00:01', 'Asia/Tokyo'));

            return $this->resolution(true);
        });

        $result = $service->execute(
            $season,
            SixHeroRoomKey::MIRACLE,
            $attacker,
            $defender,
        );

        $this->assertTrue($result->resolution->attackerWon);
        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 0);
    }

    public function test_one_hundred_practice_battles_do_not_change_competitive_or_character_state(): void
    {
        $season = $this->season();
        [$defender, $attacker] = $this->characters(2);
        $attacker->forceFill(['current_hp' => 77, 'current_mp' => 33])->save();
        $defender->forceFill(['current_hp' => 88, 'current_mp' => 44])->save();
        [$defenderRanking, $attackerRanking] = $this->registerMany(
            $season,
            SixHeroRoomKey::REVERSE_TIME,
            [$defender, $attacker],
        );
        $defenderRanking->forceFill([
            'official_attack_wins' => 2,
            'official_attack_losses' => 3,
            'defense_wins' => 5,
            'defense_losses' => 7,
        ])->save();
        $attackerRanking->forceFill([
            'official_attack_wins' => 11,
            'official_attack_losses' => 13,
            'defense_wins' => 17,
            'defense_losses' => 19,
        ])->save();
        SixHeroDailyUsage::query()->create([
            'character_id' => $attacker->id,
            'usage_date' => '2026-08-19',
            'official_attempts' => 5,
            'official_attempts_by_room' => [
                SixHeroRoomKey::REVERSE_TIME->value => 5,
            ],
        ]);
        $defenderBefore = $this->rankingSnapshot($defenderRanking);
        $attackerBefore = $this->rankingSnapshot($attackerRanking);
        $service = $this->practiceServiceWithBattle(
            fn (): PvPBattleResolution => $this->resolution(true),
            100,
        );

        for ($battle = 0; $battle < 100; $battle++) {
            $service->execute(
                $season,
                SixHeroRoomKey::REVERSE_TIME,
                $attacker,
                $defender,
            );
        }

        $this->assertSame($defenderBefore, $this->rankingSnapshot($defenderRanking));
        $this->assertSame($attackerBefore, $this->rankingSnapshot($attackerRanking));
        $dailyUsage = SixHeroDailyUsage::query()
            ->where('character_id', $attacker->id)
            ->firstOrFail();
        $this->assertSame('2026-08-19', $dailyUsage->usage_date->toDateString());
        $this->assertSame(5, $dailyUsage->official_attempts);
        $this->assertDatabaseCount('six_hero_daily_usages', 1);
        $this->assertDatabaseCount('six_hero_battle_logs', 0);
        $this->assertSame(77, $attacker->fresh()->current_hp);
        $this->assertSame(33, $attacker->fresh()->current_mp);
        $this->assertSame(88, $defender->fresh()->current_hp);
        $this->assertSame(44, $defender->fresh()->current_mp);
        $this->assertSame(0, ArenaRanking::query()->count());
        $this->assertSame(0, ArenaLog::query()->count());
    }

    public function test_battle_exception_is_propagated_without_any_database_side_effect(): void
    {
        $season = $this->season();
        [$defender, $attacker] = $this->characters(2);
        [$defenderRanking, $attackerRanking] = $this->registerMany(
            $season,
            SixHeroRoomKey::SEAL_MAGIC,
            [$defender, $attacker],
        );
        $defenderBefore = $this->rankingSnapshot($defenderRanking);
        $attackerBefore = $this->rankingSnapshot($attackerRanking);
        $service = $this->practiceServiceWithBattle(
            static fn (): never => throw new RuntimeException('practice battle failed'),
        );

        try {
            $service->execute(
                $season,
                SixHeroRoomKey::SEAL_MAGIC,
                $attacker,
                $defender,
            );
            $this->fail('The battle exception was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('practice battle failed', $exception->getMessage());
        }

        $this->assertSame($defenderBefore, $this->rankingSnapshot($defenderRanking));
        $this->assertSame($attackerBefore, $this->rankingSnapshot($attackerRanking));
        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 0);
        $this->assertSame(0, ArenaRanking::query()->count());
        $this->assertSame(0, ArenaLog::query()->count());
    }

    public function test_every_room_uses_the_exact_fresh_rule_and_the_same_context_contract(): void
    {
        $season = $this->season();
        [$defender, $attacker] = $this->characters(2);
        foreach (SixHeroRoomKey::cases() as $room) {
            $this->registerMany($season, $room, [$defender, $attacker]);
        }
        $contexts = [];
        $service = $this->practiceServiceWithBattle(
            function (
                Character $actualAttacker,
                Character $actualDefender,
                PvPBattleExecutionContext $context,
            ) use (&$contexts, $attacker, $defender): PvPBattleResolution {
                $this->assertTrue($actualAttacker->is($attacker));
                $this->assertTrue($actualDefender->is($defender));
                $contexts[] = $context;

                return $this->resolution(false);
            },
            7,
        );
        $ruleClasses = [
            SealMagicPvPRoomRule::class,
            SealBladePvPRoomRule::class,
            BurningLifePvPRoomRule::class,
            DivineSpeedPvPRoomRule::class,
            ReverseTimePvPRoomRule::class,
            MiraclePvPRoomRule::class,
        ];

        foreach (SixHeroRoomKey::cases() as $room) {
            $service->execute($season, $room, $attacker, $defender);
        }
        $service->execute(
            $season,
            SixHeroRoomKey::BURNING_LIFE,
            $attacker,
            $defender,
        );

        foreach (SixHeroRoomKey::cases() as $index => $room) {
            $this->assertSame($room->label(), $contexts[$index]->displayLabel);
            $this->assertSame('champ', $contexts[$index]->jobArtContext);
            $this->assertInstanceOf($ruleClasses[$index], $contexts[$index]->roomRule);
        }
        $this->assertInstanceOf(BurningLifePvPRoomRule::class, $contexts[6]->roomRule);
        $this->assertNotSame($contexts[2]->roomRule, $contexts[6]->roomRule);
    }

    public function test_official_and_practice_share_context_and_return_identical_resolutions(): void
    {
        $season = $this->season();
        [$defender, $attacker] = $this->characters(2);
        [$defenderRanking, $attackerRanking] = $this->registerMany(
            $season,
            SixHeroRoomKey::MIRACLE,
            [$defender, $attacker],
        );
        $contexts = [];
        $pvpBattleService = Mockery::mock(PvPBattleService::class);
        $pvpBattleService->shouldNotReceive('executeBattle');
        $pvpBattleService->shouldReceive('resolveBattle')
            ->twice()
            ->andReturnUsing(function (
                Character $actualAttacker,
                Character $actualDefender,
                PvPBattleExecutionContext $context,
            ) use (&$contexts, $attacker, $defender): PvPBattleResolution {
                $this->assertTrue($actualAttacker->is($attacker));
                $this->assertTrue($actualDefender->is($defender));
                $contexts[] = $context;

                return $this->resolution(true);
            });
        $contextFactory = $this->contextFactory();
        $officialService = new SixHeroOfficialBattleService(
            $pvpBattleService,
            $contextFactory,
            $this->rankingService,
        );
        $practiceService = new SixHeroPracticeBattleService(
            $pvpBattleService,
            $contextFactory,
        );

        $official = $officialService->execute(
            $season,
            SixHeroRoomKey::MIRACLE,
            $attacker,
            $defender,
        );
        $afterOfficialAttacker = $this->rankingSnapshot($attackerRanking);
        $afterOfficialDefender = $this->rankingSnapshot($defenderRanking);
        $practice = $practiceService->execute(
            $season,
            SixHeroRoomKey::MIRACLE,
            $attacker,
            $defender,
        );

        $this->assertResolutionSame($official->resolution, $practice->resolution);
        $this->assertSame(SixHeroRoomKey::MIRACLE, $practice->room);
        $this->assertSame('奇跡の間', $contexts[0]->displayLabel);
        $this->assertSame($contexts[0]->displayLabel, $contexts[1]->displayLabel);
        $this->assertSame($contexts[0]->jobArtContext, $contexts[1]->jobArtContext);
        $this->assertSame('champ', $contexts[0]->jobArtContext);
        $this->assertInstanceOf(MiraclePvPRoomRule::class, $contexts[0]->roomRule);
        $this->assertInstanceOf(MiraclePvPRoomRule::class, $contexts[1]->roomRule);
        $this->assertNotSame($contexts[0]->roomRule, $contexts[1]->roomRule);
        $this->assertSame($afterOfficialAttacker, $this->rankingSnapshot($attackerRanking));
        $this->assertSame($afterOfficialDefender, $this->rankingSnapshot($defenderRanking));
        $this->assertDatabaseHas('six_hero_daily_usages', [
            'character_id' => $attacker->id,
            'official_attempts' => 1,
        ]);
        $this->assertDatabaseCount('six_hero_daily_usages', 1);
        $this->assertDatabaseCount('six_hero_battle_logs', 1);
        $this->assertSame(
            SixHeroBattleLog::STATUS_COMPLETED,
            SixHeroBattleLog::query()->sole()->status,
        );
    }

    public function test_practice_service_has_no_competitive_persistence_or_arena_facade_calls(): void
    {
        $source = file_get_contents(app_path('Services/SixHeroPracticeBattleService.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('resolveBattle(', $source);
        $this->assertStringNotContainsString('executeBattle(', $source);
        $this->assertStringNotContainsString('SixHeroDailyUsage', $source);
        $this->assertStringNotContainsString('SixHeroBattleLog', $source);
        $this->assertStringNotContainsString('SixHeroRankingService', $source);
        $this->assertStringNotContainsString('isChallengeTarget(', $source);
        $this->assertStringNotContainsString('applyRankedBattleOutcome(', $source);
    }

    private function practiceServiceWithBattle(
        callable $battle,
        int $times = 1,
    ): SixHeroPracticeBattleService {
        $pvpBattleService = Mockery::mock(PvPBattleService::class);
        $pvpBattleService->shouldNotReceive('executeBattle');
        $pvpBattleService->shouldReceive('resolveBattle')
            ->times($times)
            ->andReturnUsing($battle);

        return new SixHeroPracticeBattleService(
            $pvpBattleService,
            $this->contextFactory(),
        );
    }

    private function practiceServiceWithoutBattle(): SixHeroPracticeBattleService
    {
        $pvpBattleService = Mockery::mock(PvPBattleService::class);
        $pvpBattleService->shouldNotReceive('executeBattle');
        $pvpBattleService->shouldNotReceive('resolveBattle');

        return new SixHeroPracticeBattleService(
            $pvpBattleService,
            $this->contextFactory(),
        );
    }

    private function contextFactory(): SixHeroBattleContextFactory
    {
        return new SixHeroBattleContextFactory(new SixHeroRoomRuleResolver);
    }

    private function resolution(bool $attackerWon): PvPBattleResolution
    {
        $result = new BattleResult;
        $result->result = $attackerWon ? 'victory' : 'defeat';
        $result->logs = ['固定戦闘ログ1', '固定戦闘ログ2'];
        $result->turnCount = 7;
        $result->jobArtV2Hud = [
            'player' => ['active' => ['同一戦技状態']],
            'enemy' => ['active' => []],
        ];

        return new PvPBattleResolution(
            result: $result,
            attackerWon: $attackerWon,
            turnCount: 7,
            attackerHp: 765,
            attackerMaxHp: 1000,
            defenderHp: 234,
            defenderMaxHp: 1200,
        );
    }

    private function assertResolutionSame(
        PvPBattleResolution $expected,
        PvPBattleResolution $actual,
    ): void {
        $this->assertSame($expected->attackerWon, $actual->attackerWon);
        $this->assertSame($expected->turnCount, $actual->turnCount);
        $this->assertSame($expected->attackerHp, $actual->attackerHp);
        $this->assertSame($expected->attackerMaxHp, $actual->attackerMaxHp);
        $this->assertSame($expected->defenderHp, $actual->defenderHp);
        $this->assertSame($expected->defenderMaxHp, $actual->defenderMaxHp);
        $this->assertSame($expected->result->result, $actual->result->result);
        $this->assertSame($expected->result->logs, $actual->result->logs);
        $this->assertSame($expected->result->jobArtV2Hud, $actual->result->jobArtV2Hud);
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
                'name' => "六英雄練習戦検証{$user->id}",
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

    /** @return array<string, int|string|null> */
    private function rankingSnapshot(SixHeroRanking $ranking): array
    {
        $fresh = $ranking->fresh();

        return [
            'rank' => (int) $fresh->rank,
            'official_attack_wins' => (int) $fresh->official_attack_wins,
            'official_attack_losses' => (int) $fresh->official_attack_losses,
            'defense_wins' => (int) $fresh->defense_wins,
            'defense_losses' => (int) $fresh->defense_losses,
            'first_place_since' => $fresh->first_place_since?->toISOString(),
        ];
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
}
