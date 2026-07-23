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
        ['key' => 'second_city_reached', 'label' => '第2都市到達'],
    ];

    private const RETENTION_ACTION_STEPS = [
        ['key' => 'first_victory', 'label' => '初回勝利'],
        ['key' => 'first_equipment_change', 'label' => '初回装備変更'],
        ['key' => 'first_boss_defeat', 'label' => '初回ボス撃破'],
        ['key' => 'second_city_reached', 'label' => '第2都市到達'],
    ];

    public function dashboardMetrics(): array
    {
        if (!Schema::hasTable('player_lifecycle_events')) {
            return [
                'ready' => false,
                'today_funnel' => [],
                'retention' => [],
                'retention_action_insights' => [],
                'retention_action_period' => null,
                'drop_offs' => [],
            ];
        }

        return [
            'ready' => true,
            'today_funnel' => $this->todayFunnel(),
            'retention' => [
                $this->retentionMetric(1),
                $this->retentionMetric(7),
                $this->retentionMetric(30),
            ],
            'retention_action_insights' => $this->retentionActionInsights(),
            'retention_action_period' => $this->retentionActionPeriod(),
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

    /**
     * 初日行動とD7再訪の関連を、単日コホートのブレを避けるため直近4週間で集計する。
     */
    private function retentionActionInsights(): array
    {
        $period = $this->retentionActionPeriod();
        $registrations = PlayerLifecycleEvent::query()
            ->where('event_name', 'registered')
            ->whereBetween('occurred_at', [$period['from'], $period['to']])
            ->get(['user_id', 'occurred_at'])
            ->keyBy('user_id');

        if ($registrations->isEmpty()) {
            return [];
        }

        $eventsByUser = PlayerLifecycleEvent::query()
            ->whereIn('user_id', $registrations->keys())
            ->whereIn('event_name', array_merge($this->trackedEventNames(), ['login']))
            ->get(['user_id', 'event_name', 'metadata', 'occurred_at'])
            ->groupBy('user_id');

        $groups = [];
        foreach (self::RETENTION_ACTION_STEPS as $step) {
            $groups[$step['key']] = [
                'label' => $step['label'],
                'completed' => ['eligible' => 0, 'retained' => 0],
                'not_completed' => ['eligible' => 0, 'retained' => 0],
            ];
        }

        foreach ($registrations as $userId => $registration) {
            $registeredAt = $registration->occurred_at;
            $userEvents = $eventsByUser->get($userId, collect());
            $returnedOnD7 = $this->returnedOnDay($userEvents, $registeredAt->copy()->addDays(7));
            $initialDayEvents = $userEvents->filter(
                fn (PlayerLifecycleEvent $event): bool => $event->occurred_at->betweenIncluded($registeredAt, $registeredAt->copy()->addDay())
            );

            foreach (self::RETENTION_ACTION_STEPS as $step) {
                $groupKey = $this->hasReachedStep($initialDayEvents, $step['key']) ? 'completed' : 'not_completed';
                $groups[$step['key']][$groupKey]['eligible']++;
                if ($returnedOnD7) {
                    $groups[$step['key']][$groupKey]['retained']++;
                }
            }
        }

        return collect($groups)->map(function (array $group): array {
            foreach (['completed', 'not_completed'] as $key) {
                $group[$key]['rate'] = $this->rate($group[$key]['retained'], $group[$key]['eligible']);
                $group[$key]['is_small_sample'] = $group[$key]['eligible'] < 10;
            }

            return $group;
        })->values()->all();
    }

    private function retentionActionPeriod(): array
    {
        $to = now()->subDays(7)->endOfDay();
        $from = $to->copy()->subDays(27)->startOfDay();

        return ['from' => $from, 'to' => $to];
    }

    private function trackedEventNames(): array
    {
        return ['character_created', 'first_battle', 'first_victory', 'first_equipment_change', 'first_enhancement', 'first_boss_defeat', 'city_reached'];
    }

    private function hasReachedStep(Collection $events, string $stepKey): bool
    {
        if ($stepKey === 'second_city_reached') {
            return $events->where('event_name', 'city_reached')->contains(
                fn (PlayerLifecycleEvent $event): bool => (int) (($event->metadata ?? [])['city_order'] ?? 0) >= 2
            );
        }

        return $events->contains(fn (PlayerLifecycleEvent $event): bool => $event->event_name === $stepKey);
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
        $reachedSecondCity = $events->where('event_name', 'city_reached')->contains(function (PlayerLifecycleEvent $event): bool {
            return (int) (($event->metadata ?? [])['city_order'] ?? 0) >= 2;
        });

        return $reachedSecondCity ? '2つ目の都市到達後' : '2つ目の都市到達前';
    }

    private function rate(int $value, int $total): ?float
    {
        return $total > 0 ? round(($value / $total) * 100, 1) : null;
    }
}
