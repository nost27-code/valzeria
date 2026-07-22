<?php

namespace App\Services;

use App\Models\Character;
use App\Models\PlayerValmonEgg;
use App\Models\ShopEggListing;
use App\Models\ShopEggTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ShopEggListingService
{
    public function __construct(
        private readonly PlayerShopService $shopService,
        private readonly GoldService $goldService,
        private readonly CharacterNotificationService $notificationService,
    ) {}

    public function list(Character $seller, PlayerValmonEgg $egg, int $price, int $hours = 48): ShopEggListing
    {
        return DB::transaction(function () use ($seller, $egg, $price, $hours) {
            $seller = Character::query()->lockForUpdate()->findOrFail($seller->id);
            $shop = $this->shopService->assertCanList($seller);
            $egg = PlayerValmonEgg::query()->with('master')->lockForUpdate()->findOrFail($egg->id);
            if ((int) $egg->character_id !== (int) $seller->id || $egg->is_hatched || $egg->is_lost || ! $egg->stored_at) {
                throw ValidationException::withMessages(['egg' => 'このヴァルモンの卵は出品できません。']);
            }
            if (ShopEggListing::query()->where('player_valmon_egg_id', $egg->id)->where('status', 'active')->where('expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['egg' => 'このヴァルモンの卵はすでに出品中です。']);
            }
            if ($price < 1) throw ValidationException::withMessages(['listing_price' => '販売価格は1G以上で指定してください。']);

            $listing = ShopEggListing::create([
                'shop_id' => $shop->id,
                'seller_character_id' => $seller->id,
                'player_valmon_egg_id' => $egg->id,
                'valmon_master_id' => $egg->valmon_master_id,
                'display_name_snapshot' => ($egg->master?->name ?? '不明な') . 'の卵',
                'listing_price' => $price,
                'status' => 'active',
                'expires_at' => now()->addHours(in_array($hours, [12, 24, 48], true) ? $hours : 48),
            ]);
            $shop->update(['last_stocked_at' => now()]);
            return $listing;
        });
    }

    public function buy(Character $buyer, ShopEggListing $listing): void
    {
        DB::transaction(function () use ($buyer, $listing) {
            if (! $this->shopService->isEnabled()) {
                throw new RuntimeException('個人商店は現在準備中です。');
            }

            $listing = ShopEggListing::query()->with('shop')->lockForUpdate()->findOrFail($listing->id);
            if ($listing->status !== 'active' || $listing->expires_at->isPast() || ! $listing->shop?->isOpen()) {
                throw new RuntimeException('この卵は現在購入できません。');
            }
            if ((int) $listing->seller_character_id === (int) $buyer->id) throw new RuntimeException('自分の出品は購入できません。');
            $ids = [(int) $buyer->id, (int) $listing->seller_character_id]; sort($ids);
            $characters = Character::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $buyer = $characters->get((int) $buyer->id) ?? throw new RuntimeException('購入者が見つかりません。');
            $seller = $characters->get((int) $listing->seller_character_id) ?? throw new RuntimeException('出品者が見つかりません。');
            $egg = PlayerValmonEgg::query()->lockForUpdate()->findOrFail($listing->player_valmon_egg_id);
            if ((int) $egg->character_id !== (int) $seller->id || ! $egg->stored_at || $egg->is_hatched || $egg->is_lost) throw new RuntimeException('出品された卵の状態が変更されています。');
            if ((int) $buyer->money < (int) $listing->listing_price) throw new RuntimeException('Goldが不足しています。');

            $price = (int) $listing->listing_price;
            $this->goldService->spend($buyer, $price, 'shop_egg_purchase', '商店でヴァルモンの卵を購入', ShopEggListing::class, $listing->id);
            $this->goldService->add($seller, $price, 'shop_egg_sale', '商店でヴァルモンの卵を販売', ShopEggListing::class, $listing->id);
            $egg->update(['character_id' => $buyer->id]);
            $listing->update(['buyer_character_id' => $buyer->id, 'status' => 'sold', 'sold_at' => now()]);
            ShopEggTransaction::create(['listing_id' => $listing->id, 'shop_id' => $listing->shop_id, 'seller_character_id' => $seller->id, 'buyer_character_id' => $buyer->id, 'player_valmon_egg_id' => $egg->id, 'sale_price' => $price, 'sold_at' => now()]);
            $this->notificationService->create($seller, 'market', 'shop_egg_sold', '【商店】ヴァルモンの卵が売れました', "{$listing->display_name_snapshot}\n販売価格：" . number_format($price) . 'G', '商店を見る', route('shops.show', $listing->shop_id), ['shop_egg_listing_id' => $listing->id], 70, now()->addDays(7));
        });
    }

    public function cancel(Character $seller, ShopEggListing $listing): void
    {
        DB::transaction(function () use ($seller, $listing) {
            $listing = ShopEggListing::query()->lockForUpdate()->findOrFail($listing->id);
            if ($listing->status !== 'active' || (int) $listing->seller_character_id !== (int) $seller->id) throw new RuntimeException('この出品は取り消せません。');
            $listing->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        });
    }

    public function expireListings(): int
    {
        return ShopEggListing::query()->where('status', 'active')->where('expires_at', '<=', now())->update(['status' => 'expired', 'updated_at' => now()]);
    }
}
