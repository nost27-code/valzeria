<?php

namespace App\Services;

use App\Enums\SixHeroRoomKey;
use App\Models\Character;
use App\Models\SixHeroBattleLog;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Services\Battle\PvPBattleResolution;
use App\Services\Battle\SixHeroBattleContextFactory;
use App\Support\SixHeroCompetitionRules;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SixHeroOfficialBattleService
{
    public function __construct(
        private readonly PvPBattleService $pvpBattleService,
        private readonly SixHeroBattleContextFactory $battleContextFactory,
        private readonly SixHeroRankingService $rankingService,
        private readonly ?SixHeroRankingInitializationService $rankingInitializationService = null,
        private readonly ?SixHeroDailyUsageService $dailyUsageService = null,
        private readonly ?SixHeroRankingPublicLogService $rankingPublicLogService = null,
    ) {}

    public function execute(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
        Character $attacker,
        Character $defender,
    ): SixHeroOfficialBattleResult {
        $attackerId = (int) $attacker->getKey();
        $defenderId = (int) $defender->getKey();
        if ($attackerId <= 0 || $defenderId <= 0) {
            throw new DomainException('Both characters must already exist.');
        }
        if ($attackerId === $defenderId) {
            throw new DomainException('A character cannot challenge itself.');
        }

        $currentSeason = SixHeroSeason::query()
            ->whereKey($season->getKey())
            ->firstOrFail();
        $current = $this->now();
        $this->assertSeasonActive($currentSeason, $current);
        $initializedSeason = $this->rankingInitializer()->requireInitialized(
            $currentSeason,
            $current,
        );

        $preflight = $this->beginOfficialBattle(
            $initializedSeason,
            $room,
            $attacker,
            $defender,
        );
        $battleLog = $preflight['battleLog'];

        try {
            $resolution = $this->pvpBattleService->resolveBattle(
                $attacker,
                $defender,
                $this->battleContextFactory->make($room),
            );
        } catch (Throwable $exception) {
            $this->markFailed($battleLog, SixHeroBattleLog::FAILURE_BATTLE_RUNTIME);

            throw $exception;
        }

        try {
            $this->markResolved($battleLog, $resolution);
        } catch (Throwable $exception) {
            $this->markFailed($battleLog, SixHeroBattleLog::FAILURE_RESOLUTION_LOG);

            throw $exception;
        }

        try {
            $rankChange = $this->applyOutcomeIfOfficialStartValid(
                (int) $season->getKey(),
                (int) $battleLog->getKey(),
                $preflight['attackerRanking'],
                $preflight['defenderRanking'],
                $resolution->attackerWon,
            );
        } catch (Throwable $exception) {
            $this->markFailed($battleLog, SixHeroBattleLog::FAILURE_RANKING_OUTCOME);

            throw $exception;
        }

        if ($rankChange === null) {
            $battleLog->status = SixHeroBattleLog::STATUS_EXPIRED;
            $battleLog->save();

            return $this->result(
                $resolution,
                null,
                $battleLog,
                $preflight['officialAttemptsUsed'],
            );
        }

        $battleLog->fill([
            'status' => SixHeroBattleLog::STATUS_COMPLETED,
            'rank_changed' => $rankChange->rankChanged,
            'attacker_old_rank' => $rankChange->attackerOldRank,
            'attacker_new_rank' => $rankChange->attackerNewRank,
            'defender_old_rank' => $rankChange->defenderOldRank,
            'defender_new_rank' => $rankChange->defenderNewRank,
            'completed_at' => $this->now(),
        ]);
        $battleLog->save();

        $this->publishRankChangeSafely(
            $room,
            $attacker,
            $defender,
            $rankChange,
        );

        return $this->result(
            $resolution,
            $rankChange,
            $battleLog,
            $preflight['officialAttemptsUsed'],
        );
    }

    private function publishRankChangeSafely(
        SixHeroRoomKey $room,
        Character $attacker,
        Character $defender,
        SixHeroRankChangeResult $rankChange,
    ): void {
        try {
            ($this->rankingPublicLogService ?? app(SixHeroRankingPublicLogService::class))
                ->publish($room, $attacker, $defender, $rankChange);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return array{
     *     battleLog: SixHeroBattleLog,
     *     attackerRanking: SixHeroRanking,
     *     defenderRanking: SixHeroRanking,
     *     officialAttemptsUsed: int
     * }
     */
    private function beginOfficialBattle(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
        Character $attacker,
        Character $defender,
    ): array {
        $seasonId = (int) $season->getKey();
        $attackerId = (int) $attacker->getKey();
        $defenderId = (int) $defender->getKey();

        return DB::transaction(function () use (
            $seasonId,
            $room,
            $attackerId,
            $defenderId,
        ): array {
            $lockedSeason = SixHeroSeason::query()
                ->whereKey($seasonId)
                ->lockForUpdate()
                ->firstOrFail();
            $now = $this->now();
            $this->assertSeasonActive($lockedSeason, $now);

            if ($attackerId <= 0 || $defenderId <= 0) {
                throw new DomainException('Both characters must already exist.');
            }
            if ($attackerId === $defenderId) {
                throw new DomainException('A character cannot challenge itself.');
            }

            $rankings = SixHeroRanking::query()
                ->where('season_id', $lockedSeason->id)
                ->where('room_key', $room->value)
                ->whereIn('character_id', [$attackerId, $defenderId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (SixHeroRanking $ranking): int => (int) $ranking->character_id);
            if (! $rankings->has($attackerId) || ! $rankings->has($defenderId)) {
                throw new DomainException('Both characters must be registered in this room.');
            }

            /** @var SixHeroRanking $attackerRanking */
            $attackerRanking = $rankings->get($attackerId);
            /** @var SixHeroRanking $defenderRanking */
            $defenderRanking = $rankings->get($defenderId);
            if (! $this->rankingService->isChallengeTarget(
                $attackerRanking,
                $defenderRanking,
            )) {
                throw new DomainException('The defender is not an eligible challenge target.');
            }

            $officialAttemptsUsed = $this->dailyUsage()
                ->consumeOfficialAttempt($attackerId, $room, $now);

            $battleLog = SixHeroBattleLog::query()->create([
                'season_id' => $lockedSeason->id,
                'room_key' => $room,
                'battle_mode' => SixHeroBattleLog::MODE_OFFICIAL,
                'status' => SixHeroBattleLog::STATUS_STARTED,
                'attacker_id' => $attackerId,
                'defender_id' => $defenderId,
                'attacker_rank_at_start' => $attackerRanking->rank,
                'defender_rank_at_start' => $defenderRanking->rank,
                'daily_attempt_number' => $officialAttemptsUsed,
                'started_at' => $now,
            ]);

            return [
                'battleLog' => $battleLog,
                'attackerRanking' => $attackerRanking,
                'defenderRanking' => $defenderRanking,
                'officialAttemptsUsed' => $officialAttemptsUsed,
            ];
        });
    }

    private function markResolved(
        SixHeroBattleLog $battleLog,
        PvPBattleResolution $resolution,
    ): void {
        $battleLog->fill([
            'status' => SixHeroBattleLog::STATUS_RESOLVED,
            'is_attacker_win' => $resolution->attackerWon,
            'turn_count' => $resolution->turnCount,
            'attacker_hp_ratio' => $resolution->attackerHpRatio(),
            'defender_hp_ratio' => $resolution->defenderHpRatio(),
            'resolved_at' => $this->now(),
        ]);
        $battleLog->save();
    }

    private function applyOutcomeIfOfficialStartValid(
        int $seasonId,
        int $battleLogId,
        SixHeroRanking $attackerRanking,
        SixHeroRanking $defenderRanking,
        bool $attackerWon,
    ): ?SixHeroRankChangeResult {
        return DB::transaction(function () use (
            $seasonId,
            $battleLogId,
            $attackerRanking,
            $defenderRanking,
            $attackerWon,
        ): ?SixHeroRankChangeResult {
            $lockedSeason = SixHeroSeason::query()
                ->whereKey($seasonId)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedBattleLog = SixHeroBattleLog::query()
                ->whereKey($battleLogId)
                ->where('season_id', $lockedSeason->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedSeason->finalized_at !== null
                || $lockedBattleLog->battle_mode !== SixHeroBattleLog::MODE_OFFICIAL
                || $lockedBattleLog->started_at->lessThan($lockedSeason->starts_at)
                || ! $lockedBattleLog->started_at->lessThan($lockedSeason->ends_at)
            ) {
                return null;
            }

            return $this->rankingService->applyRankedBattleOutcome(
                $attackerRanking,
                $defenderRanking,
                $attackerWon,
            );
        });
    }

    private function markFailed(SixHeroBattleLog $battleLog, string $failureCode): void
    {
        try {
            $current = SixHeroBattleLog::query()->whereKey($battleLog->getKey())->first();
            if ($current === null) {
                return;
            }

            $current->fill([
                'status' => SixHeroBattleLog::STATUS_FAILED,
                'failed_at' => $this->now(),
                'failure_code' => $failureCode,
            ]);
            $current->save();
            $battleLog->refresh();
        } catch (Throwable $loggingFailure) {
            report($loggingFailure);
        }
    }

    private function result(
        PvPBattleResolution $resolution,
        ?SixHeroRankChangeResult $rankChange,
        SixHeroBattleLog $battleLog,
        int $officialAttemptsUsed,
    ): SixHeroOfficialBattleResult {
        return new SixHeroOfficialBattleResult(
            resolution: $resolution,
            rankChange: $rankChange,
            battleLog: $battleLog->fresh(),
            officialAttemptsUsed: $officialAttemptsUsed,
            officialAttemptsRemaining: SixHeroCompetitionRules::remainingOfficialAttempts(
                $officialAttemptsUsed,
            ),
        );
    }

    private function assertSeasonActive(
        SixHeroSeason $season,
        CarbonInterface $at,
    ): void {
        if (! $this->isSeasonActive($season, $at)) {
            throw new DomainException('Season is not active.');
        }
    }

    private function isSeasonActive(SixHeroSeason $season, CarbonInterface $at): bool
    {
        return $season->finalized_at === null
            && $season->starts_at->lessThanOrEqualTo($at)
            && $at->lessThan($season->ends_at);
    }

    private function now(): CarbonInterface
    {
        return now(config('app.timezone'));
    }

    private function rankingInitializer(): SixHeroRankingInitializationService
    {
        return $this->rankingInitializationService
            ?? app(SixHeroRankingInitializationService::class);
    }

    private function dailyUsage(): SixHeroDailyUsageService
    {
        return $this->dailyUsageService ?? app(SixHeroDailyUsageService::class);
    }
}
