<?php

namespace App\Http\Controllers;

use App\Models\Enemy;
use App\Services\EnemyBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class EnemyBookController extends Controller
{
    public function index(EnemyBookService $enemyBookService)
    {
        $character = Auth::user()->currentCharacter();
        if (! $character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $book = $enemyBookService->bookFor($character);
        $initialEnemy = isset($book['initial_enemy_id'])
            ? Enemy::query()->find($book['initial_enemy_id'])
            : null;
        $initialDetail = $initialEnemy
            ? $enemyBookService->detailFor($character, $initialEnemy)
            : null;

        return view('enemy-book.index', compact('character', 'book', 'initialDetail'));
    }

    public function show(Enemy $enemy, EnemyBookService $enemyBookService): JsonResponse
    {
        $character = Auth::user()->currentCharacter();
        abort_unless($character, 404);

        $enemy->loadMissing('area');
        abort_unless((bool) $enemy->area?->is_published, 404);

        return response()->json($enemyBookService->detailFor($character, $enemy));
    }
}
