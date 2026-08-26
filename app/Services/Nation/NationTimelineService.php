<?php

namespace App\Services\Nation;

use App\Models\Character;
use App\Models\Nation;
use App\Models\NationActivityLog;
use App\Models\NationFacility;
use App\Models\NationWarHistory;
use Illuminate\Support\Collection;

final class NationTimelineService
{
    public const PUBLIC_EVENT_TYPES = [
        'nation_created',
        'ruler_transferred',
        'development_level_up',
        'member_count_milestone',
        'facility_level_milestone',
        'war_first_participation',
        'war_first_win',
        'max_level_reached',
    ];

    public function __construct(
        private readonly NationDevelopmentLevelService $levels,
        private readonly NationActivityLogService $activityLogs,
    ) {}

    public function isUnlocked(Nation $nation): bool
    {
        return $this->levels->levelFor((int) $nation->development_exp) >= 15;
    }

    /** @return Collection<int, NationActivityLog> */
    public function entries(Nation $nation, int $limit = 20): Collection
    {
        if (! $this->isUnlocked($nation)) {
            return collect();
        }

        return NationActivityLog::query()
            ->with(['actor', 'target'])
            ->where('nation_id', $nation->id)
            ->whereIn('event_type', self::PUBLIC_EVENT_TYPES)
            ->latest('id')
            ->limit(max(1, min(100, $limit)))
            ->get();
    }

    public function recordDevelopmentLevelUps(
        Nation $nation,
        int $previousExp,
        int $currentExp,
        ?Character $actor = null,
    ): void {
        $previousLevel = $this->levels->levelFor($previousExp);
        $currentLevel = $this->levels->levelFor($currentExp);
        for ($level = $previousLevel + 1; $level <= $currentLevel; $level++) {
            $this->activityLogs->record($nation, 'development_level_up', $actor, null, [
                'level' => $level,
                'cumulative_exp' => $this->levels->cumulativeExpForLevel($level),
            ]);
        }
        if ($previousLevel < $this->levels->maxLevel() && $currentLevel === $this->levels->maxLevel()) {
            $this->activityLogs->record($nation, 'max_level_reached', $actor, null, [
                'level' => $currentLevel,
            ]);
        }
    }

    public function recordMemberCountMilestone(Nation $nation, ?Character $actor = null, ?Character $target = null): void
    {
        $currentCount = $nation->memberships()->count();
        $recordedMaximum = (int) NationActivityLog::where('nation_id', $nation->id)
            ->where('event_type', 'member_count_milestone')
            ->get()
            ->max(static fn (NationActivityLog $log): int => (int) ($log->metadata['member_count'] ?? 1));
        if ($currentCount <= max(1, $recordedMaximum)) {
            return;
        }

        $this->activityLogs->record($nation, 'member_count_milestone', $actor, $target, [
            'member_count' => $currentCount,
        ]);
    }

    public function recordFacilityLevelMilestone(Nation $nation, NationFacility $facility, ?Character $actor = null): void
    {
        $this->activityLogs->record($nation, 'facility_level_milestone', $actor, null, [
            'facility_type' => $facility->facility_type,
            'level' => (int) $facility->level,
        ]);
    }

    public function recordWarResolved(NationWarHistory $history): void
    {
        foreach ([$history->declaring_nation_id, $history->defending_nation_id] as $nationId) {
            $nation = Nation::findOrFail($nationId);
            $hasEarlierWar = NationWarHistory::query()
                ->whereKeyNot($history->id)
                ->where(function ($query) use ($nationId): void {
                    $query->where('declaring_nation_id', $nationId)
                        ->orWhere('defending_nation_id', $nationId);
                })
                ->where(function ($query) use ($history): void {
                    $query->where('resolved_at', '<', $history->resolved_at)
                        ->orWhere(function ($sameTime) use ($history): void {
                            $sameTime->where('resolved_at', $history->resolved_at)
                                ->where('id', '<', $history->id);
                        });
                })
                ->exists();
            if (! $hasEarlierWar && ! $this->hasEvent($nation, 'war_first_participation')) {
                $this->activityLogs->record($nation, 'war_first_participation', null, null, [
                    'nation_war_history_id' => $history->id,
                ]);
            }
        }

        if ($history->winner_nation_id === null) {
            return;
        }

        $winner = Nation::findOrFail($history->winner_nation_id);
        $hasEarlierWin = NationWarHistory::query()
            ->whereKeyNot($history->id)
            ->where('winner_nation_id', $winner->id)
            ->where(function ($query) use ($history): void {
                $query->where('resolved_at', '<', $history->resolved_at)
                    ->orWhere(function ($sameTime) use ($history): void {
                        $sameTime->where('resolved_at', $history->resolved_at)
                            ->where('id', '<', $history->id);
                    });
            })
            ->exists();
        if (! $hasEarlierWin && ! $this->hasEvent($winner, 'war_first_win')) {
            $this->activityLogs->record($winner, 'war_first_win', null, null, [
                'nation_war_history_id' => $history->id,
            ]);
        }
    }

    public function description(NationActivityLog $log): string
    {
        $facility = match ((string) ($log->metadata['facility_type'] ?? '')) {
            'wall' => '城壁',
            'magic_cannon' => '魔導砲',
            'logistics' => '兵站所',
            'arsenal' => '要塞工廠',
            'headquarters' => '本陣',
            default => '国家施設',
        };

        return match ($log->event_type) {
            'nation_created' => '国家が建国された。',
            'ruler_transferred' => ($log->target?->name ?? '新たな統治者').'へ統治者の地位が受け継がれた。',
            'development_level_up' => '国家Lv'.(int) ($log->metadata['level'] ?? 1).'へ到達した。',
            'member_count_milestone' => '国民数が'.(int) ($log->metadata['member_count'] ?? 1).'人へ到達した。',
            'facility_level_milestone' => $facility.'がLv'.(int) ($log->metadata['level'] ?? 1).'へ到達した。',
            'war_first_participation' => '国家戦へ初めて参戦した。',
            'war_first_win' => '国家戦で初勝利を収めた。',
            'max_level_reached' => '国家Lv50へ到達し、発展の頂へ至った。',
            default => '国家の歴史に新たな出来事が刻まれた。',
        };
    }

    private function hasEvent(Nation $nation, string $eventType): bool
    {
        return NationActivityLog::where('nation_id', $nation->id)
            ->where('event_type', $eventType)
            ->exists();
    }
}
