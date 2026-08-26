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
        $warSettings = app(NationWarSettingsService::class);
        throw_unless($warSettings->featureEnabled() && $warSettings->facilityUpgradesEnabled(), \DomainException::class, '施設レベルアップは現在停止中です。');

        return DB::transaction(function () use ($actor, $facility): NationFacility {
            $nation = Nation::whereKey($facility->nation_id)->lockForUpdate()->firstOrFail();
            $lockedActor = NationMembership::whereKey($actor->id)
                ->where('nation_id', $nation->id)
                ->lockForUpdate()
                ->first();
            throw_unless($lockedActor, \DomainException::class, '自国の施設ではありません。');
            app(NationRoleService::class)->authorize($lockedActor, 'upgrade_facilities');
            $locked = NationFacility::whereKey($facility->id)
                ->where('nation_id', $nation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $benefitsEnabled = app(NationLevelBenefitSettingsService::class)->enabled();
            $levelCap = $benefitsEnabled
                ? app(NationDevelopmentLevelService::class)->facilityLevelCapForExperience((int) $nation->development_exp)
                : 10;
            throw_if(
                (int) $locked->level >= $levelCap,
                \DomainException::class,
                "現在の国家Lvでは、施設をLv{$levelCap}より強化できません。",
            );
            app(NationResourceService::class)->spend($nation, $this->upgradeCost($locked), 'facility_upgrade', ['facility_type' => $locked->facility_type, 'from_level' => $locked->level]);
            $locked->increment('level');
            $upgraded = $locked->refresh();
            if ($benefitsEnabled) {
                app(NationTimelineService::class)->recordFacilityLevelMilestone($nation, $upgraded, $lockedActor->character);
                app(NationAchievementService::class)->recordFacilityUpgrade($nation);
            }

            return $upgraded;
        }, 3);
    }
}
