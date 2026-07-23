<?php

namespace App\Services\Admin;

use App\Models\PlayerLifecycleEvent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PlayerLifecycleAnalyticsService
{
    private const INITIAL_FUNNEL_STEPS = [
        ['key' => 'registered', 'label' => '新規登録'],
        ['key' => 'character_created', 'label' => 'キャラクター作成'],
        ['key' => 'first_battle', 'label' => '初回戦闘到達'],
        ['key' => 'first_victory', 'label' => '初回勝利'],
        ['key' => 'first_equipment_change', 'label' => '初回装備変更'],
        ['key' => 'first_enhancement', 'label' => '初回装備強化'],
        ['key' => 'first_boss_defeat', 'label' => '初回ボス撃破'],
        ['key' => 'first_next_city_unlocked', 'label' => '初めて次の街を解放'],
    ];

    private const INITIAL_PROGRESS_STEPS = [
        ['key' => 'before_first_victory', 'label' => '初回勝利前'],
        ['key' => 'first_victory', 'label' => '初回勝利まで'],
        ['key' => 'first_equipment_change', 'label' => '初回装備変更まで'],
        ['key' => 'first_boss_defeat', 'label' => '初回ボス撃破まで'],
        ['key' => 'first_next_city_unlocked', 'label' => '次の街解放まで'],
    ];

    private const INITIAL_TIME_MILESTONES = [
        ['key' => 'character_created', 'label' => 'キャラクター作成'],
        ['key' => 'first_victory', 'label' => '初回勝利'],
        ['key' => 'first_equipment_change', 'label' => '初回装備変更'],
        ['key' => 'first_boss_defeat', 'label' => '初回ボス撃破'],
        ['key' => 'first_next_city_unlocked', 'label' => '次の街解放'],
    ];

    public function dashboardMetrics(): array
    {
        if (!Schema::hasTable('player_lifecycle_events')) {
            return [
                'ready' => false,
                'today_funnel' => [],
                'retention' => [],
                'initial_progress_d7' => [],
                'initial_milestone_times' => [],
                'initial_analysis_period' => null,
                'drop_offs' => [],
            ];
        }

        $initialCohort = $this->initialCohortData();

        return [
            'ready' => true,
            'today_funnel' => $this->todayFunnel(),
            'retention' => [
                $this->retentionMetric(1),
                $this->retentionMetric(7),
                $this->retentionMetric(30),
            ],
            'initial_progress_d7' => $this->initialProgressD7($initialCohort),
            'initial_milestone_times' => $this->initialMilestoneTimes($initialCohort),
            'initial_analysis_period' => $this->initialAnalysisPeriod(),
            'drop_offs' => $this->dropOffs(),
        ];
    }

    private function todayFunnel(): array
    {
        $today = now()->startOfDay();
        $userIds = User::query()
            ->where('role', '!=', 'admin')
            ->where('created_at', '>=', $today)
            ->pluck('id');
        $registered = $userIds->count();

        $steps = collect(self::INITIAL_FUNNEL_STEPS)
            ->map(fn (array $step): array => $step + [
                'label' => $step['key'] === 'registered' ? '本日の新規登録' : $step['label'],
                'count' => $step['key'] === 'registered' ? $registered : 0,
            ])
            ->all();

        $events = $userIds->isEmpty()
            ? collect()
            : PlayerLifecycleEvent::query()
                ->whereIn('user_id', $userIds)
                ->whereIn('event_name', $this->trackedEventNames())
                ->get(['user_id', 'event_name', 'metadata', 'occurred_at'])
                ->groupBy('user_id');

        foreach ($steps as $index => $step) {
            if ($index === 0) {
                continue;
            }

            $steps[$index]['count'] = $events->filter(
                fn (Collection $userEvents): bool => $this->hasReachedStep($userEvents, $step['key'])
            )->count();
        }

        return array_map(fn (array $step): array => $step + ['rate' => $this->rate((int) $step['count'], $registered)], $steps);
    }

    private function initialCohortData(): array
    {
        $period = $this->initialAnalysisPeriod();
        $registrations = PlayerLifecycleEvent::query()
            ->where('event_name', 'registered')
            ->whereBetween('occurred_at', [$period['from'], $period['to']])
            ->get(['user_id', 'occurred_at'])
            ->keyBy('user_id');

        if ($registrations->isEmpty()) {
            return [$registrations, collect()];
        }

        $eventsByUser = PlayerLifecycleEvent::query()
            ->whereIn('user_id', $registrations->keys())
            ->whereIn('event_name', array_merge($this->trackedEventNames(), ['login']))
            ->get(['user_id', 'event_name', 'metadata', 'occurred_at'])
            ->groupBy('user_id');

        return [$registrations, $eventsByUser];
    }

    /**
     * 初日の進行段階とD7再訪を、互いに重複しない段階として直近4週間で集計する。
     */
    private function initialProgressD7(array $initialCohort): array
    {
        [$registrations, $eventsByUser] = $initialCohort;
        if ($registrations->isEmpty()) {
            return [];
        }

        $groups = [];
        foreach (self::INITIAL_PROGRESS_STEPS as $step) {
            $groups[$step['key']] = [
                'key' => $step['key'],
                'label' => $step['label'],
                'eligible' => 0,
                'retained' => 0,
            ];
        }

        foreach ($registrations as $userId => $registration) {
            $registeredAt = $registration->occurred_at;
            $userEvents = $eventsByUser->get($userId, collect());
            $returnedOnD7 = $this->returnedOnDay($userEvents, $registeredAt->copy()->addDays(7));
            $initialDayEvents = $userEvents->filter(
                fn (PlayerLifecycleEvent $event): bool => $event->occurred_at->betweenIncluded($registeredAt, $registeredAt->copy()->addDay())
            );

            $groupKey = $this->initialProgressKey($initialDayEvents);
            $groups[$groupKey]['eligible']++;
            if ($returnedOnD7) {
                $groups[$groupKey]['retained']++;
            }
        }

        return collect($groups)->map(function (array $group): array {
            $group['rate'] = $this->rate($group['retained'], $group['eligible']);
            $group['is_small_sample'] = $group['eligible'] < 10;
            return $group;
        })->values()->all();
    }

    private function initialMilestoneTimes(array $initialCohort): array
    {
        [$registrations, $eventsByUser] = $initialCohort;
        if ($registrations->isEmpty()) {
            return [];
        }

        $durations = collect(self::INITIAL_TIME_MILESTONES)->mapWithKeys(
            fn (array $milestone): array => [$milestone['key'] => []]
        )->all();

        foreach ($registrations as $userId => $registration) {
            $registeredAt = $registration->occurred_at;
            $initialDayEvents = $eventsByUser->get($userId, collect())->filter(
                fn (PlayerLifecycleEvent $event): bool => $event->occurred_at->betweenIncluded($registeredAt, $registeredAt->copy()->addDay())
            );

            foreach (self::INITIAL_TIME_MILESTONES as $milestone) {
                $occurredAt = $this->firstOccurredAt($initialDayEvents, $milestone['key']);
                if ($occurredAt) {
                    $durations[$milestone['key']][] = $registeredAt->diffInMinutes($occurredAt);
                }
            }
        }

        return collect(self::INITIAL_TIME_MILESTONES)->map(function (array $milestone) use ($durations): array {
            $values = collect($durations[$milestone['key']])->sort()->values();
            $count = $values->count();

            return [
                'label' => $milestone['label'],
                'count' => $count,
                'median_minutes' => $this->medianMinutes($values),
                'median_label' => $this->durationLabel($this->medianMinutes($values)),
                'is_small_sample' => $count < 10,
            ];
        })->all();
    }

    private function initialAnalysisPeriod(): array
    {
        $to = now()->subDays(7)->endOfDay();
        $from = $to->copy()->subDays(27)->startOfDay();

        return ['from' => $from, 'to' => $to];
    }

    private function trackedEventNames(): array
    {
        return ['character_created', 'first_battle', 'first_victory', 'first_equipment_change', 'first_enhancement', 'first_boss_defeat', 'first_next_city_unlocked'];
    }

    private function hasReachedStep(Collection $events, string $stepKey): bool
    {
        return $events->contains(fn (PlayerLifecycleEvent $event): bool => $event->event_name === $stepKey);
    }

    private function initialProgressKey(Collection $events): string
    {
        foreach (array_reverse(self::INITIAL_PROGRESS_STEPS) as $step) {
            if ($step['key'] !== 'before_first_victory' && $this->hasReachedStep($events, $step['key'])) {
                return $step['key'];
            }
        }

        return 'before_first_victory';
    }

    private function firstOccurredAt(Collection $events, string $eventName)
    {
        return $events
            ->where('event_name', $eventName)
            ->sortBy(fn (PlayerLifecycleEvent $event): int => $event->occurred_at->getTimestamp())
            ->first()?->occurred_at;
    }

    private function medianMinutes(Collection $values): ?int
    {
        $count = $values->count();
        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (int) $values[$middle]
            : (int) round(((int) $values[$middle - 1] + (int) $values[$middle]) / 2);
    }

    private function durationLabel(?int $minutes): string
    {
        if ($minutes === null) {
            return '-';
        }

        if ($minutes < 60) {
            return $minutes . '分';
        }

        return intdiv($minutes, 60) . '時間' . ($minutes % 60) . '分';
    }

    private function returnedOnDay(Collection $events, $returnDay): bool
    {
        return $events->where('event_name', 'login')->contains(
            fn (PlayerLifecycleEvent $event): bool => $event->occurred_at->betweenIncluded($returnDay->copy()->startOfDay(), $returnDay->copy()->endOfDay())
        );
    }

    private function retentionMetric(int $days): array
    {
        $cohortDay = now()->subDays($days)->startOfDay();
        $cohortEnd = $cohortDay->copy()->endOfDay();
        $returnDay = $cohortDay->copy()->addDays($days);
        $userIds = PlayerLifecycleEvent::query()
            ->where('event_name', 'registered')
            ->whereBetween('occurred_at', [$cohortDay, $cohortEnd])
            ->pluck('user_id');
        $registered = $userIds->count();
        $retained = $userIds->isEmpty()
            ? 0
            : PlayerLifecycleEvent::query()
                ->whereIn('user_id', $userIds)
                ->where('event_name', 'login')
                ->whereBetween('occurred_at', [$returnDay->copy()->startOfDay(), $returnDay->copy()->endOfDay()])
                ->distinct('user_id')
                ->count('user_id');

        return [
            'days' => $days,
            'label' => "D{$days}継続率",
            'cohort_date' => $cohortDay->toDateString(),
            'registered' => $registered,
            'retained' => $retained,
            'rate' => $this->rate($retained, $registered),
        ];
    }

    private function dropOffs(): array
    {
        $inactiveBefore = now()->subDays(7);
        $registeredUserIds = PlayerLifecycleEvent::query()
            ->where('event_name', 'registered')
            ->where('occurred_at', '<', $inactiveBefore)
            ->pluck('user_id');
        if ($registeredUserIds->isEmpty()) {
            return [];
        }

        $activeUserIds = PlayerLifecycleEvent::query()
            ->whereIn('user_id', $registeredUserIds)
            ->where('event_name', 'login')
            ->where('occurred_at', '>=', $inactiveBefore)
            ->pluck('user_id');
        $inactiveUserIds = $registeredUserIds->diff($activeUserIds)->values();
        if ($inactiveUserIds->isEmpty()) {
            return [];
        }

        $eventsByUser = PlayerLifecycleEvent::query()
            ->whereIn('user_id', $inactiveUserIds)
            ->get(['user_id', 'event_name', 'metadata'])
            ->groupBy('user_id');
        $counts = [];
        foreach ($eventsByUser as $events) {
            $label = $this->dropOffLabel($events);
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        $total = array_sum($counts);
        arsort($counts);
        return collect($counts)->take(10)->map(fn (int $count, string $label): array => [
            'name' => $label,
            'count' => $count,
            'percent' => $this->rate($count, $total),
        ])->values()->all();
    }

    private function dropOffLabel(Collection $events): string
    {
        $names = $events->pluck('event_name')->all();
        if (!in_array('character_created', $names, true)) {
            return '登録完了後（キャラクター作成前）';
        }
        if (!in_array('first_battle', $names, true)) {
            return 'キャラクター作成後';
        }
        if (!in_array('first_equipment_change', $names, true)) {
            return '最初の装備選択前';
        }
        if (!in_array('first_boss_defeat', $names, true)) {
            return '初回ボス撃破前';
        }

        return $events->contains('event_name', 'first_next_city_unlocked') ? '次の街解放後' : '初回ボス撃破後';
    }

    private function rate(int $value, int $total): ?float
    {
        return $total > 0 ? round(($value / $total) * 100, 1) : null;
    }
}
