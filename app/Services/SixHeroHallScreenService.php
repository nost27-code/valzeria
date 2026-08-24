<?php

namespace App\Services;

use App\Enums\SixHeroRoomKey;
use App\Exceptions\SixHeroRankingNotReadyException;
use App\Models\Character;
use App\Models\SixHeroBattleLog;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Support\SixHeroCompetitionRules;
use App\Support\SixHeroRoomUiCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class SixHeroHallScreenService
{
    private const NEW_LEADER_DISPLAY_HOURS = 24;

    public function __construct(
        private readonly SixHeroSeasonService $seasonService,
        private readonly SixHeroRankingInitializationService $rankingInitializationService,
        private readonly SixHeroRankingService $rankingService,
        private readonly SixHeroHallOfFameService $hallOfFameService,
        private readonly SixHeroHallPresenter $hallPresenter,
        private readonly SixHeroDailyUsageService $dailyUsageService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function screenData(
        Character $character,
        SixHeroRoomKey $selectedRoom,
    ): array {
        $season = $this->seasonService->currentSeason();

        try {
            $season = $this->rankingInitializationService->requireInitialized($season);
        } catch (SixHeroRankingNotReadyException) {
            return array_merge(
                $this->notReadyData($season, $selectedRoom),
                $this->hallData($season, $character, $selectedRoom),
            );
        }

        $metricsByRoom = SixHeroRanking::query()
            ->where('season_id', $season->id)
            ->select('room_key')
            ->selectRaw('COUNT(*) as registered_count')
            ->selectRaw('COALESCE(SUM(official_attack_wins), 0) as official_attack_wins')
            ->selectRaw('COALESCE(SUM(official_attack_losses), 0) as official_attack_losses')
            ->groupBy('room_key')
            ->get()
            ->keyBy(fn (SixHeroRanking $ranking): string => $ranking->room_key->value);

        $leadersByRoom = SixHeroRanking::query()
            ->with('character')
            ->where('season_id', $season->id)
            ->where('rank', 1)
            ->get()
            ->keyBy(fn (SixHeroRanking $ranking): string => $ranking->room_key->value);

        $leaderCrownCounts = $leadersByRoom
            ->countBy(fn (SixHeroRanking $ranking): int => (int) $ranking->character_id);
        $recentFirstPlaceChangesByRoom = $this->recentFirstPlaceChangesByRoom(
            $season,
            $leadersByRoom,
        );

        $myRankingsByRoom = SixHeroRanking::query()
            ->where('season_id', $season->id)
            ->where('character_id', $character->id)
            ->get()
            ->keyBy(fn (SixHeroRanking $ranking): string => $ranking->room_key->value);

        $rooms = collect(SixHeroRoomKey::cases())
            ->mapWithKeys(function (SixHeroRoomKey $room) use (
                $metricsByRoom,
                $leadersByRoom,
                $leaderCrownCounts,
                $recentFirstPlaceChangesByRoom,
                $myRankingsByRoom,
                $character,
            ): array {
                $metrics = $metricsByRoom->get($room->value);
                $registeredCount = (int) ($metrics?->registered_count ?? 0);
                $officialBattleCount = (int) ($metrics?->official_attack_wins ?? 0)
                    + (int) ($metrics?->official_attack_losses ?? 0);

                return [
                    $room->value => $this->roomOverview(
                        $room,
                        $leadersByRoom->get($room->value),
                        $myRankingsByRoom->get($room->value),
                        $registeredCount,
                        $officialBattleCount,
                        (int) $leaderCrownCounts->get(
                            (int) ($leadersByRoom->get($room->value)?->character_id ?? 0),
                            0,
                        ),
                        $recentFirstPlaceChangesByRoom->has($room->value),
                        (int) $character->id,
                    ),
                ];
            });

        /** @var SixHeroRanking|null $mySelectedRanking */
        $mySelectedRanking = $myRankingsByRoom->get($selectedRoom->value);
        $targets = $mySelectedRanking === null
            ? collect()
            : $this->rankingService
                ->targetEntries($mySelectedRanking)
                ->sortBy(fn (SixHeroRanking $target): int => (int) $target->rank)
                ->values();

        $attemptsUsed = $this->officialAttemptsUsed($character, $selectedRoom);

        return array_merge(
            $this->baseData($season, $selectedRoom),
            [
                'ready' => true,
                'rooms' => $rooms,
                'selectedOverview' => $rooms->get($selectedRoom->value),
                'targets' => $targets,
                'attemptsUsed' => $attemptsUsed,
                'attemptsRemaining' => SixHeroCompetitionRules::remainingOfficialAttempts(
                    $attemptsUsed,
                ),
                'attemptLimit' => SixHeroCompetitionRules::DAILY_OFFICIAL_ATTEMPT_LIMIT,
                'currentCharacterId' => (int) $character->id,
            ],
            $this->hallData($season, $character, $selectedRoom),
        );
    }

    public function officialAttemptsRemaining(
        Character $character,
        SixHeroRoomKey $room,
    ): int
    {
        return SixHeroCompetitionRules::remainingOfficialAttempts(
            $this->officialAttemptsUsed($character, $room),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function roomOverview(
        SixHeroRoomKey $room,
        ?SixHeroRanking $leader,
        ?SixHeroRanking $myRanking,
        int $registeredCount,
        int $officialBattleCount,
        int $leaderCrownCount,
        bool $leaderIsNew,
        int $currentCharacterId,
    ): array {
        $missing = [];
        $missingParticipants = max(
            0,
            SixHeroCompetitionRules::MINIMUM_REGISTERED_COUNT - $registeredCount,
        );
        $missingBattles = max(
            0,
            SixHeroCompetitionRules::MINIMUM_OFFICIAL_BATTLE_COUNT - $officialBattleCount,
        );
        if ($missingParticipants > 0) {
            $missing[] = "あと{$missingParticipants}人";
        }
        if ($missingBattles > 0) {
            $missing[] = "あと{$missingBattles}戦";
        }

        return [
            'key' => $room->value,
            'label' => $room->label(),
            'description' => SixHeroRoomUiCatalog::description($room),
            'accentClasses' => SixHeroRoomUiCatalog::accentClasses($room),
            'chamberPosition' => SixHeroRoomUiCatalog::chamberPosition($room),
            'leader' => $leader,
            'leaderTenureDays' => $this->leaderTenureDays($leader),
            'leaderIsNew' => $leader !== null && $leaderIsNew,
            'leaderCrownCount' => $leader === null ? 0 : max(1, $leaderCrownCount),
            'leaderIsCurrentCharacter' => $leader !== null
                && (int) $leader->character_id === $currentCharacterId,
            'myRanking' => $myRanking,
            'registeredCount' => $registeredCount,
            'officialBattleCount' => $officialBattleCount,
            'minimumRegisteredCount' => SixHeroCompetitionRules::MINIMUM_REGISTERED_COUNT,
            'minimumOfficialBattleCount' => SixHeroCompetitionRules::MINIMUM_OFFICIAL_BATTLE_COUNT,
            'requirementsMet' => $missing === [],
            'requirementStatus' => $missing === []
                ? '成立条件を満たしています'
                : implode('・', $missing),
        ];
    }

    private function leaderTenureDays(?SixHeroRanking $leader): ?int
    {
        if ($leader?->first_place_since === null) {
            return null;
        }

        $since = CarbonImmutable::instance($leader->first_place_since)
            ->setTimezone($this->timezone())
            ->startOfDay();
        $today = CarbonImmutable::now($this->timezone())->startOfDay();

        return max(1, (int) $since->diffInDays($today) + 1);
    }

    /**
     * @param  Collection<string, SixHeroRanking>  $leadersByRoom
     * @return Collection<string, SixHeroBattleLog>
     */
    private function recentFirstPlaceChangesByRoom(
        SixHeroSeason $season,
        Collection $leadersByRoom,
    ): Collection {
        if ($leadersByRoom->isEmpty()) {
            return collect();
        }

        $threshold = CarbonImmutable::now($this->timezone())
            ->subHours(self::NEW_LEADER_DISPLAY_HOURS);
        $candidates = $leadersByRoom->filter(
            fn (SixHeroRanking $leader): bool => $leader->first_place_since !== null
                && CarbonImmutable::instance($leader->first_place_since)
                    ->greaterThanOrEqualTo($threshold),
        );
        if ($candidates->isEmpty()) {
            return collect();
        }

        return SixHeroBattleLog::query()
            ->where('season_id', $season->id)
            ->where('battle_mode', SixHeroBattleLog::MODE_OFFICIAL)
            ->where('status', SixHeroBattleLog::STATUS_COMPLETED)
            ->where('is_attacker_win', true)
            ->where('rank_changed', true)
            ->where('attacker_new_rank', 1)
            ->where('completed_at', '>=', $threshold)
            ->where(function ($query) use ($candidates): void {
                foreach ($candidates as $roomKey => $leader) {
                    $query->orWhere(function ($roomQuery) use ($roomKey, $leader): void {
                        $roomQuery
                            ->where('room_key', $roomKey)
                            ->where('attacker_id', $leader->character_id);
                    });
                }
            })
            ->orderByDesc('completed_at')
            ->get(['id', 'room_key', 'attacker_id', 'completed_at'])
            ->unique(fn (SixHeroBattleLog $log): string => $log->room_key->value)
            ->keyBy(fn (SixHeroBattleLog $log): string => $log->room_key->value);
    }

    /**
     * @return array<string, mixed>
     */
    private function notReadyData(
        SixHeroSeason $season,
        SixHeroRoomKey $selectedRoom,
    ): array {
        return array_merge(
            $this->baseData($season, $selectedRoom),
            [
                'ready' => false,
                'rooms' => collect(),
                'selectedOverview' => null,
                'targets' => collect(),
                'attemptsUsed' => 0,
                'attemptsRemaining' => SixHeroCompetitionRules::DAILY_OFFICIAL_ATTEMPT_LIMIT,
                'attemptLimit' => SixHeroCompetitionRules::DAILY_OFFICIAL_ATTEMPT_LIMIT,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function baseData(
        SixHeroSeason $season,
        SixHeroRoomKey $selectedRoom,
    ): array {
        $startsAt = CarbonImmutable::instance($season->starts_at)
            ->setTimezone($this->timezone());
        $endsAt = CarbonImmutable::instance($season->ends_at)
            ->setTimezone($this->timezone());

        return [
            'season' => $season,
            'seasonLabel' => $startsAt->format('Y年n月期'),
            'seasonPeriodLabel' => $startsAt->format('Y/m/d H:i')
                .' 〜 '.$endsAt->format('Y/m/d H:i'),
            'selectedRoomKey' => $selectedRoom,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hallData(
        SixHeroSeason $season,
        Character $character,
        SixHeroRoomKey $selectedRoom,
    ): array {
        $previousMonth = CarbonImmutable::instance($season->starts_at)
            ->setTimezone($this->timezone())
            ->subMonthNoOverflow()
            ->startOfMonth();
        $previousSeasonKey = $previousMonth->format('Y-m');
        $previousSeason = SixHeroSeason::query()
            ->where('season_key', $previousSeasonKey)
            ->first();
        $previousStatus = 'missing';
        $previousResults = [];

        if ($previousSeason !== null) {
            if ($previousSeason->finalized_at === null) {
                $previousStatus = 'pending';
            } elseif (! SixHeroCompetitionRules::recordsChampionHistory(
                (string) $previousSeason->season_key,
            )) {
                $previousStatus = 'unrecorded';
            } else {
                $previousStatus = 'finalized';
                $previousResults = $this->hallOfFameService
                    ->seasonResults($previousSeason)
                    ->map(
                        fn ($champion): array => $this->hallPresenter->champion($champion),
                    )
                    ->values()
                    ->all();
            }
        }

        $selectedRoomHistory = $this->hallOfFameService
            ->roomHistory($selectedRoom, 12)
            ->map(
                fn ($champion): array => $this->hallPresenter->champion($champion),
            )
            ->values()
            ->all();

        return [
            'previousSixHeroes' => [
                'status' => $previousStatus,
                'seasonKey' => $previousSeasonKey,
                'seasonLabel' => $previousMonth->format('Y年n月期'),
                'results' => $previousResults,
            ],
            'selectedRoomHistory' => $selectedRoomHistory,
            'selectedRoomHistoryTitle' => '歴代 '.$this->hallPresenter
                ->roomHeroTitle($selectedRoom),
            'heroSummary' => $this->hallPresenter->characterSummary(
                $this->hallOfFameService->characterSummary($character),
            ),
        ];
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    private function officialAttemptsUsed(
        Character $character,
        SixHeroRoomKey $room,
    ): int
    {
        return $this->dailyUsageService->officialAttemptsUsed(
            $character,
            $room,
            $this->now(),
        );
    }

    private function timezone(): string
    {
        return (string) config('app.timezone');
    }
}
