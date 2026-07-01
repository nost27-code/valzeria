<?php

namespace App\Http\Controllers;

use App\Services\TownRankingService;
use Illuminate\Http\Request;

class RankingController extends Controller
{
    public function index(Request $request, TownRankingService $rankingService)
    {
        $boards = $rankingService->boards();
        $activeKey = (string) $request->query('board', array_key_first($boards));
        $activeBoard = $rankingService->board($activeKey);
        $activeKey = $activeBoard['key'] ?? array_key_first($boards);

        return view('ranking.index', [
            'boards' => $boards,
            'activeKey' => $activeKey,
            'activeBoard' => $activeBoard,
        ]);
    }
}
