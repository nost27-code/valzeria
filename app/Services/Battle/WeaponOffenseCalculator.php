<?php

namespace App\Services\Battle;

/**
 * 武器の攻撃性能・防具の防御性能に共通する実効能力値を算出する。
 */
class WeaponOffenseCalculator
{
    public const BASE_NUMERATOR = 1920;
    public const DENOMINATOR = 2400;

    public function calculateEffectiveOffense(int $baseStatWithoutWeapon, int $weaponOffense): int
    {
        return $this->calculateEffectiveStat($baseStatWithoutWeapon, $weaponOffense);
    }

    public function calculateEffectiveStat(int $baseStatWithoutEquipment, int $equipmentStat): int
    {
        $baseStatWithoutEquipment = max(0, $baseStatWithoutEquipment);
        $equipmentStat = max(0, $equipmentStat);

        $numerator = $baseStatWithoutEquipment * (self::BASE_NUMERATOR + $equipmentStat);

        return intdiv($numerator + intdiv(self::DENOMINATOR, 2), self::DENOMINATOR);
    }

    public function calculateProportionalBonus(int $baseStat, int $equipmentStat): int
    {
        $baseStat = max(0, $baseStat);
        $equipmentStat = max(0, $equipmentStat);

        return intdiv(($baseStat * $equipmentStat) + intdiv(self::DENOMINATOR, 2), self::DENOMINATOR);
    }
}
