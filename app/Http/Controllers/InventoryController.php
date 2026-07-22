<?php

namespace App\Http\Controllers;

use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\CharacterMonsterMark;
use App\Models\Character;
use App\Models\PlayerValmonEgg;
use App\Services\AdventureSupportService;
use App\Services\ExplorationStaminaService;
use App\Services\ExplorationSupportService;
use App\Services\GoldService;
use App\Services\StorageCapacityService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class InventoryController extends Controller
{
    public function index(AdventureSupportService $supportService, ExplorationStaminaService $staminaService, GoldService $goldService, StorageCapacityService $storageCapacityService, ExplorationSupportService $explorationSupportService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $allMaterials = $character->characterMaterials()
            ->where('quantity', '>', 0)
            ->whereHas('material')
            ->with('material')
            ->get()
            ->sort(fn ($a, $b) => [
                (string) ($a->material?->category ?? ''),
                (string) ($a->material?->name ?? ''),
            ] <=> [
                (string) ($b->material?->category ?? ''),
                (string) ($b->material?->name ?? ''),
            ])
            ->values();

        $keyMaterials = $allMaterials
            ->filter(fn ($row) => $this->isKeyMaterial($row->material))
            ->values();

        $materials = $allMaterials
            ->reject(fn ($row) => $this->isKeyMaterial($row->material))
            ->values();
        [$materialBrowseMetadata, $materialPurposeFilters] = $this->materialBrowseData($materials);

        $marks = CharacterMonsterMark::query()
            ->where('character_id', $character->id)
            ->where('quantity', '>', 0)
            ->whereHas('monsterMark')
            ->with(['monsterMark.enemy'])
            ->get()
            ->sortBy(fn ($row) => (string) ($row->monsterMark?->mark_name ?? ''))
            ->values();

        $allEquipmentItems = $character->characterItems()
            ->whereHas('item', fn ($query) => $query->whereIn('type', ['weapon', 'armor', 'accessory']))
            ->with('item')
            ->get()
            ->sort(fn (CharacterItem $a, CharacterItem $b) => [
                (string) ($a->item?->type ?? ''),
                -1 * $this->itemRankSort($a),
                (string) ($a->item?->name ?? ''),
            ] <=> [
                (string) ($b->item?->type ?? ''),
                -1 * $this->itemRankSort($b),
                (string) ($b->item?->name ?? ''),
            ])
            ->map(function (CharacterItem $row) use ($goldService) {
                $row->sell_price = $goldService->equipmentSalePrice($row->item);
                $row->can_sell = $goldService->canSellEquipment($row);

                return $row;
            })
            ->values();

        $keyEquipmentItems = $allEquipmentItems
            ->filter(fn (CharacterItem $row) => $this->isKeyItem($row))
            ->values();

        $equipmentItems = $allEquipmentItems
            ->reject(fn (CharacterItem $row) => $this->isKeyItem($row))
            ->values();

        $equipmentGroups = [
            'weapon' => $equipmentItems->filter(fn ($row) => $row->item?->type === 'weapon')->values(),
            'armor' => $equipmentItems->filter(fn ($row) => $row->item?->type === 'armor')->values(),
            'accessory' => $equipmentItems->filter(fn ($row) => $row->item?->type === 'accessory')->values(),
        ];

        $supportItems = $this->recoveryConsumablesFor($character)
            ->concat($explorationSupportService->isEnabled() ? $this->explorationSupportConsumablesFor($character) : collect())
            ->concat(collect($supportService->ownedConsumablesFor($character)))
            ->values();
        $staminaSummary = $staminaService->summary($character);

        $keyItems = $keyMaterials
            ->map(fn ($row) => [
                'kind' => 'material',
                'name' => (string) ($row->material?->displayName() ?? '不明な大事なもの'),
                'category' => (string) ($row->material?->category ?? '大事なもの'),
                'rarity' => (string) ($row->material?->rarity ?? '-'),
                'description' => (string) ($row->material?->main_use ?? ''),
                'icon' => $this->keyMaterialIcon($row->material),
                'icon_image' => ($this->keyMaterialIcon($row->material) === '🏆') ? 'images/icon/icon_010.webp' : null,
                'quantity' => (int) $row->quantity,
            ])
            ->concat($keyEquipmentItems->map(fn (CharacterItem $row) => [
                'kind' => 'item',
                'name' => $row->displayName(),
                'category' => (string) ($row->item?->sub_type ?? '大事なもの'),
                'rarity' => (string) ($row->item?->rarity ?? '-'),
                'description' => (string) ($row->item?->description ?? ''),
                'icon' => '🔑',
                'icon_image' => null,
                'quantity' => 1,
            ]))
            ->concat(PlayerValmonEgg::with('master')
                ->where('character_id', $character->id)
                ->where('is_hatched', false)
                ->where('is_lost', false)
                ->whereNotNull('stored_at')
                ->orderByDesc('stored_at')
                ->get()
                ->map(fn (PlayerValmonEgg $egg) => [
                    'kind' => 'valmon_egg',
                    'name' => ($egg->master?->name ?? 'ヴァルモン') . 'の卵',
                    'category' => 'ヴァルモンの卵',
                    'rarity' => (string) ($egg->master?->rarity ?? '-'),
                    'description' => '自分の商店での販売は準備中です。',
                    'icon' => '🥚',
                    'icon_image' => 'images/icon/icon_038.webp',
                    'quantity' => 1,
                ]))
            ->sortBy(fn ($entry) => $entry['name'])
            ->values();

        $storageSummary = $this->buildStorageSummary($character, $materials, $marks, $equipmentGroups, $keyItems, $supportItems, $storageCapacityService);

        return view('inventory.index', compact(
            'character',
            'materials',
            'materialBrowseMetadata',
            'materialPurposeFilters',
            'marks',
            'equipmentGroups',
            'supportItems',
            'staminaSummary',
            'keyItems',
            'storageSummary'
        ));
    }

    private function materialBrowseData(Collection $materials): array
    {
        $definitions = [
            'crafting' => ['label' => '合成', 'keywords' => ['進化', '合成']],
            'smithing' => ['label' => '鍛冶・強化', 'keywords' => ['鍛冶', '強化']],
            'exchange' => ['label' => '交換所', 'keywords' => ['交換所']],
            'brewing' => ['label' => '調合', 'keywords' => ['調合']],
            'market' => ['label' => '市場', 'keywords' => []],
            'cash' => ['label' => '換金用', 'keywords' => []],
        ];

        $metadata = $materials->mapWithKeys(function (CharacterMaterial $row) use ($definitions) {
            $material = $row->material;
            $usageTags = is_array($material?->usage_tags) ? $material->usage_tags : [];
            $purposeText = implode(' ', array_filter([
                (string) ($material?->main_use ?? ''),
                ...$usageTags,
            ]));

            $purposes = collect($definitions)
                ->filter(function (array $definition, string $key) use ($purposeText, $material) {
                    if ($key === 'market') {
                        return (bool) ($material?->is_tradable) && (string) ($material?->trade_policy ?? '') === 'marketable';
                    }

                    if ($key === 'cash') {
                        return (bool) ($material?->is_cash_item);
                    }

                    return collect($definition['keywords'])
                        ->contains(fn (string $keyword) => str_contains($purposeText, $keyword));
                })
                ->keys()
                ->values()
                ->all();

            return [(int) $row->id => [
                'name' => (string) ($material?->displayName() ?? ''),
                'search_text' => implode(' ', array_filter([
                    (string) ($material?->displayName() ?? ''),
                    (string) ($material?->category ?? ''),
                    $purposeText,
                ])),
                'purposes' => $purposes,
                'created_at' => (int) ($row->updated_at?->getTimestamp() ?? $row->created_at?->getTimestamp() ?? 0),
            ]];
        })->all();

        $availablePurposes = collect($metadata)
            ->pluck('purposes')
            ->flatten()
            ->flip();

        $filters = collect($definitions)
            ->filter(fn (array $definition, string $key) => $availablePurposes->has($key))
            ->map(fn (array $definition, string $key) => ['key' => $key, 'label' => $definition['label']])
            ->values()
            ->all();

        return [$metadata, $filters];
    }

    private function buildStorageSummary(Character $character, Collection $materials, Collection $marks, array $equipmentGroups, Collection $keyItems, Collection $supportItems, StorageCapacityService $storageCapacityService): array
    {
        $weaponCount = $equipmentGroups['weapon']->count();
        $armorCount = $equipmentGroups['armor']->count();
        $accessoryCount = $equipmentGroups['accessory']->count();
        $keyItemCount = $keyItems->sum('quantity');
        $supportItemCount = $supportItems->sum('quantity');
        $ownedItemCount = $keyItemCount + $supportItemCount;

        $categories = [
            'material' => ['label' => '素材', 'count' => $materials->sum('quantity'), 'icon' => '💎', 'icon_image' => 'icon/icon_011.webp'],
            'weapon' => ['label' => '武器', 'count' => $weaponCount, 'icon' => '🗡️', 'icon_image' => 'icon/icon_006.webp'],
            'armor' => ['label' => '防具', 'count' => $armorCount, 'icon' => '🛡️', 'icon_image' => 'icon/icon_007.webp'],
            'accessory' => ['label' => '装飾品', 'count' => $accessoryCount, 'icon' => '💍', 'icon_image' => 'icon/icon_008.webp'],
        ];

        return [
            'total' => collect($categories)->sum('count') + $ownedItemCount,
            'material_storage_total' => (int) ($categories['material']['count'] ?? 0),
            'material_storage_limit' => $storageCapacityService->materialLimit($character),
            'material_storage_types' => $materials->count(),
            'equipment_storage_total' => $weaponCount + $armorCount + $accessoryCount,
            'equipment_storage_limit' => $storageCapacityService->equipmentLimit($character),
            'key_item_total' => $ownedItemCount,
            'key_item_types' => $supportItems->count() + $keyItems->count(),
            'support_item_total' => $supportItemCount,
            'support_item_types' => $supportItems->count(),
            'boss_key_item_total' => $keyItemCount,
            'boss_key_item_types' => $keyItems->count(),
            'mark_collection_total' => $marks->sum('quantity'),
            'mark_collection_types' => $marks->count(),
            'categories' => $categories,
        ];
    }

    private function recoveryConsumablesFor(Character $character): Collection
    {
        return CharacterItem::query()
            ->selectRaw('item_id, COUNT(*) as quantity')
            ->where('character_id', $character->id)
            ->where('is_equipped', false)
            ->whereHas('item', fn ($query) => $query
                ->where('type', 'consumable')
                ->whereIn('name', ['薬草', '回復薬', '魔力水']))
            ->with('item')
            ->groupBy('item_id')
            ->get()
            ->sortBy(fn (CharacterItem $row) => match ((string) ($row->item?->name ?? '')) {
                '薬草' => 10,
                '回復薬' => 20,
                '魔力水' => 30,
                default => 99,
            })
            ->map(fn (CharacterItem $row) => [
                'key' => 'recovery_item_' . (int) $row->item_id,
                'name' => (string) ($row->item?->name ?? '回復アイテム'),
                'category' => '回復アイテム',
                'description' => (string) ($row->item?->description ?? ''),
                'icon_image' => null,
                'effect_type' => 'exploration_recovery_item',
                'effect_value' => 0,
                'quantity' => (int) $row->quantity,
                'can_use' => false,
                'use_label' => '',
                'use_note' => '探索中に使用できます。持ち込みは各10個までです。',
            ])
            ->values();
    }

    private function explorationSupportConsumablesFor(Character $character): Collection
    {
        $definitionsByName = collect(ExplorationSupportService::ITEMS)->keyBy('name');

        return CharacterItem::query()
            ->selectRaw('item_id, COUNT(*) as quantity')
            ->where('character_id', $character->id)
            ->where('is_equipped', false)
            ->whereHas('item', fn ($query) => $query
                ->where('type', 'consumable')
                ->whereIn('name', $definitionsByName->keys()))
            ->with('item')
            ->groupBy('item_id')
            ->get()
            ->sortBy(fn (CharacterItem $row) => array_search((string) ($row->item?->name ?? ''), $definitionsByName->keys()->all(), true))
            ->map(fn (CharacterItem $row) => [
                'key' => 'exploration_support_item_' . (int) $row->item_id,
                'name' => (string) ($row->item?->name ?? '探索補助品'),
                'category' => '探索補助品',
                'description' => (string) ($row->item?->description ?? ''),
                'icon_image' => null,
                'effect_type' => 'exploration_support_item',
                'effect_value' => 0,
                'quantity' => (int) $row->quantity,
                'can_use' => false,
                'use_label' => '',
                'use_note' => '薬屋で使用・自動継続を設定できます。1個で30戦有効です。',
                'manage_url' => route('apothecary.index'),
            ])
            ->values();
    }

    private function isKeyMaterial(?object $material): bool
    {
        if (!$material) {
            return false;
        }

        $name = (string) ($material->name ?? '');
        $category = (string) ($material->category ?? '');
        $mainUse = (string) ($material->main_use ?? '');
        $materialType = (string) ($material->material_type ?? '');
        $categoryId = (string) ($material->category_id ?? '');

        return $materialType === 'boss_unique'
            || $categoryId === 'boss_unique'
            || str_contains($category, '討伐証')
            || str_contains($category, 'ボス特異素材')
            || str_contains($mainUse, 'レシピ解放キー')
            || str_contains($mainUse, '解放キー')
            || str_ends_with($name, 'の刻印')
            || str_ends_with($name, 'の王印')
            || str_ends_with($name, 'の神印');
    }

    private function keyMaterialIcon(?object $material): string
    {
        if (!$material) {
            return '🔑';
        }

        $materialType = (string) ($material->material_type ?? '');
        $categoryId = (string) ($material->category_id ?? '');
        $category = (string) ($material->category ?? '');

        return $materialType === 'boss_unique'
            || $categoryId === 'boss_unique'
            || str_contains($category, 'ボス特異素材')
                ? '🏆'
                : '🔑';
    }

    private function isKeyItem(CharacterItem $characterItem): bool
    {
        $item = $characterItem->item;
        if (!$item) {
            return false;
        }

        $name = (string) ($item->name ?? '');
        $subType = (string) ($item->sub_type ?? '');

        return in_array($subType, ['刻印', '王印', '神印'], true)
            || str_ends_with($name, 'の刻印')
            || str_ends_with($name, 'の王印')
            || str_ends_with($name, 'の神印');
    }

    private function itemRankSort(CharacterItem $characterItem): int
    {
        $item = $characterItem->item;
        if (!$item) {
            return 0;
        }

        return (int) match ($item->type) {
            'weapon' => $item->weapon_rank_sort ?? 0,
            'armor' => $item->armor_rank_sort ?? 0,
            'accessory' => $item->accessory_rank_sort ?? 0,
            default => 0,
        };
    }

    public function sell(Request $request, GoldService $goldService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $validated = $request->validate([
            'character_material_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $characterMaterial = CharacterMaterial::query()
            ->where('character_id', $character->id)
            ->where('id', $validated['character_material_id'])
            ->with('material')
            ->firstOrFail();

        if ($this->isKeyMaterial($characterMaterial->material)) {
            return redirect()->route('inventory.index')->with('error', '大事なものは売却できません。');
        }

        try {
            $result = $goldService->sellMaterial($character, $characterMaterial, (int) $validated['quantity']);
        } catch (\RuntimeException $e) {
            return redirect()->route('inventory.index')->with('error', $e->getMessage());
        }

        return redirect()
            ->route('inventory.index')
            ->with('status', "{$result['name']}を{$result['quantity']}個売却し、" . number_format($result['amount']) . 'Gを得ました。');
    }

    public function discardMaterial(Request $request, CharacterMaterial $characterMaterial)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character || (int) $characterMaterial->character_id !== (int) $character->id) {
            abort(404);
        }

        $characterMaterial->loadMissing('material');
        if ($this->isKeyMaterial($characterMaterial->material)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => '大事なものは捨てられません。'], 422);
            }

            return redirect()
                ->route('inventory.index')
                ->with('error', '大事なものは捨てられません。');
        }

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:' . max(1, (int) $characterMaterial->quantity)],
        ]);

        $discardQuantity = (int) $validated['quantity'];
        $materialName = (string) ($characterMaterial->material?->displayName() ?? '素材');
        $remaining = max(0, (int) $characterMaterial->quantity - $discardQuantity);

        if ($remaining <= 0) {
            $characterMaterial->delete();
        } else {
            $characterMaterial->forceFill(['quantity' => $remaining])->save();
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => "{$materialName}を{$discardQuantity}個捨てました。",
                'discarded_quantity' => $discardQuantity,
                'remaining_quantity' => $remaining,
            ]);
        }

        return redirect()
            ->route('inventory.index')
            ->with('status', "{$materialName}を{$discardQuantity}個捨てました。");
    }

    public function useSupportItem(Request $request, string $itemKey, AdventureSupportService $supportService, ExplorationStaminaService $staminaService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'キャラクターが見つかりません。'], 404);
            }

            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $result = $supportService->useConsumable($character, $itemKey);
        $character->refresh();

        if ($request->expectsJson()) {
            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'stamina' => $staminaService->summary($character),
                'support_items' => $supportService->ownedConsumablesFor($character),
            ], $result['success'] ? 200 : 422);
        }

        return redirect()
            ->route('inventory.index')
            ->with($result['success'] ? 'status' : 'error', $result['message']);
    }
}
