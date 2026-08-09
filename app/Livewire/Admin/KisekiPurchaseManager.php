<?php

namespace App\Livewire\Admin;

use App\Models\Character;
use App\Models\KisekiTransaction;
use App\Models\StripeOrder;
use App\Models\StripePaymentAudit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\WithPagination;

class KisekiPurchaseManager extends Component
{
    use WithPagination;

    private const REVENUE_DAYS = 30;

    private const EXCLUDED_REVENUE_PACK_KEYS = ['kiseki_test'];

    public function boot(): void
    {
        abort_unless(Auth::check() && Auth::user()->role === 'admin', 403);
    }

    public string $searchQuery = '';
    public string $displayMode = 'all';
    public ?int $selectedCharacterId = null;

    public function updatingSearchQuery(): void
    {
        $this->resetPage();
    }

    public function updatingDisplayMode(): void
    {
        $this->resetPage();
    }

    public function selectCharacter(int $characterId): void
    {
        $this->selectedCharacterId = $characterId;
    }

    public function render()
    {
        $hasAuditTable = Schema::hasTable('stripe_payment_audits');
        $hasKisekiTransactions = Schema::hasTable('kiseki_transactions');

        $purchaseSummary = StripeOrder::query()
            ->selectRaw('character_id, COUNT(*) as purchase_count, SUM(kiseki_amount) as purchased_kiseki, SUM(price_jpy) as purchased_jpy, MAX(fulfilled_at) as last_purchase_at')
            ->where('status', 'fulfilled')
            ->groupBy('character_id');

        $query = Character::query()
            ->with('user')
            ->leftJoinSub($purchaseSummary, 'purchase_summary', function ($join) {
                $join->on('characters.id', '=', 'purchase_summary.character_id');
            })
            ->select('characters.*')
            ->selectRaw('COALESCE(purchase_summary.purchase_count, 0) as purchase_count')
            ->selectRaw('COALESCE(purchase_summary.purchased_kiseki, 0) as purchased_kiseki')
            ->selectRaw('COALESCE(purchase_summary.purchased_jpy, 0) as purchased_jpy')
            ->selectRaw('purchase_summary.last_purchase_at as last_purchase_at');

        if ($this->displayMode === 'purchased') {
            $query->whereRaw('COALESCE(purchase_summary.purchase_count, 0) > 0');
        }

        if ($this->searchQuery !== '') {
            $search = '%' . $this->searchQuery . '%';
            $query->where(function ($q) use ($search) {
                $q->where('characters.name', 'like', $search)
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('email', 'like', $search);
                    });
            });
        }

        $characters = $query
            ->orderByDesc('purchased_jpy')
            ->orderByDesc('purchase_count')
            ->orderByDesc('characters.updated_at')
            ->paginate(30);

        if ($this->selectedCharacterId === null && $characters->isNotEmpty()) {
            $firstPurchased = $characters->first(fn (Character $character): bool => (int) $character->purchase_count > 0);
            $this->selectedCharacterId = (int) ($firstPurchased?->id ?? $characters->first()->id);
        }

        $selectedCharacter = $this->selectedCharacterId
            ? Character::with('user')->find($this->selectedCharacterId)
            : null;

        $selectedOrders = $selectedCharacter
            ? StripeOrder::where('character_id', $selectedCharacter->id)
                ->orderByDesc(DB::raw('COALESCE(fulfilled_at, created_at)'))
                ->orderByDesc('id')
                ->get()
            : collect();

        $latestOrdersQuery = StripeOrder::query()
            ->with('character.user')
            ->orderByDesc(DB::raw('COALESCE(fulfilled_at, created_at)'))
            ->orderByDesc('id');

        if ($this->displayMode === 'purchased') {
            $latestOrdersQuery->where('status', 'fulfilled');
        }

        if ($this->searchQuery !== '') {
            $search = '%' . $this->searchQuery . '%';
            $latestOrdersQuery->whereHas('character', function ($characterQuery) use ($search) {
                $characterQuery->where('name', 'like', $search)
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('email', 'like', $search);
                    });
            });
        }

        $latestOrders = $latestOrdersQuery->limit(30)->get();

        $latestAudits = $hasAuditTable
            ? $this->auditQuery()->limit(50)->get()
            : collect();

        $refundCancelAudits = $hasAuditTable
            ? $this->auditQuery()
                ->whereIn('status', ['refunded', 'canceled'])
                ->limit(30)
                ->get()
            : collect();

        $manualGrantQuery = $hasKisekiTransactions
            ? KisekiTransaction::query()
                ->where(function ($query) {
                    $query->whereIn('transaction_type', ['manual', 'manual_grant', 'admin_grant', 'adjustment'])
                        ->orWhereIn('source_type', ['manual', 'admin', 'admin_grant']);
                })
            : null;

        $manualGrantCount = $manualGrantQuery ? (int) (clone $manualGrantQuery)->count() : 0;

        $manualGrantLogs = $manualGrantQuery
            ? $manualGrantQuery
                ->with('character.user')
                ->orderByDesc('created_at')
                ->limit(30)
                ->get()
            : collect();

        $dailyRevenueSummary = $this->dailyRevenueSummary();

        $totals = [
            'purchase_count' => (int) StripeOrder::where('status', 'fulfilled')->count(),
            'purchased_kiseki' => (int) StripeOrder::where('status', 'fulfilled')->sum('kiseki_amount'),
            'purchased_jpy' => (int) StripeOrder::where('status', 'fulfilled')->sum('price_jpy'),
            'buyer_count' => (int) StripeOrder::where('status', 'fulfilled')->distinct('character_id')->count('character_id'),
        ];

        $auditTotals = [
            'received' => $hasAuditTable ? (int) StripePaymentAudit::where('status', 'received')->count() : 0,
            'fulfilled' => $hasAuditTable ? (int) StripePaymentAudit::where('status', 'fulfilled')->count() : 0,
            'failed' => $hasAuditTable ? (int) StripePaymentAudit::where('status', 'failed')->count() : 0,
            'duplicate' => $hasAuditTable ? (int) StripePaymentAudit::where('status', 'duplicate')->count() : 0,
            'refunded' => $hasAuditTable ? (int) StripePaymentAudit::where('status', 'refunded')->count() : 0,
            'canceled' => $hasAuditTable ? (int) StripePaymentAudit::where('status', 'canceled')->count() : 0,
            'manual_grants' => $manualGrantCount,
            'unmatched_orders' => $hasKisekiTransactions
                ? (int) StripeOrder::where('status', 'fulfilled')
                    ->whereNotExists(function ($query) {
                        $query->selectRaw('1')
                            ->from('kiseki_transactions')
                            ->whereColumn('kiseki_transactions.source_id', 'stripe_orders.id')
                            ->where('kiseki_transactions.source_type', 'stripe_order')
                            ->where('kiseki_transactions.transaction_type', 'purchase');
                    })
                    ->count()
                : 0,
        ];

        return view('livewire.admin.kiseki-purchase-manager', [
            'characters' => $characters,
            'selectedCharacter' => $selectedCharacter,
            'selectedCharacterId' => $this->selectedCharacterId,
            'selectedOrders' => $selectedOrders,
            'latestOrders' => $latestOrders,
            'displayMode' => $this->displayMode,
            'totals' => $totals,
            'auditTotals' => $auditTotals,
            'latestAudits' => $latestAudits,
            'refundCancelAudits' => $refundCancelAudits,
            'manualGrantLogs' => $manualGrantLogs,
            'hasAuditTable' => $hasAuditTable,
            'packs' => config('kiseki.packs', []),
            'dailyRevenueSummary' => $dailyRevenueSummary,
        ])->layout('components.layouts.admin');
    }

    private function dailyRevenueSummary(): array
    {
        $today = now()->startOfDay();
        $periodStart = $today->copy()->subDays(self::REVENUE_DAYS - 1);
        $periodEnd = $today->copy()->endOfDay();
        $dateColumn = 'COALESCE(fulfilled_at, created_at)';
        $dateExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "date({$dateColumn})"
            : "DATE({$dateColumn})";

        $rows = StripeOrder::query()
            ->where('status', 'fulfilled')
            ->whereNotIn('pack_key', self::EXCLUDED_REVENUE_PACK_KEYS)
            ->whereBetween(DB::raw($dateColumn), [$periodStart, $periodEnd])
            ->selectRaw("{$dateExpression} as sales_date")
            ->selectRaw('SUM(price_jpy) as revenue_jpy')
            ->selectRaw('SUM(kiseki_amount) as kiseki_amount')
            ->selectRaw('COUNT(*) as purchase_count')
            ->selectRaw('COUNT(DISTINCT character_id) as buyer_count')
            ->groupBy('sales_date')
            ->get()
            ->keyBy('sales_date');

        $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
        $days = [];

        for ($day = $periodStart->copy(); $day->lte($today); $day->addDay()) {
            $date = $day->toDateString();
            $row = $rows->get($date);

            $days[] = [
                'date' => $date,
                'label' => $day->format('n/j') . '（' . $weekdays[$day->dayOfWeek] . '）',
                'is_today' => $day->isSameDay($today),
                'revenue_jpy' => (int) ($row->revenue_jpy ?? 0),
                'kiseki_amount' => (int) ($row->kiseki_amount ?? 0),
                'purchase_count' => (int) ($row->purchase_count ?? 0),
                'buyer_count' => (int) ($row->buyer_count ?? 0),
            ];
        }

        $maxDailyRevenue = max(array_column($days, 'revenue_jpy'));
        $days = array_map(function (array $day) use ($maxDailyRevenue): array {
            $day['bar_percent'] = $day['revenue_jpy'] > 0 && $maxDailyRevenue > 0
                ? max(4, (int) round(($day['revenue_jpy'] / $maxDailyRevenue) * 100))
                : 0;

            return $day;
        }, $days);

        $latestFirst = array_reverse($days);

        return [
            'period_days' => self::REVENUE_DAYS,
            'today' => $latestFirst[0],
            'yesterday' => $latestFirst[1],
            'last_7_days' => $this->sumRevenueDays(array_slice($latestFirst, 0, 7)),
            'last_30_days' => $this->sumRevenueDays($latestFirst),
            'daily' => $latestFirst,
        ];
    }

    private function sumRevenueDays(array $days): array
    {
        return [
            'revenue_jpy' => array_sum(array_column($days, 'revenue_jpy')),
            'kiseki_amount' => array_sum(array_column($days, 'kiseki_amount')),
            'purchase_count' => array_sum(array_column($days, 'purchase_count')),
        ];
    }

    private function auditQuery()
    {
        $query = StripePaymentAudit::query()
            ->with(['character.user', 'user', 'order'])
            ->orderByDesc(DB::raw('COALESCE(webhook_received_at, created_at)'))
            ->orderByDesc('id');

        if ($this->searchQuery !== '') {
            $search = '%' . $this->searchQuery . '%';
            $query->where(function ($auditQuery) use ($search) {
                $auditQuery->where('stripe_session_id', 'like', $search)
                    ->orWhere('stripe_payment_intent_id', 'like', $search)
                    ->orWhere('stripe_charge_id', 'like', $search)
                    ->orWhere('stripe_event_id', 'like', $search)
                    ->orWhere('pack_key', 'like', $search)
                    ->orWhere('product_name', 'like', $search)
                    ->orWhereHas('character', function ($characterQuery) use ($search) {
                        $characterQuery->where('name', 'like', $search)
                            ->orWhereHas('user', function ($userQuery) use ($search) {
                                $userQuery->where('email', 'like', $search);
                            });
                    })
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('email', 'like', $search);
                    });
            });
        }

        return $query;
    }
}
