<?php

namespace App\Services;

use App\Enums\SixHeroRoomKey;
use App\Models\SixHeroBattleLog;
use App\Models\SixHeroChampion;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Support\SixHeroCompetitionRules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class SixHeroSeasonFinalizationService
{
    public function __construct(
        private readonly SixHeroSeasonService $seasonService,
    ) {}

    public function finalizeSeason(
        SixHeroSeason $season,
        ?CarbonInterface $at = null,
    ): SixHeroSeasonFinalizationResult {
        $seasonId = (int) $season->getKey();
        if ($seasonId <= 0) {
            throw new LogicException('Season must already exist before finalization.');
        }

        $current = $this->inAppTimezone($at);

        return DB::transaction(function () use (
            $seasonId,
            $current,
        ): SixHeroSeasonFinalizationResult {
            $lockedSeason = SixHeroSeason::query()
                ->whereKey($seasonId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->seasonService->assertMatchesCalendarMonth($lockedSeason);

            if ($lockedSeason->finalized_at !== null) {
                return new SixHeroSeasonFinalizationResult(
                    season: $lockedSeason,
                    finalized: true,
                    alreadyFinalized: true,
                    pendingBattles: false,
                    pendingBattleCount: 0,
                    champions: $this->finalizedResults($lockedSeason),
                );
            }

            if ($lockedSeason->ends_at->greaterThan($current)) {
                throw new LogicException('Season has not ended yet.');
            }

            $pendingBattleCount = $this->pendingBattleCount($lockedSeason);
            if ($pendingBattleCount > 0) {
                return new SixHeroSeasonFinalizationResult(
                    season: $lockedSeason,
                    finalized: false,
                    alreadyFinalized: false,
                    pendingBattles: true,
                    pendingBattleCount: $pendingBattleCount,
                    champions: collect(),
                );
            }

            if (SixHeroChampion::query()
                ->where('season_id', $lockedSeason->id)
                ->exists()
            ) {
                throw new LogicException(
                    "Unfinalized Season {$lockedSeason->season_key} already has Champion snapshots.",
                );
            }

            $champions = collect();
            if (SixHeroCompetitionRules::recordsChampionHistory(
                (string) $lockedSeason->season_key,
            )) {
                foreach (SixHeroRoomKey::cases() as $room) {
                    $champions->push($this->createRoomSnapshot($lockedSeason, $room));
                }
            }

            $lockedSeason->forceFill(['finalized_at' => $current])->save();

            return new SixHeroSeasonFinalizationResult(
                season: $lockedSeason->fresh(),
                finalized: true,
                alreadyFinalized: false,
                pendingBattles: false,
                pendingBattleCount: 0,
                champions: $champions,
            );
        }, 3);
    }

    /**
     * @return Collection<int, SixHeroSeasonFinalizationResult>
     */
    public function finalizeEndedSeasons(
        ?CarbonInterface $at = null,
    ): Collection {
        $current = $this->inAppTimezone($at);

        return SixHeroSeason::query()
            ->whereNull('finalized_at')
            ->where('ends_at', '<=', $current)
            ->orderBy('ends_at')
            ->orderBy('id')
            ->get()
            ->map(
                fn (SixHeroSeason $season): SixHeroSeasonFinalizationResult => $this->finalizeSeason($season, $current),
            )
            ->values();
    }

    private function pendingBattleCount(SixHeroSeason $season): int
    {
        return SixHeroBattleLog::query()
            ->where('season_id', $season->id)
            ->where('battle_mode', SixHeroBattleLog::MODE_OFFICIAL)
            ->where('started_at', '<', $season->ends_at)
            ->whereIn('status', [
                SixHeroBattleLog::STATUS_STARTED,
                SixHeroBattleLog::STATUS_RESOLVED,
            ])
            ->count();
    }

    private function createRoomSnapshot(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
    ): SixHeroChampion {
        $metrics = SixHeroRanking::query()
            ->where('season_id', $season->id)
            ->where('room_key', $room->value)
            ->selectRaw('COUNT(*) as registered_count')
            ->selectRaw(
                'COALESCE(SUM(official_attack_wins + official_attack_losses), 0)'
                .' as official_battle_count',
            )
            ->firstOrFail();
        $registeredCount = (int) $metrics->registered_count;
        $officialBattleCount = (int) $metrics->official_battle_count;

        if ($registeredCount < SixHeroCompetitionRules::MINIMUM_REGISTERED_COUNT) {
            return $this->createVacancy(
                $season,
                $room,
                SixHeroChampion::VACANCY_INSUFFICIENT_PARTICIPANTS,
                $registeredCount,
                $officialBattleCount,
            );
        }

        if ($officialBattleCount < SixHeroCompetitionRules::MINIMUM_OFFICIAL_BATTLE_COUNT) {
            return $this->createVacancy(
                $season,
                $room,
                SixHeroChampion::VACANCY_INSUFFICIENT_ACTIVITY,
                $registeredCount,
                $officialBattleCount,
            );
        }

        $rankOne = SixHeroRanking::query()
            ->with('character')
            ->where('season_id', $season->id)
            ->where('room_key', $room->value)
            ->where('rank', 1)
            ->first();
        if ($rankOne === null || $rankOne->character === null) {
            throw new LogicException(
                "Six Heroes Season {$season->season_key} {$room->value} has no rank 1 Character.",
            );
        }

        return SixHeroChampion::query()->create([
            'season_id' => $season->id,
            'room_key' => $room,
            'character_id' => $rankOne->character_id,
            'character_id_snapshot' => $rankOne->character_id,
            'character_name_snapshot' => $rankOne->character->name,
            'is_vacant' => false,
            'vacancy_reason' => null,
            'registered_count' => $registeredCount,
            'official_battle_count' => $officialBattleCount,
            'official_attack_wins' => $rankOne->official_attack_wins,
            'official_attack_losses' => $rankOne->official_attack_losses,
            'defense_wins' => $rankOne->defense_wins,
            'defense_losses' => $rankOne->defense_losses,
        ]);
    }

    private function createVacancy(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
        string $reason,
        int $registeredCount,
        int $officialBattleCount,
    ): SixHeroChampion {
        return SixHeroChampion::query()->create([
            'season_id' => $season->id,
            'room_key' => $room,
            'character_id' => null,
            'character_id_snapshot' => null,
            'character_name_snapshot' => null,
            'is_vacant' => true,
            'vacancy_reason' => $reason,
            'registered_count' => $registeredCount,
            'official_battle_count' => $officialBattleCount,
            'official_attack_wins' => null,
            'official_attack_losses' => null,
            'defense_wins' => null,
            'defense_losses' => null,
        ]);
    }

    /**
     * @return Collection<int, SixHeroChampion>
     */
    private function finalizedResults(SixHeroSeason $season): Collection
    {
        if (! SixHeroCompetitionRules::recordsChampionHistory(
            (string) $season->season_key,
        )) {
            if (SixHeroChampion::query()->where('season_id', $season->id)->exists()) {
                throw new LogicException(
                    "Preseason {$season->season_key} must not have Champion snapshots.",
                );
            }

            return collect();
        }

        $byRoom = SixHeroChampion::query()
            ->where('season_id', $season->id)
            ->get()
            ->keyBy(fn (SixHeroChampion $champion): string => $champion->room_key->value);
        if ($byRoom->count() !== count(SixHeroRoomKey::cases())) {
            throw new LogicException(
                "Finalized Season {$season->season_key} does not have exactly six Champion snapshots.",
            );
        }

        return collect(SixHeroRoomKey::cases())->map(
            function (SixHeroRoomKey $room) use ($byRoom): SixHeroChampion {
                $champion = $byRoom->get($room->value);
                if (! $champion instanceof SixHeroChampion) {
                    throw new LogicException(
                        "Finalized Season is missing the {$room->value} Champion snapshot.",
                    );
                }

                return $champion;
            },
        );
    }

    private function inAppTimezone(?CarbonInterface $at): CarbonImmutable
    {
        $timezone = (string) config('app.timezone');

        return $at === null
            ? CarbonImmutable::now($timezone)
            : CarbonImmutable::instance($at)->setTimezone($timezone);
    }
}
