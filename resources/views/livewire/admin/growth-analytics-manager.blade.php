<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">GROWTH ANALYTICS</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">運営分析</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">新規定着、輝石売上、奥義セット状況をまとめて確認します。</p>
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
                集計時刻 {{ $generatedAt->format('Y/m/d H:i') }}
            </div>
        </div>
    </div>

    <section class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="text-lg font-black text-slate-950">新規ユーザーファンネル</h2>
            <p class="mt-1 text-xs font-bold text-slate-400">ユーザー登録日コホート別に、登録後Day1/Day3/Day7時点で活動が残っている割合を表示します。活動判定はキャラクターの last_seen_at / updated_at を使います。</p>
        </div>

        <div class="grid grid-cols-2 gap-3 border-b border-slate-100 bg-slate-50/60 p-5 lg:grid-cols-4">
            <div class="rounded-md border border-slate-200 bg-white p-4">
                <div class="text-xs font-black text-slate-500">期間内登録</div>
                <div class="mt-2 text-2xl font-black text-slate-950">{{ number_format($retentionTotals['registered']) }}</div>
            </div>
            @foreach(['day1' => 'Day1定着', 'day3' => 'Day3定着', 'day7' => 'Day7定着'] as $key => $label)
                <div class="rounded-md border border-slate-200 bg-white p-4">
                    <div class="text-xs font-black text-slate-500">{{ $label }}</div>
                    <div class="mt-2 text-2xl font-black text-emerald-700">
                        {{ $retentionTotals[$key]['rate'] === null ? '-' : $retentionTotals[$key]['rate'] . '%' }}
                    </div>
                    <div class="mt-1 text-xs font-bold text-slate-400">
                        {{ number_format($retentionTotals[$key]['retained']) }} / {{ number_format($retentionTotals[$key]['eligible']) }} eligible
                    </div>
                </div>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-slate-700">
                    <tr>
                        <th class="px-4 py-3 text-left font-bold">登録日</th>
                        <th class="px-4 py-3 text-right font-bold">登録数</th>
                        <th class="px-4 py-3 text-right font-bold">Day1</th>
                        <th class="px-4 py-3 text-right font-bold">Day3</th>
                        <th class="px-4 py-3 text-right font-bold">Day7</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse($retentionCohorts as $cohort)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 whitespace-nowrap font-black text-slate-900">{{ \Illuminate\Support\Carbon::parse($cohort['date'])->format('Y/m/d') }}</td>
                            <td class="px-4 py-3 text-right font-black text-slate-900">{{ number_format($cohort['registered']) }}</td>
                            @foreach(['day1', 'day3', 'day7'] as $key)
                                @php $cell = $cohort[$key]; @endphp
                                <td class="px-4 py-3 text-right">
                                    @if($cell['rate'] === null)
                                        <span class="font-bold text-slate-400">未到達</span>
                                    @else
                                        <span class="font-black text-emerald-700">{{ $cell['rate'] }}%</span>
                                        <div class="text-[11px] font-bold text-slate-400">{{ number_format($cell['retained']) }} / {{ number_format($cell['eligible']) }}</div>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-sm font-bold text-slate-500">対象期間の登録ユーザーはありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-6 overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="text-lg font-black text-slate-950">収益サマリー</h2>
            <p class="mt-1 text-xs font-bold text-slate-400">Stripe fulfilled 注文を対象に、日次/月次売上、有料転換率、LTV上位を表示します。</p>
        </div>

        @unless($tablesReady['stripe_orders'])
            <div class="m-5 rounded-md border border-amber-200 bg-amber-50 px-5 py-4 text-sm font-bold text-amber-800">
                stripe_orders テーブルがないため、収益集計は表示できません。
            </div>
        @else
            <div class="grid grid-cols-2 gap-3 border-b border-slate-100 bg-slate-50/60 p-5 lg:grid-cols-4">
                @foreach($revenueCards as $card)
                    <div class="rounded-md border border-slate-200 bg-white p-4">
                        <div class="text-xs font-black text-slate-500">{{ $card['label'] }}</div>
                        <div class="mt-2 text-2xl font-black text-slate-950">{{ $card['value'] }}</div>
                        <div class="mt-1 text-xs font-bold text-slate-400">{{ $card['note'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-1 gap-6 p-5 xl:grid-cols-2">
                <div class="overflow-hidden rounded-md border border-slate-200">
                    <div class="border-b border-slate-100 bg-slate-50 px-4 py-3">
                        <h3 class="font-black text-slate-950">日次売上</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-white text-slate-700">
                                <tr>
                                    <th class="px-4 py-3 text-left font-bold">日付</th>
                                    <th class="px-4 py-3 text-right font-bold">売上</th>
                                    <th class="px-4 py-3 text-right font-bold">輝石</th>
                                    <th class="px-4 py-3 text-right font-bold">購入</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach($dailyRevenue as $row)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-3 whitespace-nowrap font-black text-slate-900">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('Y/m/d') }}</td>
                                        <td class="px-4 py-3 text-right font-black text-amber-700">{{ number_format($row['revenue_jpy']) }}円</td>
                                        <td class="px-4 py-3 text-right font-bold text-sky-700">{{ number_format($row['kiseki_amount']) }}</td>
                                        <td class="px-4 py-3 text-right text-xs font-bold text-slate-500">{{ number_format($row['purchase_count']) }}件 / {{ number_format($row['buyer_count']) }}人</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="overflow-hidden rounded-md border border-slate-200">
                    <div class="border-b border-slate-100 bg-slate-50 px-4 py-3">
                        <h3 class="font-black text-slate-950">月次売上</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-white text-slate-700">
                                <tr>
                                    <th class="px-4 py-3 text-left font-bold">月</th>
                                    <th class="px-4 py-3 text-right font-bold">売上</th>
                                    <th class="px-4 py-3 text-right font-bold">輝石</th>
                                    <th class="px-4 py-3 text-right font-bold">購入</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @forelse($monthlyRevenue as $row)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-3 whitespace-nowrap font-black text-slate-900">{{ $row['month'] }}</td>
                                        <td class="px-4 py-3 text-right font-black text-amber-700">{{ number_format($row['revenue_jpy']) }}円</td>
                                        <td class="px-4 py-3 text-right font-bold text-sky-700">{{ number_format($row['kiseki_amount']) }}</td>
                                        <td class="px-4 py-3 text-right text-xs font-bold text-slate-500">{{ number_format($row['purchase_count']) }}件 / {{ number_format($row['buyer_count']) }}人</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-6 py-8 text-center text-sm font-bold text-slate-500">月次売上はありません。</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="border-t border-slate-100 px-5 pb-5">
                <h3 class="py-4 font-black text-slate-950">LTV上位プレイヤー</h3>
                <div class="overflow-x-auto rounded-md border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-slate-700">
                            <tr>
                                <th class="px-4 py-3 text-left font-bold">プレイヤー</th>
                                <th class="px-4 py-3 text-right font-bold">LTV</th>
                                <th class="px-4 py-3 text-right font-bold">購入輝石</th>
                                <th class="px-4 py-3 text-right font-bold">購入回数</th>
                                <th class="px-4 py-3 text-left font-bold">最終購入</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @forelse($topLtvPlayers as $player)
                                <tr class="hover:bg-slate-50">
                                    <td class="px-4 py-3">
                                        <div class="font-black text-slate-900">{{ $player->name }}</div>
                                        <div class="text-xs font-bold text-slate-400">{{ optional($player->user)->email ?? 'N/A' }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-right font-black text-amber-700">{{ number_format((int) $player->ltv_jpy) }}円</td>
                                    <td class="px-4 py-3 text-right font-bold text-sky-700">{{ number_format((int) $player->purchased_kiseki) }}</td>
                                    <td class="px-4 py-3 text-right font-bold text-slate-700">{{ number_format((int) $player->purchase_count) }}</td>
                                    <td class="px-4 py-3 text-xs font-bold text-slate-500">{{ $player->last_purchase_at ? \Illuminate\Support\Carbon::parse($player->last_purchase_at)->format('Y/m/d H:i') : '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-6 py-8 text-center text-sm font-bold text-slate-500">購入者はまだいません。</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endunless
    </section>

    <section class="mt-6 overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="text-lg font-black text-slate-950">奥義使用分析</h2>
            <p class="mt-1 text-xs font-bold text-slate-400">奥義をセットしているプレイヤー割合、スロット別選択率、未使用奥義を確認します。</p>
        </div>

        @if(!$tablesReady['character_job_art_slots'] || !$tablesReady['skills'])
            <div class="m-5 rounded-md border border-amber-200 bg-amber-50 px-5 py-4 text-sm font-bold text-amber-800">
                奥義スロットまたはスキルテーブルがないため、奥義分析は表示できません。
            </div>
        @else
            <div class="grid grid-cols-2 gap-3 border-b border-slate-100 bg-slate-50/60 p-5 lg:grid-cols-4">
                @foreach($jobArtCards as $card)
                    <div class="rounded-md border border-slate-200 bg-white p-4">
                        <div class="text-xs font-black text-slate-500">{{ $card['label'] }}</div>
                        <div class="mt-2 text-2xl font-black text-slate-950">{{ $card['value'] }}</div>
                        <div class="mt-1 text-xs font-bold text-slate-400">{{ $card['note'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-1 gap-6 p-5 xl:grid-cols-3">
                <div class="overflow-hidden rounded-md border border-slate-200">
                    <div class="border-b border-slate-100 bg-slate-50 px-4 py-3">
                        <h3 class="font-black text-slate-950">スロット別セット率</h3>
                    </div>
                    <div class="divide-y divide-slate-100">
                        @forelse($jobArtSlotRates as $row)
                            <div class="px-4 py-3">
                                <div class="flex items-center justify-between gap-3 text-sm">
                                    <span class="font-black text-slate-900">Slot {{ $row['slot_no'] }}</span>
                                    <span class="font-black text-emerald-700">{{ $row['rate'] }}%</span>
                                </div>
                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-emerald-500" style="width: {{ min(100, $row['rate']) }}%;"></div>
                                </div>
                                <div class="mt-1 text-xs font-bold text-slate-400">{{ number_format($row['character_count']) }}人 / {{ number_format($row['set_count']) }}セット</div>
                            </div>
                        @empty
                            <div class="px-4 py-8 text-center text-sm font-bold text-slate-500">奥義セットはまだありません。</div>
                        @endforelse
                    </div>
                </div>

                <div class="overflow-hidden rounded-md border border-slate-200">
                    <div class="border-b border-slate-100 bg-slate-50 px-4 py-3">
                        <h3 class="font-black text-slate-950">奥義選択数ランキング</h3>
                    </div>
                    <div class="max-h-[520px] overflow-y-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="sticky top-0 bg-white text-slate-700">
                                <tr>
                                    <th class="px-4 py-3 text-left font-bold">奥義</th>
                                    <th class="px-4 py-3 text-right font-bold">セット</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @forelse($jobArtSkillUsage as $skill)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-3">
                                            <div class="font-black text-slate-900">{{ $skill->name }}</div>
                                            <div class="text-xs font-bold text-slate-400">{{ $skill->job_name ?? '職業不明' }} / Rank{{ $skill->learn_rank }}</div>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <div class="font-black text-slate-900">{{ number_format((int) $skill->set_count) }}</div>
                                            <div class="text-xs font-bold text-slate-400">{{ number_format((int) $skill->character_count) }}人</div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="2" class="px-6 py-8 text-center text-sm font-bold text-slate-500">奥義データがありません。</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="overflow-hidden rounded-md border border-slate-200">
                    <div class="border-b border-slate-100 bg-slate-50 px-4 py-3">
                        <h3 class="font-black text-slate-950">使われていない奥義</h3>
                    </div>
                    <div class="max-h-[520px] overflow-y-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="sticky top-0 bg-white text-slate-700">
                                <tr>
                                    <th class="px-4 py-3 text-left font-bold">奥義</th>
                                    <th class="px-4 py-3 text-left font-bold">習得</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @forelse($unusedJobArts as $skill)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-3 font-black text-slate-900">{{ $skill->name }}</td>
                                        <td class="px-4 py-3 text-xs font-bold text-slate-500">{{ $skill->job_name ?? '職業不明' }} / Rank{{ $skill->learn_rank }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="2" class="px-6 py-8 text-center text-sm font-bold text-emerald-700">未使用の奥義はありません。</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </section>
</div>
