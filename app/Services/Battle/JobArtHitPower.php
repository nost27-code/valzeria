<?php

namespace App\Services\Battle;

/**
 * Splits a Job Art's action-total power across its actual hits.
 *
 * The first hits receive any integer remainder so the ordered list is stable
 * and its sum always remains exactly equal to the runtime total power.
 */
final class JobArtHitPower
{
    /** @return list<int> */
    public static function split(int $totalPower, int $hitCount): array
    {
        $totalPower = max(0, $totalPower);
        $hitCount = max(1, $hitCount);
        $base = intdiv($totalPower, $hitCount);
        $remainder = $totalPower % $hitCount;

        $powers = [];
        for ($hit = 0; $hit < $hitCount; $hit++) {
            $powers[] = $base + ($hit < $remainder ? 1 : 0);
        }

        return $powers;
    }

    /**
     * Split action-total power expressed in hundredths of one displayed-power
     * point. Keeping this unit through hit allocation prevents the SP-output
     * bonus from being rounded once per hit.
     *
     * @return list<int>
     */
    public static function splitCenti(int $totalPowerCenti, int $hitCount): array
    {
        return self::split($totalPowerCenti, $hitCount);
    }
}
