<?php

namespace App\Livewire\Admin;

use App\Models\Character;
use App\Models\KisekiTransaction;
use App\Models\StripeOrder;
use App\Models\StripePaymentAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\WithPagination;

class KisekiPurchaseManager extends Component
{
    use WithPagination;

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
        ])->layout('components.layouts.admin');
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
