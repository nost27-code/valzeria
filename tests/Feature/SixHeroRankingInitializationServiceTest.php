<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Exceptions\SixHeroRankingNotReadyException;
use App\Models\Character;
use App\Models\SixHeroBattleLog;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Models\User;
use App\Services\SixHeroRankingInitializationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class SixHeroRankingInitializationServiceTest extends TestCase
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

    public function test_finalized_previous_rankings_copy_all_rooms_dense_and_reset_monthly_fields(): void
    {
        $previous = $this->season('2026-08', finalized: true);
        $target = $this->season('2026-09');
        $characters = $this->characters(9);
        $assignments = [
            SixHeroRoomKey::SEAL_MAGIC->value => [
                [$characters[0], 1],
                [$characters[1], 4],
            ],
            SixHeroRoomKey::SEAL_BLADE->value => [
                [$characters[2], 8],
            ],
            SixHeroRoomKey::BURNING_LIFE->value => [],
            SixHeroRoomKey::DIVINE_SPEED->value => [
                [$characters[3], 1],
                [$characters[4], 7],
                [$characters[5], 20],
            ],
            SixHeroRoomKey::REVERSE_TIME->value => [
                [$characters[6], 2],
                [$characters[7], 9],
            ],
            SixHeroRoomKey::MIRACLE->value => [
                [$characters[8], 99],
            ],
        ];

        foreach (SixHeroRoomKey::cases() as $room) {
            foreach ($assignments[$room->value] as [$character, $rank]) {
                $this->ranking(
                    $previous,
                    $room,
                    $character,
                    $rank,
                    counters: [11, 12, 13, 14],
                    registeredAt: '2026-08-15 12:00:00',
                    firstPlaceSince: '2026-08-20 08:00:00',
                );
            }
        }

        $result = $this->service()->initialize(
            $target,
            Carbon::parse('2026-09-01 00:07:00', 'Asia/Tokyo'),
        );

        $this->assertTrue($result->initialized);
        $this->assertFalse($result->alreadyInitialized);
        $this->assertFalse($result->waitingForPreviousFinalization);
        $this->assertTrue($result->sourceSeason->is($previous));
        $this->assertSame(9, $result->copiedRankingCount);
        $this->assertTrue(
            $target->fresh()->ranking_initialized_at->equalTo(
                Carbon::parse('2026-09-01 00:07:00', 'Asia/Tokyo'),
            ),
        );

        foreach (SixHeroRoomKey::cases() as $room) {
            $copied = $this->rankingsFor($target, $room);
            $expectedCharacters = array_map(
                static fn (array $assignment): int => $assignment[0]->id,
                $assignments[$room->value],
            );
            $this->assertSame($expectedCharacters, $copied->pluck('character_id')->all());
            $this->assertSame(
                $expectedCharacters === [] ? [] : range(1, count($expectedCharacters)),
                $copied->pluck('rank')->all(),
            );
            foreach ($copied as $index => $ranking) {
                $this->assertSame([0, 0, 0, 0], $this->counters($ranking));
                $this->assertTrue($ranking->registered_at->equalTo($target->starts_at));
                if ($index === 0) {
                    $this->assertTrue($ranking->first_place_since->equalTo($target->starts_at));
                } else {
                    $this->assertNull($ranking->first_place_since);
                }
            }
        }

        $this->assertSame(
            [1, 4],
            $this->rankingsFor($previous, SixHeroRoomKey::SEAL_MAGIC)->pluck('rank')->all(),
        );
        $this->assertSame(
            [11, 12, 13, 14],
            $this->counters(
                $this->rankingsFor($previous, SixHeroRoomKey::SEAL_MAGIC)->firstOrFail(),
            ),
        );
    }

    public function test_missing_exact_previous_month_initializes_empty_without_skipping_back(): void
    {
        $july = $this->season('2026-07', finalized: true);
        $target = $this->season('2026-09');
        $this->ranking(
            $july,
            SixHeroRoomKey::DIVINE_SPEED,
            $this->characters(1)[0],
            1,
        );

        $result = $this->service()->initialize($target);

        $this->assertTrue($result->initialized);
        $this->assertNull($result->sourceSeason);
        $this->assertSame(0, $result->copiedRankingCount);
        $this->assertNotNull($target->fresh()->ranking_initialized_at);
        $this->assertDatabaseCount('six_hero_rankings', 1);
        $this->assertDatabaseMissing('six_hero_rankings', [
            'season_id' => $target->id,
        ]);
    }

    public function test_unfinalized_previous_without_pending_battles_is_finalized_then_copied(): void
    {
        $previous = $this->season('2026-08');
        $target = $this->season('2026-09');
        $characters = $this->characters(2);
        $this->ranking($previous, SixHeroRoomKey::MIRACLE, $characters[0], 1);
        $this->ranking($previous, SixHeroRoomKey::MIRACLE, $characters[1], 2);

        $result = $this->service()->initialize($target);

        $this->assertTrue($result->initialized);
        $this->assertFalse($result->waitingForPreviousFinalization);
        $this->assertNotNull($previous->fresh()->finalized_at);
        $this->assertDatabaseCount('six_hero_champions', 6);
        $this->assertSame(
            $characters->pluck('id')->all(),
            $this->rankingsFor($target, SixHeroRoomKey::MIRACLE)
                ->pluck('character_id')
                ->all(),
        );
    }

    public function test_pending_previous_waits_without_target_side_effects_and_retry_succeeds(): void
    {
        $previous = $this->season('2026-08');
        $target = $this->season('2026-09');
        $characters = $this->characters(2);
        $this->ranking($previous, SixHeroRoomKey::DIVINE_SPEED, $characters[0], 1);
        $this->ranking($previous, SixHeroRoomKey::DIVINE_SPEED, $characters[1], 2);
        $pending = $this->pendingBattle($previous, $characters[1], $characters[0]);

        $waiting = $this->service()->initialize($target);

        $this->assertFalse($waiting->initialized);
        $this->assertFalse($waiting->alreadyInitialized);
        $this->assertTrue($waiting->waitingForPreviousFinalization);
        $this->assertTrue($waiting->sourceSeason->is($previous));
        $this->assertSame(0, $waiting->copiedRankingCount);
        $this->assertNull($target->fresh()->ranking_initialized_at);
        $this->assertDatabaseMissing('six_hero_rankings', ['season_id' => $target->id]);
        $this->expectNotReady(fn () => $this->service()->requireInitialized($target));

        $pending->update([
            'status' => SixHeroBattleLog::STATUS_FAILED,
            'failed_at' => Carbon::now(),
            'failure_code' => SixHeroBattleLog::FAILURE_BATTLE_RUNTIME,
        ]);
        $ready = $this->service()->initialize($target);

        $this->assertTrue($ready->initialized);
        $this->assertNotNull($previous->fresh()->finalized_at);
        $this->assertSame(2, $ready->copiedRankingCount);
        $this->assertSame(2, SixHeroRanking::query()
            ->where('season_id', $target->id)
            ->count());
    }

    public function test_non_positive_source_rank_is_rejected_without_target_changes(): void
    {
        $previous = $this->season('2026-08', finalized: true);
        $target = $this->season('2026-09');
        $this->ranking(
            $previous,
            SixHeroRoomKey::REVERSE_TIME,
            $this->characters(1)[0],
            -123,
        );

        try {
            $this->service()->initialize($target);
            $this->fail('A non-positive finalized rank was copied.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('positive', $exception->getMessage());
        }

        $this->assertNull($target->fresh()->ranking_initialized_at);
        $this->assertDatabaseMissing('six_hero_rankings', ['season_id' => $target->id]);
    }

    public function test_existing_target_rows_are_not_merged_with_non_empty_source(): void
    {
        $previous = $this->season('2026-08', finalized: true);
        $target = $this->season('2026-09');
        [$previousCharacter, $targetCharacter] = $this->characters(2);
        $this->ranking($previous, SixHeroRoomKey::SEAL_MAGIC, $previousCharacter, 1);
        $targetRanking = $this->ranking(
            $target,
            SixHeroRoomKey::SEAL_MAGIC,
            $targetCharacter,
            1,
        );

        try {
            $this->service()->initialize($target);
            $this->fail('Ambiguous target rankings were merged.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('already has Ranking rows', $exception->getMessage());
        }

        $this->assertNull($target->fresh()->ranking_initialized_at);
        $this->assertSame([$targetRanking->id], SixHeroRanking::query()
            ->where('season_id', $target->id)
            ->pluck('id')
            ->all());
    }

    public function test_empty_source_allows_legacy_target_rows_and_marks_ready_without_merging(): void
    {
        $this->season('2026-08', finalized: true);
        $target = $this->season('2026-09');
        $existing = $this->ranking(
            $target,
            SixHeroRoomKey::SEAL_BLADE,
            $this->characters(1)[0],
            1,
        );

        $result = $this->service()->initialize($target);

        $this->assertTrue($result->initialized);
        $this->assertSame(0, $result->copiedRankingCount);
        $this->assertSame([$existing->id], SixHeroRanking::query()
            ->where('season_id', $target->id)
            ->pluck('id')
            ->all());
    }

    public function test_initialization_is_idempotent_one_hundred_times(): void
    {
        $previous = $this->season('2026-08', finalized: true);
        $target = $this->season('2026-09');
        $characters = $this->characters(2);
        $this->ranking($previous, SixHeroRoomKey::BURNING_LIFE, $characters[0], 1);
        $this->ranking($previous, SixHeroRoomKey::BURNING_LIFE, $characters[1], 2);
        $first = $this->service()->initialize($target);
        $initializedAt = $first->season->ranking_initialized_at->copy();
        $snapshot = SixHeroRanking::query()
            ->where('season_id', $target->id)
            ->orderBy('id')
            ->get()
            ->map->getRawOriginal()
            ->all();

        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00:00', 'Asia/Tokyo'));
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $again = $this->service()->initialize($target);
            $this->assertTrue($again->initialized);
            $this->assertTrue($again->alreadyInitialized);
            $this->assertSame(0, $again->copiedRankingCount);
        }

        $this->assertTrue($target->fresh()->ranking_initialized_at->equalTo($initializedAt));
        $this->assertEquals($snapshot, SixHeroRanking::query()
            ->where('season_id', $target->id)
            ->orderBy('id')
            ->get()
            ->map->getRawOriginal()
            ->all());
    }

    public function test_copy_exception_rolls_back_all_rooms_and_initialized_timestamp(): void
    {
        $previous = $this->season('2026-08', finalized: true);
        $target = $this->season('2026-09');
        $characters = $this->characters(6);
        foreach (SixHeroRoomKey::cases() as $index => $room) {
            $this->ranking($previous, $room, $characters[$index], 1);
        }
        $created = 0;
        $eventName = 'eloquent.creating: '.SixHeroRanking::class;
        Event::listen($eventName, static function (SixHeroRanking $ranking) use (
            $target,
            &$created,
        ): void {
            if ((int) $ranking->season_id !== (int) $target->id) {
                return;
            }
            $created++;
            if ($created === 4) {
                throw new RuntimeException('forced carryover failure');
            }
        });

        try {
            $this->service()->initialize($target);
            $this->fail('The forced carryover failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced carryover failure', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertNull($target->fresh()->ranking_initialized_at);
        $this->assertDatabaseMissing('six_hero_rankings', ['season_id' => $target->id]);
        $this->assertSame(6, SixHeroRanking::query()
            ->where('season_id', $previous->id)
            ->count());
    }

    public function test_deleted_character_is_excluded_and_survivors_are_dense(): void
    {
        $previous = $this->season('2026-08', finalized: true);
        $target = $this->season('2026-09');
        $characters = $this->characters(3);
        foreach ($characters as $index => $character) {
            $this->ranking(
                $previous,
                SixHeroRoomKey::DIVINE_SPEED,
                $character,
                $index + 1,
            );
        }

        $characters[1]->delete();
        $this->service()->initialize($target);

        $this->assertSame(
            [$characters[0]->id, $characters[2]->id],
            $this->rankingsFor($target, SixHeroRoomKey::DIVINE_SPEED)
                ->pluck('character_id')
                ->all(),
        );
        $this->assertSame(
            [1, 2],
            $this->rankingsFor($target, SixHeroRoomKey::DIVINE_SPEED)
                ->pluck('rank')
                ->all(),
        );
    }

    public function test_ranking_initialized_migration_contract_and_rollback(): void
    {
        $this->assertTrue(Schema::hasColumn(
            'six_hero_seasons',
            'ranking_initialized_at',
        ));
        $this->assertTrue(Schema::hasIndex(
            'six_hero_seasons',
            'six_hero_seasons_ranking_initialized_at_idx',
        ));
        $season = $this->season('2026-09');
        $this->assertNull($season->ranking_initialized_at);

        $migration = require base_path(
            'database/migrations/2026_08_19_160000_add_ranking_initialized_at_to_six_hero_seasons_table.php',
        );
        $migration->down();
        try {
            $this->assertFalse(Schema::hasColumn(
                'six_hero_seasons',
                'ranking_initialized_at',
            ));

            // Simulate DDL stopping after the column was created but before its index.
            Schema::table('six_hero_seasons', function (Blueprint $table): void {
                $table->timestamp('ranking_initialized_at')->nullable();
            });
            $this->assertFalse(Schema::hasIndex(
                'six_hero_seasons',
                'six_hero_seasons_ranking_initialized_at_idx',
            ));
            $migration->up();
        } finally {
            if (! Schema::hasColumn('six_hero_seasons', 'ranking_initialized_at')
                || ! Schema::hasIndex(
                    'six_hero_seasons',
                    'six_hero_seasons_ranking_initialized_at_idx',
                )
            ) {
                $migration->up();
            }
        }

        $this->assertTrue(Schema::hasColumn(
            'six_hero_seasons',
            'ranking_initialized_at',
        ));
        $this->assertTrue(Schema::hasIndex(
            'six_hero_seasons',
            'six_hero_seasons_ranking_initialized_at_idx',
        ));
        $this->assertNull(SixHeroSeason::query()->findOrFail($season->id)->ranking_initialized_at);
    }

    private function service(): SixHeroRankingInitializationService
    {
        return app(SixHeroRankingInitializationService::class);
    }

    private function season(
        string $key,
        bool $finalized = false,
    ): SixHeroSeason {
        $startsAt = Carbon::parse("{$key}-01 00:00:00", 'Asia/Tokyo')->startOfMonth();
        $endsAt = $startsAt->copy()->addMonth();

        return SixHeroSeason::query()->create([
            'season_key' => $key,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'finalized_at' => $finalized ? $endsAt->copy()->addMinute() : null,
            'ranking_initialized_at' => null,
        ]);
    }

    /** @return Collection<int, Character> */
    private function characters(int $count): Collection
    {
        return collect(range(1, $count))->map(function (): Character {
            $user = User::factory()->create();

            return Character::query()->create([
                'user_id' => $user->id,
                'name' => "六英雄引継ぎ検証{$user->id}",
            ]);
        });
    }

    /**
     * @param  array{int, int, int, int}  $counters
     */
    private function ranking(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
        Character $character,
        int $rank,
        array $counters = [0, 0, 0, 0],
        string $registeredAt = '2026-08-01 00:00:00',
        ?string $firstPlaceSince = null,
    ): SixHeroRanking {
        return SixHeroRanking::query()->create([
            'season_id' => $season->id,
            'room_key' => $room,
            'character_id' => $character->id,
            'rank' => $rank,
            'official_attack_wins' => $counters[0],
            'official_attack_losses' => $counters[1],
            'defense_wins' => $counters[2],
            'defense_losses' => $counters[3],
            'registered_at' => $registeredAt,
            'first_place_since' => $firstPlaceSince,
        ]);
    }

    /** @return Collection<int, SixHeroRanking> */
    private function rankingsFor(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
    ): Collection {
        return SixHeroRanking::query()
            ->where('season_id', $season->id)
            ->where('room_key', $room->value)
            ->orderBy('rank')
            ->get();
    }

    /** @return array{int, int, int, int} */
    private function counters(SixHeroRanking $ranking): array
    {
        return [
            (int) $ranking->official_attack_wins,
            (int) $ranking->official_attack_losses,
            (int) $ranking->defense_wins,
            (int) $ranking->defense_losses,
        ];
    }

    private function pendingBattle(
        SixHeroSeason $season,
        Character $attacker,
        Character $defender,
    ): SixHeroBattleLog {
        return SixHeroBattleLog::query()->create([
            'season_id' => $season->id,
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
    }

    private function expectNotReady(callable $operation): void
    {
        try {
            $operation();
            $this->fail('A SixHeroRankingNotReadyException was not thrown.');
        } catch (SixHeroRankingNotReadyException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }
}
