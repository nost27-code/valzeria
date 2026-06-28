<?php

namespace App\Livewire\Admin;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperatorAnalyticsManager extends Component
{
    public string $dateFrom = '';

    public string $dateTo = '';

    public function mount(): void
    {
        $this->dateFrom = now()->subDays(29)->toDateString();
        $this->dateTo = now()->toDateString();
    }

    public function render()
    {
        return view('livewire.admin.operator-analytics-manager', $this->analyticsData())
            ->layout('components.layouts.admin');
    }

    public function downloadCsv(): StreamedResponse
    {
        $data = $this->analyticsData();

        return response()->streamDownload(function () use ($data): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'new_users', 'active_players', 'battles', 'wins', 'losses', 'champ_battles', 'rank_battles', 'chat_posts', 'revenue_jpy', 'purchase_count', 'buyer_count']);

            foreach ($data['dailyRows'] as $row) {
                fputcsv($out, [
                    $row['date'],
                    $row['new_users'],
                    $row['active_players'],
                    $row['battle_count'],
                    $row['win_count'],
                    $row['loss_count'],
                    $row['champ_battle_count'],
                    $row['rank_battle_count'],
                    $row['chat_count'],
                    $row['revenue_jpy'],
                    $row['purchase_count'],
                    $row['buyer_count'],
                ]);
            }

            fclose($out);
        }, 'valzeria-operator-analytics-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function analyticsData(): array
    {
        [$from, $to] = $this->dateRange();
        $dailyRows = $this->dailyRows($from, $to);
        $periodTotals = $this->periodTotals($dailyRows, $from, $to);

        return [
            'generatedAt' => now(),
            'dateFrom' => $from,
            'dateTo' => $to,
            'dailyRows' => $dailyRows,
            'periodTotals' => $periodTotals,
            'growthCards' => $this->growthCards(),
            'maxima' => $this->maxima($dailyRows),
            'tablesReady' => [
                'users' => Schema::hasTable('users'),
                'characters' => Schema::hasTable('characters'),
                'battle_logs' => Schema::hasTable('battle_logs'),
                'public_logs' => Schema::hasTable('public_logs'),
                'stripe_orders' => Schema::hasTable('stripe_orders'),
            ],
        ];
    }

    private function dailyRows(Carbon $from, Carbon $to): array
    {
        $keys = $this->dateKeys($from, $to);
        $rows = [];

        foreach ($keys as $key) {
            $rows[$key] = [
                'date' => $key,
                'new_users' => 0,
                'active_players' => 0,
                'battle_count' => 0,
                'win_count' => 0,
                'loss_count' => 0,
                'champ_battle_count' => 0,
                'rank_battle_count' => 0,
                'chat_count' => 0,
                'revenue_jpy' => 0,
                'purchase_count' => 0,
                'buyer_count' => 0,
            ];
        }

        foreach ($this->newUsersByDate($from, $to) as $date => $count) {
            $rows[$date]['new_users'] = $count;
        }

        foreach ($this->activePlayersByDate($from, $to) as $date => $count) {
            $rows[$date]['active_players'] = $count;
        }

        foreach ($this->battleRows($from, $to) as $date => $battle) {
            $rows[$date]['battle_count'] = $battle['battle_count'];
            $rows[$date]['win_count'] = $battle['win_count'];
            $rows[$date]['loss_count'] = $battle['loss_count'];
        }

        foreach ($this->countByDate('champ_battle_logs', 'created_at', $from, $to) as $date => $count) {
            $rows[$date]['champ_battle_count'] = $count;
        }

        foreach ($this->rankBattlesByDate($from, $to) as $date => $count) {
            $rows[$date]['rank_battle_count'] = $count;
        }

        foreach ($this->chatPostsByDate($from, $to) as $date => $count) {
            $rows[$date]['chat_count'] = $count;
        }

        foreach ($this->revenueByDate($from, $to) as $date => $revenue) {
            $rows[$date]['revenue_jpy'] = $revenue['revenue_jpy'];
            $rows[$date]['purchase_count'] = $revenue['purchase_count'];
            $rows[$date]['buyer_count'] = $revenue['buyer_count'];
        }

        return array_values($rows);
    }

    private function periodTotals(array $dailyRows, Carbon $from, Carbon $to): array
    {
        $battleCount = array_sum(array_column($dailyRows, 'battle_count'));
        $winCount = array_sum(array_column($dailyRows, 'win_count'));
        $activePlayers = $this->activePlayersBetween($from, $to);

        return [
            'new_users' => array_sum(array_column($dailyRows, 'new_users')),
            'active_players' => $activePlayers,
            'battle_count' => $battleCount,
            'win_rate' => $battleCount > 0 ? round(($winCount / $battleCount) * 100, 1) : 0.0,
            'chat_count' => array_sum(array_column($dailyRows, 'chat_count')),
            'revenue_jpy' => array_sum(array_column($dailyRows, 'revenue_jpy')),
            'purchase_count' => array_sum(array_column($dailyRows, 'purchase_count')),
        ];
    }

    private function growthCards(): array
    {
        return collect([7, 14, 30])->flatMap(function (int $days) {
            $currentEnd = now()->endOfDay();
            $currentStart = now()->subDays($days - 1)->startOfDay();
            $previousEnd = $currentStart->copy()->subSecond();
            $previousStart = $currentStart->copy()->subDays($days)->startOfDay();

            $current = $this->aggregatePeriod($currentStart, $currentEnd);
            $previous = $this->aggregatePeriod($previousStart, $previousEnd);

            return [
                $this->growthCard($days, '新規登録', $current['new_users'], $previous['new_users'], '人'),
                $this->growthCard($days, '活動者', $current['active_players'], $previous['active_players'], '人'),
                $this->growthCard($days, '戦闘', $current['battle_count'], $previous['battle_count'], '回'),
                $this->growthCard($days, '売上', $current['revenue_jpy'], $previous['revenue_jpy'], '円'),
            ];
        })->all();
    }

    private function growthCard(int $days, string $label, int $current, int $previous, string $unit): array
    {
        $rate = null;
        if ($previous > 0) {
            $rate = round((($current - $previous) / $previous) * 100, 1);
        }

        return [
            'days' => $days,
            'label' => $label,
            'current' => $current,
            'previous' => $previous,
            'unit' => $unit,
            'rate' => $rate,
            'is_new' => $previous === 0 && $current > 0,
        ];
    }

    private function aggregatePeriod(Carbon $from, Carbon $to): array
    {
        $rows = $this->dailyRows($from, $to);

        return [
            'new_users' => array_sum(array_column($rows, 'new_users')),
            'active_players' => $this->activePlayersBetween($from, $to),
            'battle_count' => array_sum(array_column($rows, 'battle_count')),
            'revenue_jpy' => array_sum(array_column($rows, 'revenue_jpy')),
        ];
    }

    private function newUsersByDate(Carbon $from, Carbon $to): array
    {
        if (!Schema::hasTable('users')) {
            return [];
        }

        return DB::table('users')
            ->where('role', '!=', 'admin')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw($this->dateExpression('created_at') . ' as metric_date')
            ->selectRaw('COUNT(*) as metric_count')
            ->groupBy('metric_date')
            ->pluck('metric_count', 'metric_date')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    private function battleRows(Carbon $from, Carbon $to): array
    {
        if (!Schema::hasTable('battle_logs')) {
            return [];
        }

        return DB::table('battle_logs')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw($this->dateExpression('created_at') . ' as metric_date')
            ->selectRaw('COUNT(*) as battle_count')
            ->selectRaw("SUM(CASE WHEN result = 'win' THEN 1 ELSE 0 END) as win_count")
            ->selectRaw("SUM(CASE WHEN result = 'lose' THEN 1 ELSE 0 END) as loss_count")
            ->groupBy('metric_date')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (string) $row->metric_date => [
                    'battle_count' => (int) $row->battle_count,
                    'win_count' => (int) $row->win_count,
                    'loss_count' => (int) $row->loss_count,
                ],
            ])
            ->all();
    }

    private function rankBattlesByDate(Carbon $from, Carbon $to): array
    {
        $counts = [];

        foreach (['arena_logs', 'arena_npc_logs'] as $table) {
            foreach ($this->countByDate($table, 'created_at', $from, $to) as $date => $count) {
                $counts[$date] = ($counts[$date] ?? 0) + $count;
            }
        }

        return $counts;
    }

    private function chatPostsByDate(Carbon $from, Carbon $to): array
    {
        if (!Schema::hasTable('public_logs')) {
            return [];
        }

        return DB::table('public_logs')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('character_id')
            ->whereIn('type', ['chat', 'guild', 'private'])
            ->selectRaw($this->dateExpression('created_at') . ' as metric_date')
            ->selectRaw('COUNT(*) as metric_count')
            ->groupBy('metric_date')
            ->pluck('metric_count', 'metric_date')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    private function revenueByDate(Carbon $from, Carbon $to): array
    {
        if (!Schema::hasTable('stripe_orders')) {
            return [];
        }

        $dateColumn = 'COALESCE(fulfilled_at, created_at)';

        return DB::table('stripe_orders')
            ->where('status', 'fulfilled')
            ->whereBetween(DB::raw($dateColumn), [$from, $to])
            ->selectRaw($this->dateExpression($dateColumn) . ' as metric_date')
            ->selectRaw('SUM(price_jpy) as revenue_jpy')
            ->selectRaw('COUNT(*) as purchase_count')
            ->selectRaw('COUNT(DISTINCT character_id) as buyer_count')
            ->groupBy('metric_date')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (string) $row->metric_date => [
                    'revenue_jpy' => (int) $row->revenue_jpy,
                    'purchase_count' => (int) $row->purchase_count,
                    'buyer_count' => (int) $row->buyer_count,
                ],
            ])
            ->all();
    }

    private function activePlayersByDate(Carbon $from, Carbon $to): array
    {
        $activity = $this->activityUserIdsByDate($from, $to);

        return collect($activity)
            ->map(fn (array $userIds): int => count($userIds))
            ->all();
    }

    private function activePlayersBetween(Carbon $from, Carbon $to): int
    {
        $userIds = [];

        foreach ($this->activityUserIdsByDate($from, $to) as $dailyUserIds) {
            foreach ($dailyUserIds as $userId => $_) {
                $userIds[$userId] = true;
            }
        }

        return count($userIds);
    }

    private function activityUserIdsByDate(Carbon $from, Carbon $to): array
    {
        if (!Schema::hasTable('characters')) {
            return [];
        }

        $activity = [];
        foreach ($this->dateKeys($from, $to) as $key) {
            $activity[$key] = [];
        }

        $this->mergeActivity($activity, 'battle_logs', 'character_id', 'created_at', $from, $to);
        $this->mergeActivity($activity, 'public_logs', 'character_id', 'created_at', $from, $to, fn ($query) => $query->whereNotNull('public_logs.character_id'));
        $this->mergeActivity($activity, 'champ_battle_logs', 'challenger_character_id', 'created_at', $from, $to);
        $this->mergeActivity($activity, 'arena_logs', 'attacker_id', 'created_at', $from, $to);
        $this->mergeActivity($activity, 'arena_npc_logs', 'attacker_id', 'created_at', $from, $to);

        if (Schema::hasColumn('characters', 'last_seen_at')) {
            $this->mergeLastSeenActivity($activity, $from, $to);
        }

        return $activity;
    }

    private function mergeActivity(array &$activity, string $table, string $characterColumn, string $dateColumn, Carbon $from, Carbon $to, ?callable $constraint = null): void
    {
        if (
            !Schema::hasTable($table)
            || !Schema::hasColumn($table, $characterColumn)
            || !Schema::hasColumn($table, $dateColumn)
        ) {
            return;
        }

        $query = DB::table($table)
            ->join('characters', "{$table}.{$characterColumn}", '=', 'characters.id')
            ->whereNotNull('characters.user_id')
            ->whereBetween("{$table}.{$dateColumn}", [$from, $to])
            ->selectRaw($this->dateExpression("{$table}.{$dateColumn}") . ' as metric_date')
            ->selectRaw('characters.user_id as user_id')
            ->groupBy('metric_date', 'characters.user_id');

        if ($constraint) {
            $constraint($query);
        }

        foreach ($query->get() as $row) {
            $date = (string) $row->metric_date;
            if (isset($activity[$date])) {
                $activity[$date][(int) $row->user_id] = true;
            }
        }
    }

    private function mergeLastSeenActivity(array &$activity, Carbon $from, Carbon $to): void
    {
        $rows = DB::table('characters')
            ->whereNotNull('user_id')
            ->whereBetween('last_seen_at', [$from, $to])
            ->selectRaw($this->dateExpression('last_seen_at') . ' as metric_date')
            ->selectRaw('user_id')
            ->groupBy('metric_date', 'user_id')
            ->get();

        foreach ($rows as $row) {
            $date = (string) $row->metric_date;
            if (isset($activity[$date])) {
                $activity[$date][(int) $row->user_id] = true;
            }
        }
    }

    private function countByDate(string $table, string $dateColumn, Carbon $from, Carbon $to): array
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $dateColumn)) {
            return [];
        }

        return DB::table($table)
            ->whereBetween($dateColumn, [$from, $to])
            ->selectRaw($this->dateExpression($dateColumn) . ' as metric_date')
            ->selectRaw('COUNT(*) as metric_count')
            ->groupBy('metric_date')
            ->pluck('metric_count', 'metric_date')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    private function maxima(array $dailyRows): array
    {
        return [
            'new_users' => max(1, max(array_column($dailyRows, 'new_users') ?: [0])),
            'active_players' => max(1, max(array_column($dailyRows, 'active_players') ?: [0])),
            'battle_count' => max(1, max(array_column($dailyRows, 'battle_count') ?: [0])),
            'chat_count' => max(1, max(array_column($dailyRows, 'chat_count') ?: [0])),
            'revenue_jpy' => max(1, max(array_column($dailyRows, 'revenue_jpy') ?: [0])),
        ];
    }

    private function dateRange(): array
    {
        $from = $this->parseDate($this->dateFrom, now()->subDays(29))->startOfDay();
        $to = $this->parseDate($this->dateTo, now())->endOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            $this->dateFrom = $from->toDateString();
            $this->dateTo = $to->toDateString();
        }

        if ($from->diffInDays($to) > 120) {
            $from = $to->copy()->subDays(119)->startOfDay();
            $this->dateFrom = $from->toDateString();
        }

        return [$from, $to];
    }

    private function parseDate(string $value, Carbon $fallback): Carbon
    {
        try {
            return Carbon::parse($value ?: $fallback->toDateString());
        } catch (\Throwable) {
            return $fallback->copy();
        }
    }

    private function dateKeys(Carbon $from, Carbon $to): array
    {
        $keys = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $keys[] = $day->toDateString();
        }

        return $keys;
    }

    private function dateExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "date({$column})"
            : "DATE({$column})";
    }
}
