<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">TOP ANALYTICS</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">TOPページアクセス解析</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">リンク元、滞在時間、登録導線のクリック数を確認します。</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @foreach([1 => '今日', 7 => '7日', 30 => '30日', 90 => '90日'] as $value => $label)
                <button type="button"
                        wire:click="setDays({{ $value }})"
                        class="rounded-md px-3 py-2 text-xs font-black shadow-sm ring-1 transition {{ $days === $value ? 'bg-slate-950 text-white ring-slate-950' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach($summaryCards as $card)
            <div class="rounded-md bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <div class="text-xs font-black text-slate-500">{{ $card['label'] }}</div>
                <div class="mt-2 flex items-baseline gap-1">
                    <span class="text-2xl font-black text-slate-950">{{ $card['value'] }}</span>
                    @if($card['unit'])
                        <span class="text-xs font-black text-amber-700">{{ $card['unit'] }}</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <section class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-lg font-black text-slate-950">登録・ログイン導線</h2>
            <div class="mt-4 space-y-2">
                @forelse($ctaCounts as $row)
                    <div class="flex items-center justify-between rounded-md bg-slate-50 px-3 py-2">
                        <div class="text-sm font-bold text-slate-700">{{ $row['label'] }}</div>
                        <div class="text-lg font-black text-slate-950">{{ number_format($row['total']) }}<span class="ml-0.5 text-xs text-amber-700">回</span></div>
                    </div>
                @empty
                    <div class="rounded-md bg-slate-50 px-3 py-8 text-center text-sm font-bold text-slate-500">まだクリック記録がありません。</div>
                @endforelse
            </div>
        </section>

        <section class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-lg font-black text-slate-950">日別TOP訪問数</h2>
            <div class="mt-4 space-y-2">
                @php $maxDaily = max(1, collect($dailyRows)->max('total') ?? 1); @endphp
                @foreach($dailyRows as $row)
                    <div class="grid grid-cols-[44px_minmax(0,1fr)_56px] items-center gap-2 text-xs font-bold">
                        <span class="text-slate-500">{{ $row['day'] }}</span>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-amber-500" style="width: {{ max(4, (int) floor(($row['total'] / $maxDaily) * 100)) }}%;"></div>
                        </div>
                        <span class="text-right text-slate-900">{{ number_format($row['total']) }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-lg font-black text-slate-950">リンク元</h2>
            <div class="mt-4 space-y-2">
                @forelse($refererRows as $row)
                    <div class="flex items-center justify-between gap-3 rounded-md bg-slate-50 px-3 py-2">
                        <div class="min-w-0 truncate text-sm font-bold text-slate-700">{{ $row['label'] }}</div>
                        <div class="shrink-0 text-sm font-black text-slate-950">{{ number_format($row['total']) }}</div>
                    </div>
                @empty
                    <div class="rounded-md bg-slate-50 px-3 py-8 text-center text-sm font-bold text-slate-500">リンク元データがありません。</div>
                @endforelse
            </div>
        </section>

        <section class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-lg font-black text-slate-950">端末種別</h2>
            <div class="mt-4 space-y-2">
                @forelse($deviceRows as $row)
                    <div class="flex items-center justify-between rounded-md bg-slate-50 px-3 py-2">
                        <div class="text-sm font-bold text-slate-700">{{ $row['label'] }}</div>
                        <div class="text-sm font-black text-slate-950">{{ number_format($row['total']) }}</div>
                    </div>
                @empty
                    <div class="rounded-md bg-slate-50 px-3 py-8 text-center text-sm font-bold text-slate-500">端末データがありません。</div>
                @endforelse
            </div>
        </section>
    </div>

    <section class="mt-5 rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="text-lg font-black text-slate-950">最近のイベント</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead>
                    <tr class="text-left text-xs font-black text-slate-500">
                        <th class="px-3 py-2">日時</th>
                        <th class="px-3 py-2">イベント</th>
                        <th class="px-3 py-2">リンク元</th>
                        <th class="px-3 py-2">補足</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($recentEvents as $event)
                        <tr>
                            <td class="whitespace-nowrap px-3 py-2 font-bold text-slate-500">{{ $event->occurred_at?->format('m/d H:i:s') }}</td>
                            <td class="whitespace-nowrap px-3 py-2 font-black text-slate-900">{{ $event->event_name }}</td>
                            <td class="max-w-48 truncate px-3 py-2 font-bold text-slate-600">{{ $event->visit?->referer_host ?: '直接/不明' }}</td>
                            <td class="max-w-xl truncate px-3 py-2 font-bold text-slate-500">
                                {{ collect($event->metadata ?? [])->map(fn($v, $k) => "{$k}: {$v}")->implode(' / ') ?: '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-3 py-8 text-center text-sm font-bold text-slate-500">イベントはまだありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold leading-7 text-amber-900">
        取得できる情報: リンク元、端末種別、UTM、TOP滞在時間、各登録/ログイン導線のクリック数。IPアドレスは生値では保存せず、推定ユニーク判定用のハッシュのみ保存しています。
    </div>
</div>
