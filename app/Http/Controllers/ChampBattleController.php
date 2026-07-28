<?php

namespace App\Http\Controllers;

use App\Services\ChampBattleService;
use App\Services\StorageCapacityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    public function challenge(Request $request, ChampBattleService $champBattleService): RedirectResponse
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        session()->forget('lastChampBattleResult');

        if ($character->is_frozen) {
            return redirect()->route('home')->with('error', 'このアカウントは凍結されています。お問い合わせください。');
        }

        if ($redirect = $this->redirectIfStorageFull($character)) {
            return $redirect;
        }

        if (! $request->has(['expected_champ_character_id', 'expected_champ_appointed_at'])) {
            return back()->with('message', '画面のチャンプ情報が古くなっています。最新の情報を確認して、もう一度挑戦してください。');
        }

        $result = $champBattleService->executeChallenge(
            $character,
            $request->integer('expected_champ_character_id'),
            $request->integer('expected_champ_appointed_at'),
        );
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
        $result = session('champ_battle_result');
        if (! $result) {
            $result = session('lastChampBattleResult');
            $nextAvailableAt = is_array($result) ? ($result['next_available_at'] ?? null) : null;

            try {
                $isReusable = $nextAvailableAt && now()->lt(Carbon::parse($nextAvailableAt));
            } catch (\Throwable) {
                $isReusable = false;
            }

            if (! $isReusable) {
                session()->forget('lastChampBattleResult');

                return redirect()->route('home');
            }
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
