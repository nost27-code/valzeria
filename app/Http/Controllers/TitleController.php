<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

class TitleController extends Controller
{
    public function index()
    {
        $character = Auth::user()->currentCharacter();
        if (! $character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        return view('titles.index', compact('character'));
    }
}
