<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class InnProfitEstimateService
{
    private const DAILY_FIXED_EXPENSE = 12000;

    private const REVENUE_EXPENSE_RATE = 0.18;

    public function dailyFixedExpense(): int
    {
        return self::DAILY_FIXED_EXPENSE;
    }

    public function revenueExpenseRatePercent(): int
    {
        return (int) round(self::REVENUE_EXPENSE_RATE * 100);
    }

    public function estimatedDailyExpense(int $revenue): int
    {
        return self::DAILY_FIXED_EXPENSE + (int) floor($revenue * self::REVENUE_EXPENSE_RATE);
    }

    public function periodEstimate(array $dailyRevenueByDate, Carbon $from, Carbon $to): array
    {
        $revenue = 0;
        $expense = 0;

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $dailyRevenue = (int) ($dailyRevenueByDate[$day->toDateString()] ?? 0);
            $revenue += $dailyRevenue;
            $expense += $this->estimatedDailyExpense($dailyRevenue);
        }

        return [
            'revenue' => $revenue,
            'expense' => $expense,
            'profit' => $revenue - $expense,
        ];
    }
}
