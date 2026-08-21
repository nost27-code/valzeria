<?php

namespace App\Services;

use App\Enums\SixHeroRoomKey;
use App\Models\Character;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SixHeroRankingService
{
    public function __construct(
        private readonly ?SixHeroRankingInitializationService $rankingInitializationService = null,
    ) {}

    public function register(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
        Character $character,
    ): SixHeroRanking {
        $initializedSeason = $this->rankingInitializer()->requireInitialized($season);

        return DB::transaction(function () use ($initializedSeason, $room, $character): SixHeroRanking {
            $lockedSeason = SixHeroSeason::query()
                ->whereKey($initializedSeason->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertSeasonWritable($lockedSeason);

            $existing = SixHeroRanking::query()
                ->where('season_id', $lockedSeason->id)
                ->where('room_key', $room->value)
                ->where('character_id', $character->getKey())
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $maxRank = (int) (SixHeroRanking::query()
                ->where('season_id', $lockedSeason->id)
                ->where('room_key', $room->value)
                ->max('rank') ?? 0);
            $rank = max(0, $maxRank) + 1;
            $registeredAt = now();

            return SixHeroRanking::query()->create([
                'season_id' => $lockedSeason->id,
                'room_key' => $room,
                'character_id' => $character->getKey(),
                'rank' => $rank,
                'official_attack_wins' => 0,
                'official_attack_losses' => 0,
                'defense_wins' => 0,
                'defense_losses' => 0,
                'registered_at' => $registeredAt,
                'first_place_since' => $rank === 1 ? $registeredAt : null,
            ]);
        });
    }

    public function rankingFor(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
        Character $character,
    ): ?SixHeroRanking {
        return SixHeroRanking::query()
            ->where('season_id', $season->getKey())
            ->where('room_key', $room->value)
            ->where('character_id', $character->getKey())
            ->first();
    }

    public function topEntries(
        SixHeroSeason $season,
        SixHeroRoomKey $room,
        int $limit = 6,
    ): Collection {
        if ($limit <= 0) {
            return collect();
        }

        return SixHeroRanking::query()
            ->where('season_id', $season->getKey())
            ->where('room_key', $room->value)
            ->orderBy('rank')
            ->limit($limit)
            ->get();
    }

    public function targetEntries(
        SixHeroRanking $myRanking,
        int $range = 3,
    ): Collection {
        if ($range <= 0 || $myRanking->getKey() === null) {
            return collect();
        }

        $current = SixHeroRanking::query()->whereKey($myRanking->getKey())->first();
        if ($current === null || (int) $current->rank <= 1) {
            return collect();
        }

        return SixHeroRanking::query()
            ->with('character')
            ->where('season_id', $current->season_id)
            ->where('room_key', $current->room_key->value)
            ->where('rank', '<', $current->rank)
            ->orderByDesc('rank')
            ->limit($range)
            ->get();
    }

    public function isChallengeTarget(
        SixHeroRanking $attacker,
        SixHeroRanking $defender,
        int $range = 3,
    ): bool {
        $attackerId = (int) $attacker->getKey();
        $defenderId = (int) $defender->getKey();
        if ($range <= 0
            || $attackerId <= 0
            || $defenderId <= 0
            || $attackerId === $defenderId
        ) {
            return false;
        }

        $rankings = SixHeroRanking::query()
            ->whereIn('id', [$attackerId, $defenderId])
            ->get()
            ->keyBy(fn (SixHeroRanking $ranking): int => (int) $ranking->id);
        if (! $rankings->has($attackerId) || ! $rankings->has($defenderId)) {
            return false;
        }

        /** @var SixHeroRanking $currentAttacker */
        $currentAttacker = $rankings->get($attackerId);
        /** @var SixHeroRanking $currentDefender */
        $currentDefender = $rankings->get($defenderId);
        if ((int) $currentAttacker->character_id === (int) $currentDefender->character_id
            || (int) $currentAttacker->season_id !== (int) $currentDefender->season_id
            || $currentAttacker->room_key !== $currentDefender->room_key
        ) {
            return false;
        }

        $rankDifference = (int) $currentAttacker->rank - (int) $currentDefender->rank;

        return $rankDifference >= 1 && $rankDifference <= $range;
    }

    public function applyRankedBattleOutcome(
        SixHeroRanking $attacker,
        SixHeroRanking $defender,
        bool $attackerWon,
    ): SixHeroRankChangeResult {
        $attackerId = (int) $attacker->getKey();
        $defenderId = (int) $defender->getKey();
        if ($attackerId <= 0 || $defenderId <= 0) {
            throw new DomainException('Both rankings must already exist.');
        }
        if ($attackerId === $defenderId) {
            throw new DomainException('A ranking cannot battle itself.');
        }

        $seasonId = (int) ($attacker->getRawOriginal('season_id')
            ?? $attacker->getAttribute('season_id'));
        if ($seasonId <= 0) {
            throw new DomainException('The attacker ranking has no season.');
        }

        return DB::transaction(function () use (
            $attackerId,
            $defenderId,
            $seasonId,
            $attackerWon,
        ): SixHeroRankChangeResult {
            $lockedSeason = SixHeroSeason::query()
                ->whereKey($seasonId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertSeasonWritable($lockedSeason);

            $rankings = SixHeroRanking::query()
                ->whereIn('id', [$attackerId, $defenderId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (SixHeroRanking $ranking): int => (int) $ranking->id);
            if (! $rankings->has($attackerId) || ! $rankings->has($defenderId)) {
                throw (new ModelNotFoundException)->setModel(
                    SixHeroRanking::class,
                    [$attackerId, $defenderId],
                );
            }

            /** @var SixHeroRanking $lockedAttacker */
            $lockedAttacker = $rankings->get($attackerId);
            /** @var SixHeroRanking $lockedDefender */
            $lockedDefender = $rankings->get($defenderId);
            $this->assertValidOutcomePair($lockedSeason, $lockedAttacker, $lockedDefender);

            $attackerOldRank = (int) $lockedAttacker->rank;
            $defenderOldRank = (int) $lockedDefender->rank;
            if ($attackerOldRank <= 0 || $defenderOldRank <= 0) {
                throw new DomainException('Rankings must have positive persisted ranks.');
            }

            if ($attackerWon) {
                $lockedAttacker->official_attack_wins =
                    (int) $lockedAttacker->official_attack_wins + 1;
                $lockedDefender->defense_losses =
                    (int) $lockedDefender->defense_losses + 1;
            } else {
                $lockedAttacker->official_attack_losses =
                    (int) $lockedAttacker->official_attack_losses + 1;
                $lockedDefender->defense_wins =
                    (int) $lockedDefender->defense_wins + 1;
            }

            if (! $attackerWon || $defenderOldRank >= $attackerOldRank) {
                $lockedAttacker->save();
                $lockedDefender->save();

                return new SixHeroRankChangeResult(
                    attackerWon: $attackerWon,
                    rankChanged: false,
                    attackerOldRank: $attackerOldRank,
                    attackerNewRank: $attackerOldRank,
                    defenderOldRank: $defenderOldRank,
                    defenderNewRank: $defenderOldRank,
                );
            }

            $targetRank = $defenderOldRank;
            $lockedDefender->save();

            $lockedAttacker->rank = -1 * $attackerId;
            $lockedAttacker->save();

            $affectedRankings = SixHeroRanking::query()
                ->where('season_id', $lockedSeason->id)
                ->where('room_key', $lockedAttacker->room_key->value)
                ->whereBetween('rank', [$targetRank, $attackerOldRank - 1])
                ->orderByDesc('rank')
                ->lockForUpdate()
                ->get();
            foreach ($affectedRankings as $affectedRanking) {
                $oldRank = (int) $affectedRanking->rank;
                $affectedRanking->rank = $oldRank + 1;
                if ($oldRank === 1) {
                    $affectedRanking->first_place_since = null;
                }
                $affectedRanking->save();
            }

            $lockedAttacker->rank = $targetRank;
            if ($targetRank === 1) {
                $lockedAttacker->first_place_since = now();
            }
            $lockedAttacker->save();
            $lockedDefender->refresh();

            return new SixHeroRankChangeResult(
                attackerWon: true,
                rankChanged: true,
                attackerOldRank: $attackerOldRank,
                attackerNewRank: $targetRank,
                defenderOldRank: $defenderOldRank,
                defenderNewRank: (int) $lockedDefender->rank,
            );
        });
    }

    private function assertSeasonWritable(SixHeroSeason $season): void
    {
        if ($season->finalized_at !== null) {
            throw new DomainException('Season has been finalized.');
        }
    }

    private function rankingInitializer(): SixHeroRankingInitializationService
    {
        return $this->rankingInitializationService
            ?? app(SixHeroRankingInitializationService::class);
    }

    private function assertValidOutcomePair(
        SixHeroSeason $lockedSeason,
        SixHeroRanking $attacker,
        SixHeroRanking $defender,
    ): void {
        if ((int) $attacker->character_id === (int) $defender->character_id) {
            throw new DomainException('A character cannot battle itself.');
        }
        if ((int) $attacker->season_id !== (int) $lockedSeason->id
            || (int) $defender->season_id !== (int) $lockedSeason->id
        ) {
            throw new DomainException('Rankings must belong to the same season.');
        }
        if ($attacker->room_key !== $defender->room_key) {
            throw new DomainException('Rankings must belong to the same room.');
        }
    }
}
