<?php

namespace App\Livewire\Admin;

use App\Models\Character;
use App\Models\Skill;
use App\Models\StripeOrder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class GrowthAnalyticsManager extends Component
{
    public string $dateFrom = '';

    public string $dateTo = '';

    public function mount(): void
    {
        $this->dateFrom = now()->subDays(13)->toDateString();
        $this->dateTo = now()->toDateString();
    }

    public function render()
    {
        return view('livewire.admin.growth-analytics-manager', $this->analyticsData())
            ->layout('components.layouts.admin');
    }

    private function analyticsData(): array
    {
        [$dateFrom, $dateTo] = $this->dateRange();
        $retention = $this->retentionCohorts($dateFrom, $dateTo);
        $revenue = $this->revenueSummary($dateFrom, $dateTo);
        $jobArts = $this->jobArtSummary();

        return [
            'generatedAt' => now(),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'retentionCohorts' => $retention['cohorts'],
            'retentionTotals' => $retention['totals'],
            'dailyRevenue' => $revenue['daily'],
            'monthlyRevenue' => $revenue['monthly'],
            'revenueCards' => $revenue['cards'],
            'topLtvPlayers' => $revenue['top_ltv_players'],
            'jobArtCards' => $jobArts['cards'],
            'jobArtSlotRates' => $jobArts['slot_rates'],
            'jobArtSkillUsage' => $jobArts['skill_usage'],
            'unusedJobArts' => $jobArts['unused_job_arts'],
            'tablesReady' => [
                'stripe_orders' => Schema::hasTable('stripe_orders'),
                'character_job_art_slots' => Schema::hasTable('character_job_art_slots'),
                'skills' => Schema::hasTable('skills'),
            ],
        ];
    }

    private function retentionCohorts(Carbon $dateFrom, Carbon $dateTo): array
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('characters')) {
            return ['cohorts' => [], 'totals' => $this->emptyRetentionTotals()];
        }

        $users = User::query()
            ->where('role', '!=', 'admin')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->with(['characters:id,user_id,last_seen_at,updated_at'])
            ->orderBy('created_at')
            ->get();

        $grouped = $users->groupBy(fn (User $user): string => $user->created_at->toDateString());
        $cohorts = [];

        for ($day = $dateFrom->copy()->startOfDay(); $day->lte($dateTo); $day->addDay()) {
            $key = $day->toDateString();
            $cohortUsers = $grouped->get($key, collect());
            $registered = $cohortUsers->count();

            $cohorts[] = [
                'date' => $key,
                'registered' => $registered,
                'day1' => $this->retentionCell($cohortUsers, 1),
                'day3' => $this->retentionCell($cohortUsers, 3),
                'day7' => $this->retentionCell($cohortUsers, 7),
            ];
        }

        return [
            'cohorts' => array_reverse($cohorts),
            'totals' => [
                'registered' => $users->count(),
                'day1' => $this->retentionCell($users, 1),
                'day3' => $this->retentionCell($users, 3),
                'day7' => $this->retentionCell($users, 7),
            ],
        ];
    }

    private function retentionCell($users, int $days): array
    {
        $now = now();
        $eligible = $users->filter(fn (User $user): bool => $user->created_at->copy()->addDays($days)->lte($now));
        $denominator = $eligible->count();

        if ($denominator <= 0) {
            return ['eligible' => 0, 'retained' => 0, 'rate' => null];
        }

        $retained = $eligible->filter(function (User $user) use ($days): bool {
            $activityAt = $this->latestUserActivityAt($user);

            return $activityAt && $activityAt->gte($user->created_at->copy()->addDays($days));
        })->count();

        return [
            'eligible' => $denominator,
            'retained' => $retained,
            'rate' => round(($retained / $denominator) * 100, 1),
        ];
    }

    private function latestUserActivityAt(User $user): ?Carbon
    {
        $activityAt = $user->characters
            ->map(fn (Character $character) => $character->last_seen_at ?: $character->updated_at)
            ->filter()
            ->max();

        return $activityAt ?: $user->updated_at;
    }

    private function emptyRetentionTotals(): array
    {
        return [
            'registered' => 0,
            'day1' => ['eligible' => 0, 'retained' => 0, 'rate' => null],
            'day3' => ['eligible' => 0, 'retained' => 0, 'rate' => null],
            'day7' => ['eligible' => 0, 'retained' => 0, 'rate' => null],
        ];
    }

    private function revenueSummary(Carbon $dateFrom, Carbon $dateTo): array
    {
        if (!Schema::hasTable('stripe_orders')) {
            return [
                'daily' => [],
                'monthly' => [],
                'cards' => $this->revenueCards(0, 0, 0, 0, 0, 0),
                'top_ltv_players' => collect(),
            ];
        }

        $periodOrders = StripeOrder::query()
            ->where('status', 'fulfilled')
            ->whereBetween(DB::raw('COALESCE(fulfilled_at, created_at)'), [$dateFrom, $dateTo]);

        $periodRevenue = (int) (clone $periodOrders)->sum('price_jpy');
        $periodKiseki = (int) (clone $periodOrders)->sum('kiseki_amount');
        $periodPurchaseCount = (int) (clone $periodOrders)->count();
        $periodBuyerCount = (int) (clone $periodOrders)->distinct('character_id')->count('character_id');

        $registeredCount = Schema::hasTable('users')
            ? (int) User::where('role', '!=', 'admin')->count()
            : 0;
        $convertedUserCount = $this->convertedUserCount();

        return [
            'daily' => $this->dailyRevenue($dateFrom, $dateTo),
            'monthly' => $this->monthlyRevenue($dateFrom, $dateTo),
            'cards' => $this->revenueCards($periodRevenue, $periodKiseki, $periodPurchaseCount, $periodBuyerCount, $registeredCount, $convertedUserCount),
            'top_ltv_players' => $this->topLtvPlayers(),
        ];
    }

    private function revenueCards(int $periodRevenue, int $periodKiseki, int $periodPurchaseCount, int $periodBuyerCount, int $registeredCount, int $convertedUserCount): array
    {
        $conversionRate = $registeredCount > 0
            ? round(($convertedUserCount / $registeredCount) * 100, 1)
            : 0.0;

        return [
            ['label' => '期間売上', 'value' => number_format($periodRevenue) . '円', 'note' => 'fulfilledのみ'],
            ['label' => '期間販売輝石', 'value' => number_format($periodKiseki), 'note' => 'stripe_orders.kiseki_amount'],
            ['label' => '期間購入回数', 'value' => number_format($periodPurchaseCount), 'note' => number_format($periodBuyerCount) . '人が購入'],
            ['label' => '有料転換率', 'value' => $conversionRate . '%', 'note' => number_format($convertedUserCount) . ' / ' . number_format($registeredCount) . ' users'],
        ];
    }

    private function convertedUserCount(): int
    {
        if (!Schema::hasTable('stripe_orders') || !Schema::hasTable('characters')) {
            return 0;
        }

        return (int) DB::table('stripe_orders')
            ->join('characters', 'stripe_orders.character_id', '=', 'characters.id')
            ->join('users', 'characters.user_id', '=', 'users.id')
            ->where('stripe_orders.status', 'fulfilled')
            ->where('users.role', '!=', 'admin')
            ->distinct('users.id')
            ->count('users.id');
    }

    private function dailyRevenue(Carbon $dateFrom, Carbon $dateTo): array
    {
        $dateExpression = $this->dateExpression('COALESCE(fulfilled_at, created_at)');
        $rows = DB::table('stripe_orders')
            ->where('status', 'fulfilled')
            ->whereBetween(DB::raw('COALESCE(fulfilled_at, created_at)'), [$dateFrom, $dateTo])
            ->selectRaw("{$dateExpression} as sales_date")
            ->selectRaw('SUM(price_jpy) as revenue_jpy')
            ->selectRaw('SUM(kiseki_amount) as kiseki_amount')
            ->selectRaw('COUNT(*) as purchase_count')
            ->selectRaw('COUNT(DISTINCT character_id) as buyer_count')
            ->groupBy('sales_date')
            ->orderByDesc('sales_date')
            ->get()
            ->keyBy('sales_date');

        $days = [];
        for ($day = $dateFrom->copy()->startOfDay(); $day->lte($dateTo); $day->addDay()) {
            $key = $day->toDateString();
            $row = $rows->get($key);

            $days[] = [
                'date' => $key,
                'revenue_jpy' => (int) ($row->revenue_jpy ?? 0),
                'kiseki_amount' => (int) ($row->kiseki_amount ?? 0),
                'purchase_count' => (int) ($row->purchase_count ?? 0),
                'buyer_count' => (int) ($row->buyer_count ?? 0),
            ];
        }

        return array_reverse($days);
    }

    private function monthlyRevenue(Carbon $dateFrom, Carbon $dateTo): array
    {
        $monthExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', COALESCE(fulfilled_at, created_at))"
            : "DATE_FORMAT(COALESCE(fulfilled_at, created_at), '%Y-%m')";

        return DB::table('stripe_orders')
            ->where('status', 'fulfilled')
            ->whereBetween(DB::raw('COALESCE(fulfilled_at, created_at)'), [$dateFrom->copy()->startOfMonth(), $dateTo])
            ->selectRaw("{$monthExpression} as sales_month")
            ->selectRaw('SUM(price_jpy) as revenue_jpy')
            ->selectRaw('SUM(kiseki_amount) as kiseki_amount')
            ->selectRaw('COUNT(*) as purchase_count')
            ->selectRaw('COUNT(DISTINCT character_id) as buyer_count')
            ->groupBy('sales_month')
            ->orderByDesc('sales_month')
            ->limit(12)
            ->get()
            ->map(fn ($row): array => [
                'month' => (string) $row->sales_month,
                'revenue_jpy' => (int) $row->revenue_jpy,
                'kiseki_amount' => (int) $row->kiseki_amount,
                'purchase_count' => (int) $row->purchase_count,
                'buyer_count' => (int) $row->buyer_count,
            ])
            ->all();
    }

    private function topLtvPlayers()
    {
        if (!Schema::hasTable('stripe_orders') || !Schema::hasTable('characters')) {
            return collect();
        }

        $summary = DB::table('stripe_orders')
            ->where('status', 'fulfilled')
            ->selectRaw('character_id')
            ->selectRaw('SUM(price_jpy) as ltv_jpy')
            ->selectRaw('SUM(kiseki_amount) as purchased_kiseki')
            ->selectRaw('COUNT(id) as purchase_count')
            ->selectRaw('MAX(COALESCE(fulfilled_at, created_at)) as last_purchase_at')
            ->groupBy('character_id');

        return Character::query()
            ->with('user')
            ->joinSub($summary, 'ltv_summary', function ($join) {
                $join->on('characters.id', '=', 'ltv_summary.character_id');
            })
            ->select('characters.*')
            ->selectRaw('ltv_summary.ltv_jpy')
            ->selectRaw('ltv_summary.purchased_kiseki')
            ->selectRaw('ltv_summary.purchase_count')
            ->selectRaw('ltv_summary.last_purchase_at')
            ->orderByDesc('ltv_jpy')
            ->limit(20)
            ->get();
    }

    private function jobArtSummary(): array
    {
        if (!Schema::hasTable('character_job_art_slots') || !Schema::hasTable('skills')) {
            return [
                'cards' => $this->jobArtCards(0, 0, 0, 0),
                'slot_rates' => [],
                'skill_usage' => collect(),
                'unused_job_arts' => collect(),
            ];
        }

        $totalCharacters = Schema::hasTable('characters') ? (int) Character::count() : 0;
        $setCharacterCount = (int) DB::table('character_job_art_slots')->distinct('character_id')->count('character_id');
        $slotCount = (int) DB::table('character_job_art_slots')->count();
        $jobArtCount = (int) Skill::where('skill_type', 'job_art')->count();

        $slotRates = DB::table('character_job_art_slots')
            ->join('skills', 'character_job_art_slots.skill_id', '=', 'skills.id')
            ->where('skills.skill_type', 'job_art')
            ->selectRaw('character_job_art_slots.slot_no as slot_no')
            ->selectRaw('COUNT(*) as set_count')
            ->selectRaw('COUNT(DISTINCT character_job_art_slots.character_id) as character_count')
            ->groupBy('character_job_art_slots.slot_no')
            ->orderBy('character_job_art_slots.slot_no')
            ->get()
            ->map(fn ($row): array => [
                'slot_no' => (int) $row->slot_no,
                'set_count' => (int) $row->set_count,
                'character_count' => (int) $row->character_count,
                'rate' => $totalCharacters > 0 ? round(((int) $row->character_count / $totalCharacters) * 100, 1) : 0.0,
            ])
            ->all();

        $usage = Skill::query()
            ->leftJoin('character_job_art_slots', 'skills.id', '=', 'character_job_art_slots.skill_id')
            ->leftJoin('job_classes', 'skills.job_id', '=', 'job_classes.id')
            ->where('skills.skill_type', 'job_art')
            ->select('skills.id', 'skills.name', 'skills.learn_rank', 'job_classes.name as job_name')
            ->selectRaw('COUNT(character_job_art_slots.id) as set_count')
            ->selectRaw('COUNT(DISTINCT character_job_art_slots.character_id) as character_count')
            ->groupBy('skills.id', 'skills.name', 'skills.learn_rank', 'job_classes.name')
            ->orderByDesc('set_count')
            ->orderBy('skills.id')
            ->limit(30)
            ->get();

        $unused = Skill::query()
            ->leftJoin('character_job_art_slots', 'skills.id', '=', 'character_job_art_slots.skill_id')
            ->leftJoin('job_classes', 'skills.job_id', '=', 'job_classes.id')
            ->where('skills.skill_type', 'job_art')
            ->select('skills.id', 'skills.name', 'skills.learn_rank', 'job_classes.name as job_name')
            ->selectRaw('COUNT(character_job_art_slots.id) as set_count')
            ->groupBy('skills.id', 'skills.name', 'skills.learn_rank', 'job_classes.name')
            ->havingRaw('COUNT(character_job_art_slots.id) = 0')
            ->orderBy('skills.id')
            ->limit(30)
            ->get();

        return [
            'cards' => $this->jobArtCards($totalCharacters, $setCharacterCount, $slotCount, $jobArtCount),
            'slot_rates' => $slotRates,
            'skill_usage' => $usage,
            'unused_job_arts' => $unused,
        ];
    }

    private function jobArtCards(int $totalCharacters, int $setCharacterCount, int $slotCount, int $jobArtCount): array
    {
        $setRate = $totalCharacters > 0 ? round(($setCharacterCount / $totalCharacters) * 100, 1) : 0.0;

        return [
            ['label' => '奥義セット率', 'value' => $setRate . '%', 'note' => number_format($setCharacterCount) . ' / ' . number_format($totalCharacters) . '人'],
            ['label' => 'セット中スロット', 'value' => number_format($slotCount), 'note' => 'character_job_art_slots'],
            ['label' => '奥義マスタ数', 'value' => number_format($jobArtCount), 'note' => 'skills.skill_type=job_art'],
            ['label' => '平均セット数', 'value' => $setCharacterCount > 0 ? round($slotCount / $setCharacterCount, 2) : '0', 'note' => 'セット済みプレイヤー平均'],
        ];
    }

    private function dateRange(): array
    {
        $from = $this->parseDate($this->dateFrom, now()->subDays(13))->startOfDay();
        $to = $this->parseDate($this->dateTo, now())->endOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            $this->dateFrom = $from->toDateString();
            $this->dateTo = $to->toDateString();
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

    private function dateExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "date({$column})"
            : "DATE({$column})";
    }
}
