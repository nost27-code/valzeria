<?php

namespace App\Services\Battle;

class BattleTypeAffinity
{
    public const RATE = 0.10;

    private const TYPES = ['physical', 'speed', 'magical'];
    private const DEFAULT_WEIGHTS = ['physical' => 1.0, 'speed' => 0.0, 'magical' => 0.0];

    private const MATRIX = [
        'physical' => ['physical' => 0, 'speed' => 1, 'magical' => -1],
        'speed' => ['physical' => -1, 'speed' => 0, 'magical' => 1],
        'magical' => ['physical' => 1, 'speed' => -1, 'magical' => 0],
    ];

    public static function multiplier(array $attackerWeights, array $defenderWeights): float
    {
        $attackerWeights = self::normalize($attackerWeights);
        $defenderWeights = self::normalize($defenderWeights);

        $net = 0.0;
        foreach (self::TYPES as $attackerType) {
            foreach (self::TYPES as $defenderType) {
                $net += $attackerWeights[$attackerType]
                    * $defenderWeights[$defenderType]
                    * self::MATRIX[$attackerType][$defenderType];
            }
        }

        return max(1.0 - self::RATE, min(1.0 + self::RATE, 1.0 + $net * self::RATE));
    }

    public static function normalize(array $weights): array
    {
        $normalized = [];
        $total = 0.0;

        foreach (self::TYPES as $type) {
            $value = max(0.0, (float) ($weights[$type] ?? 0.0));
            $normalized[$type] = $value;
            $total += $value;
        }

        if ($total <= 0.0) {
            return self::DEFAULT_WEIGHTS;
        }

        foreach (self::TYPES as $type) {
            $normalized[$type] = $normalized[$type] / $total;
        }

        return $normalized;
    }

    public static function defaultWeights(): array
    {
        return self::DEFAULT_WEIGHTS;
    }

    public static function label(float $multiplier): string
    {
        if ($multiplier >= 1.05) {
            return '有利';
        }

        if ($multiplier > 1.01) {
            return 'やや有利';
        }

        if ($multiplier <= 0.95) {
            return '不利';
        }

        if ($multiplier < 0.99) {
            return 'やや不利';
        }

        return '互角';
    }
}
