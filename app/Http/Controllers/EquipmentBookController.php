<?php

namespace App\Http\Controllers;

use App\Services\EquipmentBookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EquipmentBookController extends Controller
{
    public function index(Request $request, EquipmentBookService $equipmentBookService)
    {
        abort_unless($equipmentBookService->isEnabled(), 404);

        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        // 防具画像が揃うまでは武器図鑑のみ公開する。
        $type = 'weapon';
        $chartKey = $request->string('chart')->toString() ?: null;
        $book = $equipmentBookService->bookFor($character, $type, $chartKey);

        return view('equipment-book.index', compact('character', 'book'));
    }
}
