<?php

namespace App\Services\Nation;

use App\Models\Nation;
use Illuminate\Support\Collection;

final class NationWarActiveMemberService
{
    public function members(Nation $nation): Collection
    {
        $since = now()->subDays(max(1, app(\App\Services\GameSettingService::class)->getInt('nation_war.active_days', 7)));

        return $nation->memberships()
            ->with('character')
            ->whereHas('character', fn ($query) => $query->where('last_battle_at', '>=', $since))
            ->get();
    }
}
