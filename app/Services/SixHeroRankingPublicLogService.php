<?php

namespace App\Services;

use App\Enums\SixHeroRoomKey;
use App\Models\Character;

final class SixHeroRankingPublicLogService
{
    private const PUBLIC_RANK_UP_LOG_MAX_RANK = 30;

    public function __construct(
        private readonly PublicLogService $publicLogService,
    ) {}

    public function publish(
        SixHeroRoomKey $room,
        Character $attacker,
        Character $defender,
        SixHeroRankChangeResult $rankChange,
    ): void {
        if (! $this->shouldPublish($rankChange)) {
            return;
        }

        if ($rankChange->attackerNewRank === 1) {
            $this->publicLogService->addLog(
                'arena',
                "【六極速報】{$room->label()}で、{$attacker->name}さんが{$defender->name}さんを破り、現在首位を奪取しました！",
                $attacker,
                3,
            );

            return;
        }

        $this->publicLogService->addLog(
            'arena',
            "【六極殿】{$room->label()}で、{$attacker->name}さんが{$defender->name}さんを破り、{$rankChange->attackerOldRank}位から{$rankChange->attackerNewRank}位へ駆け上がりました！",
            $attacker,
            2,
        );
    }

    private function shouldPublish(SixHeroRankChangeResult $rankChange): bool
    {
        return $rankChange->attackerWon
            && $rankChange->rankChanged
            && $rankChange->attackerNewRank >= 1
            && $rankChange->attackerNewRank <= self::PUBLIC_RANK_UP_LOG_MAX_RANK
            && $rankChange->attackerNewRank < $rankChange->attackerOldRank;
    }
}
