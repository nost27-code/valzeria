<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Models\Character;
use App\Models\SixHeroChampion;
use App\Models\SixHeroSeason;
use App\Models\User;
use App\Services\SixHeroHallOfFameService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

final class SixHeroHallOfFameServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Tokyo']);
    }

    public function test_season_results_are_six_fixed_order_snapshots_and_only_finalized_seasons_are_visible(): void
    {
        $hero = $this->character('殿堂英雄');
        $finalized = $this->season('2026-08', [
            SixHeroRoomKey::SEAL_MAGIC->value => $hero,
            SixHeroRoomKey::BURNING_LIFE->value => $hero,
            SixHeroRoomKey::REVERSE_TIME->value => $hero,
            SixHeroRoomKey::MIRACLE->value => $hero,
        ]);
        $active = $this->season('2026-09', [
            SixHeroRoomKey::DIVINE_SPEED->value => $hero,
        ], finalized: false);

        $results = $this->service()->seasonResults($finalized);

        $this->assertCount(6, $results);
        $this->assertSame(
            SixHeroRoomKey::cases(),
            $results->map(
                fn (SixHeroChampion $champion): SixHeroRoomKey => $champion->room_key,
            )->all(),
        );
        $this->assertSame(
            [false, true, false, true, false, false],
            $results->pluck('is_vacant')->all(),
        );
        $this->assertSame(
            SixHeroChampion::VACANCY_INSUFFICIENT_ACTIVITY,
            $results[1]->vacancy_reason,
        );
        $this->assertSame(0, $results[0]->registered_count);
        $this->assertSame(0, $results[0]->official_battle_count);
        $this->assertSame($hero->id, $results[0]->character_id_snapshot);
        $this->assertTrue($this->service()->seasonResults($active)->isEmpty());
        $this->assertTrue($this->service()->latestFinalizedSeason()->is($finalized));
    }

    public function test_room_history_is_newest_first_room_scoped_and_includes_vacancies(): void
    {
        $olderHero = $this->character('旧神速英雄');
        $newerHero = $this->character('新神速英雄');
        $this->season('2026-07', [
            SixHeroRoomKey::DIVINE_SPEED->value => $olderHero,
            SixHeroRoomKey::MIRACLE->value => $newerHero,
        ]);
        $this->season('2026-08');
        $this->season('2026-09', [
            SixHeroRoomKey::DIVINE_SPEED->value => $newerHero,
        ]);
        $this->season('2026-10', [
            SixHeroRoomKey::DIVINE_SPEED->value => $olderHero,
        ], finalized: false);

        $history = $this->service()->roomHistory(SixHeroRoomKey::DIVINE_SPEED);

        $this->assertSame(
            ['2026-09', '2026-08', '2026-07'],
            $history->pluck('season.season_key')->all(),
        );
        $this->assertSame([false, true, false], $history->pluck('is_vacant')->all());
        $this->assertSame(
            [
                SixHeroRoomKey::DIVINE_SPEED,
                SixHeroRoomKey::DIVINE_SPEED,
                SixHeroRoomKey::DIVINE_SPEED,
            ],
            $history->pluck('room_key')->all(),
        );
        $this->assertSame(
            ['2026-09', '2026-08'],
            $this->service()
                ->roomHistory(SixHeroRoomKey::DIVINE_SPEED, 2)
                ->pluck('season.season_key')
                ->all(),
        );
    }

    public function test_character_history_uses_immutable_identity_and_preserves_each_historical_name(): void
    {
        $hero = $this->character('現在名');
        $this->season('2026-08', [
            SixHeroRoomKey::DIVINE_SPEED->value => [$hero, '旧名'],
        ]);
        $this->season('2026-09', [
            SixHeroRoomKey::DIVINE_SPEED->value => [$hero, '改名後'],
            SixHeroRoomKey::MIRACLE->value => [$hero, '改名後'],
        ]);
        $this->season('2026-10', [
            SixHeroRoomKey::SEAL_MAGIC->value => [$hero, '現在名'],
        ], finalized: false);

        $history = $this->service()->characterHistory($hero);

        $this->assertCount(3, $history);
        $this->assertSame(
            ['2026-09', '2026-09', '2026-08'],
            $history->pluck('season.season_key')->all(),
        );
        $this->assertSame(
            ['改名後', '改名後', '旧名'],
            $history->pluck('character_name_snapshot')->all(),
        );
        $this->assertSame([$hero->id], $history->pluck('character_id_snapshot')->unique()->values()->all());
    }

    public function test_deleted_character_keeps_hall_identity_and_name_without_live_relation(): void
    {
        $hero = $this->character('削除後も残る英雄');
        $identity = $hero->id;
        $this->season('2026-08', [
            SixHeroRoomKey::MIRACLE->value => $hero,
        ]);

        $hero->delete();

        $champion = $this->service()
            ->roomHistory(SixHeroRoomKey::MIRACLE)
            ->firstOrFail();
        $this->assertNull($champion->character_id);
        $this->assertSame($identity, $champion->character_id_snapshot);
        $this->assertSame('削除後も残る英雄', $champion->character_name_snapshot);
        $this->assertNull($champion->character);
        $this->assertFalse($champion->is_vacant);
    }

    public function test_character_summary_counts_every_crown_season_and_room_without_overall_ranking(): void
    {
        $hero = $this->character('複数冠英雄');
        $this->season('2026-08', [
            SixHeroRoomKey::SEAL_MAGIC->value => $hero,
            SixHeroRoomKey::DIVINE_SPEED->value => $hero,
        ]);
        $this->season('2026-09', [
            SixHeroRoomKey::SEAL_MAGIC->value => $hero,
            SixHeroRoomKey::REVERSE_TIME->value => $hero,
            SixHeroRoomKey::MIRACLE->value => $hero,
        ]);
        $this->season('2026-10', [
            SixHeroRoomKey::SEAL_MAGIC->value => $hero,
        ]);

        $summary = $this->service()->characterSummary($hero);

        $this->assertSame(6, $summary->heroCount);
        $this->assertSame(4, $summary->conqueredRoomCount);
        $this->assertSame(3, $summary->maxCrownsInSeason);
        $this->assertSame('2026-10', $summary->latestHeroSeasonKey);
        $this->assertSame([
            SixHeroRoomKey::SEAL_MAGIC->value => 3,
            SixHeroRoomKey::SEAL_BLADE->value => 0,
            SixHeroRoomKey::BURNING_LIFE->value => 0,
            SixHeroRoomKey::DIVINE_SPEED->value => 1,
            SixHeroRoomKey::REVERSE_TIME->value => 1,
            SixHeroRoomKey::MIRACLE->value => 1,
        ], $summary->heroCountsByRoom);
        $this->assertSame(
            [
                ['2026-10', 1, false],
                ['2026-09', 3, false],
                ['2026-08', 2, false],
            ],
            $summary->crownSeasons->map(
                fn ($crown): array => [$crown->seasonKey, $crown->crownCount, $crown->isSixCrown],
            )->all(),
        );
    }

    public function test_six_crown_is_generic_and_contains_all_rooms_in_fixed_order(): void
    {
        $hero = $this->character('六冠英雄');
        $this->season('2026-08', array_fill_keys(
            array_map(
                fn (SixHeroRoomKey $room): string => $room->value,
                SixHeroRoomKey::cases(),
            ),
            $hero,
        ));

        $summary = $this->service()->characterSummary($hero);
        $crown = $summary->crownSeasons->firstOrFail();

        $this->assertSame(6, $summary->heroCount);
        $this->assertSame(6, $summary->conqueredRoomCount);
        $this->assertSame(6, $summary->maxCrownsInSeason);
        $this->assertSame(6, $crown->crownCount);
        $this->assertTrue($crown->isSixCrown);
        $this->assertSame(SixHeroRoomKey::cases(), $crown->rooms);
    }

    public function test_streaks_are_room_scoped_cross_year_and_end_on_other_hero_or_vacancy(): void
    {
        $hero = $this->character('連覇英雄');
        $other = $this->character('別英雄');
        $this->season('2026-12', [
            SixHeroRoomKey::DIVINE_SPEED->value => $hero,
            SixHeroRoomKey::REVERSE_TIME->value => $hero,
        ]);
        $this->season('2027-01', [
            SixHeroRoomKey::DIVINE_SPEED->value => $hero,
            SixHeroRoomKey::REVERSE_TIME->value => $hero,
            SixHeroRoomKey::MIRACLE->value => $hero,
        ]);
        $this->season('2027-02', [
            SixHeroRoomKey::DIVINE_SPEED->value => $hero,
            SixHeroRoomKey::REVERSE_TIME->value => $other,
        ]);

        $summary = $this->service()->characterSummary($hero);

        $this->assertSame(3, $summary->longestStreaksByRoom[SixHeroRoomKey::DIVINE_SPEED->value]);
        $this->assertSame(3, $summary->currentStreaksByRoom[SixHeroRoomKey::DIVINE_SPEED->value]);
        $this->assertSame(2, $summary->longestStreaksByRoom[SixHeroRoomKey::REVERSE_TIME->value]);
        $this->assertSame(0, $summary->currentStreaksByRoom[SixHeroRoomKey::REVERSE_TIME->value]);
        $this->assertSame(1, $summary->longestStreaksByRoom[SixHeroRoomKey::MIRACLE->value]);
        $this->assertSame(0, $summary->currentStreaksByRoom[SixHeroRoomKey::MIRACLE->value]);
    }

    public function test_vacancy_other_hero_and_missing_calendar_month_break_streaks(): void
    {
        $hero = $this->character('中断確認英雄');
        $other = $this->character('中断確認別英雄');
        $this->season('2026-08', [
            SixHeroRoomKey::DIVINE_SPEED->value => $hero,
            SixHeroRoomKey::REVERSE_TIME->value => $hero,
        ]);
        $this->season('2026-09', [
            SixHeroRoomKey::REVERSE_TIME->value => $other,
        ]);
        $this->season('2026-10', [
            SixHeroRoomKey::DIVINE_SPEED->value => $hero,
            SixHeroRoomKey::REVERSE_TIME->value => $hero,
        ]);

        $summary = $this->service()->characterSummary($hero);
        $this->assertSame(1, $summary->longestStreaksByRoom[SixHeroRoomKey::DIVINE_SPEED->value]);
        $this->assertSame(1, $summary->currentStreaksByRoom[SixHeroRoomKey::DIVINE_SPEED->value]);
        $this->assertSame(1, $summary->longestStreaksByRoom[SixHeroRoomKey::REVERSE_TIME->value]);
        $this->assertSame(1, $summary->currentStreaksByRoom[SixHeroRoomKey::REVERSE_TIME->value]);

        SixHeroSeason::query()->where('season_key', '2026-09')->delete();
        $withoutSeptember = $this->service()->characterSummary($hero);
        $this->assertSame(1, $withoutSeptember->longestStreaksByRoom[SixHeroRoomKey::DIVINE_SPEED->value]);
        $this->assertSame(1, $withoutSeptember->currentStreaksByRoom[SixHeroRoomKey::DIVINE_SPEED->value]);
    }

    public function test_snapshot_migration_backfills_heroes_keeps_vacancies_null_and_rolls_back(): void
    {
        $hero = $this->character('移行対象英雄');
        $season = $this->season('2026-08', [
            SixHeroRoomKey::SEAL_MAGIC->value => $hero,
        ]);
        $heroRow = SixHeroChampion::query()
            ->where('season_id', $season->id)
            ->where('room_key', SixHeroRoomKey::SEAL_MAGIC->value)
            ->firstOrFail();
        $vacancyRow = SixHeroChampion::query()
            ->where('season_id', $season->id)
            ->where('room_key', SixHeroRoomKey::SEAL_BLADE->value)
            ->firstOrFail();
        $migration = require base_path(
            'database/migrations/2026_08_19_150000_add_character_id_snapshot_to_six_hero_champions_table.php',
        );

        $migration->down();
        try {
            $this->assertFalse(Schema::hasColumn('six_hero_champions', 'character_id_snapshot'));

            $migration->up();

            $this->assertTrue(Schema::hasColumn('six_hero_champions', 'character_id_snapshot'));
            $this->assertTrue(Schema::hasIndex(
                'six_hero_champions',
                'six_hero_champions_character_id_snapshot_idx',
            ));
            $this->assertSame(
                $hero->id,
                SixHeroChampion::query()->findOrFail($heroRow->id)->character_id_snapshot,
            );
            $this->assertNull(
                SixHeroChampion::query()->findOrFail($vacancyRow->id)->character_id_snapshot,
            );
        } finally {
            if (! Schema::hasColumn('six_hero_champions', 'character_id_snapshot')) {
                $migration->up();
            }
        }

        $hero->delete();
        $backfilled = SixHeroChampion::query()->findOrFail($heroRow->id);
        $this->assertNull($backfilled->character_id);
        $this->assertSame($hero->id, $backfilled->character_id_snapshot);
    }

    public function test_champion_identity_snapshot_cannot_be_changed_after_creation(): void
    {
        $hero = $this->character('不変identity英雄');
        $other = $this->character('差し替え対象');
        $season = $this->season('2026-08', [
            SixHeroRoomKey::SEAL_MAGIC->value => $hero,
        ]);
        $champion = SixHeroChampion::query()
            ->where('season_id', $season->id)
            ->where('room_key', SixHeroRoomKey::SEAL_MAGIC->value)
            ->firstOrFail();

        try {
            $champion->update(['character_id_snapshot' => $other->id]);
            $this->fail('An immutable Champion identity snapshot was changed.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $this->assertSame($hero->id, $champion->fresh()->character_id_snapshot);
    }

    public function test_database_rejects_bulk_identity_snapshot_updates_that_bypass_model_events(): void
    {
        $hero = $this->character('DB不変identity英雄');
        $other = $this->character('DB差し替え対象');
        $season = $this->season('2026-08', [
            SixHeroRoomKey::SEAL_MAGIC->value => $hero,
        ]);
        $champion = SixHeroChampion::query()
            ->where('season_id', $season->id)
            ->where('room_key', SixHeroRoomKey::SEAL_MAGIC->value)
            ->firstOrFail();
        $updates = [
            fn (): int => SixHeroChampion::query()
                ->whereKey($champion->id)
                ->update(['character_id_snapshot' => $other->id]),
            fn (): int => DB::table('six_hero_champions')
                ->where('id', $champion->id)
                ->update(['character_id_snapshot' => $other->id]),
        ];

        foreach ($updates as $update) {
            try {
                $update();
                $this->fail('A bulk query bypassed immutable Champion identity protection.');
            } catch (QueryException) {
                $this->assertSame(
                    $hero->id,
                    SixHeroChampion::query()->findOrFail($champion->id)->character_id_snapshot,
                );
            }
        }
    }

    public function test_snapshot_migration_rejects_an_already_deleted_hero_without_recoverable_identity(): void
    {
        $hero = $this->character('移行前削除英雄');
        $season = $this->season('2026-08', [
            SixHeroRoomKey::SEAL_MAGIC->value => $hero,
        ]);
        $heroRowId = SixHeroChampion::query()
            ->where('season_id', $season->id)
            ->where('room_key', SixHeroRoomKey::SEAL_MAGIC->value)
            ->value('id');
        $migration = require base_path(
            'database/migrations/2026_08_19_150000_add_character_id_snapshot_to_six_hero_champions_table.php',
        );

        $migration->down();
        try {
            $hero->delete();

            try {
                $migration->up();
                $this->fail('An unrecoverable historical identity was silently accepted.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('no identity', $exception->getMessage());
            }

            $this->assertFalse(Schema::hasColumn(
                'six_hero_champions',
                'character_id_snapshot',
            ));
        } finally {
            DB::table('six_hero_champions')->where('id', $heroRowId)->delete();
            if (! Schema::hasColumn('six_hero_champions', 'character_id_snapshot')) {
                $migration->up();
            }
        }
    }

    private function service(): SixHeroHallOfFameService
    {
        return app(SixHeroHallOfFameService::class);
    }

    private function character(string $name): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
        ]);
    }

    /**
     * @param  array<string, Character|array{0: Character, 1: string}>  $heroes
     */
    private function season(
        string $key,
        array $heroes = [],
        bool $finalized = true,
    ): SixHeroSeason {
        $startsAt = Carbon::parse("{$key}-01 00:00:00", 'Asia/Tokyo')->startOfMonth();
        $endsAt = $startsAt->copy()->addMonth();
        $season = SixHeroSeason::query()->create([
            'season_key' => $key,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'finalized_at' => $finalized ? $endsAt->copy()->addMinute() : null,
        ]);

        foreach (SixHeroRoomKey::cases() as $room) {
            $hero = $heroes[$room->value] ?? null;
            $character = is_array($hero) ? $hero[0] : $hero;
            $name = is_array($hero) ? $hero[1] : $character?->name;

            SixHeroChampion::query()->create([
                'season_id' => $season->id,
                'room_key' => $room,
                'character_id' => $character?->id,
                'character_id_snapshot' => $character?->id,
                'character_name_snapshot' => $name,
                'is_vacant' => $character === null,
                'vacancy_reason' => $character === null
                    ? SixHeroChampion::VACANCY_INSUFFICIENT_ACTIVITY
                    : null,
                'registered_count' => 0,
                'official_battle_count' => 0,
                'official_attack_wins' => $character === null ? null : 0,
                'official_attack_losses' => $character === null ? null : 0,
                'defense_wins' => $character === null ? null : 0,
                'defense_losses' => $character === null ? null : 0,
            ]);
        }

        return $season;
    }
}
