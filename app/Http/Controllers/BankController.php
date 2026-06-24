<?php

namespace App\Http\Controllers;

use App\Services\BankService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class BankController extends Controller
{
    public function index(BankService $bankService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home');
        }

        $transactions = $character->goldTransactions()
            ->whereIn('type', ['bank_deposit', 'bank_withdraw'])
            ->latest()
            ->limit(12)
            ->get();

        return view('bank.index', [
            'character' => $character,
            'summary' => $bankService->summary($character),
            'transactions' => $transactions,
        ]);
    }

    public function deposit(Request $request, BankService $bankService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home');
        }

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:2000000000'],
        ]);

        try {
            $bankService->deposit($character, (int) $validated['amount']);
        } catch (RuntimeException $e) {
            return redirect()->route('bank.index')->with('error', $e->getMessage());
        }

        return redirect()
            ->route('bank.index')
            ->with('status', number_format((int) $validated['amount']) . 'G を銀行へ預けました。');
    }

    public function withdraw(Request $request, BankService $bankService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home');
        }

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:2000000000'],
        ]);

        try {
            $bankService->withdraw($character, (int) $validated['amount']);
        } catch (RuntimeException $e) {
            return redirect()->route('bank.index')->with('error', $e->getMessage());
        }

        return redirect()
            ->route('bank.index')
            ->with('status', number_format((int) $validated['amount']) . 'G を銀行から引き出しました。');
    }
}
