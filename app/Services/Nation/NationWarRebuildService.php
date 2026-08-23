<?php

namespace App\Services\Nation;

use App\Models\NationWarFacility;
use App\Models\NationWarSide;
use Illuminate\Support\Facades\DB;

final class NationWarRebuildService
{
    public function start(NationWarSide $side, NationWarFacility $facility): NationWarFacility
    {
        return DB::transaction(function () use ($side, $facility): NationWarFacility {
            $lockedSide = NationWarSide::whereKey($side->id)->lockForUpdate()->firstOrFail();
            $locked = NationWarFacility::whereKey($facility->id)->lockForUpdate()->firstOrFail();
            throw_unless(in_array($locked->facility_type, ['wall','magic_cannon','logistics'], true), \DomainException::class, 'この施設は再建できません。');
            throw_unless($locked->nation_id === $lockedSide->nation_id && $locked->current_hp === 0 && $locked->status === 'destroyed', \DomainException::class, '再建を開始できる状態ではありません。');
            $arsenal = NationWarFacility::where('nation_war_id', $locked->nation_war_id)->where('nation_id', $locked->nation_id)->where('facility_type', 'arsenal')->lockForUpdate()->first();
            throw_if(! $arsenal || $arsenal->current_hp <= 0 || $arsenal->status !== 'active', \DomainException::class, '要塞工廠が稼働していません。');
            $count = (int) $locked->rebuild_count;
            $settings = app(NationWarSettingsService::class);
            $multiplier = $settings->rebuildMultiplier($count);
            $fullRepairCost = $settings->facilityBaseD($locked->facility_type) * $settings->repairPointsPerD();
            $cost = (int) ceil($fullRepairCost * $multiplier);
            app(NationWarRepairService::class)->spendPool($lockedSide, $cost);
            $locked->update(['status' => 'rebuilding', 'rebuild_completes_at' => now()->addMinutes(app(NationWarSettingsService::class)->rebuildMinutes()), 'rebuild_count' => $count + 1]);
            return $locked->refresh();
        }, 3);
    }
}
