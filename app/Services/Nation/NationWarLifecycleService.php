<?php

namespace App\Services\Nation;

use App\Models\NationWar;
use App\Models\NationWarFacility;

final class NationWarLifecycleService
{
    /** @return array{activated:int,resolved:int,rebuilt:int} */
    public function run(): array
    {
        NationWar::where('status', 'reserved')->where('starts_at', '<=', now())->orderBy('id')->each(function (NationWar $war): void {
            $nationIds = [$war->declaring_nation_id, $war->defending_nation_id];
            $blocked = NationWar::whereIn('status', ['preparing','active'])
                ->where(function ($query) use ($nationIds): void {
                    $query->whereIn('declaring_nation_id', $nationIds)->orWhereIn('defending_nation_id', $nationIds);
                })->exists();
            if ($blocked) return;
            $startsAt = now();
            $war->update(['status' => 'preparing', 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addDays(app(NationWarSettingsService::class)->durationDays())]);
            foreach ($war->sides()->get() as $side) {
                app(NationWarService::class)->initializeFacilities($war, \App\Models\Nation::findOrFail($side->nation_id), max(1, (int) $side->active_member_count));
            }
        });
        $activated = NationWar::where('status', 'preparing')->where('starts_at', '<=', now())->update(['status' => 'active']);
        $rebuilt = 0;
        NationWarFacility::where('status', 'rebuilding')->where('rebuild_completes_at', '<=', now())->orderBy('id')->each(function (NationWarFacility $facility) use (&$rebuilt): void {
            $facility->update(['status' => 'active', 'current_hp' => max(1, (int) floor($facility->max_hp * (app(NationWarSettingsService::class)->rebuildHpBps() / 10000))), 'rebuild_completes_at' => null, 'destroyed_at' => null]);
            $rebuilt++;
        });
        $resolved = 0;
        NationWar::where('status', 'active')->where('ends_at', '<=', now())->orderBy('id')->each(function (NationWar $war) use (&$resolved): void {
            app(NationWarJudgmentService::class)->resolve($war);
            $resolved++;
        });
        return compact('activated', 'resolved', 'rebuilt');
    }
}
