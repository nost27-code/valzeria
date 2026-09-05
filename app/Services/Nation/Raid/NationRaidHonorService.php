<?php

namespace App\Services\Nation\Raid;

use App\Models\Character;
use App\Models\Nation;
use App\Models\NationRaidEvent;
use App\Models\NationRaidNationReward;
use App\Models\NationRaidPersonalReward;

/** 能力に影響しない公開用射影。個人情報・残高・台帳IDは返さない。 */
final class NationRaidHonorService
{
    public function forCharacter(Character $character): array
    {
        if (! config('features.nation_competitive_raid_enabled', false)) {
            return [];
        }

        return NationRaidPersonalReward::query()->with('event:id,name,ends_at')
            ->where('character_id_snapshot', $character->id)->where('account_id_snapshot', $character->user_id)
            ->where('status', NationRaidPersonalReward::STATUS_CLAIMED)
            ->whereIn('reward_key', ['damage2m', 'personal_first', 'personal_top3', 'max_first'])
            ->whereHas('event', fn ($query) => $query->where('status', NationRaidEvent::STATUS_COMPLETED))
            ->latest('claimed_at')->limit(20)->get()->map(fn ($reward) => [
                'label' => $reward->reward_snapshot['title'], 'badge' => (bool) $reward->reward_snapshot['badge'],
                'event' => $reward->event->name, 'date' => $reward->event->ends_at->format('Y/n/j'),
            ])->all();
    }

    public function forNation(Nation $nation): ?array
    {
        if (! config('features.nation_competitive_raid_enabled', false)) {
            return null;
        }
        // 次回の同イベント終了まで。開催期間で比較し、遅延確定の順で古い旗を復活させない。
        $event = NationRaidEvent::where('status', NationRaidEvent::STATUS_COMPLETED)->orderByDesc('ends_at')->orderByDesc('id')->first(['id']);
        if (! $event) {
            return null;
        }
        $reward = NationRaidNationReward::where('event_id', $event->id)->where('nation_id_snapshot', $nation->id)
            ->where('reward_key', 'flag')->where('status', NationRaidNationReward::STATUS_CLAIMED)->first();

        return $reward ? ['label' => $reward->reward_snapshot['label'], 'rank' => $reward->reward_snapshot['rank']] : null;
    }
}
