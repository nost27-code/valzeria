<?php

namespace App\Http\Controllers;

use App\Services\ApothecaryService;
use App\Services\ExplorationSupportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApothecaryController extends Controller
{
    public function index(ApothecaryService $apothecary, ExplorationSupportService $support)
    {
        $this->ensureAvailable($support);
        $character = Auth::user()->currentCharacter();
        abort_unless($character, 404);
        return view('apothecary.index', ['character' => $character, 'recipes' => $apothecary->recipesFor($character), 'activeSupport' => $support->payload($character)]);
    }

    public function craft(Request $request, ApothecaryService $apothecary, ExplorationSupportService $support)
    {
        $this->ensureAvailable($support);
        $character = Auth::user()->currentCharacter(); abort_unless($character, 404);
        $data = $request->validate(['recipe_code' => ['required', 'string'], 'count' => ['required', 'integer', 'min:1', 'max:99']]);
        try { $result = $apothecary->craft($character, $data['recipe_code'], (int) $data['count']); }
        catch (\RuntimeException $e) { return back()->with('error', $e->getMessage()); }
        return back()->with('status', "{$result['name']}を{$result['quantity']}個調合した！");
    }

    public function activate(Request $request, ExplorationSupportService $support)
    {
        $this->ensureAvailable($support);
        $character = Auth::user()->currentCharacter(); abort_unless($character, 404);
        $data = $request->validate([
            'item_key' => ['required', 'string'],
            'auto_renew' => ['nullable', 'boolean'],
            'active_tab' => ['nullable', 'string'],
            'species_lures_eligible' => ['nullable', 'boolean'],
        ]);
        $autoRenew = array_key_exists('auto_renew', $data) && $data['auto_renew'] !== null ? (bool) $data['auto_renew'] : null;
        $speciesLuresEligible = $this->speciesLuresEligible($data);

        try {
            $active = $support->activate($character, $data['item_key'], $autoRenew);
        } catch (\RuntimeException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            return back()->with('error', $e->getMessage())->with('activeTab', $data['active_tab'] ?? null);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => '探索補助品を装備しました。',
                'active' => $active,
                'belongings_html' => $this->renderBelongings($character, $support, $speciesLuresEligible),
            ]);
        }

        return back()->with('status', '探索補助品を装備しました。')->with('activeTab', $data['active_tab'] ?? null);
    }

    public function autoRenew(Request $request, ExplorationSupportService $support)
    {
        $this->ensureAvailable($support);
        $character = Auth::user()->currentCharacter(); abort_unless($character, 404);
        $data = $request->validate([
            'item_key' => ['required', 'string'],
            'auto_renew' => ['required', 'boolean'],
            'species_lures_eligible' => ['nullable', 'boolean'],
        ]);
        $autoRenew = (bool) $data['auto_renew'];
        $speciesLuresEligible = $this->speciesLuresEligible($data);

        try {
            $support->setAutoRenewPreference($character, $data['item_key'], $autoRenew);
        } catch (\RuntimeException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            return back()->with('error', $e->getMessage());
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'auto_renew' => $autoRenew,
                'message' => '自動補充の設定を変更しました。',
                'active' => $support->payload($character),
                'belongings_html' => $this->renderBelongings($character, $support, $speciesLuresEligible),
            ]);
        }
        return back()->with('status', '自動補充の設定を変更しました。');
    }

    public function clear(ExplorationSupportService $support)
    {
        $this->ensureAvailable($support);
        $character = Auth::user()->currentCharacter(); abort_unless($character, 404);
        $support->clear($character);
        return back()->with('status', '探索補助品を外しました。残り戦数は保存されます。');
    }

    /** 探索画面の「もちもの」モーダルなど、簡易表示向けに部分HTMLを返す。 */
    public function belongingsPartial(Request $request, ExplorationSupportService $support)
    {
        $this->ensureAvailable($support);
        $character = Auth::user()->currentCharacter();
        abort_unless($character, 404);
        $data = $request->validate(['species_lures_eligible' => ['nullable', 'boolean']]);
        return response($this->renderBelongings($character, $support, $this->speciesLuresEligible($data)));
    }

    private function renderBelongings($character, ExplorationSupportService $support, bool $speciesLuresEligible = true): string
    {
        return view('apothecary.partials.belongings-list', [
            'belongings' => $support->belongingsFor($character, null, $speciesLuresEligible),
            'speciesLuresEligible' => $speciesLuresEligible,
        ])->render();
    }

    private function speciesLuresEligible(array $data): bool
    {
        return !array_key_exists('species_lures_eligible', $data)
            || $data['species_lures_eligible'] === null
            || (bool) $data['species_lures_eligible'];
    }

    private function ensureAvailable(ExplorationSupportService $support): void
    {
        abort_unless($support->isEnabled(), 404);
    }
}
