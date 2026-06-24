<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Item;
use App\Services\ShopService;
use App\Services\DailySupplyService;
use App\Services\EquipmentService;
use App\Services\EquipmentPermissionService;
use Illuminate\Support\Facades\Auth;

class ShopController extends Controller
{
    public function equipment(Request $request)
    {
        $character = Auth::user()->currentCharacter();
        $cityId = $character ? $character->current_city_id : null;
        $type = $this->normalizeEquipmentType($request->query('type', 'weapon'));
        $sort = $this->normalizeSort($request->query('sort', 'recommended'));

        $query = Item::where('type', $type)
            ->where('is_active', true)
            ->where('is_shop_item', true)
            ->where('unlock_city_id', $cityId);

        $this->applySort($query, $type, $sort);
        $items = $query->get();

        $equipmentService = app(EquipmentService::class);
        $equippedItems = $character ? $equipmentService->getEquippedItems($character) : [];
        $ownedItemCounts = $character
            ? $character->characterItems()
                ->whereIn('item_id', $items->pluck('id'))
                ->selectRaw('item_id, COUNT(*) as item_count')
                ->groupBy('item_id')
                ->pluck('item_count', 'item_id')
                ->map(fn ($count) => (int) $count)
                ->all()
            : [];

        return view('shop.list', [
            'categoryName' => '装備屋',
            'items' => $items,
            'type' => $type,
            'sort' => $sort,
            'character' => $character,
            'cityName' => $character?->currentCity?->name,
            'equippedItems' => $equippedItems,
            'ownedItemCounts' => $ownedItemCounts,
            'isStarterSupply' => false,
        ]);
    }

    public function armors()
    {
        return redirect()->route('shop.equipment', ['type' => 'armor']);
    }

    public function accessories()
    {
        return redirect()->route('shop.equipment', ['type' => 'accessory']);
    }

    public function weapons()
    {
        return redirect()->route('shop.equipment', ['type' => 'weapon']);
    }

    public function items(DailySupplyService $dailySupplyService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        return view('shop.supply', [
            'categoryName' => '補給所',
            'items' => $dailySupplyService->statusFor($character),
            'targetCount' => $dailySupplyService->targetCount(),
        ]);
    }

    public function claimSupply(Item $item, DailySupplyService $dailySupplyService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $result = $dailySupplyService->claim($character, $item);
        return redirect()->back()->with($result['success'] ? 'status' : 'error', $result['message']);
    }

    public function claimAllSupplies(DailySupplyService $dailySupplyService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $result = $dailySupplyService->claimAll($character);
        return redirect()->back()->with($result['success'] ? 'status' : 'error', $result['message']);
    }

    public function buy(Request $request, Item $item, ShopService $shopService, EquipmentPermissionService $permissionService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $quantity = $item->type === 'consumable'
            ? max(1, min(99, (int) $request->input('quantity', 1)))
            : 1;

        $result = $shopService->buy($character, $item, $quantity);

        if ($result['success']) {
            $redirect = redirect()->back()->with('status', $result['message']);

            if (in_array($result['item_type'] ?? null, ['weapon', 'armor', 'accessory'], true) && !empty($result['character_item_id'])) {
                $canEquip = $permissionService->canEquip($character, $item);
                $redirect->with('equipPrompt', [
                    'character_item_id' => $result['character_item_id'],
                    'item_name' => $item->name,
                    'item_type' => $item->type,
                    'can_equip' => $canEquip,
                    'restriction_message' => $canEquip ? null : ($permissionService->restrictionMessage($character, $item) ?? '現在の職業では装備できません。'),
                ]);
            }

            return $redirect;
        } else {
            return redirect()->back()->with('error', $result['message']);
        }
    }

    private function normalizeEquipmentType(mixed $type): string
    {
        return in_array($type, ['weapon', 'armor'], true) ? $type : 'weapon';
    }

    private function normalizeSort(mixed $sort): string
    {
        return in_array($sort, [
            'recommended',
            'price_asc',
            'price_desc',
            'level_asc',
            'attack_desc',
            'defense_desc',
            'magic_desc',
            'speed_desc',
            'luck_desc',
            'rarity_desc',
        ], true) ? $sort : 'recommended';
    }

    private function applySort($query, string $type, string $sort): void
    {
        match ($sort) {
            'price_asc' => $query->orderBy('price')->orderBy('required_level'),
            'price_desc' => $query->orderByDesc('price')->orderBy('required_level'),
            'level_asc' => $query->orderBy('required_level')->orderBy('price'),
            'attack_desc' => $query->orderByDesc('str_bonus')->orderByDesc('mag_bonus')->orderBy('price'),
            'defense_desc' => $query->orderByDesc('def_bonus')->orderByDesc('spr_bonus')->orderByDesc('hp_bonus')->orderBy('price'),
            'magic_desc' => $query->orderByDesc('mag_bonus')->orderByDesc('spr_bonus')->orderBy('price'),
            'speed_desc' => $query->orderByDesc('agi_bonus')->orderBy('price'),
            'luck_desc' => $query->orderByDesc('luk_bonus')->orderBy('price'),
            'rarity_desc' => $query->orderByRaw("
                CASE rarity
                    WHEN 'SSS' THEN 1
                    WHEN 'SS' THEN 2
                    WHEN 'S' THEN 3
                    WHEN 'A' THEN 4
                    WHEN 'B' THEN 5
                    WHEN 'C' THEN 6
                    WHEN 'D' THEN 7
                    WHEN 'E' THEN 8
                    WHEN 'F' THEN 9
                    WHEN 'G' THEN 10
                    WHEN 'H' THEN 11
                    WHEN 'I' THEN 12
                    WHEN 'J' THEN 13
                    WHEN 'K' THEN 14
                    WHEN 'epic' THEN 15
                    WHEN 'rare' THEN 16
                    WHEN 'normal' THEN 17
                    ELSE 99
                END
            ")->orderBy('required_level'),
            default => $this->applyRecommendedSort($query, $type),
        };
    }

    private function applyRecommendedSort($query, string $type): void
    {
        if ($type === 'weapon') {
            $query->orderByRaw('(str_bonus + mag_bonus * 0.8 + agi_bonus * 0.3 + luk_bonus * 0.2) DESC');
        } elseif ($type === 'armor') {
            $query->orderByRaw('(def_bonus + spr_bonus * 0.8 + hp_bonus * 0.04 + mp_bonus * 0.04 + agi_bonus * 0.2) DESC');
        } else {
            $query->orderByRaw('(agi_bonus + luk_bonus + hp_bonus * 0.03 + mp_bonus * 0.03 + str_bonus * 0.5 + def_bonus * 0.5 + mag_bonus * 0.5 + spr_bonus * 0.5) DESC');
        }

        $query->orderBy('required_level')->orderBy('price');
    }
}
