<?php

namespace App\Http\Controllers;

use App\Services\ItemBookService;
use Illuminate\Support\Facades\Auth;

class ItemBookController extends Controller
{
    public function index(ItemBookService $itemBookService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $book = $itemBookService->materialBookFor($character);

        return view('item-book.index', compact('character', 'book'));
    }
}
