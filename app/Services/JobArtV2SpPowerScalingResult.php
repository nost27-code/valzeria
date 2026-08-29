<?php

namespace App\Services;

/**
 * One immutable SP-output calculation shared by selection, commit, execution,
 * display, and telemetry.
 */
final class JobArtV2SpPowerScalingResult
{
    public function __construct(
        public readonly int $fixedCost,
        public readonly int $discountedFixedCost,
        public readonly int $variableCost,
        public readonly int $totalCost,
        public readonly int $linearBonusBps,
        public readonly int $excessBonusBps,
        public readonly int $bonusBps,
        public readonly int $basePowerBps,
        public readonly string $outputKey,
        public readonly int $powerReference,
        public readonly bool $powerScalingApplies,
        public readonly ?int $outputBudgetInitial,
        public readonly ?int $outputBudgetRemaining,
    ) {
    }

    public static function fixedOnly(int $fixedCost, int $discountedFixedCost): self
    {
        return new self(
            fixedCost: max(0, $fixedCost),
            discountedFixedCost: max(0, $discountedFixedCost),
            variableCost: 0,
            totalCost: max(0, $discountedFixedCost),
            linearBonusBps: 0,
            excessBonusBps: 0,
            bonusBps: 0,
            basePowerBps: JobArtV2SpPowerScalingService::NEUTRAL_BPS,
            outputKey: JobArtV2StrategyService::OUTPUT_NONE,
            powerReference: 0,
            powerScalingApplies: false,
            outputBudgetInitial: null,
            outputBudgetRemaining: null,
        );
    }

    public function scaledPowerCenti(int $basePower): int
    {
        $basePower = max(0, $basePower);
        if ($basePower === 0) {
            return 0;
        }

        if (! $this->powerScalingApplies || $this->bonusBps === 0) {
            return $basePower * 100;
        }

        return intdiv(($basePower * (self::neutralBps() + $this->bonusBps)) + 50, 100);
    }

    private static function neutralBps(): int
    {
        return JobArtV2SpPowerScalingService::NEUTRAL_BPS;
    }
}
