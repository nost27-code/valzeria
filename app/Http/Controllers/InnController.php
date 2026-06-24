<?php

namespace App\Http\Controllers;

use App\Services\InnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InnController extends Controller
{
    protected InnService $innService;

    public function __construct(InnService $innService)
    {
        $this->innService = $innService;
    }

    public function rest(Request $request)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home');
        }

        $result = $this->innService->rest($character);
        
        if (!$result['success']) {
            return redirect()->route('home')->with('error', $result['message']);
        }

        $cooldownSeconds = (int) ($result['cooldown_seconds'] ?? 0);
        $message = $cooldownSeconds > 0
            ? "宿屋で休み、HPとSPが全回復した！ 次の探索まで{$cooldownSeconds}秒待機してください。"
            : '宿屋で休み、HPとSPが全回復した！';

        return redirect()->route('home')->with('message', $message);
    }
}
