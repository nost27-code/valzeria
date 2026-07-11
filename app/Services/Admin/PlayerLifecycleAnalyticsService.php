<?php

namespace App\Services\Admin;

use App\Models\PlayerLifecycleEvent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PlayerLifecycleAnalyticsService
{
    public function dashboardMetrics(): array
    {
        if (!Schema::hasTable('player_lifecycle_events')) {
            return ['ready' => false, 'today_funnel' => [], 'retention' => [], 'drop_offs' => []];
        }

        return [
            'ready' => true,
            'today_funnel' => $this->todayFunnel(),
            'retention' => [
                $this->retentionMetric(1),
                $this->retentionMetric(7),
                $this->retentionMetric(30),
            ],
            'drop_offs' => $this->dropOffs(),
        ];
    }

    private function todayFunnel(): array
    {
        $today = now()->startOfDay();
        $userIds = User::query()->where('created_at', '>=', $today)->pluck('id');
        $registered = $userIds->count();

        $steps = [
            ['key' => 'registered', 'label' => '本日の新規登録', 'count' => $registered],
            ['key' => 'first_battle', 'label' => '初回戦闘到達'],
            ['key' => 'first_victory', 'label' => '初回勝利'],
            ['key' => 'first_equipment_change', 'label' => '初回装備変更'],
            ['key' => 'first_enhancement', 'label' => '初回装備強化'],
        ];

        foreach ($steps as $index => $step) {
            if ($index === 0) {
                continue;
            }

            $steps[$index]['count'] = $userIds->isEmpty()
                ? 0
                : PlayerLifecycleEvent::query()->whereIn('user_id', $userIds)->where('event_name', $step['key'])->count();
        }

        return array_map(fn (array $step): array => $step + ['rate' => $this->rate((int) $step['count'], $registered)], $steps);
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