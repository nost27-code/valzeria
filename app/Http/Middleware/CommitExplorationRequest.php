<?php

namespace App\Http\Middleware;

use App\Models\Character;
use App\Services\CharacterStatusService;
use App\Services\GameSettingService;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\RedirectResponse;

class CommitExplorationRequest
{
    public function handle(Request $request, Closure $next)
    {
        $character = $request->user()?->currentCharacter();
        if (! $character) {
            return $next($request);
        }
        $token = $request->input('exploration_request_id');
        if (! is_string($token) || ! Str::isUuid($token)) {
            return redirect()->route('home')->with('error', '探索画面を開き直してから、もう一度お試しください。');
        }
        $input = $request->except('_token', 'exploration_request_id');
        ksort($input);
        $hash = hash('sha256', $request->path().'|'.json_encode($input, JSON_THROW_ON_ERROR));
        $sessionBefore = $request->session()->all();

        try {
            // 回数指定も一つの操作として確定する。外部応答やセッションを伴うため自動再試行しない。
            return app(GameSettingService::class)->withFreshSnapshot(fn () => DB::transaction(function () use ($request, $next, $character, $token, $hash) {
                Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
                // 同じ冒険者の操作は上の行ロックで直列化済み。
                // 未登録tokenのFOR UPDATEはInnoDBでgap lockを取り、別の冒険者のINSERTとも競合する。
                $previous = DB::table('exploration_requests')->where('character_id', $character->id)
                    ->where('token', $token)->first();
                if ($previous) {
                    abort_unless(hash_equals($previous->request_hash, $hash), 409, '探索操作の内容が変更されています。');

                    return redirect()->to($previous->redirect_url);
                }
                $request->attributes->set('committed_exploration_token', $token);
                $response = $next($request);
                if (! $response instanceof RedirectResponse) {
                    // 競合409・認証/入力エラー・500を成功記録にせず、元の応答を保って巻き戻す。
                    throw new HttpResponseException($response);
                }
                $data = $request->attributes->get('committed_exploration_data');
                DB::table('exploration_requests')->insert([
                    'character_id' => $character->id,
                    'token' => $token,
                    'request_hash' => $hash,
                    'redirect_url' => $response->getTargetUrl(),
                    'battle_data' => $data === null ? null : Crypt::encrypt($data),
                    'created_at' => now(),
                ]);

                return $response;
            }));
        } catch (\Throwable $exception) {
            $request->session()->flush();
            $request->session()->put($sessionBefore);
            throw $exception;
        } finally {
            CharacterStatusService::clearRequestCache();
        }
    }
}
