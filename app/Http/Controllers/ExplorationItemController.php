<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Services\ExplorationItemService;
use App\Services\CharacterStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExplorationItemController extends Controller
{
    public function use(Item $item, ExplorationItemService $service, CharacterStatusService $statusService, Request $request)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'キャラクターが見つかりません。'], 404);
            }

            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $result = $service->use($character, $item);
        $character->refresh();

        if ($request->expectsJson()) {
            $stats = $statusService->getFinalStats($character);

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'hp' => [
                    'current' => (int) $character->current_hp,
                    'max' => max(1, (int) ($stats['max_hp'] ?? $character->hp_base)),
                ],
                'mp' => [
                    'current' => (int) $character->current_mp,
                    'max' => max(1, (int) ($stats['max_mp'] ?? $character->mp_base)),
                ],
                'items' => $service->carriedItems($character),
            ], $result['success'] ? 200 : 422);
        }

        return redirect()
            ->route('battle.result')
            ->with($result['success'] ? 'status' : 'error', $result['message']);
    }
}
