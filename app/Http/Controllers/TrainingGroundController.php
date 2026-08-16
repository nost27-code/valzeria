<?php

namespace App\Http\Controllers;

use App\Services\JobArtService;
use App\Services\TrainingGroundBattleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class TrainingGroundController extends Controller
{
    private const REQUEST_DELAY_SECONDS = 2;

    public function index(
        JobArtService $jobArtService,
        TrainingGroundBattleService $battleService,
    ): View|RedirectResponse
    {
        $character = Auth::user()?->currentCharacter();
        if (! $character) {
            return redirect()->route('character.select');
        }

        return view('training-ground.index', [
            'character' => $character,
            'maxTurns' => $battleService->maxTurns(),
            'damageCapPercent' => $battleService->incomingDamageCapPercent(),
            'loadouts' => [
                'pve' => [
                    'label' => '通常戦用セット',
                    'description' => '通常探索で使う奥義の発動順や連携を確認する。',
                    'arts' => $jobArtService->battleArtsFor($character, 'pve'),
                ],
                'boss' => [
                    'label' => 'ボス戦用セット',
                    'description' => 'ボスやダンジョン主に使う奥義の発動順や連携を確認する。',
                    'arts' => $jobArtService->battleArtsFor($character, 'boss'),
                ],
            ],
        ]);
    }

    public function battle(Request $request, TrainingGroundBattleService $battleService): RedirectResponse
    {
        $character = Auth::user()?->currentCharacter();
        if (! $character) {
            return redirect()->route('character.select');
        }
        if ($character->is_frozen) {
            return redirect()->route('home')->with('error', 'このアカウントは凍結されています。お問い合わせください。');
        }

        $validated = $request->validate([
            'context' => ['required', 'in:pve,boss'],
        ]);
        if (! Cache::add(
            "training_ground_request_delay:{$character->id}",
            true,
            now()->addSeconds(self::REQUEST_DELAY_SECONDS),
        )) {
            return back()->with('message', '訓練の処理中です。少し待ってからもう一度お試しください。');
        }
        $outcome = $battleService->practice($character, (string) $validated['context']);

        return redirect()
            ->route('training-ground.result')
            ->with('training_ground_result', $outcome);
    }

    public function result(Request $request): View|RedirectResponse
    {
        $character = Auth::user()?->currentCharacter();
        if (! $character) {
            return redirect()->route('character.select');
        }

        $outcome = $request->session()->get('training_ground_result');
        if (! is_array($outcome) || ! isset($outcome['result'])) {
            return redirect()->route('training-ground.index');
        }

        return view('training-ground.result', [
            'character' => $character,
            'outcome' => $outcome,
        ]);
    }
}
