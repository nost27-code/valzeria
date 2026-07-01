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
            if ((bool) ($result['rescue_refused'] ?? false)) {
                return redirect()
                    ->route('inn.rescue-refused')
                    ->with('inn_rescue_refused', [
                        'character_name' => $character->name,
                        'fee' => (int) ($result['fee'] ?? 0),
                        'rescue_streak' => (int) ($result['rescue_streak'] ?? 0),
                    ]);
            }

            return redirect()->route('home')->with('error', $result['message']);
        }

        $cooldownSeconds = (int) ($result['cooldown_seconds'] ?? 0);
        $paid    = (int) ($result['paid'] ?? 0);
        $rescued = (bool) ($result['rescued'] ?? false);

        if ($rescued) {
            $payText = $paid > 0 ? "（所持金 {$paid}G を支払った）" : '（支払い免除）';

            return redirect()
                ->route('inn.rescue')
                ->with('inn_rescue', [
                    'character_name' => $character->name,
                    'fee' => (int) ($result['fee'] ?? 0),
                    'paid' => $paid,
                    'pay_text' => $payText,
                    'rescue_streak' => (int) ($result['rescue_streak'] ?? 0),
                ]);
        } else {
            $payText = "（{$paid}G 支払った）";
        }

        $message = $cooldownSeconds > 0
            ? "宿屋で休み、HPとSPが全回復した！{$payText} 次の探索まで{$cooldownSeconds}秒待機してください。"
            : "宿屋で休み、HPとSPが全回復した！{$payText}";

        return redirect()->route('home')->with('message', $message);
    }

    public function rescue()
    {
        $payload = session('inn_rescue');
        if (!is_array($payload)) {
            return redirect()->route('home');
        }

        return view('inn.rescue', [
            'characterName' => (string) ($payload['character_name'] ?? '冒険者'),
            'fee' => (int) ($payload['fee'] ?? 0),
            'paid' => (int) ($payload['paid'] ?? 0),
            'payText' => (string) ($payload['pay_text'] ?? ''),
            'rescueStreak' => (int) ($payload['rescue_streak'] ?? 1),
        ]);
    }

    public function rescueRefused()
    {
        $payload = session('inn_rescue_refused');
        if (!is_array($payload)) {
            return redirect()->route('home');
        }

        return view('inn.rescue-refused', [
            'characterName' => (string) ($payload['character_name'] ?? '冒険者'),
            'fee' => (int) ($payload['fee'] ?? 0),
            'rescueStreak' => (int) ($payload['rescue_streak'] ?? 0),
        ]);
    }
}
