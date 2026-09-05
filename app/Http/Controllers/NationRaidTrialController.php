<?php

namespace App\Http\Controllers;

use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\NationRaidTrialService;
use App\Services\Nation\Raid\NationRaidStrategyPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

final class NationRaidTrialController extends Controller
{
    public function index(NationRaidTrialService $service, NationRaidStrategyPolicy $strategies): View
    {
        abort_unless($service->isEnabled(), 404);

        $lastResult = session('nation_raid_trial_result');
        $screen = $service->screen(Auth::user()->currentCharacter());

        return view('nation-raid.trial', [
            'screen' => $screen,
            'lastResult' => $lastResult,
            'selection' => [
                'strategy' => $strategies->forDisplay(old('strategy', $lastResult['strategy'] ?? null)),
            ],
        ]);
    }

    public function battle(Request $request, NationRaidTrialService $service, NationRaidStrategyPolicy $strategies): RedirectResponse
    {
        abort_unless($service->isEnabled(), 404);

        $validated = $request->validate([
            'strategy' => $strategies->validationRules(),
        ], [
            'strategy.*' => '作戦を選んでください。',
        ]);

        try {
            $result = $service->fight(
                Auth::user()->currentCharacter(),
                $validated['strategy'] ?? NationRaidRules::STRATEGY_BOSS_SET,
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()->route('nation-raid.trial')->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('nation-raid.trial')
            ->with('nation_raid_trial_result', $result);
    }
}
