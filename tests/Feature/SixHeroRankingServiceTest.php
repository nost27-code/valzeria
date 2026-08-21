<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Exceptions\SixHeroRankingNotReadyException;
use App\Models\Character;
use App\Models\SixHeroBattleLog;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Models\User;
use App\Services\SixHeroRankingService;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

final class SixHeroRankingServiceTest extends TestCase
{
    use RefreshDatabase;

    private SixHeroRankingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-01 12:00:00');
        $this->service = new SixHeroRankingService;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_register_appends_to_the_bottom_and_is_idempotent(): void
    {
        $season = $this->season('2026-08');
        [$firstCharacter, $secondCharacter] = $this->characters(2);

        $first = $this->service->register(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $firstCharacter,
        );
        $firstRegisteredAt = $first->registered_at->copy();

        $this->assertSame(1, $first->rank);
        $this->assertTrue($first->first_place_since->equalTo($firstRegisteredAt));
        $this->assertSame([0, 0, 0, 0], $this->counters($first));

        Carbon::setTestNow('2026-08-01 13:00:00');
        $second = $this->service->register(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $secondCharacter,
        );

        $this->assertSame(2, $second->rank);
        $this->assertNull($second->first_place_since);

        Carbon::setTestNow('2026-08-02 12:00:00');
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $again = $this->service->register(
                $season,
                SixHeroRoomKey::DIVINE_SPEED,
                $firstCharacter,
            );
            $this->assertSame($first->id, $again->id);
        }

        $first->refresh();
        $this->assertSame(1, $first->rank);
        $this->assertTrue($first->registered_at->equalTo($firstRegisteredAt));
        $this->assertTrue($first->first_place_since->equalTo($firstRegisteredAt));
        $this->assertSame(2, SixHeroRanking::query()->count());
    }

    public function test_register_initializes_from_previous_month_before_appending_new_character(): void
    {
        Carbon::setTestNow('2026-09-01 00:10:00');
        $previous = $this->season('2026-08');
        $target = $this->season(
            '2026-09',
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
            false,
        );
        $characters = $this->characters(3);
        $this->registerMany(
            $previous,
            SixHeroRoomKey::DIVINE_SPEED,
            array_slice($characters, 0, 2),
        );
        $previous->update(['finalized_at' => '2026-09-01 00:01:00']);

        $registered = $this->service->register(
            $target,
            SixHeroRoomKey::DIVINE_SPEED,
            $characters[2],
        );

        $this->assertSame(3, $registered->rank);
        $this->assertNotNull($target->fresh()->ranking_initialized_at);
        $this->assertSame(
            array_map(
                static fn (Character $character): int => (int) $character->id,
                $characters,
            ),
            SixHeroRanking::query()
                ->where('season_id', $target->id)
                ->where('room_key', SixHeroRoomKey::DIVINE_SPEED->value)
                ->orderBy('rank')
                ->pluck('character_id')
                ->all(),
        );
    }

    public function test_register_is_not_ready_without_target_ranking_side_effects_while_previous_battle_is_pending(): void
    {
        Carbon::setTestNow('2026-09-01 00:10:00');
        $previous = $this->season('2026-08');
        $target = $this->season(
            '2026-09',
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
            false,
        );
        [$attacker, $defender, $newCharacter] = $this->characters(3);
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
            $this->service->register(
                $target,
                SixHeroRoomKey::DIVINE_SPEED,
                $newCharacter,
            );
            $this->fail('Registration started before ranking initialization was ready.');
        } catch (SixHeroRankingNotReadyException) {
            $this->assertNull($target->fresh()->ranking_initialized_at);
        }

        $this->assertDatabaseMissing('six_hero_rankings', [
            'season_id' => $target->id,
        ]);
        $this->assertDatabaseCount('six_hero_battle_logs', 1);
    }

    public function test_registering_ten_characters_keeps_contiguous_ranks(): void
    {
        $season = $this->season('2026-08');
        $characters = $this->characters(10);

        $this->registerMany($season, SixHeroRoomKey::DIVINE_SPEED, $characters);

        $this->assertSame(
            range(1, 10),
            SixHeroRanking::query()
                ->where('season_id', $season->id)
                ->where('room_key', SixHeroRoomKey::DIVINE_SPEED->value)
                ->orderBy('rank')
                ->pluck('rank')
                ->all(),
        );
    }

    public function test_ranking_for_and_top_entries_are_scoped_to_one_season_and_room(): void
    {
        $august = $this->season('2026-08');
        $september = $this->season(
            '2026-09',
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
        );
        $characters = $this->characters(8);
        $speed = $this->registerMany($august, SixHeroRoomKey::DIVINE_SPEED, $characters);
        $this->registerMany(
            $august,
            SixHeroRoomKey::MIRACLE,
            array_reverse(array_slice($characters, 0, 3)),
        );
        $this->registerMany(
            $september,
            SixHeroRoomKey::DIVINE_SPEED,
            array_slice($characters, 0, 2),
        );

        $found = $this->service->rankingFor(
            $august,
            SixHeroRoomKey::DIVINE_SPEED,
            $characters[4],
        );
        $missing = $this->service->rankingFor(
            $september,
            SixHeroRoomKey::MIRACLE,
            $characters[4],
        );

        $this->assertSame($speed[4]->id, $found?->id);
        $this->assertNull($missing);
        $this->assertSame(
            range(1, 6),
            $this->service
                ->topEntries($august, SixHeroRoomKey::DIVINE_SPEED)
                ->pluck('rank')
                ->all(),
        );
        $this->assertSame(
            array_map(fn (SixHeroRanking $ranking): int => $ranking->id, array_slice($speed, 0, 6)),
            $this->service
                ->topEntries($august, SixHeroRoomKey::DIVINE_SPEED)
                ->pluck('id')
                ->all(),
        );
        $this->assertTrue(
            $this->service
                ->topEntries($august, SixHeroRoomKey::DIVINE_SPEED, 0)
                ->isEmpty(),
        );
    }

    public function test_target_entries_returns_the_closest_higher_rankings_from_current_db_rank(): void
    {
        $season = $this->season('2026-08');
        $rankings = $this->registerMany(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $this->characters(10),
        );

        $expected = [
            1 => [],
            2 => [1],
            3 => [2, 1],
            4 => [3, 2, 1],
            10 => [9, 8, 7],
        ];
        foreach ($expected as $rank => $expectedTargets) {
            $this->assertSame(
                $expectedTargets,
                $this->service
                    ->targetEntries($rankings[$rank - 1])
                    ->pluck('rank')
                    ->all(),
                "rank {$rank}",
            );
        }

        $rankings[9]->rank = 2;
        $this->assertSame(
            [9, 8, 7],
            $this->service->targetEntries($rankings[9])->pluck('rank')->all(),
        );
    }

    public function test_is_challenge_target_uses_the_latest_rank_and_rejects_other_scopes(): void
    {
        $august = $this->season('2026-08');
        $september = $this->season(
            '2026-09',
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
        );
        $characters = $this->characters(12);
        $rankings = $this->registerMany(
            $august,
            SixHeroRoomKey::DIVINE_SPEED,
            array_slice($characters, 0, 11),
        );
        $otherRoom = $this->service->register(
            $august,
            SixHeroRoomKey::MIRACLE,
            $characters[11],
        );
        $otherSeason = $this->service->register(
            $september,
            SixHeroRoomKey::DIVINE_SPEED,
            $characters[11],
        );
        $attacker = $rankings[9];

        $this->assertTrue($this->service->isChallengeTarget($attacker, $rankings[8]));
        $this->assertTrue($this->service->isChallengeTarget($attacker, $rankings[7]));
        $this->assertTrue($this->service->isChallengeTarget($attacker, $rankings[6]));
        $this->assertFalse($this->service->isChallengeTarget($attacker, $rankings[5]));
        $this->assertFalse($this->service->isChallengeTarget($attacker, $attacker));
        $this->assertFalse($this->service->isChallengeTarget($attacker, $rankings[10]));
        $this->assertFalse($this->service->isChallengeTarget($attacker, $otherRoom));
        $this->assertFalse($this->service->isChallengeTarget($attacker, $otherSeason));
        $this->assertFalse($this->service->isChallengeTarget($attacker, $rankings[8], 0));

        $attacker->rank = 2;
        $rankings[8]->rank = 100;
        $this->assertTrue($this->service->isChallengeTarget($attacker, $rankings[8]));
    }

    public function test_loss_keeps_ranks_and_updates_only_loss_and_defense_win_counters(): void
    {
        $season = $this->season('2026-08');
        $rankings = $this->registerMany(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $this->characters(4),
        );
        $attacker = $rankings[3];
        $defender = $rankings[1];

        $result = $this->service->applyRankedBattleOutcome($attacker, $defender, false);

        $this->assertFalse($result->attackerWon);
        $this->assertFalse($result->rankChanged);
        $this->assertSame([4, 4, 2, 2], [
            $result->attackerOldRank,
            $result->attackerNewRank,
            $result->defenderOldRank,
            $result->defenderNewRank,
        ]);
        $this->assertSame([0, 1, 0, 0], $this->counters($attacker->fresh()));
        $this->assertSame([0, 0, 1, 0], $this->counters($defender->fresh()));
        $this->assertSame([1, 2, 3, 4], $this->roomRanks($season, SixHeroRoomKey::DIVINE_SPEED));
    }

    public function test_higher_rank_win_moves_ten_to_seven_without_duplicates_or_gaps(): void
    {
        $season = $this->season('2026-08');
        $characters = $this->characters(10);
        $rankings = $this->registerMany($season, SixHeroRoomKey::DIVINE_SPEED, $characters);
        $championSince = $rankings[0]->first_place_since->copy();
        Carbon::setTestNow('2026-08-10 09:00:00');

        $result = $this->service->applyRankedBattleOutcome($rankings[9], $rankings[6], true);

        $this->assertTrue($result->attackerWon);
        $this->assertTrue($result->rankChanged);
        $this->assertSame([10, 7, 7, 8], [
            $result->attackerOldRank,
            $result->attackerNewRank,
            $result->defenderOldRank,
            $result->defenderNewRank,
        ]);
        $this->assertSame(range(1, 10), $this->roomRanks($season, SixHeroRoomKey::DIVINE_SPEED));
        $this->assertSame([
            $characters[0]->id,
            $characters[1]->id,
            $characters[2]->id,
            $characters[3]->id,
            $characters[4]->id,
            $characters[5]->id,
            $characters[9]->id,
            $characters[6]->id,
            $characters[7]->id,
            $characters[8]->id,
        ], $this->orderedCharacterIds($season, SixHeroRoomKey::DIVINE_SPEED));
        $this->assertSame([1, 0, 0, 0], $this->counters($rankings[9]->fresh()));
        $this->assertSame([0, 0, 0, 1], $this->counters($rankings[6]->fresh()));
        $this->assertTrue($rankings[0]->fresh()->first_place_since->equalTo($championSince));
        $this->assertNull($rankings[9]->fresh()->first_place_since);
    }

    public function test_second_place_win_sets_new_first_place_since_and_clears_the_old_champion(): void
    {
        $season = $this->season('2026-08');
        $rankings = $this->registerMany(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $this->characters(2),
        );
        Carbon::setTestNow('2026-08-15 18:30:00');

        $result = $this->service->applyRankedBattleOutcome($rankings[1], $rankings[0], true);

        $this->assertTrue($result->rankChanged);
        $this->assertSame(1, $rankings[1]->fresh()->rank);
        $this->assertTrue(
            $rankings[1]->fresh()->first_place_since->equalTo(Carbon::now()),
        );
        $this->assertSame(2, $rankings[0]->fresh()->rank);
        $this->assertNull($rankings[0]->fresh()->first_place_since);
    }

    public function test_win_against_a_currently_lower_rank_updates_counters_without_moving_ranks(): void
    {
        $season = $this->season('2026-08');
        $rankings = $this->registerMany(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $this->characters(3),
        );

        $result = $this->service->applyRankedBattleOutcome($rankings[1], $rankings[2], true);

        $this->assertTrue($result->attackerWon);
        $this->assertFalse($result->rankChanged);
        $this->assertSame([2, 2, 3, 3], [
            $result->attackerOldRank,
            $result->attackerNewRank,
            $result->defenderOldRank,
            $result->defenderNewRank,
        ]);
        $this->assertSame([1, 0, 0, 0], $this->counters($rankings[1]->fresh()));
        $this->assertSame([0, 0, 0, 1], $this->counters($rankings[2]->fresh()));
        $this->assertSame([1, 2, 3], $this->roomRanks($season, SixHeroRoomKey::DIVINE_SPEED));
    }

    public function test_rank_changes_are_isolated_between_rooms(): void
    {
        $season = $this->season('2026-08');
        $characters = $this->characters(4);
        $speed = $this->registerMany($season, SixHeroRoomKey::DIVINE_SPEED, $characters);
        $this->registerMany($season, SixHeroRoomKey::MIRACLE, array_reverse($characters));

        $this->service->applyRankedBattleOutcome($speed[3], $speed[1], true);

        $this->assertSame(
            [$characters[0]->id, $characters[3]->id, $characters[1]->id, $characters[2]->id],
            $this->orderedCharacterIds($season, SixHeroRoomKey::DIVINE_SPEED),
        );
        $this->assertSame(
            [$characters[3]->id, $characters[2]->id, $characters[1]->id, $characters[0]->id],
            $this->orderedCharacterIds($season, SixHeroRoomKey::MIRACLE),
        );
    }

    public function test_rank_changes_are_isolated_between_seasons(): void
    {
        $august = $this->season('2026-08');
        $september = $this->season(
            '2026-09',
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
        );
        $characters = $this->characters(4);
        $augustRankings = $this->registerMany(
            $august,
            SixHeroRoomKey::DIVINE_SPEED,
            $characters,
        );
        $this->registerMany(
            $september,
            SixHeroRoomKey::DIVINE_SPEED,
            array_reverse($characters),
        );

        $this->service->applyRankedBattleOutcome(
            $augustRankings[3],
            $augustRankings[1],
            true,
        );

        $this->assertSame(
            [$characters[0]->id, $characters[3]->id, $characters[1]->id, $characters[2]->id],
            $this->orderedCharacterIds($august, SixHeroRoomKey::DIVINE_SPEED),
        );
        $this->assertSame(
            [$characters[3]->id, $characters[2]->id, $characters[1]->id, $characters[0]->id],
            $this->orderedCharacterIds($september, SixHeroRoomKey::DIVINE_SPEED),
        );
    }

    public function test_invalid_outcome_pairs_are_rejected_without_counter_changes(): void
    {
        $august = $this->season('2026-08');
        $september = $this->season(
            '2026-09',
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
        );
        [$firstCharacter, $secondCharacter] = $this->characters(2);
        $attacker = $this->service->register(
            $august,
            SixHeroRoomKey::DIVINE_SPEED,
            $firstCharacter,
        );
        $defender = $this->service->register(
            $august,
            SixHeroRoomKey::DIVINE_SPEED,
            $secondCharacter,
        );
        $otherRoom = $this->service->register(
            $august,
            SixHeroRoomKey::MIRACLE,
            $secondCharacter,
        );
        $otherSeason = $this->service->register(
            $september,
            SixHeroRoomKey::DIVINE_SPEED,
            $secondCharacter,
        );
        $before = $this->rankingSnapshot();

        $this->assertDomainFailure(
            fn () => $this->service->applyRankedBattleOutcome($attacker, $attacker, true),
        );
        $this->assertDomainFailure(
            fn () => $this->service->applyRankedBattleOutcome($attacker, $otherRoom, true),
        );
        $this->assertDomainFailure(
            fn () => $this->service->applyRankedBattleOutcome($attacker, $otherSeason, false),
        );

        $this->assertSame($before, $this->rankingSnapshot());
        $this->assertSame([0, 0, 0, 0], $this->counters($defender->fresh()));
    }

    public function test_finalized_season_rejects_registration_and_outcome_but_allows_reads(): void
    {
        $season = $this->season('2026-08');
        [$firstCharacter, $secondCharacter, $newCharacter] = $this->characters(3);
        $first = $this->service->register(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $firstCharacter,
        );
        $second = $this->service->register(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $secondCharacter,
        );
        SixHeroSeason::query()->whereKey($season->id)->update([
            'finalized_at' => '2026-09-01 00:05:00',
        ]);
        $before = $this->rankingSnapshot();

        $this->assertDomainFailure(fn () => $this->service->register(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $newCharacter,
        ));
        $this->assertDomainFailure(
            fn () => $this->service->applyRankedBattleOutcome($second, $first, true),
        );

        $this->assertSame($before, $this->rankingSnapshot());
        $this->assertSame(
            $first->id,
            $this->service->rankingFor(
                $season,
                SixHeroRoomKey::DIVINE_SPEED,
                $firstCharacter,
            )?->id,
        );
        $this->assertCount(
            2,
            $this->service->topEntries($season, SixHeroRoomKey::DIVINE_SPEED),
        );
    }

    public function test_outcome_reloads_stale_rank_and_counter_attributes_from_the_database(): void
    {
        $season = $this->season('2026-08');
        $rankings = $this->registerMany(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $this->characters(10),
        );
        $attacker = $rankings[9];
        $defender = $rankings[6];
        $attacker->rank = 2;
        $attacker->official_attack_wins = 900;
        $defender->rank = 20;
        $defender->defense_losses = 900;

        $result = $this->service->applyRankedBattleOutcome($attacker, $defender, true);

        $this->assertSame([10, 7, 7, 8], [
            $result->attackerOldRank,
            $result->attackerNewRank,
            $result->defenderOldRank,
            $result->defenderNewRank,
        ]);
        $this->assertSame([1, 0, 0, 0], $this->counters($attacker->fresh()));
        $this->assertSame([0, 0, 0, 1], $this->counters($defender->fresh()));
    }

    public function test_shift_failure_rolls_back_ranks_counters_and_first_place_times(): void
    {
        $season = $this->season('2026-08');
        $rankings = $this->registerMany(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $this->characters(10),
        );
        $before = $this->rankingSnapshot();
        $eventName = 'eloquent.updating: '.SixHeroRanking::class;
        Event::listen($eventName, function (SixHeroRanking $ranking): void {
            if ((int) $ranking->getOriginal('rank') === 9
                && (int) $ranking->rank === 10
            ) {
                throw new RuntimeException('forced rank shift failure');
            }
        });

        try {
            $this->service->applyRankedBattleOutcome($rankings[9], $rankings[6], true);
            $this->fail('The forced rank shift failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced rank shift failure', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame($before, $this->rankingSnapshot());
    }

    public function test_missing_ranking_is_not_recreated_by_outcome_application(): void
    {
        $season = $this->season('2026-08');
        $rankings = $this->registerMany(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $this->characters(2),
        );
        SixHeroRanking::query()->whereKey($rankings[0]->id)->delete();

        $this->expectException(ModelNotFoundException::class);
        try {
            $this->service->applyRankedBattleOutcome($rankings[1], $rankings[0], true);
        } finally {
            $this->assertSame(1, SixHeroRanking::query()->count());
        }
    }

    /** @return array<int, Character> */
    private function characters(int $count): array
    {
        $characters = [];
        for ($index = 1; $index <= $count; $index++) {
            $user = User::factory()->create();
            $characters[] = Character::query()->create([
                'user_id' => $user->id,
                'name' => "六英雄順位検証{$user->id}",
            ]);
        }

        return $characters;
    }

    private function season(
        string $seasonKey,
        string $startsAt = '2026-08-01 00:00:00',
        string $endsAt = '2026-09-01 00:00:00',
        bool $initialized = true,
    ): SixHeroSeason {
        return SixHeroSeason::query()->create([
            'season_key' => $seasonKey,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'finalized_at' => null,
            'ranking_initialized_at' => $initialized ? $startsAt : null,
        ]);
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
            fn (Character $character): SixHeroRanking => $this->service->register(
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

    /** @return array<int, array<string, mixed>> */
    private function rankingSnapshot(): array
    {
        return DB::table('six_hero_rankings')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
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
}
