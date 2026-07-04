<?php

namespace App\Livewire\Admin;

use App\Models\GoldTransaction;
use App\Services\InnProfitEstimateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class InnAnalyticsManager extends Component
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
        return view('livewire.admin.inn-analytics-manager', $this->analyticsData())
            ->layout('components.layouts.admin');
    }

    private function analyticsData(): array
    {
        [$from, $to] = $this->dateRange();
        $profitEstimator = app(InnProfitEstimateService::class);

        if (! Schema::hasTable('gold_transactions')) {
            return [
                'generatedAt' => now(),
                'dateFrom' => $from,
                'dateTo' => $to,
                'missingTables' => true,
                'summaryCards' => [],
                'dailyRows' => [],
                'recentTransactions' => collect(),
            ];
        }

        $transactions = $this->transactions($from, $to);
        $dailyRows = $this->dailyRows($transactions, $from, $to, $profitEstimator);
        $periodRevenue = (int) $transactions->sum(fn (GoldTransaction $row): int => abs((int) $row->amount));
        $periodExpense = (int) collect($dailyRows)->sum('expense');
        $periodProfit = $periodRevenue - $periodExpense;
        $periodStays = $transactions->count();
        $periodGuests = $transactions->pluck('character_id')->filter()->unique()->count();
        $rescuedRows = $transactions->filter(fn (GoldTransaction $row): bool => (bool) data_get($row->metadata, 'rescued', false));

        return [
            'generatedAt' => now(),
            'dateFrom' => $from,
            'dateTo' => $to,
            'missingTables' => false,
            'expensePolicy' => [
                'dailyFixed' => $profitEstimator->dailyFixedExpense(),
                'revenueRatePercent' => $profitEstimator->revenueExpenseRatePercent(),
            ],
            'summaryCards' => [
                ['label' => '期間売上', 'value' => number_format($periodRevenue), 'unit' => 'G', 'note' => '宿泊で支払われたGold'],
                ['label' => '想定支出', 'value' => number_format($periodExpense), 'unit' => 'G', 'note' => '日次固定費＋売上連動分'],
                ['label' => '想定利益', 'value' => number_format($periodProfit), 'unit' => 'G', 'note' => '期間売上 − 想定支出', 'tone' => $periodProfit < 0 ? 'negative' : 'positive'],
                ['label' => '宿泊回数', 'value' => number_format($periodStays), 'unit' => '回', 'note' => '支払い発生分'],
                ['label' => '利用者数', 'value' => number_format($periodGuests), 'unit' => '人', 'note' => 'ユニーク冒険者'],
                ['label' => '平均宿代', 'value' => number_format($periodStays > 0 ? (int) floor($periodRevenue / $periodStays) : 0), 'unit' => 'G', 'note' => '期間売上 ÷ 宿泊回数'],
                ['label' => '救済宿泊', 'value' => number_format($rescuedRows->count()), 'unit' => '回', 'note' => 'メタ情報記録後のみ'],
                ['label' => '累計宿屋売上', 'value' => number_format($this->lifetimeRevenue()), 'unit' => 'G', 'note' => '過去ログ全体'],
            ],
            'dailyRows' => $dailyRows,
            'recentTransactions' => $this->recentTransactions(),
        ];
    }

    private function transactions(Carbon $from, Carbon $to): Collection
    {
        return GoldTransaction::query()
            ->where('type', 'inn')
            ->where('amount', '<', 0)
            ->whereBetween('created_at', [$from, $to])
            ->with('character:id,name,level')
            ->orderBy('created_at')
            ->get();
    }

    private function dailyRows(Collection $transactions, Carbon $from, Carbon $to, InnProfitEstimateService $profitEstimator): array
    {
        $rows = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $rows[$day->toDateString()] = [
                'date' => $day->toDateString(),
                'revenue' => 0,
                'expense' => 0,
                'profit' => 0,
                'stays' => 0,
                'guests' => [],
                'rescued' => 0,
            ];
        }

        foreach ($transactions as $transaction) {
            $date = $transaction->created_at?->toDateString();
            if (! $date || ! isset($rows[$date])) {
                continue;
            }

            $rows[$date]['revenue'] += abs((int) $transaction->amount);
            $rows[$date]['stays']++;
            $rows[$date]['guests'][(int) $transaction->character_id] = true;
            if ((bool) data_get($transaction->metadata, 'rescued', false)) {
                $rows[$date]['rescued']++;
            }
        }

        return collect($rows)
            ->map(function (array $row) use ($profitEstimator): array {
                $expense = $profitEstimator->estimatedDailyExpense((int) $row['revenue']);

                return [
                    ...$row,
                    'expense' => $expense,
                    'profit' => (int) $row['revenue'] - $expense,
                    'guests' => count($row['guests']),
                    'average' => $row['stays'] > 0 ? (int) floor($row['revenue'] / $row['stays']) : 0,
                ];
            })
            ->reverse()
            ->values()
            ->all();
    }

    private function recentTransactions(): Collection
    {
        return GoldTransaction::query()
            ->where('type', 'inn')
            ->where('amount', '<', 0)
            ->with('character:id,name,level')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    private function lifetimeRevenue(): int
    {
        return (int) GoldTransaction::query()
            ->where('type', 'inn')
            ->where('amount', '<', 0)
            ->get()
            ->sum(fn (GoldTransaction $row): int => abs((int) $row->amount));
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

        if ($from->diffInDays($to) > 180) {
            $from = $to->copy()->subDays(179)->startOfDay();
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
}
