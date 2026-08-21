<?php

namespace App\Services;

use App\Enums\SixHeroRoomKey;
use App\Models\Character;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Services\Battle\SixHeroBattleContextFactory;
use Carbon\CarbonInterface;
use DomainException;

final class SixHeroPracticeBattleService
{
    public function __construct(
        private readonly PvPBattleService $pvpBattleService,
        private readonly SixHeroBattleContextFactory $battleContextFactory,
        private readonly ?SixHeroRankingInitializationService $rankingInitializationService = null,
    ) {}

    public function execute(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
        Character $attacker,
        Character $defender,
    ): SixHeroPracticeBattleResult {
        $attackerId = (int) $attacker->getKey();
        $defenderId = (int) $defender->getKey();
        if ($attackerId <= 0 || $defenderId <= 0) {
            throw new DomainException('Both characters must already exist.');
        }
        if ($attackerId === $defenderId) {
            throw new DomainException('A character cannot practice against itself.');
        }

        $currentSeason = SixHeroSeason::query()
            ->whereKey($season->getKey())
            ->firstOrFail();
        $current = $this->now();
        $this->assertSeasonActive($currentSeason, $current);
        $currentSeason = $this->rankingInitializer()->requireInitialized(
            $currentSeason,
            $current,
        );

        $registeredCharacterIds = SixHeroRanking::query()
            ->where('season_id', $currentSeason->id)
            ->where('room_key', $room->value)
            ->whereIn('character_id', [$attackerId, $defenderId])
            ->pluck('character_id')
            ->map(static fn (mixed $characterId): int => (int) $characterId);
        if (! $registeredCharacterIds->contains($attackerId)
            || ! $registeredCharacterIds->contains($defenderId)
        ) {
            throw new DomainException('Both characters must be registered in this room.');
        }

        return new SixHeroPracticeBattleResult(
            resolution: $this->pvpBattleService->resolveBattle(
                $attacker,
                $defender,
                $this->battleContextFactory->make($room),
            ),
            room: $room,
        );
    }

    private function assertSeasonActive(
        SixHeroSeason $season,
        CarbonInterface $at,
    ): void {
        if ($season->finalized_at !== null
            || $season->starts_at->greaterThan($at)
            || $at->greaterThanOrEqualTo($season->ends_at)
        ) {
            throw new DomainException('Season is not active.');
        }
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
}
