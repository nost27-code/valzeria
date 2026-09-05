<?php

namespace App\Services\Nation\Raid;

use InvalidArgumentException;

/**
 * 既存player engineがplayer行動を解決した直後の、1ターン限りの能力状態。
 *
 * Phase 1の固定snapshot経路では使わず、turn-by-turn bridgeだけが渡す。
 */
final readonly class NationRaidPlayerTurnState
{
    public function __construct(
        public int $maxHp,
        public int $currentHp,
        public int $defense,
        public int $spirit,
        public int $maxSp,
        public int $currentSp,
        public float $enemyHitChancePercent,
        public float $enemyEvadeChancePercent,
        public float $enemyCriticalChancePercent,
        public float $finalDamageReductionRate,
        public ?NationRaidIncomingDamageApplier $incomingDamageApplier = null,
    ) {
        if ($maxHp < 1 || $currentHp < 0 || $currentHp > $maxHp
            || $defense < 0 || $spirit < 0 || $maxSp < 0
            || $currentSp < 0 || $currentSp > $maxSp
        ) {
            throw new InvalidArgumentException('Raid live player turn state has an invalid ability value.');
        }
        if ($enemyHitChancePercent < 0 || $enemyHitChancePercent > 100
            || $enemyEvadeChancePercent < 0 || $enemyEvadeChancePercent > 100
            || $enemyCriticalChancePercent < 0 || $enemyCriticalChancePercent > 100
            || $finalDamageReductionRate < 0 || $finalDamageReductionRate >= 1
        ) {
            throw new InvalidArgumentException('Raid live player turn state has an invalid rate.');
        }
    }

    public function damageSnapshot(NationRaidPlayerSnapshot $base): NationRaidPlayerSnapshot
    {
        return new NationRaidPlayerSnapshot(
            maxHp: $this->maxHp,
            defense: $this->defense,
            spirit: $this->spirit,
            maxSp: $this->maxSp,
            enemyHitChancePercent: $this->enemyHitChancePercent,
            enemyEvadeChancePercent: $this->enemyEvadeChancePercent,
            enemyCriticalChancePercent: $this->enemyCriticalChancePercent,
            finalDamageReductionRate: $this->finalDamageReductionRate,
            counterplayEnabled: $base->counterplayEnabled,
            bossSetExactIdentities: $base->bossSetExactIdentities,
        );
    }
}
