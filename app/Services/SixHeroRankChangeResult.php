<?php

namespace App\Services;

final readonly class SixHeroRankChangeResult
{
    public function __construct(
        public bool $attackerWon,
        public bool $rankChanged,
        public int $attackerOldRank,
        public int $attackerNewRank,
        public int $defenderOldRank,
        public int $defenderNewRank,
    ) {}
}
