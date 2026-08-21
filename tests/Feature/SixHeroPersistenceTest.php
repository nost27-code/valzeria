<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Models\Character;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SixHeroPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_season_has_datetime_casts_nullable_finalized_at_and_half_open_boundaries(): void
    {
        $season = $this->season('2026-08');

        $this->assertInstanceOf(CarbonInterface::class, $season->starts_at);
        $this->assertInstanceOf(CarbonInterface::class, $season->ends_at);
        $this->assertNull($season->finalized_at);
        $season->update(['finalized_at' => '2026-09-01 00:05:00']);
        $this->assertInstanceOf(CarbonInterface::class, $season->fresh()->finalized_at);

        $this->assertTrue(SixHeroSeason::query()
            ->containing(CarbonImmutable::parse('2026-08-01 00:00:00'))
            ->whereKey($season->id)
            ->exists());
        $this->assertTrue(SixHeroSeason::query()
            ->containing(CarbonImmutable::parse('2026-08-31 23:59:59'))
            ->whereKey($season->id)
            ->exists());
        $this->assertFalse(SixHeroSeason::query()
            ->containing(CarbonImmutable::parse('2026-09-01 00:00:00'))
            ->whereKey($season->id)
            ->exists());
    }

    public function test_season_key_is_unique_in_the_database(): void
    {
        $this->season('2026-08');

        $this->expectException(QueryException::class);
        $this->season('2026-08');
    }

    public function test_same_character_cannot_be_registered_twice_in_one_season_and_room(): void
    {
        $season = $this->season('2026-08');
        $character = $this->character();
        $this->ranking($season, SixHeroRoomKey::DIVINE_SPEED, $character, 1);

        $this->expectException(QueryException::class);
        $this->ranking($season, SixHeroRoomKey::DIVINE_SPEED, $character, 2);
    }

    public function test_same_character_can_have_an_independent_rank_in_all_six_rooms(): void
    {
        $season = $this->season('2026-08');
        $character = $this->character();

        foreach (SixHeroRoomKey::cases() as $room) {
            $this->ranking($season, $room, $character, 1);
        }

        $rankings = SixHeroRanking::query()
            ->where('season_id', $season->id)
            ->where('character_id', $character->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(6, $rankings);
        $this->assertSame(SixHeroRoomKey::cases(), $rankings->pluck('room_key')->all());
        $this->assertTrue($rankings->every(
            fn (SixHeroRanking $ranking): bool => $ranking->rank === 1
                && $ranking->official_attack_wins === 0
                && $ranking->official_attack_losses === 0
                && $ranking->defense_wins === 0
                && $ranking->defense_losses === 0,
        ));
    }

    public function test_same_character_can_register_in_the_same_room_in_another_season(): void
    {
        $august = $this->season('2026-08');
        $september = $this->season(
            '2026-09',
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
        );
        $character = $this->character();

        $this->ranking($august, SixHeroRoomKey::DIVINE_SPEED, $character, 1);
        $this->ranking($september, SixHeroRoomKey::DIVINE_SPEED, $character, 1);

        $this->assertSame(2, SixHeroRanking::query()
            ->where('character_id', $character->id)
            ->where('room_key', SixHeroRoomKey::DIVINE_SPEED->value)
            ->count());
    }

    public function test_same_rank_cannot_exist_twice_in_one_season_and_room(): void
    {
        $season = $this->season('2026-08');
        $this->ranking($season, SixHeroRoomKey::DIVINE_SPEED, $this->character(), 1);

        $this->expectException(QueryException::class);
        $this->ranking($season, SixHeroRoomKey::DIVINE_SPEED, $this->character(), 1);
    }

    public function test_same_rank_is_allowed_in_another_room_or_season(): void
    {
        $august = $this->season('2026-08');
        $september = $this->season(
            '2026-09',
            '2026-09-01 00:00:00',
            '2026-10-01 00:00:00',
        );

        $this->ranking($august, SixHeroRoomKey::DIVINE_SPEED, $this->character(), 1);
        $this->ranking($august, SixHeroRoomKey::MIRACLE, $this->character(), 1);
        $this->ranking($september, SixHeroRoomKey::DIVINE_SPEED, $this->character(), 1);

        $this->assertDatabaseCount('six_hero_rankings', 3);
    }

    public function test_room_key_cast_relations_and_signed_rank_are_persisted(): void
    {
        $season = $this->season('2026-08');
        $character = $this->character();
        $ranking = $this->ranking(
            $season,
            SixHeroRoomKey::BURNING_LIFE,
            $character,
            -987,
        )->fresh();

        $this->assertSame(SixHeroRoomKey::BURNING_LIFE, $ranking->room_key);
        $this->assertSame(-987, $ranking->rank);
        $this->assertInstanceOf(CarbonInterface::class, $ranking->registered_at);
        $this->assertNull($ranking->first_place_since);
        $this->assertTrue($ranking->season->is($season));
        $this->assertTrue($ranking->character->is($character));
        $this->assertTrue($season->fresh()->rankings->contains($ranking));
    }

    private function season(
        string $seasonKey,
        string $startsAt = '2026-08-01 00:00:00',
        string $endsAt = '2026-09-01 00:00:00',
    ): SixHeroSeason {
        return SixHeroSeason::query()->create([
            'season_key' => $seasonKey,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'finalized_at' => null,
        ]);
    }

    private function character(): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => "六英雄基盤検証{$user->id}",
        ]);
    }

    private function ranking(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
        Character $character,
        int $rank,
    ): SixHeroRanking {
        return SixHeroRanking::query()->create([
            'season_id' => $season->id,
            'room_key' => $room,
            'character_id' => $character->id,
            'rank' => $rank,
            'registered_at' => '2026-08-01 12:00:00',
            'first_place_since' => null,
        ]);
    }
}
