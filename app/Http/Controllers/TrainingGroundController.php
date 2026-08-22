<?php

namespace App\Http\Controllers;

use App\Models\ArenaRanking;
use App\Models\Character;
use App\Services\JobArtService;
use App\Services\TrainingGroundBattleService;
use App\Services\TrainingGroundPvpBattleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TrainingGroundController extends Controller
{
    private const REQUEST_DELAY_SECONDS = 2;

    public function index(
        Request $request,
        JobArtService $jobArtService,
        TrainingGroundBattleService $battleService,
    ): View|RedirectResponse {
        $character = Auth::user()?->currentCharacter();
        if (! $character) {
            return redirect()->route('character.select');
        }

        $opponentSearch = trim((string) $request->query('opponent_search', ''));
        if (mb_strlen($opponentSearch) > 50) {
            $opponentSearch = mb_substr($opponentSearch, 0, 50);
        }
        $searchResults = collect();
        if ($opponentSearch !== '') {
            $escapedSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $opponentSearch);
            $searchResults = Character::query()
                ->visibleToPublic()
                ->where('id', '!=', $character->id)
                ->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$escapedSearch.'%'])
                ->with(['currentJob', 'arenaRanking'])
                ->orderBy('name')
                ->limit(10)
                ->get();
        }

        $rankingOpponents = ArenaRanking::query()
            ->whereHas('character', fn ($query) => $query
                ->visibleToPublic()
                ->where('id', '!=', $character->id))
            ->with('character.currentJob')
            ->orderBy('rank')
            ->limit(100)
            ->get();

        $selectedOpponent = null;
        $selectedOpponentId = filter_var($request->query('opponent_id'), FILTER_VALIDATE_INT);
        if (is_int($selectedOpponentId) && $selectedOpponentId > 0) {
            $selectedOpponent = Character::query()
                ->visibleToPublic()
                ->where('id', '!=', $character->id)
                ->with(['currentJob', 'arenaRanking'])
                ->find($selectedOpponentId);
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
            'opponentSearch' => $opponentSearch,
            'searchResults' => $searchResults,
            'rankingOpponents' => $rankingOpponents,
            'selectedOpponent' => $selectedOpponent,
            'pvpSetEnabled' => $jobArtService->pvpSetEnabled(),
        ]);
    }

    public function battle(
        Request $request,
        TrainingGroundBattleService $battleService,
        TrainingGroundPvpBattleService $pvpBattleService,
    ): RedirectResponse {
        $character = Auth::user()?->currentCharacter();
        if (! $character) {
            return redirect()->route('character.select');
        }
        if ($character->is_frozen) {
            return redirect()->route('home')->with('error', 'このアカウントは凍結されています。お問い合わせください。');
        }

        $validated = $request->validate([
            'context' => ['required', 'in:pve,boss,pvp'],
            'opponent_id' => ['nullable', 'required_if:context,pvp', 'integer'],
        ]);
        $opponent = null;
        if ($validated['context'] === 'pvp') {
            $opponent = Character::query()
                ->visibleToPublic()
                ->where('id', '!=', $character->id)
                ->find((int) $validated['opponent_id']);
            if (! $opponent) {
                throw ValidationException::withMessages([
                    'opponent_id' => '対戦相手を選び直してください。',
                ]);
            }
        }
        if (! Cache::add(
            "training_ground_request_delay:{$character->id}",
            true,
            now()->addSeconds(self::REQUEST_DELAY_SECONDS),
        )) {
            return back()->with('message', '訓練の処理中です。少し待ってからもう一度お試しください。');
        }
        $outcome = $opponent
            ? $pvpBattleService->practice($character, $opponent)
            : $battleService->practice($character, (string) $validated['context']);

        return redirect()
            ->route('training-ground.result')
            ->with('training_ground_result', $outcome);
    }

    public function result(Request $request, JobArtService $jobArtService): View|RedirectResponse
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
            'pvpSetEnabled' => $jobArtService->pvpSetEnabled(),
        ]);
    }
}
