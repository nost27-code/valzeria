<?php

namespace App\Services\Battle;

final class SpeedBreakthroughService
{
    private const DEAD_ZONE_RATIO = 1.30;

    private const ADVANTAGE_COEFFICIENT = 1.25;

    private const MAX_BREAKTHROUGH_RATE = 0.30;

    private const MAX_TOTAL_IGNORE_RATE = 0.50;

    public function nominalRate(BattleActor $attacker, BattleActor $defender): float
    {
        return $this->nominalRateForAgility(
            $attacker->effectiveAgi(),
            $defender->effectiveAgi(),
        );
    }

    public function nominalRateForAgility(float $attackerAgi, float $defenderAgi): float
    {
        $ratio = $attackerAgi / max(1, $defenderAgi);
        $effectiveAdvantage = max(0.0, $ratio - self::DEAD_ZONE_RATIO);

        return min(
            self::MAX_BREAKTHROUGH_RATE,
            $effectiveAdvantage * self::ADVANTAGE_COEFFICIENT,
        );
    }

    /**
     * @return array{
     *     nominal_rate: float,
     *     existing_ignore_rate: float,
     *     combined_ignore_rate: float,
     *     additional_ignore_rate: float
     * }
     */
    public function rates(float $nominalRate, float $existingIgnoreRate): array
    {
        $nominalRate = max(0.0, min(self::MAX_BREAKTHROUGH_RATE, $nominalRate));
        $existingIgnoreRate = max(0.0, min(self::MAX_TOTAL_IGNORE_RATE, $existingIgnoreRate));
        $combinedIgnoreRate = min(
            self::MAX_TOTAL_IGNORE_RATE,
            1 - ((1 - $existingIgnoreRate) * (1 - $nominalRate)),
        );
        $additionalIgnoreRate = 1 - ((1 - $combinedIgnoreRate) / (1 - $existingIgnoreRate));

        return [
            'nominal_rate' => $nominalRate,
            'existing_ignore_rate' => $existingIgnoreRate,
            'combined_ignore_rate' => $combinedIgnoreRate,
            'additional_ignore_rate' => min($nominalRate, max(0.0, $additionalIgnoreRate)),
        ];
    }
}
