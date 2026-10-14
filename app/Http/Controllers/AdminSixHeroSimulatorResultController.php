<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Response;

final class AdminSixHeroSimulatorResultController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        abort_unless($request->user()?->role === 'admin', 403);
        $preview = Cache::get('six_hero_simulator:'.$request->user()->id.':'.$token);
        abort_unless(is_array($preview), 404, '模擬戦の保存期間（30分）が終了しました。再度対戦してください。');

        // Enable only this admin response, independently of the player replay setting.
        config(['battle.replay.enabled' => true]);

        return response()->view('six-heroes.battle-result', [
            'battleResult' => $preview['battleResult'],
            'battleLogs' => $preview['battleLogs'],
            'simulatorPreview' => $preview,
        ])->header('Cache-Control', 'private, no-store');
    }
}
