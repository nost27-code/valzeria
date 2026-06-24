<?php

namespace App\Http\Controllers;

use App\Services\ChampBattleService;
use App\Services\StorageCapacityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ChampBattleController extends Controller
{
    public function confirm(ChampBattleService $champBattleService): View|RedirectResponse
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        if ($redirect = $this->redirectIfStorageFull($character)) {
            return $redirect;
        }

        return view('champ.confirm', [
            'summary' => $champBattleService->summary($character),
        ]);
    }

    public function challenge(ChampBattleService $champBattleService): RedirectResponse
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        if ($character->is_frozen) {
            return redirect()->route('home')->with('error', 'このアカウントは凍結されています。お問い合わせください。');
        }

        if ($redirect = $this->redirectIfStorageFull($character)) {
            return $redirect;
        }

        $result = $champBattleService->executeChallenge($character);
        if (empty($result['ok'])) {
            return back()
                ->with('message', $result['message'] ?? '今はチャンプに挑戦できません。');
        }

        return redirect()
            ->route('champ.result')
            ->with('champ_battle_result', $result);
    }

    public function result(): View|RedirectResponse
    {
        $result = session('champ_battle_result') ?? session('lastChampBattleResult');
        if (!$result) {
            return redirect()->route('home');
        }

        session(['lastChampBattleResult' => $result]);

        return view('champ.result', ['result' => $result]);
    }

    private function redirectIfStorageFull($character): ?RedirectResponse
    {
        $storageCapacity = app(StorageCapacityService::class);
        if (!$storageCapacity->isFull($character)) {
            return null;
        }

        return redirect()
            ->route('home')
            ->with('message', $storageCapacity->fullMessageHtml($character));
    }
}
