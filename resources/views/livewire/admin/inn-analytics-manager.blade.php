<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">INN ANALYTICS</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">宿屋売上分析</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">宿泊で支払われたGold、宿泊回数、利用者数、救済宿泊の状況を確認します。</p>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <label class="text-xs font-black text-slate-600">
                開始日
                <input type="date" wire:model.live="dateFrom" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
            </label>
            <label class="text-xs font-black text-slate-600">
                終了日
                <input type="date" wire:model.live="dateTo" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
            </label>
            <div class="rounded-md border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-500 shadow-sm">
                集計 {{ $generatedAt->format('Y/m/d H:i') }}
            </div>
        </div>
    </div>

    @if($missingTables)
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-8 text-center text-sm font-black text-amber-900">
            宿屋売上の集計に必要なGold取引テーブルがまだありません。
        </div>
    @else
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-5 py-4 text-sm font-bold text-amber-900">
            過去分は `gold_transactions.type = inn` の支払額から集計します。救済宿泊の内訳は、この集計メタ情報を記録し始めた後の宿泊分から反映されます。
        </div>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            @foreach($summaryCards as $card)
                <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-black text-slate-500">{{ $card['label'] }}</div>
                    <div class="mt-2 flex items-baseline gap-1">
                        <span class="text-2xl font-black text-slate-950">{{ $card['value'] }}</span>
                        <span class="text-xs font-black text-amber-700">{{ $card['unit'] }}</span>
                    </div>
                    <div class="mt-1 text-xs font-bold text-slate-400">{{ $card['note'] }}</div>
                </div>
            @endforeach
        </section>

        <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(360px,0.72fr)]">
            <section class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="text-lg font-black text-slate-950">日別売上</h2>
                    <p class="mt-1 text-xs font-bold text-slate-400">宿泊によって回収されたGoldの推移です。</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-slate-700">
                            <tr>
                                <th class="px-4 py-3 text-left font-bold">日付</th>
                                <th class="px-4 py-3 text-right font-bold">売上</th>
                                <th class="px-4 py-3 text-right font-bold">宿泊</th>
                                <th class="px-4 py-3 text-right font-bold">利用者</th>
                                <th class="px-4 py-3 text-right font-bold">平均</th>
                                <th class="px-4 py-3 text-right font-bold">救済</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @foreach($dailyRows as $row)
                                <tr class="hover:bg-slate-50">
                                    <td class="whitespace-nowrap px-4 py-3 font-black text-slate-900">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('Y/m/d') }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-black text-amber-700">{{ number_format($row['revenue']) }}G</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-bold text-slate-700">{{ number_format($row['stays']) }}回</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-bold text-slate-700">{{ number_format($row['guests']) }}人</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-bold text-slate-700">{{ number_format($row['average']) }}G</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-bold text-rose-700">{{ number_format($row['rescued']) }}回</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-black text-slate-950">最近の宿泊</h2>
                <div class="mt-4 space-y-2">
                    @forelse($recentTransactions as $transaction)
                        @php
                            $meta = $transaction->metadata ?? [];
                            $paid = abs((int) $transaction->amount);
                            $fee = (int) data_get($meta, 'fee', $paid);
                            $rescued = (bool) data_get($meta, 'rescued', false);
                        @endphp
                        <div class="rounded-md bg-slate-50 px-3 py-2">
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-black text-slate-950">{{ $transaction->character?->name ?? '不明な冒険者' }}</div>
                                    <div class="mt-0.5 text-xs font-bold text-slate-500">
                                        Lv{{ number_format((int) ($transaction->character?->level ?? data_get($meta, 'level', 0))) }}
                                        @if($rescued)
                                            <span class="ml-2 rounded bg-rose-100 px-1.5 py-0.5 text-rose-700">救済</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div class="text-sm font-black text-amber-700">{{ number_format($paid) }}G</div>
                                    <div class="text-xs font-bold text-slate-500">料金 {{ number_format($fee) }}G</div>
                                    <div class="text-xs font-bold text-slate-400">{{ $transaction->created_at?->timezone('Asia/Tokyo')->format('m/d H:i') }}</div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-md bg-slate-50 px-3 py-8 text-center text-sm font-bold text-slate-500">宿泊履歴はまだありません。</div>
                    @endforelse
                </div>
            </section>
        </div>
    @endif
</div>
