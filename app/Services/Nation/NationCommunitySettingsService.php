<?php

namespace App\Services\Nation;

use App\Models\Nation;
use App\Services\GameSettingService;

final class NationCommunitySettingsService
{
    public function __construct(private readonly GameSettingService $settings) {}

    public function maxMembers(): int
    {
        $absoluteCap = (int) config('nation_development.system_absolute_member_cap', 100);

        return max(1, min($absoluteCap, $this->settings->getInt('nation.max_members', $absoluteCap)));
    }

    public function maxMembersFor(Nation $nation): int
    {
        $levelCapacity = app(NationDevelopmentLevelService::class)
            ->memberCapacityForExperience((int) $nation->development_exp);

        return min($levelCapacity, $this->maxMembers());
    }

    public function applicationRetryHours(): int
    {
        return max(0, $this->settings->getInt('nation.application_retry_hours', 24));
    }

    public function minimumMembershipHours(): int
    {
        return max(0, $this->settings->getInt('nation.minimum_membership_hours', 24));
    }

    public function leaveJoinCooldownHours(): int
    {
        return max(0, $this->settings->getInt('nation.leave_join_cooldown_hours', 72));
    }

    public function expelJoinCooldownHours(): int
    {
        return max(0, $this->settings->getInt('nation.expel_join_cooldown_hours', 24));
    }

    public function expelSameNationCooldownDays(): int
    {
        return max(0, $this->settings->getInt('nation.expel_same_nation_cooldown_days', 7));
    }

    public function dissolutionWaitHours(): int
    {
        return max(1, $this->settings->getInt('nation.dissolution_wait_hours', 24));
    }

    public function rulerRefoundCooldownDays(): int
    {
        return max(0, $this->settings->getInt('nation.ruler_refound_cooldown_days', 7));
    }
}
