<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckCharacterSelected
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // ログインしていない場合はログイン画面へ（基本的にはauthミドルウェアの後なので通らないはず）
        if (!$user) {
            return redirect()->route('top');
        }

        // キャラクターが選択されていない（取得できない）場合
        $character = $user->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select')->with('message', 'キャラクターを選択または作成してください。');
        }

        $routeName = (string) $request->route()?->getName();
        if (!str_starts_with($routeName, 'valmons.starter')
            && !\App\Models\PlayerValmon::where('character_id', $character->id)->exists()) {
            return redirect()->route('valmons.starter');
        }

        // オンライン状態を更新（1分以上経過していれば更新してDBへの書き込み頻度を抑える）
        if (!$character->last_seen_at || $character->last_seen_at->diffInSeconds(now()) >= 60) {
            $character->timestamps = false;
            $character->update(['last_seen_at' => now()]);
            $character->timestamps = true;
        }

        return $next($request);
    }
}
