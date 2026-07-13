<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CharacterItem;
use App\Services\EquipmentAutoUnequipService;
use App\Services\EquipmentPermissionService;
use App\Services\EquipmentService;
use App\Services\ExplorationSupportService;
use App\Services\GoldService;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class EquipmentController extends Controller
{
    public function index(
        EquipmentService $equipmentService,
        EquipmentAutoUnequipService $autoUnequipService,
        EquipmentPermissionService $permissionService,
        ExplorationSupportService $supportService
    )
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $autoUnequipService->unequipInvalidItems($character);

        $characterItems = $character->characterItems()->with(['item', 'affixPrefix', 'affixSuffix'])->get()
            ->filter(fn($ci) => $ci->item)
            ->map(fn($ci) => $this->attachSortValues($ci, $equipmentService));

        $weapons = $characterItems->filter(fn($ci) => $ci->item->type === 'weapon')->sortByDesc('sort_recommend')->values();
        $armors = $characterItems->filter(fn($ci) => $ci->item->type === 'armor')->sortByDesc('sort_recommend')->values();
        $accessories = $characterItems->filter(fn($ci) => $ci->item->type === 'accessory' && !$equipmentService->isMark($ci->item))->sortByDesc('sort_recommend')->values();
        $explorationSupportEnabled = $supportService->isEnabled();
        $belongings = $explorationSupportEnabled ? $supportService->belongingsFor($character) : [];

        return view('equipment.index', compact(
            'character',
            'permissionService',
            'weapons',
            'armors',
            'accessories',
            'belongings',
            'explorationSupportEnabled'
        ));
    }

    public function equip(CharacterItem $characterItem, EquipmentService $equipmentService, Request $request)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'キャラクターが見つかりません。'], 404);
            }

            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $tab = $characterItem->item ? $equipmentService->getAccessoryTab($characterItem->item) : 'weapon';
        $unequippedIds = [];
        if ($request->expectsJson() && $characterItem->item) {
            $slot = $characterItem->item->type === 'accessory'
                ? EquipmentService::ACCESSORY_SLOT
                : $characterItem->item->type;

            $unequippedIds = CharacterItem::where('character_id', $character->id)
                ->where('is_equipped', true)
                ->where('equipped_slot', $slot)
                ->where('id', '!=', $characterItem->id)
                ->pluck('id')
                ->values()
                ->all();
        }

        $result = $equipmentService->equip($character, $characterItem);

        if ($result['success']) {
            \App\Livewire\MainScreen::clearHomeCache($character->id);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'action' => 'equip',
                'active_tab' => $tab,
                'active_mode' => 'inventory',
                'character_item_id' => $characterItem->id,
                'unequipped_ids' => $result['success'] ? $unequippedIds : [],
            ], $result['success'] ? 200 : 422);
        }

        if ($request->boolean('return_to_shop')) {
            $flashType = $result['success'] ? 'status' : 'error';
            return redirect()->back()->with($flashType, $result['message']);
        }

        if ($result['success']) {
            return redirect()->route('equipment.index')->with('status', $result['message'])->with('activeTab', $tab);
        } else {
            return redirect()->route('equipment.index')->with('error', $result['message'])->with('activeTab', $tab);
        }
    }

    public function unequip(CharacterItem $characterItem, EquipmentService $equipmentService, Request $request)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'キャラクターが見つかりません。'], 404);
            }

            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $result = $equipmentService->unequip($character, $characterItem);
        $tab = $characterItem->item ? $equipmentService->getAccessoryTab($characterItem->item) : 'weapon';

        if ($result['success']) {
            \App\Livewire\MainScreen::clearHomeCache($character->id);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'action' => 'unequip',
                'active_mode' => 'inventory',
                'active_tab' => $tab,
                'character_item_id' => $characterItem->id,
            ], $result['success'] ? 200 : 422);
        }

        if ($result['success']) {
            return redirect()->route('equipment.index')->with('status', $result['message'])->with('activeTab', $tab);
        } else {
            return redirect()->route('equipment.index')->with('error', $result['message'])->with('activeTab', $tab);
        }
    }

    public function toggleLock(CharacterItem $characterItem, EquipmentService $equipmentService, Request $request)
    {
        $character = Auth::user()->currentCharacter();
        $tab = $characterItem->item ? $equipmentService->getAccessoryTab($characterItem->item) : 'weapon';
        $mode = 'inventory';

        if (!$character || $characterItem->character_id !== $character->id || !$characterItem->item || !in_array($characterItem->item->type, ['weapon', 'armor', 'accessory'], true)) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'この装備は保護できません。'], 422);
            }

            return redirect()->route('equipment.index')
                ->with('error', 'この装備は保護できません。')
                ->with('activeMode', $mode)
                ->with('activeTab', $tab);
        }
        if ($characterItem->isMarketListed()) {
            return redirect()->route('equipment.index')->with('error', 'この武器は冒険者市場へ出品中です。操作するには先に出品を取り消してください。');
        }

        $characterItem->is_locked = !$characterItem->is_locked;
        $characterItem->save();

        $message = $characterItem->is_locked
            ? "{$characterItem->displayName()}を保護しました。"
            : "{$characterItem->displayName()}の保護を解除しました。";
        $this->attachSortValues($characterItem, $equipmentService);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'action' => 'lock',
                'active_mode' => $mode,
                'active_tab' => $tab,
                'character_item_id' => $characterItem->id,
                'is_locked' => (bool) $characterItem->is_locked,
                'can_sell' => (bool) ($characterItem->can_sell ?? false),
            ]);
        }

        return redirect()->route('equipment.index')
            ->with('status', $message)
            ->with('activeMode', $mode)
            ->with('activeTab', $tab);
    }

    public function store(CharacterItem $characterItem, EquipmentService $equipmentService, Request $request)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character || $characterItem->character_id !== $character->id) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'この装備は所持していません。'], 404);
            }

            return redirect()->route('equipment.index')->with('error', 'この装備は所持していません。');
        }
        if ($characterItem->isMarketListed()) {
            return redirect()->route('equipment.index')->with('error', 'この武器は冒険者市場へ出品中です。操作するには先に出品を取り消してください。');
        }

        if (!$characterItem->item || !in_array($characterItem->item->type, ['weapon', 'armor', 'accessory'], true)) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => '保管状態を変更できないアイテムです。'], 422);
            }

            return redirect()->route('equipment.index')->with('error', '保管状態を変更できないアイテムです。');
        }

        if ($characterItem->is_equipped) {
            $result = $equipmentService->unequip($character, $characterItem);
            if (!$result['success']) {
                if ($request->expectsJson()) {
                    return response()->json(['success' => false, 'message' => $result['message']], 422);
                }

                return redirect()->route('equipment.index')->with('error', $result['message']);
            }
            $characterItem->refresh();
        }

        $characterItem->is_stored = false;
        $characterItem->save();

        $tab = $equipmentService->getAccessoryTab($characterItem->item);
        $message = "{$characterItem->displayName()}を装備一覧に戻しました。";

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'action' => 'store',
                'active_mode' => 'inventory',
                'active_tab' => $tab,
                'character_item_id' => $characterItem->id,
            ]);
        }

        return redirect()->route('equipment.index')
            ->with('status', $message)
            ->with('activeMode', 'inventory')
            ->with('activeTab', $tab);
    }

    public function unstore(CharacterItem $characterItem, EquipmentService $equipmentService, Request $request)
    {
        $character = Auth::user()->currentCharacter();
        $tab = $characterItem->item ? $equipmentService->getAccessoryTab($characterItem->item) : 'weapon';
        if (!$character || $characterItem->character_id !== $character->id) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'この装備は所持していません。'], 404);
            }

            return redirect()->route('equipment.index')->with('error', 'この装備は所持していません。')->with('activeMode', 'storage');
        }
        if ($characterItem->isMarketListed()) {
            return redirect()->route('equipment.index')->with('error', 'この武器は冒険者市場へ出品中です。操作するには先に出品を取り消してください。');
        }

        $characterItem->is_stored = false;
        $characterItem->save();
        $message = "{$characterItem->displayName()}を装備一覧に戻しました。";

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'action' => 'unstore',
                'active_mode' => 'storage',
                'active_tab' => $tab,
                'character_item_id' => $characterItem->id,
            ]);
        }

        return redirect()->route('equipment.index')
            ->with('status', $message)
            ->with('activeMode', 'inventory')
            ->with('activeTab', $tab);
    }

    public function sellStored(CharacterItem $characterItem, EquipmentService $equipmentService, GoldService $goldService, Request $request)
    {
        $tab = $characterItem->item ? $equipmentService->getAccessoryTab($characterItem->item) : 'weapon';
        $character = Auth::user()->currentCharacter();
        $mode = 'inventory';
        $redirectRoute = 'equipment.index';
        if ($request->boolean('return_to_inventory')) {
            $redirectRoute = 'inventory.index';
        } elseif ($request->boolean('return_to_smith')) {
            $redirectRoute = 'smith.index';
        }

        if (!$character || (int) $characterItem->character_id !== (int) $character->id) {
            $message = 'この装備は所持していません。';

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'action' => 'sell',
                    'active_mode' => $mode,
                    'active_tab' => $tab,
                    'character_item_id' => $characterItem->id,
                ], 404);
            }

            return redirect()->route($redirectRoute)
                ->with('error', $message)
                ->with('activeMode', $mode)
                ->with('activeTab', $tab);
        }

        try {
            $result = $goldService->sellEquipment($character, $characterItem);
            $message = "{$result['name']}を売却し、" . number_format($result['amount']) . 'Gを得ました。';
        } catch (RuntimeException $e) {
            $message = $e->getMessage();

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'action' => 'sell',
                    'active_mode' => $mode,
                    'active_tab' => $tab,
                    'character_item_id' => $characterItem->id,
                ], 422);
            }

            return redirect()->route($redirectRoute)
                ->with('error', $message)
                ->with('activeMode', $mode)
                ->with('activeTab', $tab);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'action' => 'sell',
                'active_mode' => $mode,
                'active_tab' => $tab,
                'character_item_id' => $characterItem->id,
            ]);
        }

        return redirect()->route($redirectRoute)
            ->with('status', $message)
            ->with('activeMode', $mode)
            ->with('activeTab', $tab);
    }

    public function disassemble(CharacterItem $characterItem, EquipmentService $equipmentService, Request $request)
    {
        $character = Auth::user()->currentCharacter();
        $tab = $characterItem->item ? $equipmentService->getAccessoryTab($characterItem->item) : 'weapon';
        $mode = 'inventory';
        $message = '装備分解は現在停止中です。不要な装備は売却してください。';

        if (!$character || $characterItem->character_id !== $character->id) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'この装備は所持していません。'], 404);
            }

            return redirect()->route('equipment.index')
                ->with('error', 'この装備は所持していません。')
                ->with('activeMode', $mode)
                ->with('activeTab', $tab);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'action' => 'disassemble',
                'active_mode' => $mode,
                'active_tab' => $tab,
                'character_item_id' => $characterItem->id,
            ], 422);
        }

        return redirect()->route('equipment.index')
            ->with('error', $message)
            ->with('activeMode', $mode)
            ->with('activeTab', $tab);
    }

    private function attachSortValues(CharacterItem $characterItem, EquipmentService $equipmentService): CharacterItem
    {
        $item = $characterItem->item;
        $rank = $this->itemRankSort($item);

        $affixBonuses = $characterItem->affixStatBonuses();
        $str = (int) ($item->str_bonus ?? 0) + (int) ($affixBonuses['str'] ?? 0);
        $def = (int) ($item->def_bonus ?? 0) + (int) ($affixBonuses['def'] ?? 0);
        $mag = (int) ($item->mag_bonus ?? 0) + (int) ($affixBonuses['mag'] ?? 0);
        $agi = (int) ($item->agi_bonus ?? 0) + (int) ($affixBonuses['agi'] ?? 0);
        $spr = (int) ($item->spr_bonus ?? 0) + (int) ($affixBonuses['spr'] ?? 0);
        $luk = (int) ($item->luk_bonus ?? 0) + (int) ($affixBonuses['luk'] ?? 0);
        $hp = (int) ($item->hp_bonus ?? 0) + (int) ($affixBonuses['hp'] ?? 0);

        $recommend = match ($item->type) {
            'weapon' => $str + ($mag * 0.8) + ($agi * 0.3) + ($luk * 0.2),
            'armor' => $def + ($spr * 0.8) + ($hp * 0.05) + ($agi * 0.2),
            'accessory' => $this->accessoryRecommendScore($equipmentService, $item, $rank, $str, $def, $mag, $agi, $spr, $luk, $hp),
            default => $str + $def + $mag + $agi + $spr + $luk + ($hp * 0.03),
        };

        $characterItem->sort_recommend = round($recommend, 2);
        $characterItem->sort_str = $str;
        $characterItem->sort_def = $def;
        $characterItem->sort_mag = $mag;
        $characterItem->sort_agi = $agi;
        $characterItem->sort_rank = $rank;
        $characterItem->sort_new = $characterItem->created_at?->timestamp ?? $characterItem->id;
        $goldService = app(GoldService::class);
        $characterItem->sell_price = $goldService->equipmentSalePrice($item);
        $characterItem->can_sell = $goldService->canSellEquipment($characterItem);

        return $characterItem;
    }

    private function accessoryRecommendScore(EquipmentService $equipmentService, $item, int $rank, int $str, int $def, int $mag, int $agi, int $spr, int $luk, int $hp): float
    {
        $special = $rank * 5;
        if (!$equipmentService->isMark($item)) {
            $special += 10;
        }
        if ($item->sub_type) {
            $special += 3;
        }

        $balancedBonus = ($str + $def + $mag + $spr) * 0.3;
        return $special + $agi + $luk + ($hp * 0.02) + $balancedBonus;
    }

    private function itemRankSort($item): int
    {
        if (!$item) {
            return 0;
        }

        $storedRank = match ($item->type) {
            'weapon' => $item->weapon_rank_sort,
            'armor' => $item->armor_rank_sort,
            'accessory' => $item->accessory_rank_sort,
            default => null,
        };

        if ($storedRank !== null) {
            return (int) $storedRank;
        }

        $rarity = strtolower((string) ($item->rarity ?? ''));
        $map = [
            'normal' => 0,
            'g' => 1,
            'f' => 2,
            'e' => 3,
            'd' => 4,
            'c' => 5,
            'b' => 6,
            'a' => 7,
            's' => 8,
            'ss' => 9,
            'sss' => 10,
            'rare' => 10,
            'epic' => 11,
            'legend' => 11,
            'legendary' => 11,
            'mythic' => 12,
        ];

        return $map[$rarity] ?? 0;
    }

    private function isProtectedRareWeapon(CharacterItem $characterItem, int $rank): bool
    {
        if (!$characterItem->item || $characterItem->item->type !== 'weapon') {
            return false;
        }

        return $characterItem->acquired_from === 'drop' || $rank >= 8;
    }
}
