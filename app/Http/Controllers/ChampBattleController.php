<?php

namespace App\Http\Controllers;

use App\Services\ChampBattleResultStore;
use App\Services\ChampBattleService;
use App\Services\StorageCapacityService;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ChampBattleController extends Controller
{
    public function confirm(ChampBattleService $champBattleService): View|RedirectResponse
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        if ($redirect = $this->redirectIfStorageFull($character)) {
            return $redirect;
        }

        return view('champ.confirm', [
            'summary' => $champBattleService->summary($character),
        ]);
    }

    public function challenge(Request $request, ChampBattleService $champBattleService): RedirectResponse
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        session()->forget('lastChampBattleResult');

        if ($character->is_frozen) {
            return redirect()->route('home')->with('error', 'このアカウントは凍結されています。お問い合わせください。');
        }

        if ($redirect = $this->redirectIfStorageFull($character)) {
            return $redirect;
        }

        if (! $request->has(['expected_champ_character_id', 'expected_champ_appointed_at'])) {
            return back()->with('message', '画面のチャンプ情報が古くなっています。最新の情報を確認して、もう一度挑戦してください。');
        }

        try {
            $result = $champBattleService->executeChallenge(
                $character,
                $request->integer('expected_champ_character_id'),
                $request->integer('expected_champ_appointed_at'),
            );
        } catch (DeadlockException|QueryException $exception) {
            if (! $this->isDatabaseContention($exception)) {
                throw $exception;
            }

            Log::warning('Champ battle database contention handled.', [
                'database_error_code' => (int) ($exception->errorInfo[1] ?? 0),
            ]);

            return back()->with(
                'message',
                'ほかの冒険者のチャンプ戦を処理中です。少し待ってから、もう一度挑戦してください。',
            );
        }
        if (empty($result['ok'])) {
            return back()
                ->with('message', $result['message'] ?? '今はチャンプに挑戦できません。');
        }

        $resultToken = null;
        try {
            $resultToken = app(ChampBattleResultStore::class)->store((int) $character->id, $result);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return redirect()
            ->route('champ.result', array_filter(['result_token' => $resultToken]))
            ->with('champ_battle_result', $result);
    }

    public function result(): View|RedirectResponse
    {
        $character = Auth::user()?->currentCharacter();
        $resultToken = request()->query('result_token');
        $result = $character
            ? app(ChampBattleResultStore::class)->retrieve(
                (int) $character->id,
                is_string($resultToken) ? $resultToken : null,
            )
            : null;
        $result ??= session('champ_battle_result');
        if (! $result) {
            $result = session('lastChampBattleResult');
            $nextAvailableAt = is_array($result) ? ($result['next_available_at'] ?? null) : null;

            try {
                $isReusable = $nextAvailableAt && now()->lt(Carbon::parse($nextAvailableAt));
            } catch (\Throwable) {
                $isReusable = false;
            }

            if (! $isReusable) {
                session()->forget('lastChampBattleResult');

                return redirect()->route('home');
            }
        }

        session(['lastChampBattleResult' => $result]);

        return view('champ.result', ['result' => $result]);
    }

    private function redirectIfStorageFull($character): ?RedirectResponse
    {
        $storageCapacity = app(StorageCapacityService::class);
        if (!$storageCapacity->isFull($character)) {
            return null;
        }

        return redirect()
            ->route('home')
            ->with('message', $storageCapacity->fullMessageHtml($character));
    }

    private function isDatabaseContention(DeadlockException|QueryException $exception): bool
    {
        $error = $exception->errorInfo ?? [];

        return in_array((int) ($error[1] ?? 0), [1205, 1213, 3572], true)
            || (string) ($error[0] ?? $exception->getCode()) === '40001';
    }
}
