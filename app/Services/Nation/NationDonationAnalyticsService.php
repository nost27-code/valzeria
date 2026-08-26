<?php

namespace App\Services\Nation;

use App\Models\Nation;
use App\Models\NationResourceTransaction;
use App\Models\NationWantedMaterial;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class NationDonationAnalyticsService
{
    /** @return array<string, mixed> */
    public function summary(Nation $nation): array
    {
        $now = CarbonImmutable::now();

        return [
            'seven_days' => $this->periodComparison($nation, $now->subDays(7), $now),
            'thirty_days' => $this->periodComparison($nation, $now->subDays(30), $now),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function materialBreakdown(Nation $nation, int $days = 30): Collection
    {
        return NationResourceTransaction::query()
            ->leftJoin('materials', 'materials.id', '=', 'nation_resource_transactions.material_id')
            ->where('nation_resource_transactions.nation_id', $nation->id)
            ->where('nation_resource_transactions.transaction_type', 'donation')
            ->where('nation_resource_transactions.created_at', '>=', now()->subDays(max(1, $days)))
            ->groupBy('nation_resource_transactions.material_id', 'materials.name', 'materials.material_code')
            ->selectRaw(
                'nation_resource_transactions.material_id, materials.name, materials.material_code, '
                .'SUM(nation_resource_transactions.quantity) AS quantity, '
                .'SUM(nation_resource_transactions.points_delta) AS points, '
                .'SUM(nation_resource_transactions.development_exp_delta) AS development_exp'
            )
            ->orderByDesc('quantity')
            ->orderBy('nation_resource_transactions.material_id')
            ->get()
            ->map(static function ($row): array {
                $quantity = (int) $row->quantity;
                $points = (int) $row->points;

                return [
                    'material_id' => $row->material_id === null ? null : (int) $row->material_id,
                    'name' => $row->name ?? '削除済み素材',
                    'material_code' => $row->material_code,
                    'quantity' => $quantity,
                    'points' => $points,
                    'development_exp' => (int) $row->development_exp,
                    'tier' => $quantity > 0 && $points >= $quantity * 3 ? 'high' : 'low',
                ];
            });
    }

    /** @return Collection<int, array{date:string,quantity:int,points:int,development_exp:int}> */
    public function dailyTrend(Nation $nation, int $days = 30): Collection
    {
        return NationResourceTransaction::query()
            ->where('nation_id', $nation->id)
            ->where('transaction_type', 'donation')
            ->where('created_at', '>=', now()->subDays(max(1, $days)))
            ->orderBy('id')
            ->get(['quantity', 'points_delta', 'development_exp_delta', 'created_at'])
            ->groupBy(static fn (NationResourceTransaction $row): string => $row->created_at->timezone(config('app.timezone'))->format('Y-m-d'))
            ->map(static fn (Collection $rows, string $date): array => [
                'date' => $date,
                'quantity' => (int) $rows->sum('quantity'),
                'points' => (int) $rows->sum('points_delta'),
                'development_exp' => (int) $rows->sum('development_exp_delta'),
            ])
            ->values();
    }

    /** @return Collection<int, array{week_start:string,quantity:int,points:int,development_exp:int}> */
    public function weeklyTrend(Nation $nation, int $weeks = 8): Collection
    {
        return NationResourceTransaction::query()
            ->where('nation_id', $nation->id)
            ->where('transaction_type', 'donation')
            ->where('created_at', '>=', now()->subWeeks(max(1, $weeks))->startOfWeek())
            ->orderBy('id')
            ->get(['quantity', 'points_delta', 'development_exp_delta', 'created_at'])
            ->groupBy(static fn (NationResourceTransaction $row): string => $row->created_at
                ->timezone(config('app.timezone'))
                ->startOfWeek()
                ->format('Y-m-d'))
            ->map(static fn (Collection $rows, string $weekStart): array => [
                'week_start' => $weekStart,
                'quantity' => (int) $rows->sum('quantity'),
                'points' => (int) $rows->sum('points_delta'),
                'development_exp' => (int) $rows->sum('development_exp_delta'),
            ])
            ->values();
    }

    /** @return array{total_quantity:int,low_quantity:int,high_quantity:int,low_bps:int,high_bps:int} */
    public function tierSummary(Nation $nation, int $days = 30): array
    {
        $rows = NationResourceTransaction::query()
            ->where('nation_id', $nation->id)
            ->where('transaction_type', 'donation')
            ->where('created_at', '>=', now()->subDays(max(1, $days)))
            ->get(['quantity', 'points_delta']);
        $total = (int) $rows->sum('quantity');
        $high = (int) $rows
            ->filter(static fn (NationResourceTransaction $row): bool => (int) $row->quantity > 0
                && (int) $row->points_delta >= (int) $row->quantity * 3)
            ->sum('quantity');
        $low = max(0, $total - $high);

        return [
            'total_quantity' => $total,
            'low_quantity' => $low,
            'high_quantity' => $high,
            'low_bps' => $total > 0 ? intdiv($low * 10000, $total) : 0,
            'high_bps' => $total > 0 ? intdiv($high * 10000, $total) : 0,
        ];
    }

    /** @return Collection<int, array{material_id:int,name:string,purpose_note:?string,quantity_30d:int}> */
    public function wantedMaterialProgress(Nation $nation): Collection
    {
        $quantities = NationResourceTransaction::query()
            ->where('nation_id', $nation->id)
            ->where('transaction_type', 'donation')
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('material_id')
            ->groupBy('material_id')
            ->selectRaw('material_id, SUM(quantity) AS quantity')
            ->pluck('quantity', 'material_id');

        return NationWantedMaterial::query()
            ->with('material')
            ->where('nation_id', $nation->id)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get()
            ->filter(fn (NationWantedMaterial $wanted): bool => $wanted->material !== null)
            ->map(static fn (NationWantedMaterial $wanted): array => [
                'material_id' => (int) $wanted->material_id,
                'name' => (string) $wanted->material->name,
                'purpose_note' => $wanted->purpose_note,
                'quantity_30d' => (int) ($quantities[$wanted->material_id] ?? 0),
            ])
            ->values();
    }

    /** @return array{current:array<string,int>,previous:array<string,int>,changes:array<string,int>} */
    private function periodComparison(Nation $nation, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $seconds = (int) $start->diffInSeconds($end);
        $current = $this->aggregatePeriod($nation, $start, $end);
        $previous = $this->aggregatePeriod($nation, $start->subSeconds($seconds), $start);

        return [
            'current' => $current,
            'previous' => $previous,
            'changes' => [
                'quantity' => $current['quantity'] - $previous['quantity'],
                'points' => $current['points'] - $previous['points'],
                'development_exp' => $current['development_exp'] - $previous['development_exp'],
                'participants' => $current['participants'] - $previous['participants'],
            ],
        ];
    }

    /** @return array{quantity:int,points:int,development_exp:int,participants:int} */
    private function aggregatePeriod(Nation $nation, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $row = NationResourceTransaction::query()
            ->where('nation_id', $nation->id)
            ->where('transaction_type', 'donation')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw(
                'COALESCE(SUM(quantity), 0) AS quantity, '
                .'COALESCE(SUM(points_delta), 0) AS points, '
                .'COALESCE(SUM(development_exp_delta), 0) AS development_exp, '
                .'COUNT(DISTINCT character_id) AS participants'
            )
            ->first();

        return [
            'quantity' => (int) $row->quantity,
            'points' => (int) $row->points,
            'development_exp' => (int) $row->development_exp,
            'participants' => (int) $row->participants,
        ];
    }
}
