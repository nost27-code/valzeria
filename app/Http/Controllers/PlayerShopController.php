<?php

namespace App\Http\Controllers;

use App\Models\EquipmentMarketListing;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\MarketListing;
use App\Models\Material;
use App\Models\PlayerShop;
use App\Models\PlayerValmonEgg;
use App\Models\ShopEggListing;
use App\Models\ShopFavorite;
use App\Services\PlayerShopService;
use App\Services\MarketService;
use App\Services\EquipmentMarketService;
use App\Services\EquipmentMarketAppraisalService;
use App\Services\ShopEggListingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class PlayerShopController extends Controller
{
    public function __construct(
        private readonly PlayerShopService $shopService,
        private readonly ShopEggListingService $eggListingService,
        private readonly MarketService $marketService,
        private readonly EquipmentMarketService $equipmentMarketService,
        private readonly EquipmentMarketAppraisalService $appraisalService,
    ) {}

    public function street(Request $request)
    {
        $character = $this->character();
        $query = trim((string) $request->query('q', ''));
        $shops = PlayerShop::query()->with('character')->where('status', 'open')
            ->when($query !== '', fn ($q) => $q->where(fn ($sq) => $sq->where('name', 'like', "%{$query}%")->orWhereHas('character', fn ($cq) => $cq->where('name', 'like', "%{$query}%"))))
            ->orderByDesc('last_stocked_at')->orderByDesc('id')->limit(30)->get();
        $ownShop = PlayerShop::query()->where('character_id', $character->id)->first();
        $recentEggs = ShopEggListing::query()->active()->with(['shop.character'])->orderByDesc('created_at')->limit(6)->get();
        return view('shopping-street.index', compact('character', 'shops', 'ownShop', 'recentEggs', 'query'));
    }

    public function index(Request $request)
    {
        return $this->street($request);
    }

    public function show(PlayerShop $shop)
    {
        $character = $this->character();
        abort_unless($shop->status === 'open' || (int) $shop->character_id === (int) $character->id, 404);
        $shop->load('character');
        $materialListings = MarketListing::query()->active()->where('shop_id', $shop->id)->with('material')->orderBy('unit_price')->get();
        $equipmentListings = EquipmentMarketListing::query()->active()->where('shop_id', $shop->id)->orderBy('listing_price')->get();
        $eggListings = ShopEggListing::query()->active()->where('shop_id', $shop->id)->orderBy('listing_price')->get();
        $isFavorite = ShopFavorite::query()->where('shop_id', $shop->id)->where('character_id', $character->id)->exists();
        return view('shops.show', compact('character', 'shop', 'materialListings', 'equipmentListings', 'eggListings', 'isFavorite'));
    }

    public function mine()
    {
        $character = $this->character();
        $shop = $this->shopService->ensureForCharacter($character);
        $materialListings = MarketListing::query()->where('shop_id', $shop->id)->with('material')->latest()->limit(100)->get();
        $equipmentListings = EquipmentMarketListing::query()->where('shop_id', $shop->id)->latest()->limit(100)->get();
        $eggListings = ShopEggListing::query()->where('shop_id', $shop->id)->latest()->limit(100)->get();
        $eggs = PlayerValmonEgg::query()->with('master')->where('character_id', $character->id)->whereNotNull('stored_at')->where('is_hatched', false)->where('is_lost', false)
            ->whereDoesntHave('shopListings', fn ($q) => $q->where('status', 'active')->where('expires_at', '>', now()))->latest('stored_at')->get();
        $materials = CharacterMaterial::query()->where('character_id', $character->id)->where('quantity', '>', 0)
            ->with('material')->whereHas('material', fn ($q) => $q->marketable())->get()->sortBy(fn ($row) => $row->material?->displayName())->values();
        $weapons = CharacterItem::query()->with(['item', 'affixPrefix', 'affixSuffix'])
            ->where('character_id', $character->id)->whereNull('market_listing_id')->where('is_equipped', false)->where('is_locked', false)
            ->whereHas('item', fn ($q) => $q->where('type', 'weapon')->where('is_tradeable', true))
            ->where(fn ($q) => $q->whereNotNull('affix_prefix_id')->orWhereNotNull('affix_suffix_id'))
            ->where('is_tradeable', true)->where(fn ($q) => $q->whereNull('market_relistable_at')->orWhere('market_relistable_at', '<=', now()))
            ->latest('id')->get()->map(function (CharacterItem $item) {
                try { $item->market_appraisal = $this->appraisalService->appraisal($item); } catch (RuntimeException) { $item->market_appraisal = null; }
                return $item;
            });
        return view('shops.mine', compact('character', 'shop', 'materialListings', 'equipmentListings', 'eggListings', 'eggs', 'materials', 'weapons'));
    }

    public function update(Request $request, PlayerShop $shop)
    {
        $character = $this->character();
        abort_unless((int) $shop->character_id === (int) $character->id, 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:20', 'not_regex:/[\x00-\x1F<>]/u'],
            'description' => ['nullable', 'string', 'max:100', 'not_regex:/[\x00-\x1F<>]/u'],
            'shop_type' => ['required', 'in:general,material,equipment,valmon'],
            'icon_key' => ['required', 'in:general,material,equipment,valmon'],
            'banner_key' => ['required', 'in:default,forest,forge,night'],
        ]);
        $this->shopService->update($shop, $data);
        return redirect()->route('shops.mine')->with('status', '商店情報を更新しました。');
    }

    public function favorite(PlayerShop $shop)
    {
        $character = $this->character();
        ShopFavorite::query()->firstOrCreate(['shop_id' => $shop->id, 'character_id' => $character->id]);
        return back()->with('status', '商店をお気に入りに登録しました。');
    }

    public function unfavorite(PlayerShop $shop)
    {
        ShopFavorite::query()->where('shop_id', $shop->id)->where('character_id', $this->character()->id)->delete();
        return back()->with('status', 'お気に入りを解除しました。');
    }

    public function listEgg(Request $request)
    {
        $character = $this->character();
        $data = $request->validate(['egg_id' => ['required', 'integer', 'exists:player_valmon_eggs,id'], 'listing_price' => ['required', 'integer', 'min:1', 'max:999999999'], 'listing_hours' => ['required', 'integer', 'in:12,24,48']]);
        $egg = PlayerValmonEgg::findOrFail($data['egg_id']);
        $this->eggListingService->list($character, $egg, (int) $data['listing_price'], (int) $data['listing_hours']);
        return redirect()->route('shops.mine')->with('status', 'ヴァルモンの卵を出品しました。');
    }

    public function listMaterial(Request $request)
    {
        $character = $this->character();
        $data = $request->validate([
            'material_id' => ['required', 'integer', 'exists:materials,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999999'],
            'unit_price' => ['required', 'integer', 'min:1', 'max:999999999'],
        ]);
        $material = Material::findOrFail($data['material_id']);
        $this->marketService->listMaterial($character, $material, (int) $data['quantity'], (int) $data['unit_price']);
        return redirect()->route('shops.mine')->with('status', "{$material->displayName()}を商店へ出品しました。");
    }

    public function listEquipment(Request $request)
    {
        $character = $this->character();
        $data = $request->validate([
            'character_item_id' => ['required', 'integer', 'exists:character_items,id'],
            'listing_price' => ['required', 'integer', 'min:1', 'max:999999999'],
        ]);
        try {
            $item = CharacterItem::findOrFail($data['character_item_id']);
            $this->equipmentMarketService->listWeapon($character, $item, (int) $data['listing_price']);
        } catch (RuntimeException $e) {
            return redirect()->route('shops.mine')->with('error', $e->getMessage());
        }
        return redirect()->route('shops.mine')->with('status', '装備を商店へ出品しました。');
    }

    public function buyEgg(ShopEggListing $listing)
    {
        try { $this->eggListingService->buy($this->character(), $listing); }
        catch (RuntimeException $e) { return back()->with('error', $e->getMessage()); }
        return redirect()->route('shops.mine')->with('status', 'ヴァルモンの卵を購入しました。所持品に追加されています。');
    }

    public function cancelEgg(ShopEggListing $listing)
    {
        try { $this->eggListingService->cancel($this->character(), $listing); }
        catch (RuntimeException $e) { return back()->with('error', $e->getMessage()); }
        return redirect()->route('shops.mine')->with('status', 'ヴァルモンの卵の出品を取り消しました。');
    }

    private function character()
    {
        abort_unless($this->shopService->isEnabled(), 404);

        $character = Auth::user()?->currentCharacter();
        abort_unless($character, 403, 'キャラクターが見つかりません。');
        return $character;
    }
}
