<?php

namespace App\Http\Controllers;

use App\Models\CharacterItem;
use App\Models\Area;
use App\Models\CharacterMaterial;
use App\Models\Material;
use App\Services\EquipmentEnhancementService;
use App\Services\EquipmentEvolutionService;
use App\Services\WeaponTraitWorkshopService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function enhanceIndex()
    {
        $character = Auth::user()->currentCharacter();
        $currentCity = $character->currentCity;
        $enhancementCandidates = $this->equipmentEnhancementService->candidates($character);

        return view('smith.enhance', compact('character', 'currentCity', 'enhancementCandidates'));
    }

    /**
     * 装備強化の解説を表示する
     */
    public function enhanceHelp()
    {
        return $this->helpView('smith.enhance-help');
    }

    /**
     * 武器・防具・装飾品を +1〜+5 に強化する
     */
    public function enhance(CharacterItem $characterItem)
    {
        $character = Auth::user()->currentCharacter();

        try {
            $result = $this->equipmentEnhancementService->enhance($character, $characterItem);
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
    public function index()
    {
        $character = Auth::user()->currentCharacter();
        $currentCity = $character->currentCity;
        $evolutionCandidates = $this->equipmentEvolutionService->candidates($character);

        return view('smith.index', compact('character', 'currentCity', 'evolutionCandidates'));
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

        return view('smith.traits', compact('character', 'currentCity', 'workshopCandidates', 'forgeGoldCosts', 'dualDiscountRate'));
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
        ]);

        try {
            $result = $this->weaponTraitWorkshopService->process(
                $character,
                $validated['trait_kind'],
                $validated['action'],
                (int) $validated['base_character_item_id'],
                (int) $validated['material_character_item_id'],
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
        ]);

        try {
            $result = $this->equipmentEvolutionService->evolve(
                $character,
                $validated['recipe_type'],
                $validated['recipe_id'],
                $validated['source_character_item_id'] ?? null
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('smith.index')->with('status', $result['message']);
    }

    /**
     * 武器・防具・装飾品の分解画面を表示する
     */
    public function disassembleIndex()
    {
        return redirect()
            ->route('equipment.index')
            ->with('error', '装備分解は現在停止中です。不要な装備は売却してください。');
    }

    /**
     * 武器・防具・装飾品を素材へ分解する
     */
    public function disassemble(Request $request, CharacterItem $characterItem)
    {
        $message = '装備分解は現在停止中です。不要な装備は売却してください。';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], 422);
        }

        return redirect()
            ->route('equipment.index')
            ->with('error', $message);
    }
}
