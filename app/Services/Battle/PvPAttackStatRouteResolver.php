<?php

namespace App\Services\Battle;

use App\Models\Skill;

final class PvPAttackStatRouteResolver
{
    public const STR = 'str';

    public const MAG = 'mag';

    public const HYBRID = 'hybrid';

    public const OTHER = 'other';

    public function resolve(string $resolvedAttackType, ?Skill $skill): string
    {
        $explicitAttackStat = strtolower(trim((string) $skill?->getAttribute('job_art_v2_attack_stat')));
        if ($explicitAttackStat !== '') {
            return match ($explicitAttackStat) {
                self::STR => self::STR,
                self::MAG => self::MAG,
                default => self::OTHER,
            };
        }

        return match ($resolvedAttackType) {
            'physical' => self::STR,
            'magical' => self::MAG,
            'hybrid' => self::HYBRID,
            default => self::OTHER,
        };
    }
}
