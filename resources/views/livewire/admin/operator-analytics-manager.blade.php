<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">OPERATOR ANALYTICS</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">統計分析</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">新規登録、活動者、戦闘、チャット、売上の日別推移と伸び率を確認します。</p>
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
            <button type="button" wire:click="downloadCsv" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-black text-slate-700 shadow-sm transition hover:border-amber-400 hover:text-amber-700">
                CSV
            </button>
            <div class="rounded-md border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-500 shadow-sm">
                集計 {{ $generatedAt->format('Y/m/d H:i') }}
            </div>
        </div>
    </div>

    <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-5 py-4 text-sm font-bold text-amber-900">
        日別ログイン履歴テーブルはまだないため、「活動者」は戦闘・チャット・チャンプ戦・ランク戦・last_seen_at から推定した人数です。正確な日別ログインは次フェイズでログ蓄積を追加すると精度が上がります。
        Gold経済は直近30日を初期表示し、最大90日までの範囲で `gold_transactions` を読取集計します。
    </div>

    <section class="grid grid-cols-2 gap-3 lg:grid-cols-6">
        <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-black text-slate-500">新規登録</div>
            <div class="mt-2 text-2xl font-black text-slate-950">{{ number_format($periodTotals['new_users']) }}</div>
            <div class="mt-1 text-xs font-bold text-slate-400">期間内</div>
        </div>
        <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-black text-slate-500">活動者</div>
            <div class="mt-2 text-2xl font-black text-emerald-700">{{ number_format($periodTotals['active_players']) }}</div>
            <div class="mt-1 text-xs font-bold text-slate-400">推定ユニーク</div>
        </div>
        <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-black text-slate-500">戦闘回数</div>
            <div class="mt-2 text-2xl font-black text-sky-700">{{ number_format($periodTotals['battle_count']) }}</div>
            <div class="mt-1 text-xs font-bold text-slate-400">通常探索</div>
        </div>
        <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-black text-slate-500">勝率</div>
            <div class="mt-2 text-2xl font-black text-indigo-700">{{ $periodTotals['win_rate'] }}%</div>
            <div class="mt-1 text-xs font-bold text-slate-400">通常探索</div>
        </div>
        <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-black text-slate-500">チャット</div>
            <div class="mt-2 text-2xl font-black text-fuchsia-700">{{ number_format($periodTotals['chat_count']) }}</div>
            <div class="mt-1 text-xs font-bold text-slate-400">投稿数</div>
        </div>
        <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-black text-slate-500">売上</div>
            <div class="mt-2 text-2xl font-black text-amber-700">{{ number_format($periodTotals['revenue_jpy']) }}円</div>
            <div class="mt-1 text-xs font-bold text-slate-400">{{ number_format($periodTotals['purchase_count']) }}件</div>
        </div>
    </section>

    <section class="mt-6 overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="text-lg font-black text-slate-950">Gold経済</h2>
            <p class="mt-1 text-xs font-bold text-slate-400">発行・回収・移転・市場取引を日別/type別に確認します。銀行と市場取引はnet計算から外します。</p>
        </div>

        @if($goldEconomy['missingTable'])
            <div class="px-5 py-8 text-center text-sm font-black text-amber-800">
                Gold取引テーブルがまだありません。
            </div>
        @else
            <div class="grid gap-3 bg-slate-50/60 p-5 sm:grid-cols-2 xl:grid-cols-6">
                <div class="rounded-md border border-emerald-100 bg-white p-4">
                    <div class="text-xs font-black text-slate-500">発行</div>
                    <div class="mt-2 text-2xl font-black text-emerald-700">{{ number_format($goldEconomy['summary']['issue']) }}G</div>
                    <div class="mt-1 text-xs font-bold text-slate-400">システムから増えたGold</div>
                </div>
                <div class="rounded-md border border-rose-100 bg-white p-4">
                    <div class="text-xs font-black text-slate-500">回収</div>
                    <div class="mt-2 text-2xl font-black text-rose-700">{{ number_format($goldEconomy['summary']['sink']) }}G</div>
                    <div class="mt-1 text-xs font-bold text-slate-400">システムへ消えたGold</div>
                </div>
                <div class="rounded-md border border-sky-100 bg-white p-4">
                    <div class="text-xs font-black text-slate-500">net</div>
                    <div class="mt-2 text-2xl font-black {{ $goldEconomy['summary']['net'] < 0 ? 'text-rose-700' : 'text-sky-700' }}">{{ number_format($goldEconomy['summary']['net']) }}G</div>
                    <div class="mt-1 text-xs font-bold text-slate-400">発行 - 回収</div>
                </div>
                <div class="rounded-md border border-indigo-100 bg-white p-4">
                    <div class="text-xs font-black text-slate-500">移転</div>
                    <div class="mt-2 text-2xl font-black text-indigo-700">{{ number_format($goldEconomy['summary']['transfer']) }}G</div>
                    <div class="mt-1 text-xs font-bold text-slate-400">銀行預入/引出</div>
                </div>
                <div class="rounded-md border border-amber-100 bg-white p-4">
                    <div class="text-xs font-black text-slate-500">市場取引</div>
                    <div class="mt-2 text-2xl font-black text-amber-700">{{ number_format($goldEconomy['summary']['market']) }}G</div>
                    <div class="mt-1 text-xs font-bold text-slate-400">発行/回収へ単純分類しない</div>
                </div>
                <div class="rounded-md border border-slate-200 bg-white p-4">
                    <div class="text-xs font-black text-slate-500">NPC調達</div>
                    <div class="mt-2 text-2xl font-black text-purple-700">{{ number_format($goldEconomy['summary']['npc_procurement_gold']) }}G</div>
                    <div class="mt-1 text-xs font-bold text-slate-400">{{ number_format($goldEconomy['summary']['npc_procurement_count']) }}件 / 平均{{ number_format($goldEconomy['summary']['npc_procurement_average']) }}G</div>
                </div>
            </div>

            @php
                $warehouseGoldExpansion = $goldEconomy['warehouseGoldExpansion'];
            @endphp
            <section class="border-t border-slate-100 bg-white px-5 py-5">
                <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                    <div>
                        <h3 class="text-base font-black text-slate-950">素材倉庫Gold拡張</h3>
                        <p class="mt-1 text-xs font-bold text-slate-400">
                            購入数は `shop_purchase_logs`、Gold回収額は `gold_transactions` の実支払いから集計します。現在価格は {{ number_format($warehouseGoldExpansion['current_price']) }}G です。
                        </p>
                    </div>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:min-w-[620px]">
                        <div class="rounded-md border border-amber-100 bg-amber-50 p-4">
                            <div class="text-xs font-black text-slate-500">期間内販売</div>
                            <div class="mt-2 text-2xl font-black text-amber-700">{{ number_format($warehouseGoldExpansion['purchase_count']) }}回</div>
                            <div class="mt-1 text-xs font-bold text-slate-500">{{ number_format($warehouseGoldExpansion['buyer_count']) }}人</div>
                        </div>
                        <div class="rounded-md border border-rose-100 bg-rose-50 p-4">
                            <div class="text-xs font-black text-slate-500">Gold回収</div>
                            <div class="mt-2 text-2xl font-black text-rose-700">{{ number_format($warehouseGoldExpansion['gold_spent']) }}G</div>
                            <div class="mt-1 text-xs font-bold text-slate-500">実取引ログ</div>
                        </div>
                        <div class="rounded-md border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs font-black text-slate-500">現価格換算</div>
                            <div class="mt-2 text-2xl font-black text-slate-800">{{ number_format($warehouseGoldExpansion['estimated_gold_at_current_price']) }}G</div>
                            <div class="mt-1 text-xs font-bold text-slate-500">購入数 × 現価格</div>
                        </div>
                        <div class="rounded-md border border-indigo-100 bg-indigo-50 p-4">
                            <div class="text-xs font-black text-slate-500">累計販売</div>
                            <div class="mt-2 text-2xl font-black text-indigo-700">{{ number_format($warehouseGoldExpansion['all_time_purchase_count']) }}回</div>
                            <div class="mt-1 text-xs font-bold text-slate-500">{{ number_format($warehouseGoldExpansion['all_time_buyer_count']) }}人</div>
                        </div>
                    </div>
                </div>

                <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.7fr)]">
                    <div class="overflow-hidden rounded-md border border-slate-200">
                        <div class="border-b border-slate-100 px-4 py-3">
                            <h4 class="text-sm font-black text-slate-950">日別販売</h4>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-slate-700">
                                    <tr>
                                        <th class="px-4 py-3 text-left font-bold">日付</th>
                                        <th class="px-4 py-3 text-right font-bold">販売回数</th>
                                        <th class="px-4 py-3 text-right font-bold">購入者</th>
                                        <th class="px-4 py-3 text-right font-bold">Gold回収</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @forelse($warehouseGoldExpansion['dailyRows'] as $row)
                                        <tr class="hover:bg-slate-50">
                                            <td class="whitespace-nowrap px-4 py-3 font-black text-slate-900">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('Y/m/d') }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right font-black text-amber-700">{{ number_format($row['purchase_count']) }}回</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right font-bold text-slate-700">{{ number_format($row['buyer_count']) }}人</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right font-black text-rose-700">{{ number_format($row['gold_spent']) }}G</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="px-4 py-8 text-center text-sm font-bold text-slate-500">期間内の素材倉庫Gold拡張購入はありません。</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="overflow-hidden rounded-md border border-slate-200">
                        <div class="border-b border-slate-100 px-4 py-3">
                            <h4 class="text-sm font-black text-slate-950">最近の購入冒険者</h4>
                        </div>
                        <div class="divide-y divide-slate-100">
                            @forelse($warehouseGoldExpansion['recentBuyers'] as $row)
                                <div class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                    <div class="min-w-0">
                                        <div class="truncate font-black text-slate-900">{{ $row['character_name'] }}</div>
                                        <div class="text-xs font-bold text-slate-400">ID {{ $row['character_id'] }} / {{ \Illuminate\Support\Carbon::parse($row['last_purchased_at'])->format('Y/m/d H:i') }}</div>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <div class="font-black text-amber-700">{{ number_format($row['purchase_count']) }}回</div>
                                        <div class="text-xs font-bold text-slate-400">期間内</div>
                                    </div>
                                </div>
                            @empty
                                <div class="px-4 py-8 text-center text-sm font-bold text-slate-500">期間内の購入者はいません。</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </section>

            @if($goldEconomy['summary']['unclassified'] > 0)
                <div class="border-y border-amber-100 bg-amber-50 px-5 py-3 text-sm font-bold text-amber-900">
                    要分類typeがあります。発行・回収へ混ぜず、type別明細を確認してください。
                </div>
            @endif

            <div class="grid gap-6 p-5 xl:grid-cols-[minmax(0,1fr)_minmax(360px,0.8fr)]">
                <div class="overflow-hidden rounded-md border border-slate-200">
                    <div class="border-b border-slate-100 px-4 py-3">
                        <h3 class="text-sm font-black text-slate-950">日別Gold推移</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-slate-700">
                                <tr>
                                    <th class="px-4 py-3 text-left font-bold">日付</th>
                                    <th class="px-4 py-3 text-right font-bold">発行</th>
                                    <th class="px-4 py-3 text-right font-bold">回収</th>
                                    <th class="px-4 py-3 text-right font-bold">net</th>
                                    <th class="px-4 py-3 text-right font-bold">移転</th>
                                    <th class="px-4 py-3 text-right font-bold">市場</th>
                                    <th class="px-4 py-3 text-right font-bold">NPC調達</th>
                                    <th class="px-4 py-3 text-right font-bold">要分類</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @forelse($goldEconomy['dailyRows'] as $row)
                                    <tr class="hover:bg-slate-50">
                                        <td class="whitespace-nowrap px-4 py-3 font-black text-slate-900">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('Y/m/d') }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-black text-emerald-700">{{ number_format($row['issue']) }}G</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-black text-rose-700">{{ number_format($row['sink']) }}G</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-black {{ $row['net'] < 0 ? 'text-rose-700' : 'text-sky-700' }}">{{ number_format($row['net']) }}G</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-bold text-indigo-700">{{ number_format($row['transfer']) }}G</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-bold text-amber-700">{{ number_format($row['market']) }}G</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-bold text-purple-700">{{ number_format($row['npc_procurement']) }}G</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-bold text-slate-500">{{ number_format($row['unclassified']) }}G</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="px-4 py-8 text-center text-sm font-bold text-slate-500">該当するGold取引はありません。</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="overflow-hidden rounded-md border border-slate-200">
                        <div class="border-b border-slate-100 px-4 py-3">
                            <h3 class="text-sm font-black text-slate-950">type別分類</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-slate-700">
                                    <tr>
                                        <th class="px-4 py-3 text-left font-bold">type</th>
                                        <th class="px-4 py-3 text-left font-bold">分類</th>
                                        <th class="px-4 py-3 text-right font-bold">件数</th>
                                        <th class="px-4 py-3 text-right font-bold">正</th>
                                        <th class="px-4 py-3 text-right font-bold">負</th>
                                        <th class="px-4 py-3 text-right font-bold">net</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @forelse($goldEconomy['typeRows'] as $row)
                                        @php
                                            $bucketTone = match ($row['bucket']) {
                                                'issue' => 'bg-emerald-50 text-emerald-700',
                                                'sink' => 'bg-rose-50 text-rose-700',
                                                'transfer' => 'bg-indigo-50 text-indigo-700',
                                                'market' => 'bg-amber-50 text-amber-700',
                                                default => 'bg-slate-100 text-slate-700',
                                            };
                                        @endphp
                                        <tr class="hover:bg-slate-50">
                                            <td class="whitespace-nowrap px-4 py-3">
                                                <div class="font-black text-slate-900">{{ $row['label'] }}</div>
                                                <div class="text-[11px] font-bold text-slate-400">{{ $row['type'] }}</div>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3"><span class="rounded-md px-2 py-1 text-xs font-black {{ $bucketTone }}">{{ $row['bucket_label'] }}</span></td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right font-bold">{{ number_format($row['tx_count']) }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right font-bold text-emerald-700">{{ number_format($row['positive_amount']) }}G</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right font-bold text-rose-700">{{ number_format($row['negative_amount']) }}G</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right font-black {{ $row['net_amount'] < 0 ? 'text-rose-700' : 'text-slate-700' }}">{{ number_format($row['net_amount']) }}G</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="px-4 py-8 text-center text-sm font-bold text-slate-500">該当するGold取引はありません。</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="overflow-hidden rounded-md border border-slate-200">
                        <div class="border-b border-slate-100 px-4 py-3">
                            <h3 class="text-sm font-black text-slate-950">NPC調達 上位冒険者</h3>
                        </div>
                        <div class="divide-y divide-slate-100">
                            @forelse($goldEconomy['npcProcurementTopCharacters'] as $row)
                                <div class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                    <div class="min-w-0">
                                        <div class="truncate font-black text-slate-900">{{ $row['character_name'] }}</div>
                                        <div class="text-xs font-bold text-slate-400">ID {{ $row['character_id'] }} / {{ number_format($row['delivery_count']) }}件</div>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <div class="font-black text-purple-700">{{ number_format($row['total_gold']) }}G</div>
                                        <div class="text-xs font-bold text-slate-400">平均 {{ number_format($row['average_gold']) }}G</div>
                                    </div>
                                </div>
                            @empty
                                <div class="px-4 py-8 text-center text-sm font-bold text-slate-500">NPC調達納品のGold発行はありません。</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </section>

    <section class="mt-6 overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="text-lg font-black text-slate-950">伸び率</h2>
            <p class="mt-1 text-xs font-bold text-slate-400">直近7/14/30日を、それぞれ直前の同日数と比較します。</p>
        </div>
        <div class="grid grid-cols-2 gap-3 bg-slate-50/60 p-5 lg:grid-cols-4 xl:grid-cols-6">
            @foreach($growthCards as $card)
                @php
                    $isPositive = $card['is_new'] || ($card['rate'] !== null && $card['rate'] >= 0);
                    $rateLabel = $card['is_new'] ? '新規' : ($card['rate'] === null ? '-' : (($card['rate'] > 0 ? '+' : '') . $card['rate'] . '%'));
                @endphp
                <div class="rounded-md border border-slate-200 bg-white p-4">
                    <div class="flex items-center justify-between gap-2">
                        <div class="text-xs font-black text-slate-500">{{ $card['days'] }}日 {{ $card['label'] }}</div>
                        <div class="rounded-md px-2 py-1 text-[11px] font-black {{ $isPositive ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">{{ $rateLabel }}</div>
                    </div>
                    <div class="mt-2 text-xl font-black text-slate-950">
                        {{ number_format($card['current']) }}{{ $card['unit'] }}
                    </div>
                    <div class="mt-1 text-xs font-bold text-slate-400">前期間 {{ number_format($card['previous']) }}{{ $card['unit'] }}</div>
                </div>
            @endforeach
        </div>
    </section>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
        <section class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-lg font-black text-slate-950">集客・活動推移</h2>
                <p class="mt-1 text-xs font-bold text-slate-400">新規登録と推定活動者の流れを確認します。</p>
            </div>
            <div class="divide-y divide-slate-100">
                @foreach($dailyRows as $row)
                    <div class="grid grid-cols-[6rem,1fr] gap-3 px-5 py-3 text-sm">
                        <div class="font-black text-slate-700">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('m/d') }}</div>
                        <div class="space-y-2">
                            <div>
                                <div class="flex items-center justify-between text-xs font-bold text-slate-500"><span>新規</span><span>{{ number_format($row['new_users']) }}</span></div>
                                <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-amber-500" style="width: {{ min(100, max(2, ($row['new_users'] / $maxima['new_users']) * 100)) }}%;"></div></div>
                            </div>
                            <div>
                                <div class="flex items-center justify-between text-xs font-bold text-slate-500"><span>活動者</span><span>{{ number_format($row['active_players']) }}</span></div>
                                <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" style="width: {{ min(100, max(2, ($row['active_players'] / $maxima['active_players']) * 100)) }}%;"></div></div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-lg font-black text-slate-950">戦闘推移</h2>
                <p class="mt-1 text-xs font-bold text-slate-400">通常探索の勝敗と、チャンプ戦・ランク戦の利用量を見ます。</p>
            </div>
            <div class="divide-y divide-slate-100">
                @foreach($dailyRows as $row)
                    @php
                        $battleWidth = min(100, max(2, ($row['battle_count'] / $maxima['battle_count']) * 100));
                        $winWidth = $row['battle_count'] > 0 ? ($row['win_count'] / $row['battle_count']) * 100 : 0;
                    @endphp
                    <div class="px-5 py-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <div class="font-black text-slate-700">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('m/d') }}</div>
                            <div class="text-xs font-bold text-slate-500">
                                通常 {{ number_format($row['battle_count']) }} / チャンプ {{ number_format($row['champ_battle_count']) }} / ランク {{ number_format($row['rank_battle_count']) }}
                            </div>
                        </div>
                        <div class="mt-2 h-3 overflow-hidden rounded-full bg-slate-100" style="width: {{ $battleWidth }}%;">
                            <div class="h-full bg-sky-500" style="width: {{ $winWidth }}%;"></div>
                        </div>
                        <div class="mt-1 text-[11px] font-bold text-slate-400">勝利 {{ number_format($row['win_count']) }} / 敗北 {{ number_format($row['loss_count']) }}</div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    <section class="mt-6 overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="text-lg font-black text-slate-950">コミュニティ・売上推移</h2>
            <p class="mt-1 text-xs font-bold text-slate-400">チャット投稿と fulfilled 注文の売上を日別に確認します。</p>
        </div>
        <div class="divide-y divide-slate-100">
            @foreach($dailyRows as $row)
                <div class="grid grid-cols-[6rem,1fr] gap-3 px-5 py-3 text-sm">
                    <div class="font-black text-slate-700">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('m/d') }}</div>
                    <div class="space-y-2">
                        <div>
                            <div class="flex items-center justify-between text-xs font-bold text-slate-500"><span>チャット</span><span>{{ number_format($row['chat_count']) }}</span></div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-fuchsia-500" style="width: {{ min(100, max(2, ($row['chat_count'] / $maxima['chat_count']) * 100)) }}%;"></div></div>
                        </div>
                        <div>
                            <div class="flex items-center justify-between text-xs font-bold text-slate-500"><span>売上</span><span>{{ number_format($row['revenue_jpy']) }}円 / {{ number_format($row['purchase_count']) }}件</span></div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-amber-500" style="width: {{ min(100, max(2, ($row['revenue_jpy'] / $maxima['revenue_jpy']) * 100)) }}%;"></div></div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="mt-6 overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="text-lg font-black text-slate-950">日別明細</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-slate-700">
                    <tr>
                        <th class="px-4 py-3 text-left font-bold">日付</th>
                        <th class="px-4 py-3 text-right font-bold">新規</th>
                        <th class="px-4 py-3 text-right font-bold">活動者</th>
                        <th class="px-4 py-3 text-right font-bold">通常戦闘</th>
                        <th class="px-4 py-3 text-right font-bold">勝/敗</th>
                        <th class="px-4 py-3 text-right font-bold">チャンプ</th>
                        <th class="px-4 py-3 text-right font-bold">ランク</th>
                        <th class="px-4 py-3 text-right font-bold">チャット</th>
                        <th class="px-4 py-3 text-right font-bold">売上</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @foreach(array_reverse($dailyRows) as $row)
                        <tr class="hover:bg-slate-50">
                            <td class="whitespace-nowrap px-4 py-3 font-black text-slate-900">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('Y/m/d') }}</td>
                            <td class="px-4 py-3 text-right font-bold">{{ number_format($row['new_users']) }}</td>
                            <td class="px-4 py-3 text-right font-bold text-emerald-700">{{ number_format($row['active_players']) }}</td>
                            <td class="px-4 py-3 text-right font-bold text-sky-700">{{ number_format($row['battle_count']) }}</td>
                            <td class="px-4 py-3 text-right text-xs font-bold text-slate-500">{{ number_format($row['win_count']) }} / {{ number_format($row['loss_count']) }}</td>
                            <td class="px-4 py-3 text-right font-bold">{{ number_format($row['champ_battle_count']) }}</td>
                            <td class="px-4 py-3 text-right font-bold">{{ number_format($row['rank_battle_count']) }}</td>
                            <td class="px-4 py-3 text-right font-bold text-fuchsia-700">{{ number_format($row['chat_count']) }}</td>
                            <td class="px-4 py-3 text-right font-black text-amber-700">{{ number_format($row['revenue_jpy']) }}円</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
