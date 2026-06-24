<?php

namespace App\Http\Controllers;

use App\Models\NpcMaster;
use App\Services\TavernNpcService;
use Illuminate\Support\Facades\Auth;

class TavernController extends Controller
{
    public function index(TavernNpcService $service)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $visit = $service->visit($character);
        $npcs = $service->dailyNpcs($character);
        $roster = $service->roster($character);

        return view('tavern.index', compact('character', 'visit', 'npcs', 'roster'));
    }

    public function talk(NpcMaster $npc, TavernNpcService $service)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $result = $service->talk($character, $npc);

        return view('tavern.talk', [
            'character' => $character,
            'npc' => $npc,
            'encounter' => $result['encounter'],
            'isFirst' => $result['is_first'],
        ]);
    }

    public function roster(TavernNpcService $service)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        return view('tavern.roster', $service->roster($character));
    }

    public function rosterDetail(NpcMaster $npc, TavernNpcService $service)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $encounter = $service->roster($character)['encounters']->get($npc->npc_id);
        if (!$encounter) {
            return redirect()->route('tavern.roster')->with('error', 'まだ出会っていない冒険者です。');
        }

        return view('tavern.detail', compact('npc', 'encounter'));
    }
}
