<x-layouts.facility title="銀行" headerIconImage="images/icon/icon_030.webp" bgImage="images/facilities/bank.webp">
    @php
        $depositMax = (int) min(2000000000, $summary['hand_gold']);
        $withdrawMax = (int) min(2000000000, $summary['bank_gold']);
    @endphp

    <div class="w-full mx-auto pb-10">
        <div class="space-y-4">
            <div class="rounded-lg border border-[#d4af37]/60 bg-white p-4 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-amber-50 shadow-inner">
                        <img src="{{ asset('images/facilities/facility_bank.webp') }}" alt="" class="h-10 w-10 object-contain">
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-xs font-black uppercase tracking-wide text-amber-700">VALZERIA BANK</div>
                        <h2 class="mt-0.5 text-xl font-black text-slate-950">Goldを安全に預けられます</h2>
                        <p class="mt-1 text-xs font-bold leading-5 text-slate-500">
                            銀行に預けたGoldは、探索敗北時のGold喪失の対象外です。
                        </p>
                    </div>
                </div>
            </div>

            @if(session('status'))
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700 shadow-sm">
                    {{ session('status') }}
                </div>
            @endif
            @if(session('error'))
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700 shadow-sm">
                    {{ session('error') }}
                </div>
            @endif
            @error('amount')
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700 shadow-sm">
                    {{ $message }}
                </div>
            @enderror

            <div class="grid grid-cols-2 gap-2">
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-center shadow-sm">
                    <div class="text-[11px] font-black text-amber-700">手持ち</div>
                    <div class="mt-1 text-lg font-black text-slate-950">{{ number_format($summary['hand_gold']) }}G</div>
                </div>
                <div class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-center shadow-sm">
                    <div class="text-[11px] font-black text-sky-700">預金</div>
                    <div class="mt-1 text-lg font-black text-slate-950">{{ number_format($summary['bank_gold']) }}G</div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-black text-slate-950">預ける</div>
                            <div class="text-xs font-bold text-slate-500">手持ちGoldを銀行へ移します。</div>
                        </div>
                        <div class="text-xs font-black text-amber-700">最大 {{ number_format($summary['hand_gold']) }}G</div>
                    </div>
                    <form method="POST" action="{{ route('bank.deposit') }}" class="bank-amount-form mt-3 space-y-3">
                        @csrf
                        <div class="flex overflow-hidden rounded-lg border border-slate-300 bg-white">
                            <input
                                type="number"
                                name="amount"
                                min="1"
                                max="{{ max(1, $depositMax) }}"
                                inputmode="numeric"
                                class="min-w-0 flex-1 border-0 px-3 py-3 text-right text-lg font-black text-slate-950 focus:ring-0"
                                placeholder="0"
                            >
                            <span class="flex items-center bg-slate-50 px-3 text-sm font-black text-slate-500">G</span>
                            <button type="button" onclick="this.closest('form').querySelector('[name=amount]').value = ''" class="border-l border-slate-200 bg-white px-3 text-xs font-black text-slate-500 hover:bg-slate-50">
                                クリア
                            </button>
                        </div>
                        <div class="grid grid-cols-4 gap-2">
                            @foreach([100, 1000, 10000] as $quick)
                                <button type="button" data-bank-amount-add="{{ $quick }}" data-bank-max="{{ $depositMax }}" class="rounded-md border border-slate-200 bg-slate-50 px-2 py-2 text-xs font-black text-slate-700">
                                    +{{ number_format($quick) }}
                                </button>
                            @endforeach
                            <button type="button" data-bank-amount-set="{{ $depositMax }}" class="rounded-md border border-amber-200 bg-amber-50 px-2 py-2 text-xs font-black text-amber-700">
                                全額
                            </button>
                        </div>
                        <button type="submit" class="w-full rounded-lg bg-slate-950 px-4 py-3 text-sm font-black text-white shadow-sm active:scale-[0.99] disabled:opacity-50" @disabled($summary['hand_gold'] <= 0)>
                            預ける
                        </button>
                    </form>
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-black text-slate-950">引き出す</div>
                            <div class="text-xs font-bold text-slate-500">預金Goldを手持ちへ戻します。</div>
                        </div>
                        <div class="text-xs font-black text-sky-700">最大 {{ number_format($summary['bank_gold']) }}G</div>
                    </div>
                    <form method="POST" action="{{ route('bank.withdraw') }}" class="bank-amount-form mt-3 space-y-3">
                        @csrf
                        <div class="flex overflow-hidden rounded-lg border border-slate-300 bg-white">
                            <input
                                type="number"
                                name="amount"
                                min="1"
                                max="{{ max(1, $withdrawMax) }}"
                                inputmode="numeric"
                                class="min-w-0 flex-1 border-0 px-3 py-3 text-right text-lg font-black text-slate-950 focus:ring-0"
                                placeholder="0"
                            >
                            <span class="flex items-center bg-slate-50 px-3 text-sm font-black text-slate-500">G</span>
                            <button type="button" onclick="this.closest('form').querySelector('[name=amount]').value = ''" class="border-l border-slate-200 bg-white px-3 text-xs font-black text-slate-500 hover:bg-slate-50">
                                クリア
                            </button>
                        </div>
                        <div class="grid grid-cols-4 gap-2">
                            @foreach([100, 1000, 10000] as $quick)
                                <button type="button" data-bank-amount-add="{{ $quick }}" data-bank-max="{{ $withdrawMax }}" class="rounded-md border border-slate-200 bg-slate-50 px-2 py-2 text-xs font-black text-slate-700">
                                    +{{ number_format($quick) }}
                                </button>
                            @endforeach
                            <button type="button" data-bank-amount-set="{{ $withdrawMax }}" class="rounded-md border border-sky-200 bg-sky-50 px-2 py-2 text-xs font-black text-sky-700">
                                全額
                            </button>
                        </div>
                        <button type="submit" class="w-full rounded-lg bg-sky-700 px-4 py-3 text-sm font-black text-white shadow-sm active:scale-[0.99] disabled:opacity-50" @disabled($summary['bank_gold'] <= 0)>
                            引き出す
                        </button>
                    </form>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-4 py-3">
                    <div class="text-sm font-black text-slate-950">最近の入出金</div>
                </div>
                <div class="divide-y divide-slate-100">
                    @forelse($transactions as $transaction)
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <div class="text-sm font-bold text-slate-800">{{ $transaction->note ?? '銀行取引' }}</div>
                                <div class="mt-0.5 text-[11px] font-bold text-slate-400">{{ $transaction->created_at?->format('Y/m/d H:i') }}</div>
                            </div>
                            <div class="shrink-0 text-sm font-black {{ (int) $transaction->amount < 0 ? 'text-amber-700' : 'text-sky-700' }}">
                                {{ (int) $transaction->amount < 0 ? '' : '+' }}{{ number_format((int) $transaction->amount) }}G
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-6 text-center text-sm font-bold text-slate-400">
                            入出金履歴はまだありません。
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('click', (event) => {
            const button = event.target.closest('[data-bank-amount-add], [data-bank-amount-set]');

            if (!button) {
                return;
            }

            const form = button.closest('.bank-amount-form');
            const input = form?.querySelector('[name="amount"]');

            if (!form || !input) {
                return;
            }

            const addAmount = parseInt(button.dataset.bankAmountAdd || '0', 10);
            const setAmount = parseInt(button.dataset.bankAmountSet || '0', 10);
            const maxAmount = parseInt(button.dataset.bankMax || button.dataset.bankAmountSet || '0', 10);
            const currentAmount = parseInt(input.value || '0', 10) || 0;
            const nextAmount = addAmount > 0
                ? Math.min(maxAmount, currentAmount + addAmount)
                : setAmount;

            input.value = nextAmount;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    </script>
</x-layouts.facility>
