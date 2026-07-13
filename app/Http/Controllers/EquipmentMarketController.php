<?php

namespace App\Http\Controllers;

use App\Models\CharacterItem;
use App\Models\EquipmentMarketListing;
use App\Models\EquipmentMarketTransaction;
use App\Services\EquipmentMarketAppraisalService;
use App\Services\EquipmentMarketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class EquipmentMarketController extends Controller
{
    public function __construct(private readonly EquipmentMarketService $service) {}

    public function index(Request $request)
    {
        $character = Auth::user()->currentCharacter();
        if (! $character) return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');

        $tab = in_array($request->query('tab'), ['buy', 'sell', 'listings', 'history'], true) ? $request->query('tab') : 'buy';
        $query = EquipmentMarketListing::query()->active()->with('seller');
        foreach (['weapon_category', 'weapon_rank', 'quality_key'] as $key) {
            if ($request->filled($key)) $query->where($key, (string) $request->input($key));
        }
        foreach (['engraving_id', 'slayer_type_id'] as $key) {
            if ($request->filled($key)) $query->where($key, (int) $request->input($key));
        }
        foreach (['engraving_level', 'slayer_level', 'enhance_level'] as $key) {
            if ($request->filled($key)) $query->where($key, (int) $request->input($key));
        }
        if ($request->filled('min_price')) $query->where('listing_price', '>=', (int) $request->input('min_price'));
        if ($request->filled('max_price')) $query->where('listing_price', '<=', (int) $request->input('max_price'));
        if ($request->filled('name')) $query->where('display_name_snapshot', 'like', '%' . addcslashes((string) $request->input('name'), '%_\\') . '%');
        $sort = $request->query('sort', 'price_asc');
        match ($sort) {
            'price_desc' => $query->orderByDesc('listing_price'),
            'newest' => $query->latest(),
            'engraving_desc' => $query->orderByDesc('engraving_level')->orderBy('listing_price'),
            'slayer_desc' => $query->orderByDesc('slayer_level')->orderBy('listing_price'),
            'rank_desc' => $query->orderByDesc('weapon_rank')->orderBy('listing_price'),
            default => $query->orderBy('listing_price')->orderBy('created_at'),
        };

        $listingsCount = (clone $query)->count();
        $listings = $query->limit(100)->get();

        $engravingOptions = EquipmentMarketListing::query()->active()->whereNotNull('engraving_id')
            ->select('engraving_id', 'item_snapshot')->get()
            ->unique('engraving_id')
            ->map(fn ($l) => ['id' => $l->engraving_id, 'name' => $l->item_snapshot['engraving_name'] ?? "銘#{$l->engraving_id}"])
            ->sortBy('name')->values();
        $slayerOptions = EquipmentMarketListing::query()->active()->whereNotNull('slayer_type_id')
            ->select('slayer_type_id', 'item_snapshot')->get()
            ->unique('slayer_type_id')
            ->map(fn ($l) => ['id' => $l->slayer_type_id, 'name' => $l->item_snapshot['slayer_name'] ?? "特攻#{$l->slayer_type_id}"])
            ->sortBy('name')->values();
        $categoryOptions = EquipmentMarketListing::query()->active()->whereNotNull('weapon_category')
            ->distinct()->orderBy('weapon_category')->pluck('weapon_category');

        $sellable = CharacterItem::query()->with(['item', 'affixPrefix', 'affixSuffix'])
            ->where('character_id', $character->id)->whereNull('market_listing_id')
            ->where('is_equipped', false)->where('is_locked', false)
            ->whereHas('item', fn ($q) => $q->where('type', 'weapon')->where('is_tradeable', true))
            ->where(fn ($q) => $q->whereNotNull('affix_prefix_id')->orWhereNotNull('affix_suffix_id'))
            ->where('is_tradeable', true)
            ->where(fn ($q) => $q->whereNull('market_relistable_at')->orWhere('market_relistable_at', '<=', now()))
            ->orderByDesc('id')->get();
        $appraisalService = app(EquipmentMarketAppraisalService::class);
        $sellable = $sellable->map(function (CharacterItem $item) use ($appraisalService) {
            try { $item->market_appraisal = $appraisalService->appraisal($item); } catch (RuntimeException) { $item->market_appraisal = null; }
            return $item;
        });
        $ownListings = EquipmentMarketListing::query()->where('seller_character_id', $character->id)->orderByRaw("CASE status WHEN 'active' THEN 1 WHEN 'sold' THEN 2 ELSE 3 END")->latest()->limit(100)->get();
        $history = EquipmentMarketTransaction::query()->where(fn ($q) => $q->where('seller_character_id', $character->id)->orWhere('buyer_character_id', $character->id))->latest('sold_at')->limit(100)->get();

        return view('equipment-market.index', compact(
            'character', 'tab', 'listings', 'listingsCount', 'sellable', 'ownListings', 'history', 'sort',
            'engravingOptions', 'slayerOptions', 'categoryOptions'
        ));
    }

    public function show(EquipmentMarketListing $listing)
    {
        $character = Auth::user()->currentCharacter();
        if (! $character) return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        if ($listing->status === 'active' && $listing->expires_at->isPast()) $this->service->expireListings();
        $listing->refresh();
        $listing->load('seller');
        return view('equipment-market.show', compact('character', 'listing'));
    }

    public function store(Request $request)
    {
        $character = Auth::user()->currentCharacter();
        if (! $character) return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        $data = $request->validate(['character_item_id' => ['required', 'integer', 'exists:character_items,id'], 'listing_price' => ['required', 'integer', 'min:1', 'max:999999999']]);
        $item = CharacterItem::findOrFail($data['character_item_id']);
        $listing = $this->service->listWeapon($character, $item, (int) $data['listing_price']);
        return redirect()->route('equipment-market.index', ['tab' => 'listings'])->with('status', "{$listing->display_name_snapshot}を" . number_format($listing->listing_price) . 'Gで出品しました。');
    }

    public function buy(EquipmentMarketListing $listing)
    {
        $character = Auth::user()->currentCharacter();
        if (! $character) return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        try {
            $this->service->buyWeapon($character, $listing);
        } catch (RuntimeException $e) {
            return redirect()->route('equipment-market.show', $listing)->with('error', $e->getMessage());
        }
        return redirect()->route('equipment-market.index', ['tab' => 'history'])->with('status', '武器を購入しました。72時間は再出品できません。');
    }

    public function cancel(EquipmentMarketListing $listing)
    {
        $character = Auth::user()->currentCharacter();
        if (! $character) return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        try { $this->service->cancelListing($character, $listing); }
        catch (RuntimeException $e) { return redirect()->route('equipment-market.index', ['tab' => 'listings'])->with('error', $e->getMessage()); }
        return redirect()->route('equipment-market.index', ['tab' => 'listings'])->with('status', '出品を取り消しました。');
    }
}
