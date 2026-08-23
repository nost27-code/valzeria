<?php

namespace App\Services\Nation;

use App\Models\Nation;
use App\Models\NationFacility;
use App\Models\NationMembership;
use Illuminate\Support\Facades\DB;

final class NationFacilityService
{
    public function upgradeCost(NationFacility $facility): int
    {
        $next = min(10, (int) $facility->level + 1);
        return (int) ceil(NationWarSettingsService::UPGRADE_BASE_COSTS[$next] * NationWarSettingsService::UPGRADE_MULTIPLIERS[$facility->facility_type]);
    }

    public function upgrade(NationMembership $actor, NationFacility $facility): NationFacility
    {
        throw_unless(app(NationWarSettingsService::class)->facilityUpgradesEnabled(), \DomainException::class, '施設レベルアップは現在停止中です。');
        app(NationRoleService::class)->authorize($actor, 'upgrade_facilities');
        throw_unless($actor->nation_id === $facility->nation_id, \DomainException::class, '自国の施設ではありません。');

        return DB::transaction(function () use ($facility): NationFacility {
            $locked = NationFacility::whereKey($facility->id)->lockForUpdate()->firstOrFail();
            throw_if($locked->level >= 10, \DomainException::class, '施設はすでに最大Lvです。');
            $nation = Nation::whereKey($locked->nation_id)->lockForUpdate()->firstOrFail();
            app(NationResourceService::class)->spend($nation, $this->upgradeCost($locked), 'facility_upgrade', ['facility_type' => $locked->facility_type, 'from_level' => $locked->level]);
            $locked->increment('level');
            return $locked->refresh();
        }, 3);
    }
}
