<?php

namespace App\Http\Controllers;

use App\Services\AccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    public function deleteConfirm()
    {
        $user = Auth::user();
        $characters = $user->characters()->with('jobClass')->get();

        return view('account.delete', compact('user', 'characters'));
    }

    public function destroy(Request $request, AccountDeletionService $accountDeletionService)
    {
        $request->validate([
            'confirmation' => ['required', 'string', 'in:削除'],
        ], [
            'confirmation.in' => '確認欄に「削除」と入力してください。',
        ]);

        $user = Auth::user();

        $accountDeletionService->deleteUser($user);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('top')->with('message', 'アカウントと作成データを削除しました。');
    }
}
