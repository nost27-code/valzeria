<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Models\Character;
use App\Models\SixHeroBattleLog;
use App\Models\SixHeroChampion;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Models\User;
use App\Services\SixHeroSeasonFinalizationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class SixHeroSeasonFinalizationServiceTest extends TestCase
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

    public function test_each_room_is_finalized_as_a_hero_or_explicit_vacancy_from_room_wide_counters(): void
    {
        $season = $this->season();
        $characters = $this->characters(8);

        $sealMagic = $this->rankings(
            $season,
            SixHeroRoomKey::SEAL_MAGIC,
            $characters,
            officialBattleCount: 10,
            rankOneStats: [0, 0, 30, 2],
        );
        $this->rankings(
            $season,
            SixHeroRoomKey::SEAL_BLADE,
            array_slice($characters, 0, 7),
            officialBattleCount: 100,
        );
        $this->rankings(
            $season,
            SixHeroRoomKey::BURNING_LIFE,
            $characters,
            officialBattleCount: 9,
        );
        $divineSpeed = $this->rankings(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $characters,
            officialBattleCount: 10,
            rankOneStats: [0, 0, 0, 0],
        );
        $reverseTime = $this->rankings(
            $season,
            SixHeroRoomKey::REVERSE_TIME,
            $characters,
            officialBattleCount: 47,
            rankOneStats: [0, 0, 12, 4],
        );

        // BattleLog件数は英雄条件の正本ではない。counterが9戦なら10件以上の
        // completed logがあってもactivity不足のままとする。
        for ($index = 0; $index < 12; $index++) {
            $this->battleLog(
                $season,
                SixHeroRoomKey::BURNING_LIFE,
                SixHeroBattleLog::STATUS_COMPLETED,
                $characters[1],
                $characters[0],
                '2026-08-20 12:00:00',
            );
        }

        $result = $this->service()->finalizeSeason($season);

        $this->assertTrue($result->finalized);
        $this->assertFalse($result->alreadyFinalized);
        $this->assertFalse($result->pendingBattles);
        $this->assertSame(0, $result->pendingBattleCount);
        $this->assertCount(6, $result->champions);
        $this->assertDatabaseCount('six_hero_champions', 6);
        $this->assertNotNull($season->fresh()->finalized_at);

        $champions = $result->champions->keyBy(
            fn (SixHeroChampion $champion): string => $champion->room_key->value,
        );
        $sealMagicChampion = $champions->get(SixHeroRoomKey::SEAL_MAGIC->value);
        $this->assertNotNull($sealMagicChampion);
        $this->assertFalse($sealMagicChampion->is_vacant);
        $this->assertNull($sealMagicChampion->vacancy_reason);
        $this->assertSame($sealMagic[0]->character_id, $sealMagicChampion->character_id);
        $this->assertSame($sealMagic[0]->character_id, $sealMagicChampion->character_id_snapshot);
        $this->assertSame($characters[0]->name, $sealMagicChampion->character_name_snapshot);
        $this->assertSame(8, $sealMagicChampion->registered_count);
        $this->assertSame(10, $sealMagicChampion->official_battle_count);
        $this->assertSame(0, $sealMagicChampion->official_attack_wins);
        $this->assertSame(0, $sealMagicChampion->official_attack_losses);
        $this->assertSame(30, $sealMagicChampion->defense_wins);
        $this->assertSame(2, $sealMagicChampion->defense_losses);
        $this->assertTrue($sealMagicChampion->season->is($season));
        $this->assertTrue($sealMagicChampion->character->is($characters[0]));

        $participantVacancy = $champions->get(SixHeroRoomKey::SEAL_BLADE->value);
        $this->assertTrue($participantVacancy->is_vacant);
        $this->assertSame(
            SixHeroChampion::VACANCY_INSUFFICIENT_PARTICIPANTS,
            $participantVacancy->vacancy_reason,
        );
        $this->assertSame(7, $participantVacancy->registered_count);
        $this->assertSame(100, $participantVacancy->official_battle_count);
        $this->assertVacantStats($participantVacancy);

        $activityVacancy = $champions->get(SixHeroRoomKey::BURNING_LIFE->value);
        $this->assertTrue($activityVacancy->is_vacant);
        $this->assertSame(
            SixHeroChampion::VACANCY_INSUFFICIENT_ACTIVITY,
            $activityVacancy->vacancy_reason,
        );
        $this->assertSame(8, $activityVacancy->registered_count);
        $this->assertSame(9, $activityVacancy->official_battle_count);
        $this->assertVacantStats($activityVacancy);

        $this->assertFalse(
            $champions->get(SixHeroRoomKey::DIVINE_SPEED->value)->is_vacant,
        );
        $this->assertSame(
            $divineSpeed[0]->character_id,
            $champions->get(SixHeroRoomKey::DIVINE_SPEED->value)->character_id,
        );
        $this->assertFalse(
            $champions->get(SixHeroRoomKey::REVERSE_TIME->value)->is_vacant,
        );
        $this->assertSame(
            $reverseTime[0]->character_id,
            $champions->get(SixHeroRoomKey::REVERSE_TIME->value)->character_id,
        );

        $emptyRoom = $champions->get(SixHeroRoomKey::MIRACLE->value);
        $this->assertTrue($emptyRoom->is_vacant);
        $this->assertSame(
            SixHeroChampion::VACANCY_INSUFFICIENT_PARTICIPANTS,
            $emptyRoom->vacancy_reason,
        );
        $this->assertSame(0, $emptyRoom->registered_count);
        $this->assertSame(0, $emptyRoom->official_battle_count);
        $this->assertVacantStats($emptyRoom);
    }

    public function test_august_preseason_finalizes_without_permanent_champion_or_vacancy_snapshots(): void
    {
        config(['six_heroes.champion_recording_starts_from_season' => '2026-09']);
        $season = $this->season();

        $first = $this->service()->finalizeSeason($season);
        $second = $this->service()->finalizeSeason($season->fresh());

        $this->assertTrue($first->finalized);
        $this->assertFalse($first->alreadyFinalized);
        $this->assertCount(0, $first->champions);
        $this->assertTrue($second->finalized);
        $this->assertTrue($second->alreadyFinalized);
        $this->assertCount(0, $second->champions);
        $this->assertNotNull($season->fresh()->finalized_at);
        $this->assertDatabaseCount('six_hero_champions', 0);
    }

    public function test_finalization_is_fully_idempotent_and_preserves_the_first_snapshot_and_timestamp(): void
    {
        $season = $this->season();
        $first = $this->service()->finalizeSeason($season);
        $firstFinalizedAt = $first->season->finalized_at->copy();
        $firstSnapshots = SixHeroChampion::query()
            ->orderBy('room_key')
            ->get()
            ->map->getRawOriginal()
            ->all();

        Carbon::setTestNow(Carbon::parse('2026-09-02 09:00:00', 'Asia/Tokyo'));
        for ($call = 0; $call < 10; $call++) {
            $duplicate = $this->service()->finalizeSeason($season);
            $this->assertTrue($duplicate->finalized);
            $this->assertTrue($duplicate->alreadyFinalized);
            $this->assertFalse($duplicate->pendingBattles);
            $this->assertCount(6, $duplicate->champions);
        }

        $this->assertTrue($season->fresh()->finalized_at->equalTo($firstFinalizedAt));
        $this->assertEquals(
            $firstSnapshots,
            SixHeroChampion::query()
                ->orderBy('room_key')
                ->get()
                ->map->getRawOriginal()
                ->all(),
        );
        $this->assertDatabaseCount('six_hero_champions', 6);
    }

    public function test_active_season_cannot_be_finalized(): void
    {
        $season = $this->season(
            '2026-09',
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
        );

        try {
            $this->service()->finalizeSeason($season);
            $this->fail('An active Season was finalized.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('ended', $exception->getMessage());
        }

        $this->assertNull($season->fresh()->finalized_at);
        $this->assertDatabaseCount('six_hero_champions', 0);
    }

    #[DataProvider('invalidSeasonBoundaryProvider')]
    public function test_invalid_calendar_month_season_is_rejected_without_repair_or_snapshot(
        string $seasonKey,
        string $startsAt,
        string $endsAt,
    ): void {
        $season = $this->season($seasonKey, $startsAt, $endsAt);
        $before = $season->getRawOriginal();

        try {
            $this->service()->finalizeSeason($season);
            $this->fail('An invalid calendar-month Season was finalized.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('invalid', $exception->getMessage());
        }

        $this->assertEquals($before, $season->fresh()->getRawOriginal());
        $this->assertDatabaseCount('six_hero_champions', 0);
    }

    public static function invalidSeasonBoundaryProvider(): array
    {
        return [
            'invalid start' => [
                '2026-08',
                '2026-08-02 00:00:00',
                '2026-09-01 00:00:00',
            ],
            'invalid end' => [
                '2026-08',
                '2026-08-01 00:00:00',
                '2026-09-05 00:00:00',
            ],
            'invalid key' => [
                '2026-13',
                '2026-08-01 00:00:00',
                '2026-09-01 00:00:00',
            ],
        ];
    }

    #[DataProvider('pendingStatusProvider')]
    public function test_pre_deadline_started_or_resolved_official_battle_defers_finalization(
        string $status,
    ): void {
        $season = $this->season();
        [$attacker, $defender] = $this->characters(2);
        $this->battleLog(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $status,
            $attacker,
            $defender,
            '2026-08-31 23:59:59',
        );

        $result = $this->service()->finalizeSeason($season);

        $this->assertFalse($result->finalized);
        $this->assertFalse($result->alreadyFinalized);
        $this->assertTrue($result->pendingBattles);
        $this->assertSame(1, $result->pendingBattleCount);
        $this->assertCount(0, $result->champions);
        $this->assertNull($season->fresh()->finalized_at);
        $this->assertDatabaseCount('six_hero_champions', 0);
    }

    public static function pendingStatusProvider(): array
    {
        return [
            'started' => [SixHeroBattleLog::STATUS_STARTED],
            'resolved' => [SixHeroBattleLog::STATUS_RESOLVED],
        ];
    }

    #[DataProvider('terminalStatusProvider')]
    public function test_terminal_battle_status_does_not_block_finalization(string $status): void
    {
        $season = $this->season();
        [$attacker, $defender] = $this->characters(2);
        $this->battleLog(
            $season,
            SixHeroRoomKey::DIVINE_SPEED,
            $status,
            $attacker,
            $defender,
            '2026-08-31 23:59:59',
        );

        $result = $this->service()->finalizeSeason($season);

        $this->assertTrue($result->finalized);
        $this->assertFalse($result->pendingBattles);
        $this->assertDatabaseCount('six_hero_champions', 6);
    }

    public static function terminalStatusProvider(): array
    {
        return [
            'failed' => [SixHeroBattleLog::STATUS_FAILED],
            'completed' => [SixHeroBattleLog::STATUS_COMPLETED],
            'legacy expired' => [SixHeroBattleLog::STATUS_EXPIRED],
        ];
    }

    public function test_pending_old_season_does_not_prevent_other_ended_seasons_from_finalizing(): void
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
        $august = $this->season();
        $september = $this->season(
            '2026-09',
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
        );
        [$attacker, $defender] = $this->characters(2);
        $this->battleLog(
            $june,
            SixHeroRoomKey::SEAL_MAGIC,
            SixHeroBattleLog::STATUS_STARTED,
            $attacker,
            $defender,
            '2026-06-30 23:59:59',
        );

        $results = $this->service()->finalizeEndedSeasons();

        $this->assertSame(
            ['2026-06', '2026-07', '2026-08'],
            $results->map->season->pluck('season_key')->all(),
        );
        $this->assertTrue($results[0]->pendingBattles);
        $this->assertTrue($results[1]->finalized);
        $this->assertTrue($results[2]->finalized);
        $this->assertNull($june->fresh()->finalized_at);
        $this->assertNotNull($july->fresh()->finalized_at);
        $this->assertNotNull($august->fresh()->finalized_at);
        $this->assertNull($september->fresh()->finalized_at);
        $this->assertSame(12, SixHeroChampion::query()->count());
    }

    public function test_missing_rank_one_is_detected_and_rolls_back_all_room_snapshots(): void
    {
        $season = $this->season();
        $characters = $this->characters(8);
        foreach ($characters as $index => $character) {
            SixHeroRanking::query()->create([
                'season_id' => $season->id,
                'room_key' => SixHeroRoomKey::BURNING_LIFE,
                'character_id' => $character->id,
                'rank' => $index + 2,
                'official_attack_losses' => $index === 0 ? 10 : 0,
                'registered_at' => '2026-08-01 00:00:00',
            ]);
        }

        try {
            $this->service()->finalizeSeason($season);
            $this->fail('A corrupt ranking without rank 1 was finalized.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('rank 1', $exception->getMessage());
        }

        $this->assertDatabaseCount('six_hero_champions', 0);
        $this->assertNull($season->fresh()->finalized_at);
    }

    public function test_exception_during_room_inserts_rolls_back_all_snapshots_and_finalized_at(): void
    {
        $season = $this->season();
        $created = 0;
        $eventName = 'eloquent.creating: '.SixHeroChampion::class;
        Event::listen($eventName, static function () use (&$created): void {
            $created++;
            if ($created === 4) {
                throw new RuntimeException('forced champion snapshot failure');
            }
        });

        try {
            $this->service()->finalizeSeason($season);
            $this->fail('The forced Champion insert failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced champion snapshot failure', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertDatabaseCount('six_hero_champions', 0);
        $this->assertNull($season->fresh()->finalized_at);
    }

    public function test_character_deletion_keeps_the_champion_snapshot_and_nulls_only_the_foreign_key(): void
    {
        $season = $this->season();
        $characters = $this->characters(8);
        $this->rankings(
            $season,
            SixHeroRoomKey::SEAL_MAGIC,
            $characters,
            officialBattleCount: 10,
        );
        $this->service()->finalizeSeason($season);
        $champion = SixHeroChampion::query()
            ->where('room_key', SixHeroRoomKey::SEAL_MAGIC->value)
            ->firstOrFail();
        $identitySnapshot = $champion->character_id_snapshot;
        $nameSnapshot = $champion->character_name_snapshot;

        $characters[0]->delete();

        $champion->refresh();
        $this->assertNull($champion->character_id);
        $this->assertSame($identitySnapshot, $champion->character_id_snapshot);
        $this->assertNull($champion->character);
        $this->assertSame($nameSnapshot, $champion->character_name_snapshot);
        $this->assertFalse($champion->is_vacant);
        $this->assertDatabaseCount('six_hero_champions', 6);
    }

    public function test_champion_database_contract_and_migration_rollback_are_enforced(): void
    {
        $season = $this->season();
        $this->assertTrue(Schema::hasColumns('six_hero_champions', [
            'season_id',
            'room_key',
            'character_id',
            'character_id_snapshot',
            'character_name_snapshot',
            'is_vacant',
            'vacancy_reason',
            'registered_count',
            'official_battle_count',
            'official_attack_wins',
            'official_attack_losses',
            'defense_wins',
            'defense_losses',
        ]));

        SixHeroChampion::query()->create([
            'season_id' => $season->id,
            'room_key' => SixHeroRoomKey::MIRACLE,
            'character_id' => null,
            'character_id_snapshot' => null,
            'character_name_snapshot' => null,
            'is_vacant' => true,
            'vacancy_reason' => SixHeroChampion::VACANCY_INSUFFICIENT_PARTICIPANTS,
            'registered_count' => 0,
            'official_battle_count' => 0,
        ]);
        try {
            SixHeroChampion::query()->create([
                'season_id' => $season->id,
                'room_key' => SixHeroRoomKey::MIRACLE,
                'character_id' => null,
                'character_id_snapshot' => null,
                'character_name_snapshot' => null,
                'is_vacant' => true,
                'vacancy_reason' => SixHeroChampion::VACANCY_INSUFFICIENT_PARTICIPANTS,
                'registered_count' => 0,
                'official_battle_count' => 0,
            ]);
            $this->fail('The Season/Room unique constraint was not enforced.');
        } catch (QueryException) {
            $this->assertDatabaseCount('six_hero_champions', 1);
        }

        $championMigration = require base_path(
            'database/migrations/2026_08_19_140000_create_six_hero_champions_table.php',
        );
        $snapshotMigration = require base_path(
            'database/migrations/2026_08_19_150000_add_character_id_snapshot_to_six_hero_champions_table.php',
        );

        try {
            $snapshotMigration->down();
            $championMigration->down();
            $this->assertFalse(Schema::hasTable('six_hero_champions'));
        } finally {
            if (! Schema::hasTable('six_hero_champions')) {
                $championMigration->up();
            }
            if (! Schema::hasColumn('six_hero_champions', 'character_id_snapshot')) {
                $snapshotMigration->up();
            }
        }

        $this->assertTrue(Schema::hasTable('six_hero_champions'));
        $this->assertTrue(Schema::hasColumn(
            'six_hero_champions',
            'character_id_snapshot',
        ));
    }

    private function service(): SixHeroSeasonFinalizationService
    {
        return app(SixHeroSeasonFinalizationService::class);
    }

    private function season(
        string $key = '2026-08',
        string $startsAt = '2026-08-01 00:00:00',
        string $endsAt = '2026-09-01 00:00:00',
    ): SixHeroSeason {
        return SixHeroSeason::query()->create([
            'season_key' => $key,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'finalized_at' => null,
            'ranking_initialized_at' => null,
        ]);
    }

    /** @return array<int, Character> */
    private function characters(int $count): array
    {
        $characters = [];
        for ($index = 0; $index < $count; $index++) {
            $user = User::factory()->create();
            $characters[] = Character::query()->create([
                'user_id' => $user->id,
                'name' => "六英雄確定検証{$user->id}",
            ]);
        }

        return $characters;
    }

    /**
     * @param  array<int, Character>  $characters
     * @param  array{int,int,int,int}  $rankOneStats
     * @return array<int, SixHeroRanking>
     */
    private function rankings(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
        array $characters,
        int $officialBattleCount,
        array $rankOneStats = [0, 0, 0, 0],
    ): array {
        $rankings = [];
        foreach ($characters as $index => $character) {
            [$attackWins, $attackLosses, $defenseWins, $defenseLosses] = $index === 0
                ? $rankOneStats
                : [0, 0, 0, 0];
            if ($index === 1) {
                $attackLosses += $officialBattleCount;
            } elseif ($index === 0 && count($characters) === 1) {
                $attackLosses += $officialBattleCount;
            }

            $rankings[] = SixHeroRanking::query()->create([
                'season_id' => $season->id,
                'room_key' => $room,
                'character_id' => $character->id,
                'rank' => $index + 1,
                'official_attack_wins' => $attackWins,
                'official_attack_losses' => $attackLosses,
                'defense_wins' => $defenseWins,
                'defense_losses' => $defenseLosses,
                'registered_at' => '2026-08-01 00:00:00',
                'first_place_since' => $index === 0
                    ? '2026-08-31 23:59:58'
                    : null,
            ]);
        }

        return $rankings;
    }

    private function battleLog(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
        string $status,
        Character $attacker,
        Character $defender,
        string $startedAt,
    ): SixHeroBattleLog {
        return SixHeroBattleLog::query()->create([
            'season_id' => $season->id,
            'room_key' => $room,
            'battle_mode' => SixHeroBattleLog::MODE_OFFICIAL,
            'status' => $status,
            'attacker_id' => $attacker->id,
            'defender_id' => $defender->id,
            'attacker_rank_at_start' => 2,
            'defender_rank_at_start' => 1,
            'daily_attempt_number' => 1,
            'started_at' => $startedAt,
            'resolved_at' => in_array($status, [
                SixHeroBattleLog::STATUS_RESOLVED,
                SixHeroBattleLog::STATUS_COMPLETED,
                SixHeroBattleLog::STATUS_EXPIRED,
            ], true) ? $startedAt : null,
            'completed_at' => $status === SixHeroBattleLog::STATUS_COMPLETED
                ? $startedAt
                : null,
            'failed_at' => $status === SixHeroBattleLog::STATUS_FAILED
                ? $startedAt
                : null,
        ]);
    }

    private function assertVacantStats(SixHeroChampion $champion): void
    {
        $this->assertNull($champion->character_id);
        $this->assertNull($champion->character_id_snapshot);
        $this->assertNull($champion->character_name_snapshot);
        $this->assertNull($champion->official_attack_wins);
        $this->assertNull($champion->official_attack_losses);
        $this->assertNull($champion->defense_wins);
        $this->assertNull($champion->defense_losses);
    }
}
