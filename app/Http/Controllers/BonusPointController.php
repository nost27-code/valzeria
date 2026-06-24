<?php

namespace App\Http\Controllers;

use App\Services\BonusPointService;
use App\Services\CharacterStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class BonusPointController extends Controller
{
    public function index(BonusPointService $bonusPointService, CharacterStatusService $statusService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $character->refresh();
        $finalStats = $statusService->getFinalStats($character);
        $statOptions = $bonusPointService->statOptions();
        $pointsPerLevel = $bonusPointService->pointsPerLevel();

        return view('bonus-points.index', compact('character', 'finalStats', 'statOptions', 'pointsPerLevel'));
    }

    public function allocate(Request $request, BonusPointService $bonusPointService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'キャラクターが見つかりません。'], 404);
            }

            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        if ($request->has('allocations')) {
            $validated = $request->validate([
                'allocations' => ['required', 'array'],
                'allocations.*' => ['integer', 'min:0', 'max:999'],
            ]);

            try {
                $result = $bonusPointService->allocateMany($character, $validated['allocations']);
            } catch (Throwable $e) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }

                return redirect()->route('bonus-points.index')->with('error', $e->getMessage());
            }

            $message = "{$result['spent']} BPを割り振りました。残りBP: {$result['remaining']}";

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'result' => $result,
                ]);
            }

            return redirect()->route('bonus-points.index')->with('status', $message);
        }

        $validated = $request->validate([
            'stat' => ['required', 'string'],
            'points' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        try {
            $result = $bonusPointService->allocate($character, $validated['stat'], (int) $validated['points']);
        } catch (Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return redirect()->route('bonus-points.index')->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => "{$result['label']}に +{$result['gain']} 割り振りました。残りBP: {$result['remaining']}",
                'result' => $result,
            ]);
        }

        return redirect()
            ->route('bonus-points.index')
            ->with('status', "{$result['label']}に +{$result['gain']} 割り振りました。残りBP: {$result['remaining']}");
    }
}
