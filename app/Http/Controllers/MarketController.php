<?php

namespace App\Http\Controllers;

use App\Models\CharacterMaterial;
use App\Models\MarketListing;
use App\Models\MarketTransaction;
use App\Models\Material;
use App\Services\MarketDemandService;
use App\Services\MaterialMarketInfoService;
use App\Services\MarketService;
use App\Services\NpcProcurementRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MarketController extends Controller
{
    public function __construct(private readonly MarketService $marketService)
    {
    }

    public function index(Request $request, MaterialMarketInfoService $materialInfoService, MarketDemandService $marketDemandService)
    {
        $character = Auth::user()->currentCharacter();
        if (! $character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $tab = in_array($request->query('tab'), ['demand', 'buy', 'sell', 'listings', 'history'], true)
            ? $request->query('tab')
            : 'demand';
        $selectedMaterialId = (int) $request->query('material_id', 0);

        $marketStats = MarketListing::query()
            ->active()
            ->where('listing_type', 'material')
            ->where('seller_character_id', '!=', $character->id)
            ->select('material_id', DB::raw('SUM(remaining_quantity) as stock'), DB::raw('MIN(unit_price) as lowest_price'))
            ->groupBy('material_id')
            ->get()
            ->keyBy('material_id');

        $ownedByMaterial = CharacterMaterial::query()
            ->where('character_id', $character->id)
            ->where('quantity', '>', 0)
            ->pluck('quantity', 'material_id');

        $materials = Material::query()
            ->marketable()
            ->orderByRaw("CASE market_category WHEN 'normal' THEN 1 WHEN 'regional' THEN 2 ELSE 9 END")
            ->orderBy('display_order')
            ->orderBy('material_code')
            ->get();

        $materialInfos = $materials
            ->mapWithKeys(fn (Material $material) => [
                $material->id => $materialInfoService->getInfo($character, $material),
            ]);

        $ownedMaterials = CharacterMaterial::query()
            ->where('character_id', $character->id)
            ->where('quantity', '>', 0)
            ->whereHas('material', fn ($query) => $query->marketable())
            ->with('material')
            ->get()
            ->sortBy(fn (CharacterMaterial $row) => [
                (string) ($row->material?->market_category ?? ''),
                (int) ($row->material?->display_order ?? 0),
                (string) ($row->material?->material_code ?? ''),
            ])
            ->values();

        $ownedMaterialInfos = $ownedMaterials
            ->filter(fn (CharacterMaterial $row) => $row->material !== null)
            ->mapWithKeys(fn (CharacterMaterial $row) => [
                $row->material->id => $materialInfoService->getInfo($character, $row->material),
            ]);

        $ownListings = MarketListing::query()
            ->where('seller_character_id', $character->id)
            ->with('material')
            ->orderByRaw("CASE status WHEN 'active' THEN 1 WHEN 'sold_out' THEN 2 WHEN 'cancelled' THEN 3 WHEN 'expired' THEN 4 ELSE 9 END")
            ->orderByDesc('created_at')
            ->limit(80)
            ->get();

        $history = MarketTransaction::query()
            ->where(function ($query) use ($character) {
                $query->where('seller_character_id', $character->id)
                    ->orWhere('buyer_character_id', $character->id);
            })
            ->with(['material', 'seller', 'buyer'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $demandItems = $tab === 'demand'
            ? $marketDemandService->getDemandBoard($character)
            : [];

        return view('market.index', compact(
            'character',
            'tab',
            'selectedMaterialId',
            'materials',
            'ownedMaterials',
            'ownListings',
            'history',
            'marketStats',
            'ownedByMaterial',
            'materialInfos',
            'ownedMaterialInfos',
            'demandItems'
        ));
    }

    public function showMaterial(Material $material, MaterialMarketInfoService $service, NpcProcurementRequestService $npcProcurementRequestService)
    {
        $character = Auth::user()->currentCharacter();
        if (! $character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $info = $service->getInfo($character, $material);
        $npcRequests = $npcProcurementRequestService->getActiveRequestsForMaterial($material, $character, 3);

        return view('market.material-detail', compact('character', 'material', 'info', 'npcRequests'));
    }

    public function listMaterial(Request $request)
    {
        $character = Auth::user()->currentCharacter();
        if (! $character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $validated = $request->validate([
            'material_id' => ['required', 'integer', 'exists:materials,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999999'],
            'unit_price' => ['required', 'integer', 'min:1', 'max:999999999'],
        ]);

        $material = Material::findOrFail($validated['material_id']);
        $listing = $this->marketService->listMaterial(
            $character,
            $material,
            (int) $validated['quantity'],
            (int) $validated['unit_price']
        );

        return redirect()
            ->route('market.index', ['tab' => 'listings'])
            ->with('status', "{$material->displayName()} x{$listing->quantity} を {$listing->unit_price}G で出品しました。");
    }

    public function buyMaterial(Request $request)
    {
        $character = Auth::user()->currentCharacter();
        if (! $character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $validated = $request->validate([
            'material_id' => ['required', 'integer', 'exists:materials,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999999'],
        ]);

        $material = Material::findOrFail($validated['material_id']);
        $result = $this->marketService->buyMaterial($character, $material, (int) $validated['quantity']);

        return redirect()
            ->route('market.index', ['tab' => 'buy'])
            ->with('status', "{$result['material_name']} x{$result['quantity']} を合計 " . number_format($result['total_price']) . 'G で購入しました。')
            ->with('market_result', $result);
    }

    public function cancelListing(MarketListing $listing)
    {
        $character = Auth::user()->currentCharacter();
        if (! $character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $result = $this->marketService->cancelListing($character, $listing);

        return redirect()
            ->route('market.index', ['tab' => 'listings'])
            ->with('status', "{$result['material_name']} x{$result['quantity']} を出品から取り下げました。");
    }
}
