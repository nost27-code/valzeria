<?php

namespace App\Services\Battle;

final readonly class PvPBattleResolution
{
    public function __construct(
        public BattleResult $result,
        public bool $attackerWon,
        public int $turnCount,
        public int $attackerHp,
        public int $attackerMaxHp,
        public int $defenderHp,
        public int $defenderMaxHp,
        /** @var array<string, mixed> */
        public array $attackerMetrics = [],
        /** @var array<string, mixed> */
        public array $defenderMetrics = [],
    ) {}

    public function attackerHpRatio(): float
    {
        return $this->attackerHp / max(1, $this->attackerMaxHp);
    }

    public function defenderHpRatio(): float
    {
        return $this->defenderHp / max(1, $this->defenderMaxHp);
    }
}
