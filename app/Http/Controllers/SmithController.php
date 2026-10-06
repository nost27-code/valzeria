<?php

namespace App\Http\Controllers;

use App\Models\CharacterItem;
use App\Models\Area;
use App\Models\CharacterMaterial;
use App\Models\Material;
use App\Services\BankService;
use App\Services\EquipmentEnhancementService;
use App\Services\EquipmentDecompositionService;
use App\Services\EquipmentEvolutionService;
use App\Services\WeaponTraitWorkshopService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class SmithController extends Controller
{
    public function __construct(
        private EquipmentEvolutionService $equipmentEvolutionService,
        private EquipmentEnhancementService $equipmentEnhancementService,
        private WeaponTraitWorkshopService $weaponTraitWorkshopService,
    ) {
    }

    /**
     * 鍛冶屋の装備強化画面を表示する
     */
    public function enhanceIndex(Request $request)
    {
        $character = Auth::user()->currentCharacter();
        $currentCity = $character->currentCity;
        $typeCounts = $this->equipmentEnhancementService->candidateCounts($character);
        $requestedType = (string) $request->query('type', '');
        $initialType = in_array($requestedType, ['weapon', 'armor', 'accessory'], true)
            ? $requestedType
            : (array_key_first(array_filter($typeCounts)) ?? 'weapon');
        $requestedSort = (string) $request->query('sort', 'recommended');
        $enhanceSort = in_array($requestedSort, ['recommended', 'rank_desc', 'quality_desc', 'enhance_asc', 'enhance_desc', 'name_asc'], true)
            ? $requestedSort
            : 'recommended';
        $browseFilters = [
            'q' => mb_substr($request->string('q')->toString(), 0, 200),
            'status' => in_array($request->query('status'), ['equipped', 'locked', 'ready'], true) ? $request->query('status') : 'all',
            'quality' => in_array($request->query('quality'), ['normal', 'good', 'excellent'], true) ? $request->query('quality') : 'all',
        ];
        $matchingEnhancementCount = $this->equipmentEnhancementService->browseCandidateCount($character, $initialType, $browseFilters);
        $enhanceLimit = max(20, min(
            max(20, $typeCounts[$initialType] ?? 0),
            (int) $request->query('limit', 20),
        ));
        $enhancementCandidates = $this->equipmentEnhancementService->candidatesForType(
            $character,
            $initialType,
            $enhanceSort,
            $enhanceLimit,
            $browseFilters,
        );
        $candidateCount = array_sum($typeCounts);
        $hasMoreEnhancementCandidates = count($enhancementCandidates) < $matchingEnhancementCount;
        $goldSummary = app(BankService::class)->summary($character);

        return view('smith.enhance', compact(
            'character',
            'currentCity',
            'enhancementCandidates',
            'goldSummary',
            'typeCounts',
            'initialType',
            'enhanceSort',
            'enhanceLimit',
            'candidateCount',
            'hasMoreEnhancementCandidates',
            'browseFilters',
            'matchingEnhancementCount',
        ));
    }

    /**
     * 装備強化の解説を表示する
     */
    public function enhanceHelp()
    {
        return $this->helpView('smith.enhance-help');
    }

    /**
     * 武器・防具・装飾品をランクごとの上限まで強化する
     */
    public function enhance(Request $request, CharacterItem $characterItem)
    {
        $character = Auth::user()->currentCharacter();
        $validated = $request->validate([
            'use_bank' => 'nullable|boolean',
        ]);

        try {
            $result = $this->equipmentEnhancementService->enhance(
                $character,
                $characterItem,
                (bool) ($validated['use_bank'] ?? false)
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('blacksmith.index')
            ->with('status', $result['message']);
    }

    /**
     * 合成屋のトップ画面を表示する
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'type' => 'nullable|in:weapon,armor,accessory',
        ]);
        $equipmentType = $validated['type'] ?? null;
        $character = Auth::user()->currentCharacter();
        $currentCity = $character->currentCity;
        $evolutionCandidates = $equipmentType === null
            ? []
            : $this->equipmentEvolutionService->candidates($character, $equipmentType);
        $goldSummary = app(BankService::class)->summary($character);

        return view('smith.index', compact('character', 'currentCity', 'equipmentType', 'evolutionCandidates', 'goldSummary'));
    }

    /**
     * 進化合成の解説を表示する
     */
    public function evolutionHelp()
    {
        return $this->helpView('smith.evolution-help');
    }

    /**
     * 武器の銘・特攻を鍛錬する画面を表示する
     */
    public function traitIndex()
    {
        $character = Auth::user()->currentCharacter();
        $currentCity = $character->currentCity;
        $workshopCandidates = $this->weaponTraitWorkshopService->candidates($character);
        $forgeGoldCosts = config('equipment_affix.forge.single_gold_costs', []);
        $dualDiscountRate = (float) config('equipment_affix.forge.dual_discount_rate', 0.80);
        $goldSummary = app(BankService::class)->summary($character);

        return view('smith.traits', compact('character', 'currentCity', 'workshopCandidates', 'forgeGoldCosts', 'dualDiscountRate', 'goldSummary'));
    }

    /**
     * 銘・特攻を鍛える解説を表示する
     */
    public function traitHelp()
    {
        return $this->helpView('smith.trait-help');
    }

    /**
     * 武器特性の自動判定結果を実行する
     */
    public function traitWorkshopProcess(Request $request)
    {
        $character = Auth::user()->currentCharacter();
        $validated = $request->validate([
            'trait_kind' => 'required|in:engraving,slayer',
            'action' => 'required|in:forge,transfer,dual',
            'base_character_item_id' => 'required|integer',
            'material_character_item_id' => 'required|integer|different:base_character_item_id',
            'use_bank' => 'nullable|boolean',
        ]);

        try {
            $result = $this->weaponTraitWorkshopService->process(
                $character,
                $validated['trait_kind'],
                $validated['action'],
                (int) $validated['base_character_item_id'],
                (int) $validated['material_character_item_id'],
                (bool) ($validated['use_bank'] ?? false),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('blacksmith.traits.index')
            ->with('status', $result['message'])
            ->with('weapon_trait_kind', $validated['trait_kind']);
    }

    /**
     * 旧・銘特攻移しURLから統合画面へ戻す
     */
    public function traitTransferIndex()
    {
        return redirect()->route('blacksmith.traits.index');
    }

    private function helpView(string $view)
    {
        $character = Auth::user()->currentCharacter();
        $currentCity = $character->currentCity;

        return view($view, compact('currentCity'));
    }

    public function sourceArea(Request $request, Area $area)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home');
        }

        $area->loadMissing('city');
        $city = $area->city;
        if (!$city) {
            return redirect()->route('smith.index')->with('error', '入手場所の街が見つかりません。');
        }

        $highestCityOrder = $character->highestCity?->sort_order ?? 0;
        if ((int) $city->sort_order > (int) $highestCityOrder) {
            return redirect()
                ->route('smith.index')
                ->with('error', "{$city->name} はまだ解放されていません。");
        }

        if ((int) $character->current_city_id !== (int) $city->id) {
            $character->current_city_id = $city->id;
            $character->save();
            app(\App\Services\ExplorationStateService::class)->reset($character);
        }

        $materialHunt = null;
        $materialId = (int) $request->query('material', 0);
        $required = (int) $request->query('required', 0);
        if ($materialId > 0 && $required > 0) {
            $material = Material::find($materialId);
            if ($material) {
                $owned = (int) (CharacterMaterial::where('character_id', $character->id)
                    ->where('material_id', $material->id)
                    ->value('quantity') ?? 0);
                $materialHunt = [
                    'material_id' => (int) $material->id,
                    'material_code' => (string) $material->material_code,
                    'material_name' => $material->displayName(),
                    'required' => $required,
                    'started_owned' => $owned,
                    'source_area_id' => (int) $area->id,
                ];
            }
        }

        $sessionValues = [
            'current_location' => 'dungeon',
            'target_area_id' => (int) $area->id,
            'target_area_purpose' => 'material_source',
        ];
        if ($materialHunt) {
            $sessionValues['material_hunt'] = $materialHunt;
        } else {
            session()->forget('material_hunt');
        }

        session($sessionValues);

        return redirect()
            ->to(route('home') . '#dungeon-area-' . $area->id)
            ->with('success', "{$city->name} / {$area->name} の探索場所へ移動しました。");
    }

    /**
     * 武器・防具・装飾品を進化合成する
     */
    public function craft(Request $request)
    {
        $character = Auth::user()->currentCharacter();

        $validated = $request->validate([
            'recipe_type' => 'required|in:weapon,armor,accessory',
            'recipe_id' => 'required|string|max:100',
            'source_character_item_id' => 'nullable|integer',
            'use_bank' => 'nullable|boolean',
        ]);

        try {
            $result = $this->equipmentEvolutionService->evolve(
                $character,
                $validated['recipe_type'],
                $validated['recipe_id'],
                $validated['source_character_item_id'] ?? null,
                (bool) ($validated['use_bank'] ?? false)
            );
        } catch (RuntimeException $e) {
            return redirect()->route('smith.index', ['type' => $validated['recipe_type']])->with('error', $e->getMessage());
        }

        return redirect()->route('smith.index', ['type' => $validated['recipe_type']])->with('status', $result['message']);
    }

    /**
     * 武器・防具・装飾品の分解画面を表示する
     */
    public function disassembleIndex(Request $request, EquipmentDecompositionService $decomposition)
    {
        abort_unless($decomposition->enabled(), 404);
        $validated = $request->validate(['type' => 'nullable|in:weapon,armor,accessory']);
        $equipmentType = $validated['type'] ?? 'weapon';
        $character = Auth::user()->currentCharacter();
        $currentCity = $character->currentCity;
        $decompositionCandidates = $decomposition->candidates($character, $equipmentType);

        return view('smith.disassemble', compact('character', 'currentCity', 'equipmentType', 'decompositionCandidates'));
    }

    public function disassembleConfirm(CharacterItem $characterItem, EquipmentDecompositionService $decomposition)
    {
        abort_unless($decomposition->enabled(), 404);
        $character = Auth::user()->currentCharacter();
        abort_unless((int) $characterItem->character_id === (int) $character->id, 404);
        try {
            $candidate = $decomposition->preview($character, $characterItem);
        } catch (RuntimeException $e) {
            return redirect()->route('smith.disassemble.index')->with('error', $e->getMessage());
        }
        $currentCity = $character->currentCity;

        return view('smith.disassemble-confirm', compact('character', 'currentCity', 'candidate'));
    }

    /**
     * 武器・防具・装飾品を素材へ分解する
     */
    public function disassemble(Request $request, CharacterItem $characterItem, EquipmentDecompositionService $decomposition)
    {
        abort_unless($decomposition->enabled(), 404);
        $character = Auth::user()->currentCharacter();
        abort_unless((int) $characterItem->character_id === (int) $character->id, 404);
        $validator = Validator::make($request->all(), [
            'confirmed' => ['required', 'accepted'],
            'confirmation_hash' => ['required', 'string', 'size:64'],
        ]);
        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => '分解内容を確認してください。', 'errors' => $validator->errors()], 422);
            }

            return redirect()->route('smith.disassemble.confirm', $characterItem)->withErrors($validator);
        }
        $validated = $validator->validated();
        try {
            $result = $decomposition->disassemble($character, $characterItem, $validated['confirmation_hash']);
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return redirect()->route('smith.disassemble.index', ['type' => $characterItem->item->type])
                ->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true] + $result);
        }

        return redirect()->route('smith.disassemble.index', ['type' => $characterItem->item->type])
            ->with('status', $result['message']);
    }
}
