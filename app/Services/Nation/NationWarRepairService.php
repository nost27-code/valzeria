<?php

namespace App\Services\Nation;

use App\Models\NationWarFacility;
use App\Models\NationWarSide;
use Illuminate\Support\Facades\DB;

final class NationWarRepairService
{
    public function repair(NationWarSide $side, NationWarFacility $facility, int $requestedHp): int
    {
        throw_if($requestedHp < 1, \DomainException::class, '修復HPは1以上で指定してください。');
        return DB::transaction(function () use ($side, $facility, $requestedHp): int {
            $lockedSide = NationWarSide::whereKey($side->id)->lockForUpdate()->firstOrFail();
            $locked = NationWarFacility::whereKey($facility->id)->lockForUpdate()->firstOrFail();
            throw_unless($locked->nation_id === $lockedSide->nation_id && $locked->nation_war_id === $lockedSide->nation_war_id, \DomainException::class, '対象施設が自国の国家戦施設ではありません。');
            $logistics = NationWarFacility::where('nation_war_id', $locked->nation_war_id)->where('nation_id', $locked->nation_id)->where('facility_type', 'logistics')->lockForUpdate()->first();
            throw_if(! $logistics || $logistics->current_hp <= 0 || $logistics->status !== 'active', \DomainException::class, '兵站所が稼働していません。');
            throw_if($locked->current_hp <= 0 || $locked->status !== 'active', \DomainException::class, '破壊中・再建中の施設は修復できません。');
            $healed = min($requestedHp, max(0, $locked->max_hp - $locked->current_hp));
            if ($healed < 1) return 0;
            $settings = app(NationWarSettingsService::class);
            $facilityD = $settings->facilityBaseD($locked->facility_type);
            $dAmount = $healed / max(1, ($locked->max_hp / $facilityD));
            $cost = (int) ceil($dAmount * $settings->repairPointsPerD() * ($locked->facility_type === 'logistics' ? $settings->logisticsSelfRepairMultiplier() : 1));
            $this->spendPool($lockedSide, $cost);
            $locked->increment('current_hp', $healed);
            return $healed;
        }, 3);
    }

    public function spendPool(NationWarSide $side, int $points): void
    {
        $remaining = (int) $side->resource_pool_points - (int) $side->resource_spent_points;
        throw_if($remaining < $points, \DomainException::class, '戦争資材プールが足りません。');
        $side->increment('resource_spent_points', $points);
    }
}
