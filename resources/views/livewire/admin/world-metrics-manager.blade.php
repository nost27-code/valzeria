<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">WORLD METRICS</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">世界指標</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">全プレイヤーのGold総量と、敗北で世界から失われたGoldを確認します。</p>
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
            <button type="button" wire:click="downloadCsv" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-black text-slate-700 shadow-sm hover:bg-slate-50">
                CSV出力
            </button>
            <button type="button" wire:click="downloadGraphSvg" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-xs font-black text-amber-800 shadow-sm hover:bg-amber-100">
                グラフSVG
            </button>
            <div class="rounded-md border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-500 shadow-sm">
                集計時刻 {{ $generatedAt->format('Y/m/d H:i') }}
            </div>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-6">
        @foreach($summaryCards as $card)
            <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-black tracking-wide text-slate-500">{{ $card['label'] }}</div>
                <div class="mt-2 text-xl font-black text-slate-950">{{ $card['value'] }}</div>
                <div class="mt-1 text-xs font-bold text-slate-400">{{ $card['note'] }}</div>
            </div>
        @endforeach
    </div>

    @unless($hasGoldLostColumn)
        <div class="mt-6 rounded-md border border-amber-200 bg-amber-50 px-5 py-4 text-sm font-bold text-amber-800">
            敗北Gold喪失額のログ列がまだありません。マイグレーション適用後の戦闘から日別集計されます。
        </div>
    @endunless

    <section class="mt-6 overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-col gap-2 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-lg font-black text-slate-950">日別推移グラフ</h2>
                <p class="mt-1 text-xs font-bold text-slate-400">棒は失われたGold、赤線は敗北回数です。上部のグラフSVGから保存できます。</p>
            </div>
            <div class="flex gap-4 text-xs font-black text-slate-600">
                <span class="inline-flex items-center gap-2"><span class="h-3 w-3 rounded bg-amber-600"></span>Gold喪失</span>
                <span class="inline-flex items-center gap-2"><span class="h-1 w-5 rounded bg-red-600"></span>敗北回数</span>
            </div>
        </div>

        <div class="overflow-x-auto p-5">
            @if(count($graphRows) > 0)
                <div class="min-w-[720px]">
                    <div class="relative h-72 border-l border-b border-slate-200 bg-slate-50/60 px-3 pt-4">
                        <div class="absolute inset-x-3 top-1/4 border-t border-slate-200"></div>
                        <div class="absolute inset-x-3 top-1/2 border-t border-slate-200"></div>
                        <div class="absolute inset-x-3 top-3/4 border-t border-slate-200"></div>
                        <div class="relative z-10 flex h-full items-end gap-2">
                            @foreach($graphRows as $row)
                                <div class="group flex h-full min-w-10 flex-1 flex-col items-center justify-end gap-2">
                                    <div class="relative flex h-56 w-full items-end justify-center">
                                        <div class="absolute left-1/2 h-2.5 w-2.5 -translate-x-1/2 rounded-full bg-red-600 ring-2 ring-white"
                                             style="bottom: calc({{ $row['defeat_percent'] }}% - 5px);"></div>
                                        <div class="w-5 rounded-t bg-amber-600"
                                             style="height: {{ $row['gold_percent'] }}%;"></div>
                                        <div class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 hidden w-44 -translate-x-1/2 rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white shadow-lg group-hover:block">
                                            <div>{{ \Illuminate\Support\Carbon::parse($row['date'])->format('Y/m/d') }}</div>
                                            <div>Gold喪失 {{ number_format($row['gold_lost']) }}G</div>
                                            <div>敗北 {{ number_format($row['defeat_count']) }}回 / {{ number_format($row['characters_count']) }}人</div>
                                        </div>
                                    </div>
                                    <div class="whitespace-nowrap text-[11px] font-black text-slate-500">{{ $row['label'] }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @else
                <div class="rounded-md bg-slate-50 px-6 py-8 text-center text-sm font-bold text-slate-500">
                    グラフ化できる日別データはまだありません。
                </div>
            @endif
        </div>
    </section>

    <section class="mt-6 overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="text-lg font-black text-slate-950">日別 敗北Gold喪失</h2>
            <p class="mt-1 text-xs font-bold text-slate-400">探索敗北で実際に所持Goldから差し引かれた額を集計します。貯金は喪失対象外です。</p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-slate-700">
                    <tr>
                        <th class="px-4 py-3 text-left font-bold">日付</th>
                        <th class="px-4 py-3 text-right font-bold">失われたGold</th>
                        <th class="px-4 py-3 text-right font-bold">敗北回数</th>
                        <th class="px-4 py-3 text-right font-bold">対象プレイヤー数</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse($dailyGoldLosses as $row)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 whitespace-nowrap font-black text-slate-900">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('Y/m/d') }}</td>
                            <td class="px-4 py-3 text-right font-black text-amber-700">{{ number_format($row['gold_lost']) }}G</td>
                            <td class="px-4 py-3 text-right font-bold text-red-700">{{ number_format($row['defeat_count']) }}敗</td>
                            <td class="px-4 py-3 text-right font-bold text-slate-600">{{ number_format($row['characters_count']) }}人</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-8 text-center text-sm font-bold text-slate-500">
                                対象期間のGold喪失ログはありません。
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
