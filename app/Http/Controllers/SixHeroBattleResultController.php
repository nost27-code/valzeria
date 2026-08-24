<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SixHeroBattleResultController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        abort_unless(
            (bool) config('features.six_hero_ui_enabled', false),
            404,
        );
        $request->session()->put([
            'current_location' => 'colosseum',
            'colosseum_mode' => \App\Livewire\ArenaHub::MODE_SIX_HEROES,
        ]);

        $payload = $request->session()->get('six_hero_battle_result');
        if (! is_array($payload)
            || ! is_array($payload['battleResult'] ?? null)
            || ! is_array($payload['battleLogs'] ?? null)
        ) {
            return redirect()->route('six-heroes.index');
        }

        return view('six-heroes.battle-result', [
            'battleResult' => $payload['battleResult'],
            'battleLogs' => $payload['battleLogs'],
        ]);
    }
}
