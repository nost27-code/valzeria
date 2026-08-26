<?php

namespace App\Services\Nation;

use App\Models\Nation;
use App\Models\NationAchievement;
use App\Models\NationActivityLog;
use App\Models\NationResourceTransaction;
use App\Models\NationWarHistory;

final class NationAchievementBackfillService
{
    public function __construct(
        private readonly NationAchievementService $achievements,
        private readonly NationDevelopmentLevelService $levels,
    ) {}

    /** @return array{created:int,existing:int} */
    public function backfill(Nation $nation): array
    {
        $before = NationAchievement::where('nation_id', $nation->id)->count();

        $donations = NationResourceTransaction::query()
            ->where('nation_id', $nation->id)
            ->where('transaction_type', 'donation')
            ->orderBy('id')
            ->get();
        if ($firstDonation = $donations->first()) {
            $this->achievements->unlock($nation, 'first_donation', [], $firstDonation->created_at);
        }

        $experience = 0;
        $previousLevel = 1;
        foreach ($donations as $donation) {
            $experience += (int) $donation->development_exp_delta;
            $currentLevel = $this->levels->levelFor($experience);
            foreach (array_keys(config('nation_development.benefit_milestones', [])) as $milestoneLevel) {
                $milestoneLevel = (int) $milestoneLevel;
                if ($milestoneLevel <= 1 || $milestoneLevel <= $previousLevel || $milestoneLevel > $currentLevel) {
                    continue;
                }
                $this->achievements->unlock(
                    $nation,
                    "nation_level_{$milestoneLevel}",
                    ['level' => $milestoneLevel],
                    $donation->created_at,
                );
            }
            $previousLevel = $currentLevel;
        }

        $firstMemberJoined = NationActivityLog::query()
            ->where('nation_id', $nation->id)
            ->where('event_type', 'member_joined')
            ->oldest('id')
            ->first();
        if ($firstMemberJoined) {
            $this->achievements->unlock($nation, 'first_member_joined', [], $firstMemberJoined->created_at);
        }

        $firstFacilityUpgrade = NationResourceTransaction::query()
            ->where('nation_id', $nation->id)
            ->where('transaction_type', 'facility_upgrade')
            ->oldest('id')
            ->first();
        if ($firstFacilityUpgrade) {
            $this->achievements->unlock($nation, 'first_facility_upgrade', [], $firstFacilityUpgrade->created_at);
        }

        $firstWar = NationWarHistory::query()
            ->where(function ($query) use ($nation): void {
                $query->where('declaring_nation_id', $nation->id)
                    ->orWhere('defending_nation_id', $nation->id);
            })
            ->orderBy('resolved_at')
            ->orderBy('id')
            ->first();
        if ($firstWar) {
            $this->achievements->unlock($nation, 'first_war_participation', [
                'nation_war_history_id' => $firstWar->id,
            ], $firstWar->resolved_at);
        }

        $firstWin = NationWarHistory::query()
            ->where('winner_nation_id', $nation->id)
            ->orderBy('resolved_at')
            ->orderBy('id')
            ->first();
        if ($firstWin) {
            $this->achievements->unlock($nation, 'first_war_win', [
                'nation_war_history_id' => $firstWin->id,
            ], $firstWin->resolved_at);
        }

        if ($nation->founded_at?->lte(now()->subYear())) {
            $this->achievements->unlock($nation, 'first_anniversary', [], $nation->founded_at->copy()->addYear());
        }

        $after = NationAchievement::where('nation_id', $nation->id)->count();

        return ['created' => $after - $before, 'existing' => $before];
    }
}
