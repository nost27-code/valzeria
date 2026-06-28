<?php

namespace App\Services;

use App\Models\MarketListing;
use App\Models\Material;
use App\Models\NpcMaterialStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NpcMarketListingService
{
    private const MAX_ACTIVE_NPC_LISTINGS = 12;
    private const LISTING_HOURS = 24;

    public function generateListings(int $limit = 6): array
    {
        if (! Schema::hasTable('npc_material_stocks') || ! Schema::hasColumn('market_listings', 'seller_type')) {
            return ['generated' => 0, 'reason' => 'npc market schema is not ready'];
        }

        $activeNpcListings = MarketListing::query()
            ->active()
            ->where('seller_type', 'npc')
            ->whereHas('sellerNpc', fn ($query) => $query->marketSellerEligible())
            ->count();

        $slots = min($limit, max(0, self::MAX_ACTIVE_NPC_LISTINGS - $activeNpcListings));
        if ($slots <= 0) {
            return ['generated' => 0, 'reason' => 'npc listing limit reached'];
        }

        $generated = 0;
        $stocks = NpcMaterialStock::query()
            ->where('quantity', '>', 0)
            ->whereHas('material', fn ($query) => $query->marketable())
            ->whereHas('npc', fn ($query) => $query->marketSellerEligible())
            ->with(['material', 'npc'])
            ->inRandomOrder()
            ->limit($slots * 3)
            ->get();

        foreach ($stocks as $stock) {
            if ($generated >= $slots) {
                break;
            }

            $created = DB::transaction(function () use ($stock): bool {
                $locked = NpcMaterialStock::query()
                    ->whereKey($stock->id)
                    ->lockForUpdate()
                    ->with(['material', 'npc'])
                    ->first();

                if (
                    ! $locked
                    || (int) $locked->quantity <= 0
                    || ! $locked->material?->isMarketable()
                    || ! $locked->npc?->isMarketSellerEligible()
                ) {
                    return false;
                }

                $quantity = min((int) $locked->quantity, $this->listingQuantity((int) $locked->quantity));
                $unitPrice = $this->listingPrice($locked->material);

                $locked->quantity = (int) $locked->quantity - $quantity;
                $locked->save();

                MarketListing::create([
                    'seller_character_id' => 0,
                    'seller_type' => 'npc',
                    'seller_npc_id' => $locked->npc_id,
                    'listing_type' => 'material',
                    'material_id' => $locked->material_id,
                    'quantity' => $quantity,
                    'remaining_quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'listing_fee' => 0,
                    'status' => 'active',
                    'expires_at' => now()->addHours(self::LISTING_HOURS),
                ]);

                return true;
            });

            if ($created) {
                $generated++;
            }
        }

        return ['generated' => $generated, 'active_npc_listings' => $activeNpcListings + $generated];
    }

    private function listingQuantity(int $available): int
    {
        if ($available <= 5) {
            return $available;
        }

        return random_int(3, min(20, $available));
    }

    private function listingPrice(Material $material): int
    {
        $min = $material->marketMinPrice();
        $max = $material->marketMaxPrice();
        $base = (int) ($material->npc_sell_price ?? $material->npc_sale_price ?? $min);
        $price = max($min, (int) ceil($base * random_int(115, 160) / 100));

        return min($max, $price);
    }
}
