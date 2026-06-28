<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\MarketListing;
use App\Models\MarketTransaction;
use App\Models\Material;
use App\Models\NpcMaterialStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketService
{
    private const SALE_FEE_RATE = 0.03;
    private const LISTING_HOURS = 48;

    public function __construct(
        private readonly GoldService $goldService,
        private readonly CharacterNotificationService $notificationService
    )
    {
    }

    public function listMaterial(Character $seller, Material $material, int $quantity, int $unitPrice): MarketListing
    {
        $quantity = max(1, $quantity);

        return DB::transaction(function () use ($seller, $material, $quantity, $unitPrice) {
            $material = Material::whereKey($material->id)->lockForUpdate()->firstOrFail();
            $this->assertMarketable($material);
            $this->assertPriceInRange($material, $unitPrice);

            $owned = CharacterMaterial::query()
                ->where('character_id', $seller->id)
                ->where('material_id', $material->id)
                ->lockForUpdate()
                ->first();

            if (! $owned || (int) $owned->quantity < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => '出品する素材数が不足しています。',
                ]);
            }

            $remaining = (int) $owned->quantity - $quantity;
            if ($remaining <= 0) {
                $owned->delete();
            } else {
                $owned->forceFill(['quantity' => $remaining])->save();
            }

            return MarketListing::create([
                'seller_character_id' => $seller->id,
                'seller_type' => 'character',
                'seller_npc_id' => null,
                'listing_type' => 'material',
                'material_id' => $material->id,
                'quantity' => $quantity,
                'remaining_quantity' => $quantity,
                'unit_price' => $unitPrice,
                'listing_fee' => 0,
                'status' => 'active',
                'expires_at' => now()->addHours(self::LISTING_HOURS),
            ]);
        });
    }

    public function buyMaterial(Character $buyer, Material $material, int $quantity): array
    {
        $quantity = max(1, $quantity);

        return DB::transaction(function () use ($buyer, $material, $quantity) {
            $buyer = Character::whereKey($buyer->id)->lockForUpdate()->firstOrFail();
            $material = Material::whereKey($material->id)->lockForUpdate()->firstOrFail();
            $this->assertMarketable($material);

            $listings = MarketListing::query()
                ->active()
                ->marketSellerEligible()
                ->where('listing_type', 'material')
                ->where('material_id', $material->id)
                ->where('seller_character_id', '!=', $buyer->id)
                ->orderBy('unit_price')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            $remainingToBuy = $quantity;
            $fills = [];
            $totalPrice = 0;

            foreach ($listings as $listing) {
                if ($remainingToBuy <= 0) {
                    break;
                }

                $fillQuantity = min($remainingToBuy, (int) $listing->remaining_quantity);
                if ($fillQuantity <= 0) {
                    continue;
                }

                $lineTotal = $fillQuantity * (int) $listing->unit_price;
                $fills[] = [$listing, $fillQuantity, $lineTotal];
                $totalPrice += $lineTotal;
                $remainingToBuy -= $fillQuantity;
            }

            if ($remainingToBuy > 0) {
                throw ValidationException::withMessages([
                    'quantity' => '市場在庫が不足しています。',
                ]);
            }

            if ((int) $buyer->money < $totalPrice) {
                throw ValidationException::withMessages([
                    'quantity' => 'Goldが不足しています。',
                ]);
            }

            $buyer->money = (int) $buyer->money - $totalPrice;
            $buyer->save();
            $materialName = $material->displayName();

            $this->goldService->record($buyer, 'market_purchase', -$totalPrice, "{$materialName} x{$quantity} を市場で購入", MarketTransaction::class, null, [
                'material_id' => $material->id,
                'quantity' => $quantity,
            ]);

            $this->addMaterialQuantity($buyer, $material, $quantity);

            $lines = [];
            foreach ($fills as [$listing, $fillQuantity, $lineTotal]) {
                $isNpcListing = $listing->isNpcListing();
                $saleFee = $isNpcListing ? 0 : (int) floor($lineTotal * self::SALE_FEE_RATE);
                $sellerReceived = max(0, $lineTotal - $saleFee);
                $seller = null;

                if (! $isNpcListing) {
                    $seller = Character::whereKey($listing->seller_character_id)->lockForUpdate()->firstOrFail();
                    $seller->money = (int) $seller->money + $sellerReceived;
                    $seller->save();
                }

                $transaction = MarketTransaction::create([
                    'listing_id' => $listing->id,
                    'seller_character_id' => $isNpcListing ? 0 : $seller->id,
                    'seller_type' => $isNpcListing ? 'npc' : 'character',
                    'seller_npc_id' => $isNpcListing ? $listing->seller_npc_id : null,
                    'buyer_character_id' => $buyer->id,
                    'listing_type' => 'material',
                    'material_id' => $material->id,
                    'quantity' => $fillQuantity,
                    'unit_price' => (int) $listing->unit_price,
                    'total_price' => $lineTotal,
                    'sale_fee' => $saleFee,
                    'seller_received' => $sellerReceived,
                    'created_at' => now(),
                ]);

                if ($seller) {
                    $this->goldService->record($seller, 'market_sale', $sellerReceived, "{$materialName} x{$fillQuantity} が市場で売却", MarketTransaction::class, $transaction->id, [
                        'material_id' => $material->id,
                        'quantity' => $fillQuantity,
                        'total_price' => $lineTotal,
                        'sale_fee' => $saleFee,
                    ]);

                    $this->notificationService->create(
                        character: $seller,
                        category: 'market',
                        type: 'market_material_sold',
                        title: '市場で素材が売れました',
                        body: "{$materialName} ×{$fillQuantity} が " . number_format($lineTotal) . "G で売れました。受取 " . number_format($sellerReceived) . 'G。',
                        actionLabel: '履歴を見る',
                        actionUrl: route('market.index', ['tab' => 'history']),
                        payload: [
                            'market_transaction_id' => $transaction->id,
                            'material_id' => $material->id,
                            'quantity' => $fillQuantity,
                            'total_price' => $lineTotal,
                            'sale_fee' => $saleFee,
                            'seller_received' => $sellerReceived,
                        ],
                        priority: 60,
                        expiresAt: now()->addDays(7),
                    );
                }

                $listing->remaining_quantity = max(0, (int) $listing->remaining_quantity - $fillQuantity);
                if ((int) $listing->remaining_quantity <= 0) {
                    $listing->status = 'sold_out';
                }
                $listing->save();

                $lines[] = [
                    'quantity' => $fillQuantity,
                    'unit_price' => (int) $listing->unit_price,
                    'total_price' => $lineTotal,
                ];
            }

            return [
                'material_name' => $materialName,
                'quantity' => $quantity,
                'total_price' => $totalPrice,
                'lines' => $lines,
            ];
        });
    }

    public function cancelListing(Character $seller, MarketListing $listing): array
    {
        return DB::transaction(function () use ($seller, $listing) {
            $listing = MarketListing::whereKey($listing->id)->lockForUpdate()->firstOrFail();
            if ((int) $listing->seller_character_id !== (int) $seller->id) {
                throw ValidationException::withMessages([
                    'listing' => 'この出品はキャンセルできません。',
                ]);
            }

            if ($listing->status !== 'active' || (int) $listing->remaining_quantity <= 0) {
                throw ValidationException::withMessages([
                    'listing' => 'この出品はすでに終了しています。',
                ]);
            }

            $material = Material::whereKey($listing->material_id)->lockForUpdate()->firstOrFail();
            $returnQuantity = (int) $listing->remaining_quantity;

            $listing->status = 'cancelled';
            $listing->remaining_quantity = 0;
            $listing->save();

            $this->addMaterialQuantity($seller, $material, $returnQuantity);

            return [
                'material_name' => $material->displayName(),
                'quantity' => $returnQuantity,
            ];
        });
    }

    public function expireListings(): int
    {
        $expiredCount = 0;

        MarketListing::query()
            ->where('status', 'active')
            ->where('remaining_quantity', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($listings) use (&$expiredCount) {
                foreach ($listings as $listing) {
                    DB::transaction(function () use ($listing, &$expiredCount) {
                        $locked = MarketListing::whereKey($listing->id)->lockForUpdate()->first();
                        if (! $locked || $locked->status !== 'active' || (int) $locked->remaining_quantity <= 0 || $locked->expires_at > now()) {
                            return;
                        }

                        $material = Material::whereKey($locked->material_id)->lockForUpdate()->first();
                        if ($material) {
                            if ($locked->isNpcListing()) {
                                $this->addNpcMaterialQuantity((int) $locked->seller_npc_id, $material, (int) $locked->remaining_quantity);
                            } else {
                                $seller = Character::whereKey($locked->seller_character_id)->lockForUpdate()->first();
                                if ($seller) {
                                    $this->addMaterialQuantity($seller, $material, (int) $locked->remaining_quantity);
                                }
                            }
                        }

                        $locked->status = 'expired';
                        $locked->remaining_quantity = 0;
                        $locked->save();
                        $expiredCount++;
                    });
                }
            });

        return $expiredCount;
    }

    private function addMaterialQuantity(Character $character, Material $material, int $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        $row = CharacterMaterial::query()
            ->where('character_id', $character->id)
            ->where('material_id', $material->id)
            ->lockForUpdate()
            ->first();

        if ($row) {
            $row->quantity = (int) $row->quantity + $quantity;
            $row->save();
            return;
        }

        CharacterMaterial::create([
            'character_id' => $character->id,
            'material_id' => $material->id,
            'quantity' => $quantity,
        ]);
    }

    private function addNpcMaterialQuantity(int $npcId, Material $material, int $quantity): void
    {
        if ($npcId <= 0 || $quantity <= 0) {
            return;
        }

        $row = NpcMaterialStock::query()
            ->where('npc_id', $npcId)
            ->where('material_id', $material->id)
            ->lockForUpdate()
            ->first();

        if ($row) {
            $row->quantity = (int) $row->quantity + $quantity;
            $row->save();
            return;
        }

        NpcMaterialStock::create([
            'npc_id' => $npcId,
            'material_id' => $material->id,
            'quantity' => $quantity,
            'last_received_at' => now(),
        ]);
    }

    private function assertMarketable(Material $material): void
    {
        if (! $material->isMarketable()) {
            throw ValidationException::withMessages([
                'material_id' => 'この素材は市場で取引できません。',
            ]);
        }
    }

    private function assertPriceInRange(Material $material, int $unitPrice): void
    {
        $min = $material->marketMinPrice();
        $max = $material->marketMaxPrice();

        if ($unitPrice < $min || $unitPrice > $max) {
            throw ValidationException::withMessages([
                'unit_price' => "単価は {$min}G 〜 {$max}G の範囲で指定してください。",
            ]);
        }
    }
}
