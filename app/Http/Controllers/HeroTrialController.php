<?php

namespace App\Http\Controllers;

use App\Services\CharacterIconSetService;
use App\Services\CharacterStatusService;
use App\Services\HeroTrialService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class HeroTrialController extends Controller
{
    private const REQUEST_DELAY_SECONDS = 3;

    public function index(HeroTrialService $trialService): View|RedirectResponse
    {
        if (! $trialService->isEnabled()) {
            return redirect()->route('home')->with('error', '英雄試練は現在公開されていません。');
        }

        $character = Auth::user()?->currentCharacter();
        if (! $character) {
            return redirect()->route('home');
        }

        $trials = $trialService->trialFacilitiesFor($character, (int) $character->current_city_id);
        if ($trials === []) {
            return redirect()->route('home')->with('error', '現在挑戦できる英雄試練はありません。');
        }

        session(['current_location' => 'dungeon']);

        return view('hero-trials.index', [
            'trials' => $trialService->hallFacilitiesFor($character, (int) $character->current_city_id),
        ]);
    }

    public function challenge(string $trialKey, HeroTrialService $trialService): RedirectResponse
    {
        $character = Auth::user()?->currentCharacter();
        if (! $character) {
            return redirect()->route('home');
        }

        session(['current_location' => 'dungeon']);

        if (! Cache::add(
            "hero_trial_request_delay:{$character->id}",
            true,
            now()->addSeconds(self::REQUEST_DELAY_SECONDS)
        )) {
            return redirect()->route('home')
                ->with('error', '試練の処理中です。少し待ってからもう一度お試しください。');
        }

        try {
            $outcome = $trialService->challenge($character, $trialKey);
        } catch (DomainException $exception) {
            return redirect()->route('home')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('hero-trials.result', ['trialKey' => $trialKey])
            ->with('heroTrialData', $outcome);
    }

    public function result(
        Request $request,
        string $trialKey,
        HeroTrialService $trialService,
        CharacterStatusService $statusService,
        CharacterIconSetService $iconSetService,
    ): View|RedirectResponse
    {
        if (! $trialService->isEnabled()) {
            return redirect()->route('home')->with('error', '英雄試練は現在公開されていません。');
        }

        $outcome = $request->session()->get('heroTrialData');
        if (! is_array($outcome) || (string) ($outcome['trial_key'] ?? '') !== $trialKey) {
            return redirect()->route('home');
        }

        $character = Auth::user()?->currentCharacter();
        if (! $character) {
            return redirect()->route('home');
        }
        $character->loadMissing('jobClass');
        $jobLevel = (int) ($character->jobHistories()
            ->where('job_class_id', $character->current_job_id)
            ->value('job_level') ?? 1);

        return view('hero-trials.result', [
            'outcome' => $outcome,
            'character' => $character,
            'finalStats' => $statusService->getFinalStats($character),
            'jobLevel' => $jobLevel,
            'characterBattleImagePath' => $iconSetService->pathFor($character, 'battle'),
            'characterVictoryImagePath' => $iconSetService->pathFor($character, 'victory'),
            'characterDefeatImagePath' => $iconSetService->pathFor($character, 'defeat'),
        ]);
    }
}
