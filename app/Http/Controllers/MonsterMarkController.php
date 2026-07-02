<?php

namespace App\Http\Controllers;

use App\Services\MonsterMarkService;
use Illuminate\Support\Facades\Auth;

class MonsterMarkController extends Controller
{
    public function index(MonsterMarkService $monsterMarkService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $collection = $monsterMarkService->collectionFor($character);
        $groups = $monsterMarkService->groupedCollectionFor($character, $collection);
        $summary = $monsterMarkService->summary($character);

        return view('monster-marks.index', compact('character', 'collection', 'groups', 'summary'));
    }
}
