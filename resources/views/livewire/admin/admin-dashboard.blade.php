<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">ADMIN ANALYTICS</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">管理ダッシュボード</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">プレイヤーがどこで止まっているかを中心に、育成・戦闘・装備の利用状況を確認します。</p>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <button type="button" wire:click="downloadAiText" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-black text-slate-700 shadow-sm hover:bg-slate-50">
                AI分析用TXT
            </button>
            <button type="button" wire:click="downloadCsv" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-black text-slate-700 shadow-sm hover:bg-slate-50">
                CSV出力
            </button>
            <div class="rounded-md border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-500 shadow-sm">
                集計時刻 {{ $generatedAt->format('Y/m/d H:i') }}
            </div>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach($summaryCards as $card)
            <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-black tracking-wide text-slate-500">{{ $card['label'] }}</div>
                <div class="mt-2 text-2xl font-black text-slate-950">{{ $card['value'] }}</div>
                <div class="mt-1 text-xs font-bold text-slate-400">{{ $card['note'] }}</div>
            </div>
        @endforeach
    </div>

    <section class="mt-6 rounded-md border-2 border-amber-300 bg-white shadow-sm">
        <div class="border-b border-amber-100 bg-amber-50 px-5 py-4">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                <h2 class="text-xl font-black text-slate-950">到達街分布</h2>
                <p class="text-xs font-bold text-amber-700">highest_city_id を優先、未設定時は current_city_id で集計</p>
            </div>
        </div>
        <div class="p-5">
            <div class="space-y-4">
                @forelse($cityDistribution as $row)
                    <div>
                        <div class="mb-1 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <span class="font-black text-slate-900">{{ $row['city'] }}</span>
                                <span class="ml-2 text-xs font-bold text-slate-400">{{ $row['recommended'] }}</span>
                                @if($row['is_initial'])
                                    <span class="ml-2 rounded-full bg-slate-900 px-2 py-0.5 text-[11px] font-black text-amber-200">初期街</span>
                                @endif
                            </div>
                            <div class="shrink-0 text-sm font-black text-slate-700">
                                {{ number_format($row['count']) }}人 / {{ number_format($row['percent'], 1) }}%
                            </div>
                        </div>
                        <div class="h-3 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-gradient-to-r from-amber-400 to-slate-900" style="width: {{ min(100, $row['percent']) }}%"></div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-md bg-slate-50 p-6 text-center text-sm font-bold text-slate-500">街データまたはキャラクターデータがありません。</div>
                @endforelse
            </div>
        </div>
    </section>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
        <section class="rounded-md border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-lg font-black text-slate-950">敗北が多いダンジョン</h2>
                <p class="mt-1 text-xs font-bold text-slate-400">敗北数順。難度・導線の詰まり候補です。</p>
            </div>
            <div class="divide-y divide-slate-100">
                @forelse($dungeonLosses as $row)
                    <div class="flex items-center justify-between gap-4 px-5 py-3">
                        <div class="min-w-0">
                            <div class="truncate font-black text-slate-900">{{ $row['name'] }}</div>
                            <div class="text-xs font-bold text-slate-400">{{ $row['city'] }} / 総戦闘 {{ number_format($row['total']) }}</div>
                        </div>
                        <div class="text-right">
                            <div class="font-black text-red-600">{{ number_format($row['count']) }}敗</div>
                            <div class="text-xs font-bold text-slate-400">敗北率 {{ number_format($row['rate'], 1) }}%</div>
                        </div>
                    </div>
                @empty
                    <div class="p-6 text-sm font-bold text-slate-500">敗北ログはまだありません。</div>
                @endforelse
            </div>
        </section>

        <section class="rounded-md border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-lg font-black text-slate-950">離脱が多い地点</h2>
                <p class="mt-1 text-xs font-bold text-slate-400">7日以上ログインしていないキャラの最終到達街による推定です。</p>
            </div>
            <div class="divide-y divide-slate-100">
                @forelse($dropOffPoints as $row)
                    <div class="flex items-center justify-between gap-4 px-5 py-3">
                        <div class="font-black text-slate-900">{{ $row['name'] }}</div>
                        <div class="text-right">
                            <div class="font-black text-slate-800">{{ number_format($row['count']) }}人</div>
                            <div class="text-xs font-bold text-slate-400">離脱候補の {{ number_format($row['percent'], 1) }}%</div>
                        </div>
                    </div>
                @empty
                    <div class="p-6 text-sm font-bold text-slate-500">7日以上未ログインのキャラクターはありません。</div>
                @endforelse
            </div>
        </section>

        <section class="rounded-md border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-lg font-black text-slate-950">よく使われる職業</h2>
            </div>
            <div class="divide-y divide-slate-100">
                @forelse($popularJobs as $row)
                    <div class="flex items-center justify-between gap-4 px-5 py-3">
                        <div class="font-black text-slate-900">{{ $row['name'] }}</div>
                        <div class="text-right">
                            <div class="font-black text-slate-800">{{ number_format($row['count']) }}人</div>
                            <div class="text-xs font-bold text-slate-400">{{ number_format($row['percent'], 1) }}%</div>
                        </div>
                    </div>
                @empty
                    <div class="p-6 text-sm font-bold text-slate-500">職業データがありません。</div>
                @endforelse
            </div>
        </section>

        <section class="rounded-md border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-lg font-black text-slate-950">よく装備されている武器</h2>
            </div>
            <div class="divide-y divide-slate-100">
                @forelse($popularWeapons as $row)
                    <div class="flex items-center justify-between gap-4 px-5 py-3">
                        <div class="truncate font-black text-slate-900">{{ $row['name'] }}</div>
                        <div class="shrink-0 font-black text-slate-800">{{ number_format($row['count']) }}人</div>
                    </div>
                @empty
                    <div class="p-6 text-sm font-bold text-slate-500">装備中の武器データがありません。</div>
                @endforelse
            </div>
        </section>
    </div>
</div>
