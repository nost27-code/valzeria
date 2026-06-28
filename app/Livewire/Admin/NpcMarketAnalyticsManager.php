<?php

namespace App\Livewire\Admin;

use App\Models\MarketListing;
use App\Models\MarketTransaction;
use App\Models\Material;
use App\Models\NpcMaster;
use App\Models\NpcMaterialStock;
use App\Models\NpcProcurementRequest;
use App\Models\NpcProcurementRequestMaterial;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class NpcMarketAnalyticsManager extends Component
{
    public string $rank = 'all';

    public function setRank(string $rank): void
    {
        $this->rank = in_array($rank, ['all', 'common', 'skilled', 'hero', 'legend'], true) ? $rank : 'all';
    }

    public function render()
    {
        $data = $this->analyticsData();

        return view('livewire.admin.npc-market-analytics-manager', $data)
            ->layout('components.layouts.admin');
    }

    private function analyticsData(): array
    {
        if (! $this->hasRequiredTables()) {
            return [
                'summaryCards' => [],
                'npcRows' => collect(),
                'materialRows' => collect(),
                'recentSales' => collect(),
                'missingTables' => true,
            ];
        }

        $npcs = NpcMaster::query()
            ->when($this->rank !== 'all', fn ($query) => $query->where('npc_rank', $this->rank))
            ->orderBy('sort_order')
            ->orderBy('npc_id')
            ->get()
            ->keyBy('npc_id');

        $materials = Material::query()->get()->keyBy('id');
        $delivered = $this->deliveredRows();
        $stock = $this->stockRows();
        $activeListings = $this->activeListingRows();
        $sold = $this->soldRows();
        $requests = $this->requestRows();

        $npcIds = collect()
            ->merge($npcs->keys())
            ->merge($delivered->keys())
            ->merge($stock->keys())
            ->merge($activeListings->keys())
            ->merge($sold->keys())
            ->merge($requests->keys())
            ->unique()
            ->filter(fn ($npcId) => $npcs->has((int) $npcId))
            ->values();

        $npcRows = $npcIds
            ->map(function ($npcId) use ($npcs, $delivered, $stock, $activeListings, $sold, $requests) {
                $npc = $npcs[(int) $npcId];
                $deliveredQuantity = (int) ($delivered[$npcId]['quantity'] ?? 0);
                $stockQuantity = (int) ($stock[$npcId]['quantity'] ?? 0);
                $activeQuantity = (int) ($activeListings[$npcId]['remaining_quantity'] ?? 0);
                $soldQuantity = (int) ($sold[$npcId]['quantity'] ?? 0);
                $soldRevenue = (int) ($sold[$npcId]['revenue'] ?? 0);

                return [
                    'npc' => $npc,
                    'delivered_quantity' => $deliveredQuantity,
                    'stock_quantity' => $stockQuantity,
                    'active_listing_quantity' => $activeQuantity,
                    'active_listing_count' => (int) ($activeListings[$npcId]['listing_count'] ?? 0),
                    'sold_quantity' => $soldQuantity,
                    'sold_revenue' => $soldRevenue,
                    'avg_price' => $soldQuantity > 0 ? (int) floor($soldRevenue / $soldQuantity) : 0,
                    'request_count' => (int) ($requests[$npcId]['total'] ?? 0),
                    'active_request_count' => (int) ($requests[$npcId]['active'] ?? 0),
                    'completed_request_count' => (int) ($requests[$npcId]['completed'] ?? 0),
                    'latest_sale_at' => $sold[$npcId]['latest_sale_at'] ?? null,
                ];
            })
            ->filter(fn (array $row) => $this->rowHasActivity($row))
            ->sortByDesc(fn (array $row) => $row['delivered_quantity'] + $row['sold_quantity'] + $row['active_listing_quantity'] + $row['stock_quantity'])
            ->values();

        $materialRows = $this->materialRows($npcs, $materials)
            ->filter(fn (array $row) => $this->rank === 'all' || (string) $row['npc']->npc_rank === $this->rank)
            ->take(80)
            ->values();

        return [
            'summaryCards' => $this->summaryCards($npcRows),
            'npcRows' => $npcRows,
            'materialRows' => $materialRows,
            'recentSales' => $this->recentSales(),
            'missingTables' => false,
        ];
    }

    private function hasRequiredTables(): bool
    {
        return Schema::hasTable('npc_master')
            && Schema::hasTable('npc_procurement_requests')
            && Schema::hasTable('npc_procurement_request_materials')
            && Schema::hasTable('npc_material_stocks')
            && Schema::hasTable('market_listings')
            && Schema::hasTable('market_transactions');
    }

    private function deliveredRows(): Collection
    {
        return NpcProcurementRequestMaterial::query()
            ->join('npc_procurement_requests', 'npc_procurement_requests.id', '=', 'npc_procurement_request_materials.npc_procurement_request_id')
            ->whereNotNull('npc_procurement_requests.npc_id')
            ->selectRaw('npc_procurement_requests.npc_id as npc_id, SUM(npc_procurement_request_materials.delivered_quantity) as quantity')
            ->groupBy('npc_procurement_requests.npc_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->npc_id => ['quantity' => (int) $row->quantity]]);
    }

    private function stockRows(): Collection
    {
        return NpcMaterialStock::query()
            ->selectRaw('npc_id, SUM(quantity) as quantity')
            ->groupBy('npc_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->npc_id => ['quantity' => (int) $row->quantity]]);
    }

    private function activeListingRows(): Collection
    {
        return MarketListing::query()
            ->active()
            ->where('seller_type', 'npc')
            ->selectRaw('seller_npc_id as npc_id, SUM(remaining_quantity) as remaining_quantity, COUNT(*) as listing_count')
            ->groupBy('seller_npc_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->npc_id => [
                'remaining_quantity' => (int) $row->remaining_quantity,
                'listing_count' => (int) $row->listing_count,
            ]]);
    }

    private function soldRows(): Collection
    {
        return MarketTransaction::query()
            ->where('seller_type', 'npc')
            ->selectRaw('seller_npc_id as npc_id, SUM(quantity) as quantity, SUM(total_price) as revenue, MAX(created_at) as latest_sale_at')
            ->groupBy('seller_npc_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->npc_id => [
                'quantity' => (int) $row->quantity,
                'revenue' => (int) $row->revenue,
                'latest_sale_at' => $row->latest_sale_at,
            ]]);
    }

    private function requestRows(): Collection
    {
        return NpcProcurementRequest::query()
            ->whereNotNull('npc_id')
            ->selectRaw("npc_id, COUNT(*) as total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->groupBy('npc_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->npc_id => [
                'total' => (int) $row->total,
                'active' => (int) $row->active,
                'completed' => (int) $row->completed,
            ]]);
    }

    private function materialRows(Collection $npcs, Collection $materials): Collection
    {
        $delivered = NpcProcurementRequestMaterial::query()
            ->join('npc_procurement_requests', 'npc_procurement_requests.id', '=', 'npc_procurement_request_materials.npc_procurement_request_id')
            ->whereNotNull('npc_procurement_requests.npc_id')
            ->selectRaw('npc_procurement_requests.npc_id as npc_id, npc_procurement_request_materials.material_id, SUM(npc_procurement_request_materials.delivered_quantity) as delivered_quantity')
            ->groupBy('npc_procurement_requests.npc_id', 'npc_procurement_request_materials.material_id')
            ->get();

        $stock = NpcMaterialStock::query()
            ->selectRaw('npc_id, material_id, SUM(quantity) as stock_quantity')
            ->groupBy('npc_id', 'material_id')
            ->get();

        $activeListings = MarketListing::query()
            ->active()
            ->where('seller_type', 'npc')
            ->selectRaw('seller_npc_id as npc_id, material_id, SUM(remaining_quantity) as active_listing_quantity')
            ->groupBy('seller_npc_id', 'material_id')
            ->get();

        $sold = MarketTransaction::query()
            ->where('seller_type', 'npc')
            ->selectRaw('seller_npc_id as npc_id, material_id, SUM(quantity) as sold_quantity, SUM(total_price) as sold_revenue')
            ->groupBy('seller_npc_id', 'material_id')
            ->get();

        $rows = collect();
        foreach ($delivered as $row) {
            $rows->put($this->materialKey((int) $row->npc_id, (int) $row->material_id), [
                'npc_id' => (int) $row->npc_id,
                'material_id' => (int) $row->material_id,
                'delivered_quantity' => (int) $row->delivered_quantity,
                'stock_quantity' => 0,
                'active_listing_quantity' => 0,
                'sold_quantity' => 0,
                'sold_revenue' => 0,
            ]);
        }

        foreach (['stock_quantity' => $stock, 'active_listing_quantity' => $activeListings] as $field => $source) {
            foreach ($source as $row) {
                $key = $this->materialKey((int) $row->npc_id, (int) $row->material_id);
                $data = $rows->get($key, [
                    'npc_id' => (int) $row->npc_id,
                    'material_id' => (int) $row->material_id,
                    'delivered_quantity' => 0,
                    'stock_quantity' => 0,
                    'active_listing_quantity' => 0,
                    'sold_quantity' => 0,
                    'sold_revenue' => 0,
                ]);
                $data[$field] = (int) $row->{$field};
                $rows->put($key, $data);
            }
        }

        foreach ($sold as $row) {
            $key = $this->materialKey((int) $row->npc_id, (int) $row->material_id);
            $data = $rows->get($key, [
                'npc_id' => (int) $row->npc_id,
                'material_id' => (int) $row->material_id,
                'delivered_quantity' => 0,
                'stock_quantity' => 0,
                'active_listing_quantity' => 0,
                'sold_quantity' => 0,
                'sold_revenue' => 0,
            ]);
            $data['sold_quantity'] = (int) $row->sold_quantity;
            $data['sold_revenue'] = (int) $row->sold_revenue;
            $rows->put($key, $data);
        }

        return $rows
            ->map(function (array $row) use ($npcs, $materials) {
                return [
                    ...$row,
                    'npc' => $npcs[(int) $row['npc_id']] ?? null,
                    'material' => $materials[(int) $row['material_id']] ?? null,
                ];
            })
            ->filter(fn (array $row) => $row['npc'] && $row['material'])
            ->sortByDesc(fn (array $row) => $row['delivered_quantity'] + $row['sold_quantity'] + $row['active_listing_quantity'] + $row['stock_quantity'])
            ->values();
    }

    private function recentSales(): Collection
    {
        return MarketTransaction::query()
            ->where('seller_type', 'npc')
            ->with(['sellerNpc', 'material', 'buyer'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    private function summaryCards(Collection $npcRows): array
    {
        return [
            ['label' => '納品された素材', 'value' => number_format((int) $npcRows->sum('delivered_quantity')), 'unit' => '個'],
            ['label' => 'NPC在庫', 'value' => number_format((int) $npcRows->sum('stock_quantity')), 'unit' => '個'],
            ['label' => '出品中', 'value' => number_format((int) $npcRows->sum('active_listing_quantity')), 'unit' => '個'],
            ['label' => '販売済み', 'value' => number_format((int) $npcRows->sum('sold_quantity')), 'unit' => '個'],
            ['label' => '販売額', 'value' => number_format((int) $npcRows->sum('sold_revenue')), 'unit' => 'G'],
        ];
    }

    private function materialKey(int $npcId, int $materialId): string
    {
        return "{$npcId}:{$materialId}";
    }

    private function rowHasActivity(array $row): bool
    {
        return ($row['delivered_quantity'] + $row['stock_quantity'] + $row['active_listing_quantity'] + $row['sold_quantity'] + $row['request_count']) > 0;
    }
}
