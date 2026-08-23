<?php

namespace App\Services\Nation;

use App\Models\NationFacility;

final class NationWarHpCalculator
{
    public function maxHp(string $type, int $level, int $activeMembers, ?int $referenceDamage = null): int
    {
        $d = $referenceDamage ?? app(NationWarSettingsService::class)->referenceDamage();
        throw_if($d < 1, \DomainException::class, '国家戦の基準Dが未校正です。');
        throw_unless(isset(NationWarSettingsService::FACILITY_HP_D[$type]), \DomainException::class, '施設種別が不正です。');
        $settings = app(NationWarSettingsService::class);
        $ratio = $settings->levelHpRatio($level);
        $memberRatio = max(1, min(100, $activeMembers)) / 100;

        return max(1, (int) floor($d * $settings->facilityBaseD($type) * $ratio * $memberRatio));
    }

    public function startingHp(NationFacility $facility, int $activeMembers): int
    {
        return max(0, (int) floor($this->maxHp($facility->facility_type, $facility->level, $activeMembers) * (max(0, min(10000, $facility->condition_bps)) / 10000)));
    }
}
