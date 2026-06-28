<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">NPC MARKET</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">NPC調達・市場分析</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">NPCごとの納品素材、現在在庫、出品中、販売済み数と販売額を確認します。</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @foreach(['all' => '全員', 'common' => '一般', 'skilled' => '熟練', 'hero' => '上級', 'legend' => 'レジェンド'] as $value => $label)
                <button type="button"
                        wire:click="setRank('{{ $value }}')"
                        class="rounded-md px-3 py-2 text-xs font-black shadow-sm ring-1 transition {{ $rank === $value ? 'bg-slate-950 text-white ring-slate-950' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    @if($missingTables)
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-8 text-center text-sm font-black text-amber-900">
            NPC調達・市場の集計に必要なテーブルがまだ揃っていません。
        </div>
    @else
        <div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            @foreach($summaryCards as $card)
                <div class="rounded-md bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <div class="text-xs font-black text-slate-500">{{ $card['label'] }}</div>
                    <div class="mt-2 flex items-baseline gap-1">
                        <span class="text-2xl font-black text-slate-950">{{ $card['value'] }}</span>
                        <span class="text-xs font-black text-amber-700">{{ $card['unit'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>

        <section class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-lg font-black text-slate-950">NPC別サマリ</h2>
                    <p class="mt-1 text-xs font-bold text-slate-500">納品は依頼に届けられた累計、在庫は未出品分、出品中は市場に残っている数量です。</p>
                </div>
                <div class="text-xs font-black text-slate-500">{{ number_format($npcRows->count()) }}人</div>
            </div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead>
                        <tr class="text-left text-xs font-black text-slate-500">
                            <th class="px-3 py-2">NPC</th>
                            <th class="px-3 py-2 text-right">依頼</th>
                            <th class="px-3 py-2 text-right">納品</th>
                            <th class="px-3 py-2 text-right">在庫</th>
                            <th class="px-3 py-2 text-right">出品中</th>
                            <th class="px-3 py-2 text-right">販売済み</th>
                            <th class="px-3 py-2 text-right">販売額</th>
                            <th class="px-3 py-2 text-right">平均単価</th>
                            <th class="px-3 py-2">最終販売</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($npcRows as $row)
                            <tr>
                                <td class="min-w-56 px-3 py-3">
                                    <div class="flex items-center gap-3">
                                        <img src="{{ asset($row['npc']->image_path) }}" alt="" class="h-10 w-10 shrink-0 object-contain">
                                        <div class="min-w-0">
                                            <div class="truncate font-black text-slate-950">{{ $row['npc']->npc_name }}</div>
                                            <div class="text-xs font-bold text-slate-500">{{ $row['npc']->npc_rank }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-bold text-slate-700">
                                    {{ number_format($row['request_count']) }}
                                    <span class="text-xs text-slate-400">完{{ number_format($row['completed_request_count']) }}</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-black text-slate-950">{{ number_format($row['delivered_quantity']) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-black text-slate-950">{{ number_format($row['stock_quantity']) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-black text-slate-950">
                                    {{ number_format($row['active_listing_quantity']) }}
                                    <span class="text-xs text-slate-400">{{ number_format($row['active_listing_count']) }}件</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-black text-slate-950">{{ number_format($row['sold_quantity']) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-black text-amber-700">{{ number_format($row['sold_revenue']) }}G</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-bold text-slate-700">{{ $row['avg_price'] > 0 ? number_format($row['avg_price']) . 'G' : '-' }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-xs font-bold text-slate-500">
                                    {{ $row['latest_sale_at'] ? \Illuminate\Support\Carbon::parse($row['latest_sale_at'])->timezone('Asia/Tokyo')->format('m/d H:i') : '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-3 py-8 text-center text-sm font-bold text-slate-500">集計対象のNPC活動はまだありません。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(360px,0.7fr)]">
            <section class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-lg font-black text-slate-950">素材別明細</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-black text-slate-500">
                                <th class="px-3 py-2">NPC</th>
                                <th class="px-3 py-2">素材</th>
                                <th class="px-3 py-2 text-right">納品</th>
                                <th class="px-3 py-2 text-right">在庫</th>
                                <th class="px-3 py-2 text-right">出品中</th>
                                <th class="px-3 py-2 text-right">販売</th>
                                <th class="px-3 py-2 text-right">販売額</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($materialRows as $row)
                                <tr>
                                    <td class="whitespace-nowrap px-3 py-2 font-bold text-slate-700">{{ $row['npc']->npc_name }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 font-black text-slate-950">{{ $row['material']->displayName() }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-black text-slate-950">{{ number_format($row['delivered_quantity']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-bold text-slate-700">{{ number_format($row['stock_quantity']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-bold text-slate-700">{{ number_format($row['active_listing_quantity']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-black text-slate-950">{{ number_format($row['sold_quantity']) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-black text-amber-700">{{ number_format($row['sold_revenue']) }}G</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-3 py-8 text-center text-sm font-bold text-slate-500">素材別の集計はまだありません。</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 text-xs font-bold text-slate-500">表示は活動量が多い順に最大80件です。</div>
            </section>

            <section class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-lg font-black text-slate-950">最近のNPC販売</h2>
                <div class="mt-4 space-y-2">
                    @forelse($recentSales as $sale)
                        <div class="rounded-md bg-slate-50 px-3 py-2">
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-black text-slate-950">{{ $sale->material?->displayName() ?? '不明な素材' }} x{{ number_format((int) $sale->quantity) }}</div>
                                    <div class="mt-0.5 truncate text-xs font-bold text-slate-500">
                                        {{ $sale->sellerNpc?->npc_name ?? '旅の冒険者' }} → {{ $sale->buyer?->name ?? '冒険者' }}
                                    </div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div class="text-sm font-black text-amber-700">{{ number_format((int) $sale->total_price) }}G</div>
                                    <div class="text-xs font-bold text-slate-500">{{ $sale->created_at?->timezone('Asia/Tokyo')->format('m/d H:i') }}</div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-md bg-slate-50 px-3 py-8 text-center text-sm font-bold text-slate-500">NPC販売履歴はまだありません。</div>
                    @endforelse
                </div>
            </section>
        </div>
    @endif
</div>
