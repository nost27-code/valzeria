<?php

namespace App\Http\Controllers;

use App\Services\TownRankingService;
use App\Services\WeeklyWinRankingService;
use Illuminate\Http\Request;

class RankingController extends Controller
{
    public function index(
        Request $request,
        TownRankingService $rankingService,
        WeeklyWinRankingService $weeklyWinRankingService
    ) {
        $boards = $rankingService->boards();
        $activeKey = (string) $request->query('board', array_key_first($boards));
        $activeBoard = $rankingService->board($activeKey);
        $activeKey = $activeBoard['key'] ?? array_key_first($boards);
        $character = $request->user()?->currentCharacter();

        return view('ranking.index', [
            'boards' => $boards,
            'activeKey' => $activeKey,
            'activeBoard' => $activeBoard,
            'weeklyWinStatus' => $character
                ? $weeklyWinRankingService->currentStatusFor($character)
                : null,
        ]);
    }
}
