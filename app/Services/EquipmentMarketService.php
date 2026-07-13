<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\EquipmentMarketListing;
use App\Models\EquipmentMarketTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EquipmentMarketService
{
    public function __construct(
        private readonly EquipmentMarketAppraisalService $appraisalService,
        private readonly GoldService $goldService,
        private readonly StorageCapacityService $storageCapacityService,
        private readonly CharacterNotificationService $notificationService,
    ) {}

    public function listWeapon(Character $seller, CharacterItem $characterItem, int $listingPrice): EquipmentMarketListing
    {
        return DB::transaction(function () use ($seller, $characterItem, $listingPrice) {
            $seller = Character::query()->lockForUpdate()->findOrFail($seller->id);
            $item = CharacterItem::query()->with(['item', 'affixPrefix', 'affixSuffix'])->lockForUpdate()->findOrFail($characterItem->id);
            $this->assertOwnedBy($item, $seller);
            $this->assertMarketListable($item);
            $this->assertListingLimit($seller);
            $appraisal = $this->appraisalService->appraisal($item);
            if ($listingPrice < $appraisal['minimum_price'] || $listingPrice > $appraisal['maximum_price']) {
                throw ValidationException::withMessages(['listing_price' => '出品可能価格の範囲外です。']);
            }

            $listing = EquipmentMarketListing::create([
                'seller_character_id' => $seller->id,
                'character_item_id' => $item->id,
                'item_id' => $item->item_id,
                'display_name_snapshot' => $item->displayName(),
                'item_name_snapshot' => (string) $item->item->name,
                'item_snapshot' => $this->makeSnapshot($item),
                'weapon_category' => $item->item->weapon_category,
                'weapon_rank' => strtoupper((string) $item->item->weapon_rank),
                'quality_key' => (string) ($item->affix_quality ?: 'normal'),
                'enhance_level' => (int) $item->enhance_level,
                'engraving_id' => $item->affix_prefix_id,
                'engraving_level' => $item->effectiveAffixPrefixLevel(),
                'slayer_type_id' => $item->affix_suffix_id,
                'slayer_level' => $item->effectiveAffixSuffixLevel(),
                'appraisal_price' => $appraisal['appraisal_price'],
                'minimum_price' => $appraisal['minimum_price'],
                'maximum_price' => $appraisal['maximum_price'],
                'listing_price' => $listingPrice,
                'fee_rate_bps' => (int) config('equipment_market.fee_rate_bps'),
                'status' => 'active',
                'expires_at' => now()->addHours((int) config('equipment_market.listing_hours')),
            ]);

            $item->update(['market_listing_id' => $listing->id]);
            return $listing;
        });
    }

    public function buyWeapon(Character $buyer, EquipmentMarketListing $listing): EquipmentMarketTransaction
    {
        return DB::transaction(function () use ($buyer, $listing) {
            $listing = EquipmentMarketListing::query()->lockForUpdate()->findOrFail($listing->id);
            if ($listing->status !== 'active') throw new RuntimeException('この出品は購入できません。');
            if ($listing->expires_at->isPast()) {
                $this->expireLockedListing($listing);
                throw new RuntimeException('この出品は期限切れです。');
            }
            if ((int) $listing->seller_character_id === (int) $buyer->id) throw new RuntimeException('自分の出品は購入できません。');

            $item = CharacterItem::query()->with('item')->lockForUpdate()->findOrFail($listing->character_item_id);
            $characterIds = [(int) $buyer->id, (int) $listing->seller_character_id];
            sort($characterIds);
            $characters = Character::query()->whereIn('id', $characterIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $buyer = $characters->get((int) $buyer->id) ?? throw new RuntimeException('購入者が見つかりません。');
            $seller = $characters->get((int) $listing->seller_character_id) ?? throw new RuntimeException('出品者が見つかりません。');
            $this->assertListingItemValid($listing, $item, $seller);
            $this->storageCapacityService->assertCanReceiveEquipment($buyer, 1);
            if ((int) $buyer->money < (int) $listing->listing_price) throw new RuntimeException('Goldが不足しています。');

            $salePrice = (int) $listing->listing_price;
            $fee = $this->appraisalService->fee($salePrice, (int) $listing->fee_rate_bps);
            $proceeds = $salePrice - $fee;
            $this->goldService->spend($buyer, $salePrice, 'equipment_market_purchase', '装備市場で武器を購入', EquipmentMarketListing::class, $listing->id, ['character_item_id' => $item->id]);
            $this->goldService->add($seller, $proceeds, 'equipment_market_sale', '装備市場で武器を売却', EquipmentMarketListing::class, $listing->id, ['gross_price' => $salePrice, 'fee_amount' => $fee, 'net_proceeds' => $proceeds]);

            $item->update([
                'character_id' => $buyer->id, 'is_equipped' => false, 'equipped_slot' => null,
                'is_locked' => false, 'is_stored' => false, 'market_listing_id' => null,
                'market_relistable_at' => now()->addHours((int) config('equipment_market.listing_hours')),
            ]);
            $listing->update(['buyer_character_id' => $buyer->id, 'status' => 'sold', 'fee_amount' => $fee, 'seller_proceeds' => $proceeds, 'sold_at' => now()]);
            $transaction = EquipmentMarketTransaction::create([
                'listing_id' => $listing->id, 'seller_character_id' => $seller->id, 'buyer_character_id' => $buyer->id,
                'character_item_id' => $item->id, 'item_id' => $item->item_id, 'item_snapshot' => $listing->item_snapshot,
                'sale_price' => $salePrice, 'fee_rate_bps' => $listing->fee_rate_bps, 'fee_amount' => $fee,
                'seller_proceeds' => $proceeds, 'sold_at' => now(),
            ]);
            $this->notifySale($seller, $listing, $salePrice, $fee, $proceeds);
            $this->notifyPurchase($buyer, $listing, $salePrice);
            return $transaction;
        });
    }

    public function cancelListing(Character $seller, EquipmentMarketListing $listing): void
    {
        DB::transaction(function () use ($seller, $listing) {
            $listing = EquipmentMarketListing::query()->lockForUpdate()->findOrFail($listing->id);
            if ($listing->status !== 'active' || (int) $listing->seller_character_id !== (int) $seller->id) throw new RuntimeException('この出品は取り消せません。');
            $item = CharacterItem::query()->lockForUpdate()->findOrFail($listing->character_item_id);
            $item->update(['market_listing_id' => null]);
            $listing->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        });
    }

    public function adminCancelListing(EquipmentMarketListing $listing): void
    {
        DB::transaction(function () use ($listing) {
            $listing = EquipmentMarketListing::query()->lockForUpdate()->findOrFail($listing->id);
            if ($listing->status !== 'active') throw new RuntimeException('この出品は取り消せません。');
            $item = CharacterItem::query()->lockForUpdate()->find($listing->character_item_id);
            if ($item && (int) $item->market_listing_id === (int) $listing->id) $item->update(['market_listing_id' => null]);
            $listing->update(['status' => 'admin_cancelled', 'cancelled_at' => now()]);
        });
    }

    public function expireListings(): int
    {
        $ids = EquipmentMarketListing::query()->where('status', 'active')->where('expires_at', '<=', now())->pluck('id');
        foreach ($ids as $id) DB::transaction(function () use ($id) {
            $listing = EquipmentMarketListing::query()->lockForUpdate()->find($id);
            if ($listing && $listing->status === 'active' && $listing->expires_at->isPast()) $this->expireLockedListing($listing);
        });
        return $ids->count();
    }

    private function assertMarketListable(CharacterItem $item): void
    {
        if (($item->item?->type ?? null) !== 'weapon') throw new RuntimeException('武器のみ出品できます。');
        if (! $item->affix_prefix_id && ! $item->affix_suffix_id) throw new RuntimeException('銘または特攻が付いた武器のみ出品できます。');
        if ($item->is_equipped) throw new RuntimeException('装備中の武器は出品できません。');
        if ($item->is_locked) throw new RuntimeException('保護中の武器は出品できません。');
        if ($item->isMarketListed()) throw new RuntimeException('この武器はすでに出品中です。');
        if (! (bool) $item->is_tradeable || ! (bool) ($item->item?->is_tradeable ?? true)) throw new RuntimeException('この武器は取引できません。');
        if ($item->market_relistable_at?->isFuture()) throw new RuntimeException('この武器は市場で購入したばかりです。再出品可能日時：' . $item->market_relistable_at->format('Y年n月j日 H:i'));
    }

    private function assertOwnedBy(CharacterItem $item, Character $seller): void
    {
        if ((int) $item->character_id !== (int) $seller->id) throw new RuntimeException('この武器は所持していません。');
    }

    private function assertListingLimit(Character $seller): void
    {
        if (EquipmentMarketListing::query()->where('seller_character_id', $seller->id)->where('status', 'active')->where('expires_at', '>', now())->count() >= (int) config('equipment_market.max_active_listings')) {
            throw new RuntimeException('同時に出品できる武器は' . config('equipment_market.max_active_listings') . '件までです。');
        }
    }

    private function assertListingItemValid(EquipmentMarketListing $listing, CharacterItem $item, Character $seller): void
    {
        if ((int) $item->character_id !== (int) $seller->id || (int) $item->market_listing_id !== (int) $listing->id) throw new RuntimeException('出品武器の状態が変更されています。');
    }

    private function expireLockedListing(EquipmentMarketListing $listing): void
    {
        $item = CharacterItem::query()->lockForUpdate()->find($listing->character_item_id);
        if ($item && (int) $item->market_listing_id === (int) $listing->id) $item->update(['market_listing_id' => null]);
        $listing->update(['status' => 'expired']);
        $seller = Character::query()->find($listing->seller_character_id);
        if ($seller) $this->notificationService->create($seller, 'market', 'equipment_market_expired', '【装備市場】出品期限が終了しました', "{$listing->display_name_snapshot}\n武器は持ち物へ戻りました。", '出品を見る', route('equipment-market.index', ['tab' => 'listings']), ['equipment_market_listing_id' => $listing->id], 60, now()->addDays(7));
    }

    /**
     * アクティブ出品(status=active)のitem_snapshot/display_name_snapshotを、
     * 現在のitems/銘/特攻マスタから作り直す。武器のステータス改定など、
     * character_itemの状態は変わらないがitem側のマスタ値が変わった場合に使う。
     *
     * @return int 更新件数
     */
    public function refreshActiveSnapshots(): int
    {
        $updated = 0;

        EquipmentMarketListing::query()
            ->where('status', 'active')
            ->with(['characterItem' => fn ($q) => $q->with(['item', 'affixPrefix', 'affixSuffix'])])
            ->chunkById(100, function ($listings) use (&$updated) {
                foreach ($listings as $listing) {
                    $item = $listing->characterItem;
                    if (! $item || ! $item->item) {
                        continue;
                    }

                    $listing->update([
                        'display_name_snapshot' => $item->displayName(),
                        'item_snapshot' => $this->makeSnapshot($item),
                    ]);
                    $updated++;
                }
            });

        return $updated;
    }

    private function makeSnapshot(CharacterItem $item): array
    {
        $affixBonuses = $item->affixStatBonuses();

        return [
            'item_id' => $item->item_id, 'item_name' => $item->item->name, 'display_name' => $item->displayName(),
            'weapon_category' => $item->item->weapon_category, 'weapon_rank' => $item->item->weapon_rank,
            'quality' => $item->affix_quality ?: 'normal', 'enhance_level' => (int) $item->enhance_level,
            'engraving_id' => $item->affix_prefix_id, 'engraving_name' => $item->affixPrefix?->name,
            'engraving_level' => $item->effectiveAffixPrefixLevel(), 'slayer_type_id' => $item->affix_suffix_id,
            'slayer_name' => $item->affixSuffix?->name, 'slayer_level' => $item->effectiveAffixSuffixLevel(),
            'affix_lines' => $item->affixEffectLines(),
            'base_performance_lines' => $item->basePerformanceLines(),
            'engraving_effect_lines' => $item->engravingEffectLines(),
            'slayer_effect_lines' => $item->slayerEffectLines(),
            'stats' => ['hp' => (int) $item->item->hp_bonus + (int) ($affixBonuses['hp'] ?? 0), 'str' => (int) $item->item->str_bonus + (int) ($affixBonuses['str'] ?? 0), 'def' => (int) $item->item->def_bonus + (int) ($affixBonuses['def'] ?? 0), 'mag' => (int) $item->item->mag_bonus + (int) ($affixBonuses['mag'] ?? 0), 'spr' => (int) $item->item->spr_bonus + (int) ($affixBonuses['spr'] ?? 0), 'agi' => (int) $item->item->agi_bonus + (int) ($affixBonuses['agi'] ?? 0), 'luk' => (int) $item->item->luk_bonus + (int) ($affixBonuses['luk'] ?? 0)],
        ];
    }

    private function notifySale(Character $seller, EquipmentMarketListing $listing, int $price, int $fee, int $proceeds): void
    {
        $this->notificationService->create($seller, 'market', 'equipment_market_sold', '【装備市場】武器が売れました', "{$listing->display_name_snapshot}\n販売価格：" . number_format($price) . "G\n成立手数料：" . number_format($fee) . "G\n受取Gold：" . number_format($proceeds) . 'G', '履歴を見る', route('equipment-market.index', ['tab' => 'history']), ['equipment_market_listing_id' => $listing->id], 70, now()->addDays(7));
    }

    private function notifyPurchase(Character $buyer, EquipmentMarketListing $listing, int $price): void
    {
        $this->notificationService->create($buyer, 'market', 'equipment_market_purchased', '【装備市場】武器を購入しました', "{$listing->display_name_snapshot}\n購入価格：" . number_format($price) . 'G\n装備画面または持ち物から確認できます。', '装備を見る', route('equipment.index'), ['equipment_market_listing_id' => $listing->id], 70, now()->addDays(7));
    }
}
