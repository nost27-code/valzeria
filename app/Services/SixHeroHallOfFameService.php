<?php

namespace App\Services;

use App\Enums\SixHeroRoomKey;
use App\Models\Character;
use App\Models\SixHeroChampion;
use App\Models\SixHeroSeason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;

final class SixHeroHallOfFameService
{
    /**
     * @return Collection<int, SixHeroChampion>
     */
    public function seasonResults(SixHeroSeason $season): Collection
    {
        $finalizedSeason = SixHeroSeason::query()->findOrFail($season->getKey());
        if ($finalizedSeason->finalized_at === null) {
            return collect();
        }

        $byRoom = SixHeroChampion::query()
            ->with(['season', 'character'])
            ->where('season_id', $finalizedSeason->id)
            ->get()
            ->keyBy(fn (SixHeroChampion $champion): string => $champion->room_key->value);

        if ($byRoom->count() !== count(SixHeroRoomKey::cases())) {
            throw new LogicException(
                "Finalized Season {$finalizedSeason->season_key} does not have exactly six Champion snapshots.",
            );
        }

        return collect(SixHeroRoomKey::cases())->map(
            function (SixHeroRoomKey $room) use ($byRoom, $finalizedSeason): SixHeroChampion {
                $champion = $byRoom->get($room->value);
                if (! $champion instanceof SixHeroChampion) {
                    throw new LogicException(
                        "Finalized Season {$finalizedSeason->season_key} is missing the {$room->value} Champion snapshot.",
                    );
                }

                return $champion;
            },
        );
    }

    /**
     * @return Collection<int, SixHeroChampion>
     */
    public function roomHistory(
        SixHeroRoomKey $room,
        int $limit = 24,
    ): Collection {
        if ($limit < 1) {
            throw new InvalidArgumentException('Room history limit must be at least 1.');
        }

        return $this->finalizedChampionQuery()
            ->with(['season', 'character'])
            ->where('six_hero_champions.room_key', $room->value)
            ->orderByDesc('six_hero_seasons.starts_at')
            ->orderByDesc('six_hero_seasons.id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, SixHeroChampion>
     */
    public function characterHistory(Character $character): Collection
    {
        $identity = $this->characterSnapshotIdentity($character);
        $roomOrder = array_flip(array_map(
            fn (SixHeroRoomKey $room): string => $room->value,
            SixHeroRoomKey::cases(),
        ));

        return $this->finalizedChampionQuery()
            ->with('season')
            ->where('six_hero_champions.character_id_snapshot', $identity)
            ->where('six_hero_champions.is_vacant', false)
            ->orderByDesc('six_hero_seasons.starts_at')
            ->orderByDesc('six_hero_seasons.id')
            ->get()
            ->groupBy('season_id')
            ->flatMap(
                fn (Collection $champions): Collection => $champions
                    ->sortBy(
                        fn (SixHeroChampion $champion): int => $roomOrder[$champion->room_key->value],
                    )
                    ->values(),
            )
            ->values();
    }

    public function characterSummary(Character $character): SixHeroCharacterHeroSummary
    {
        $identity = $this->characterSnapshotIdentity($character);
        $history = $this->characterHistory($character);
        $heroCountsByRoom = $this->emptyRoomCounts();

        foreach ($history as $champion) {
            $heroCountsByRoom[$champion->room_key->value]++;
        }

        $crownSeasons = $history
            ->groupBy('season_id')
            ->map(function (Collection $champions): SixHeroCrownSeasonSummary {
                /** @var SixHeroChampion $first */
                $first = $champions->firstOrFail();
                $wonRooms = $champions
                    ->map(fn (SixHeroChampion $champion): string => $champion->room_key->value)
                    ->flip();
                $rooms = collect(SixHeroRoomKey::cases())
                    ->filter(fn (SixHeroRoomKey $room): bool => $wonRooms->has($room->value))
                    ->values()
                    ->all();
                $crownCount = count($rooms);

                return new SixHeroCrownSeasonSummary(
                    seasonKey: $first->season->season_key,
                    crownCount: $crownCount,
                    rooms: $rooms,
                    isSixCrown: $crownCount === count(SixHeroRoomKey::cases()),
                );
            })
            ->values();

        [$longestStreaksByRoom, $currentStreaksByRoom] = $this->streaksByRoom($identity);

        return new SixHeroCharacterHeroSummary(
            heroCount: $history->count(),
            conqueredRoomCount: count(array_filter(
                $heroCountsByRoom,
                fn (int $count): bool => $count > 0,
            )),
            maxCrownsInSeason: (int) ($crownSeasons->max('crownCount') ?? 0),
            heroCountsByRoom: $heroCountsByRoom,
            longestStreaksByRoom: $longestStreaksByRoom,
            currentStreaksByRoom: $currentStreaksByRoom,
            crownSeasons: $crownSeasons,
            latestHeroSeasonKey: $history->first()?->season->season_key,
        );
    }

    public function latestFinalizedSeason(): ?SixHeroSeason
    {
        return SixHeroSeason::query()
            ->whereNotNull('finalized_at')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{array<string, int>, array<string, int>}
     */
    private function streaksByRoom(int $identity): array
    {
        $longestByRoom = $this->emptyRoomCounts();
        $currentByRoom = $this->emptyRoomCounts();
        $latestFinalizedSeason = $this->latestFinalizedSeason();
        $allResults = $this->finalizedChampionQuery()
            ->with('season')
            ->orderBy('six_hero_seasons.starts_at')
            ->orderBy('six_hero_seasons.id')
            ->get();

        foreach (SixHeroRoomKey::cases() as $room) {
            $roomResults = $allResults
                ->filter(
                    fn (SixHeroChampion $champion): bool => $champion->room_key === $room,
                )
                ->values();
            $run = 0;
            $longest = 0;
            $previousSeasonKey = null;

            foreach ($roomResults as $champion) {
                $seasonKey = $champion->season->season_key;
                $this->parseSeasonKey($seasonKey);
                $isTargetHero = ! $champion->is_vacant
                    && (int) $champion->character_id_snapshot === $identity;

                if (! $isTargetHero) {
                    $run = 0;
                } elseif ($run > 0
                    && $previousSeasonKey !== null
                    && $this->isNextCalendarMonth($previousSeasonKey, $seasonKey)
                ) {
                    $run++;
                } else {
                    $run = 1;
                }

                $longest = max($longest, $run);
                $previousSeasonKey = $seasonKey;
            }

            $last = $roomResults->last();
            $isCurrent = $last instanceof SixHeroChampion
                && $latestFinalizedSeason !== null
                && (int) $last->season_id === (int) $latestFinalizedSeason->id
                && ! $last->is_vacant
                && (int) $last->character_id_snapshot === $identity;

            $longestByRoom[$room->value] = $longest;
            $currentByRoom[$room->value] = $isCurrent ? $run : 0;
        }

        return [$longestByRoom, $currentByRoom];
    }

    private function isNextCalendarMonth(string $previous, string $current): bool
    {
        return $this->parseSeasonKey($previous)
            ->addMonthNoOverflow()
            ->format('Y-m') === $current;
    }

    private function parseSeasonKey(string $seasonKey): CarbonImmutable
    {
        if (preg_match('/^\\d{4}-(?:0[1-9]|1[0-2])$/D', $seasonKey) !== 1) {
            throw new LogicException("Invalid Six Heroes season key: {$seasonKey}.");
        }

        return CarbonImmutable::createFromFormat(
            '!Y-m',
            $seasonKey,
            (string) config('app.timezone'),
        );
    }

    private function characterSnapshotIdentity(Character $character): int
    {
        $identity = (int) $character->getKey();
        if ($identity < 1) {
            throw new LogicException('Character must already exist before Hall history is queried.');
        }

        return $identity;
    }

    /**
     * @return array<string, int>
     */
    private function emptyRoomCounts(): array
    {
        return array_fill_keys(
            array_map(
                fn (SixHeroRoomKey $room): string => $room->value,
                SixHeroRoomKey::cases(),
            ),
            0,
        );
    }

    /**
     * @return Builder<SixHeroChampion>
     */
    private function finalizedChampionQuery(): Builder
    {
        return SixHeroChampion::query()
            ->select('six_hero_champions.*')
            ->join(
                'six_hero_seasons',
                'six_hero_seasons.id',
                '=',
                'six_hero_champions.season_id',
            )
            ->whereNotNull('six_hero_seasons.finalized_at');
    }
}
