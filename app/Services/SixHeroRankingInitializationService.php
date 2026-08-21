<?php

namespace App\Services;

use App\Enums\SixHeroRoomKey;
use App\Exceptions\SixHeroRankingNotReadyException;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

final class SixHeroRankingInitializationService
{
    public function __construct(
        private readonly SixHeroSeasonService $seasonService,
        private readonly SixHeroSeasonFinalizationService $finalizationService,
    ) {}

    public function initialize(
        SixHeroSeason $season,
        ?CarbonInterface $at = null,
    ): SixHeroRankingInitializationResult {
        $seasonId = (int) $season->getKey();
        if ($seasonId <= 0) {
            throw new LogicException('Season must already exist before ranking initialization.');
        }

        $current = $this->inAppTimezone($at);
        $target = SixHeroSeason::query()->findOrFail($seasonId);
        if ($target->ranking_initialized_at !== null) {
            return $this->alreadyInitializedResult($target, null);
        }

        $this->seasonService->assertMatchesCalendarMonth($target);
        $previousKey = $this->previousSeasonKey($target);
        $source = SixHeroSeason::query()
            ->where('season_key', $previousKey)
            ->first();
        $this->assertTargetWritable($target);

        if ($source !== null) {
            $this->seasonService->assertMatchesCalendarMonth($source);
            if ($source->finalized_at === null) {
                if ($source->ends_at->greaterThan($current)) {
                    return $this->waitingResult($target, $source);
                }

                // Do not hold the target Season lock while finalizing the source Season.
                $finalization = $this->finalizationService->finalizeSeason($source, $current);
                if ($finalization->pendingBattles) {
                    return $this->waitingResult($target, $finalization->season);
                }

                $source = $finalization->season;
            }
        }

        return DB::transaction(function () use (
            $seasonId,
            $previousKey,
            $current,
        ): SixHeroRankingInitializationResult {
            $lockedTarget = SixHeroSeason::query()
                ->whereKey($seasonId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTarget->ranking_initialized_at !== null) {
                return $this->alreadyInitializedResult($lockedTarget, null);
            }
            $this->seasonService->assertMatchesCalendarMonth($lockedTarget);
            $this->assertTargetWritable($lockedTarget);

            $lockedSource = SixHeroSeason::query()
                ->where('season_key', $previousKey)
                ->first();
            if ($lockedSource !== null) {
                $this->seasonService->assertMatchesCalendarMonth($lockedSource);
            }

            // A source Season inserted or changed after the preflight is retried later.
            // Finalization must never run while the current Season row is locked.
            if ($lockedSource !== null && $lockedSource->finalized_at === null) {
                return $this->waitingResult($lockedTarget, $lockedSource);
            }

            $sourceRankingCount = $lockedSource === null
                ? 0
                : SixHeroRanking::query()
                    ->where('season_id', $lockedSource->id)
                    ->count();
            $targetRankingCount = SixHeroRanking::query()
                ->where('season_id', $lockedTarget->id)
                ->count();

            if ($sourceRankingCount > 0) {
                $this->assertSourceRankingsValid($lockedSource);
                if ($targetRankingCount > 0) {
                    throw new LogicException(
                        "Six Heroes Season {$lockedTarget->season_key} already has Ranking rows; carryover cannot be merged.",
                    );
                }
            }

            $copiedRankingCount = $lockedSource === null
                ? 0
                : $this->copyRankings($lockedSource, $lockedTarget);

            $lockedTarget->forceFill([
                'ranking_initialized_at' => $current,
            ])->save();

            return new SixHeroRankingInitializationResult(
                season: $lockedTarget->fresh(),
                initialized: true,
                alreadyInitialized: false,
                waitingForPreviousFinalization: false,
                sourceSeason: $lockedSource,
                copiedRankingCount: $copiedRankingCount,
            );
        }, 3);
    }

    public function requireInitialized(
        SixHeroSeason $season,
        ?CarbonInterface $at = null,
    ): SixHeroSeason {
        $result = $this->initialize($season, $at);
        if (! $result->initialized) {
            throw new SixHeroRankingNotReadyException(
                '月次ランキングを準備しています。少し後でもう一度お試しください。',
            );
        }

        return $result->season;
    }

    private function assertTargetWritable(SixHeroSeason $season): void
    {
        if ($season->finalized_at !== null) {
            throw new LogicException(
                "Finalized Season {$season->season_key} cannot initialize rankings.",
            );
        }
    }

    private function assertSourceRankingsValid(SixHeroSeason $source): void
    {
        $validRooms = array_map(
            static fn (SixHeroRoomKey $room): string => $room->value,
            SixHeroRoomKey::cases(),
        );
        if (SixHeroRanking::query()
            ->where('season_id', $source->id)
            ->whereNotIn('room_key', $validRooms)
            ->exists()
        ) {
            throw new LogicException(
                "Finalized Season {$source->season_key} has an unknown room Ranking.",
            );
        }
        if (SixHeroRanking::query()
            ->where('season_id', $source->id)
            ->where('rank', '<=', 0)
            ->exists()
        ) {
            throw new LogicException(
                "Finalized Season {$source->season_key} Rankings must have positive ranks.",
            );
        }
    }

    private function copyRankings(
        SixHeroSeason $source,
        SixHeroSeason $target,
    ): int {
        $copiedRankingCount = 0;
        foreach (SixHeroRoomKey::cases() as $room) {
            $sourceRankings = SixHeroRanking::query()
                ->where('season_id', $source->id)
                ->where('room_key', $room->value)
                ->orderBy('rank')
                ->orderBy('id')
                ->get();

            foreach ($sourceRankings as $index => $sourceRanking) {
                $rank = $index + 1;
                SixHeroRanking::query()->create([
                    'season_id' => $target->id,
                    'room_key' => $room,
                    'character_id' => $sourceRanking->character_id,
                    'rank' => $rank,
                    'official_attack_wins' => 0,
                    'official_attack_losses' => 0,
                    'defense_wins' => 0,
                    'defense_losses' => 0,
                    'registered_at' => $target->starts_at,
                    'first_place_since' => $rank === 1 ? $target->starts_at : null,
                ]);
                $copiedRankingCount++;
            }
        }

        return $copiedRankingCount;
    }

    private function alreadyInitializedResult(
        SixHeroSeason $target,
        ?SixHeroSeason $source,
    ): SixHeroRankingInitializationResult {
        return new SixHeroRankingInitializationResult(
            season: $target,
            initialized: true,
            alreadyInitialized: true,
            waitingForPreviousFinalization: false,
            sourceSeason: $source,
            copiedRankingCount: 0,
        );
    }

    private function waitingResult(
        SixHeroSeason $target,
        SixHeroSeason $source,
    ): SixHeroRankingInitializationResult {
        return new SixHeroRankingInitializationResult(
            season: $target,
            initialized: false,
            alreadyInitialized: false,
            waitingForPreviousFinalization: true,
            sourceSeason: $source,
            copiedRankingCount: 0,
        );
    }

    private function previousSeasonKey(SixHeroSeason $season): string
    {
        return CarbonImmutable::instance($season->starts_at)
            ->setTimezone($this->timezone())
            ->subMonthNoOverflow()
            ->format('Y-m');
    }

    private function inAppTimezone(?CarbonInterface $at): CarbonImmutable
    {
        return $at === null
            ? CarbonImmutable::now($this->timezone())
            : CarbonImmutable::instance($at)->setTimezone($this->timezone());
    }

    private function timezone(): string
    {
        return (string) config('app.timezone');
    }
}
