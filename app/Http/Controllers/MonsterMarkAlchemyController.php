<?php

namespace App\Http\Controllers;

use App\Services\CharacterStatusService;
use App\Services\MonsterMarkAlchemyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class MonsterMarkAlchemyController extends Controller
{
    public function index(
        MonsterMarkAlchemyService $alchemyService,
        CharacterStatusService $statusService,
    ) {
        $character = Auth::user()->currentCharacter();
        if (! $character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $summary = $alchemyService->summary($character);
        $statOptions = $alchemyService->statOptions();
        $finalStats = $statusService->getFinalStats($character);
        $requestToken = (string) Str::uuid();

        return view('monster-mark-alchemy.index', compact(
            'character',
            'summary',
            'statOptions',
            'finalStats',
            'requestToken',
        ));
    }

    public function refine(Request $request, MonsterMarkAlchemyService $alchemyService)
    {
        $character = Auth::user()->currentCharacter();
        if (! $character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $validated = $request->validate([
            'stat' => ['required', 'string', 'in:hp,mp,str,def,agi,mag,spr,luk'],
            'request_token' => ['required', 'uuid'],
        ]);

        try {
            $result = $alchemyService->refine(
                $character,
                (string) $validated['stat'],
                (string) $validated['request_token'],
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()->route('monster-mark-alchemy.index')->with('error', $e->getMessage());
        }

        $message = $result['idempotent']
            ? 'この印錬成はすでに完了しています。'
            : "余剰印を{$result['spent_marks']}個錬成し、{$result['label']}が+{$result['gain']}上がりました。";

        return redirect()->route('monster-mark-alchemy.index')->with('status', $message);
    }
}
