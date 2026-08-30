<?php

namespace App\Services\Battle;

use InvalidArgumentException;
use UnexpectedValueException;

final class CompetitiveHitPolicy
{
    /**
     * @return array{
     *   agi_factor:float,
     *   min_rate:int,
     *   normal_max_rate:int,
     *   aim_max_rate:int,
     *   vital_hit_max_rate:float
     * }
     */
    public function rulesFor(string $battleType): array
    {
        if (! $this->supports($battleType)) {
            throw new InvalidArgumentException("Unsupported competitive battle type: {$battleType}");
        }

        $rules = config('battle.competitive_hit.'.$battleType);
        foreach (['agi_factor', 'min_rate', 'normal_max_rate', 'aim_max_rate', 'vital_hit_max_rate'] as $key) {
            if (! is_array($rules) || ! is_numeric($rules[$key] ?? null)) {
                throw new UnexpectedValueException("Missing competitive hit rule: {$battleType}.{$key}");
            }
        }

        $minRate = max(0, min(100, (int) $rules['min_rate']));
        $normalMaximum = max($minRate, min(100, (int) $rules['normal_max_rate']));

        return [
            'agi_factor' => (float) $rules['agi_factor'],
            'min_rate' => $minRate,
            'normal_max_rate' => $normalMaximum,
            'aim_max_rate' => max($normalMaximum, min(100, (int) $rules['aim_max_rate'])),
            'vital_hit_max_rate' => max(0.0, min(100.0, (float) $rules['vital_hit_max_rate'])),
        ];
    }

    public function supports(string $battleType): bool
    {
        return in_array($battleType, ['pvp', 'champ'], true);
    }

    public function vitalHitChance(
        string $battleType,
        float $accuracyOverflow,
        float $cardCriticalBonus,
    ): float {
        $rules = $this->rulesFor($battleType);

        return min(
            $rules['vital_hit_max_rate'],
            max(0.0, $accuracyOverflow) + max(0.0, $cardCriticalBonus),
        );
    }
}
