<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidCoordinationParticipant;
use App\Models\NationRaidEvent;
use App\Models\NationRaidParticipation;

/** 正式出撃の国家帰属は開始時snapshotを正とする。trialのfile cacheとは共有しない。 */
final class NationRaidCoordinationService
{
    /** ランキング用の一括read。登録・時刻延長はせず、表示用の失効予定だけを返す。 */
    public function liveForNations(NationRaidEvent $event, array $nationIds): array
    {
        $at = now();
        if ($nationIds === [] || ! $event->acceptsNewSortiesAt($at)) {
            return [];
        }
        $members = NationRaidCoordinationParticipant::query()
            ->where('event_id', $event->id)->whereIn('nation_id_snapshot', $nationIds)
            ->where('window_joined_at', '>', $at->copy()->subMinutes(NationRaidRules::COORDINATION_WINDOW_MINUTES))
            ->get(['nation_id_snapshot', 'character_id_snapshot', 'window_joined_at']);
        $states = [];
        foreach ($members->groupBy('nation_id_snapshot') as $nationId => $nationMembers) {
            $expirations = $nationMembers->unique('character_id_snapshot')->map(fn ($member) => min(
                $event->ends_at->getTimestampMs(),
                $member->window_joined_at->copy()->addMinutes(NationRaidRules::COORDINATION_WINDOW_MINUTES)->getTimestampMs(),
            ) - $at->getTimestampMs())->sort()->values()->all();
            $steps = [];
            foreach (array_unique([0, ...$expirations]) as $elapsed) {
                $count = count(array_filter($expirations, fn ($expiration) => $expiration > $elapsed));
                $percent = (int) round(NationRaidRules::coordinationDamageRate($count) * 100);
                $steps[] = [
                    'after_ms' => (int) $elapsed, 'count' => $count, 'percent' => $percent,
                    'active' => $percent > 0,
                    'label' => $percent > 0 ? "{$count}人共闘・+{$percent}%連携ボーナス中！" : '',
                ];
            }
            $states[(int) $nationId] = ['steps' => $steps];
        }
        return $states;
    }

    /** event lock内、settlement成功transaction内からのみregister=trueで呼ぶ。 */
    public function snapshot(NationRaidEvent $event, ?NationRaidParticipation $participation, bool $register = false): array
    {
        $eligible = $participation?->is_nation_eligible && $participation->nation_id !== null;
        $threshold = now()->subMinutes(NationRaidRules::COORDINATION_WINDOW_MINUTES);
        $new = false;
        if ($eligible && $register) {
            $member = NationRaidCoordinationParticipant::query()->firstOrNew([
                'event_id' => $event->id,
                'nation_id_snapshot' => $participation->nation_id,
                'character_id_snapshot' => $participation->character_id,
            ]);
            $new = ! $member->exists || $member->window_joined_at->lte($threshold);
            // 同じ国民の反復では3時間窓を延長しない。
            $member->fill([
                'participation_id' => $participation->id,
                'window_joined_at' => $new ? now() : $member->window_joined_at,
                'last_resolved_at' => now(),
            ])->save();
        }
        $members = $eligible ? NationRaidCoordinationParticipant::query()
            ->where('event_id', $event->id)->where('nation_id_snapshot', $participation->nation_id)
            ->where('window_joined_at', '>', $threshold)->orderBy('window_joined_at')->orderBy('id')->get() : collect();

        return [
            'eligible' => (bool) $eligible,
            'nation_id' => $eligible ? $participation->nation_id : null,
            'nation_name' => $eligible ? $participation->nation_name_snapshot : '無所属',
            'window_minutes' => NationRaidRules::COORDINATION_WINDOW_MINUTES,
            'unique_count' => $members->count(),
            'bonus_rate' => NationRaidRules::coordinationDamageRate($members->count()),
            'participant_ids' => $members->pluck('character_id_snapshot')->all(),
            'participated_at' => $members->map(fn ($member) => $member->window_joined_at->getTimestamp())->all(),
            'newly_registered' => $new,
        ];
    }
}
