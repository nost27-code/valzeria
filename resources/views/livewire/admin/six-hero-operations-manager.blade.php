<div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8 lg:py-10" data-six-hero-operations>
    @php
        $overallStatus = $report->overallStatus();
        $statusCounts = $report->statusCounts();
        $featureEnabled = (bool) ($report->item('feature_flag')?->metadata['enabled'] ?? false);
        $databaseMessage = $report->item('database')?->message ?? '確認不可';
        $overallPresentation = match ($overallStatus) {
            'pass' => ['正常', 'border-emerald-200 bg-emerald-50 text-emerald-800'],
            'warning' => ['要確認', 'border-amber-200 bg-amber-50 text-amber-900'],
            default => ['要対応', 'border-rose-200 bg-rose-50 text-rose-800'],
        };
    @endphp

    <header class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">SIX HEROES OPERATIONS</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">六英雄戦 運用状況</h1>
            <p class="mt-2 max-w-3xl text-sm font-bold leading-relaxed text-slate-500">
                競技データをread-onlyで診断し、既存の安全なSeason処理だけを再試行できます。順位・戦績・挑戦回数・英雄・BattleLogを直接変更する機能はありません。
            </p>
        </div>
        <div class="flex flex-wrap gap-2 text-xs font-black">
            <span class="rounded-md border px-3 py-2 {{ $featureEnabled ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-slate-300 bg-slate-100 text-slate-700' }}">
                公開状態 {{ $featureEnabled ? 'ON' : 'OFF' }}
            </span>
            <span class="rounded-md border px-3 py-2 {{ $overallPresentation[1] }}">
                System Health {{ $overallPresentation[0] }}
            </span>
        </div>
    </header>

    @if (session('status'))
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-black text-emerald-800" role="status">
            {{ session('status') }}
        </div>
    @endif
    @if (session('warning'))
        <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-black text-amber-900" role="status">
            {{ session('warning') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-black text-rose-800" role="alert">
            {{ session('error') }}
        </div>
    @endif

    <section class="grid gap-3 sm:grid-cols-3">
        @foreach ([
            ['PASS', $statusCounts['pass'], 'border-emerald-200 bg-emerald-50 text-emerald-800'],
            ['WARNING', $statusCounts['warning'], 'border-amber-200 bg-amber-50 text-amber-900'],
            ['FAIL', $statusCounts['fail'], 'border-rose-200 bg-rose-50 text-rose-800'],
        ] as [$label, $count, $classes])
            <div class="rounded-lg border p-4 shadow-sm {{ $classes }}">
                <div class="text-xs font-black tracking-[0.16em]">{{ $label }}</div>
                <div class="mt-1 text-3xl font-black">{{ $count }}</div>
            </div>
        @endforeach
    </section>

    <section class="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h2 class="text-lg font-black text-slate-950">安全な手動再試行</h2>
                <p class="mt-1 text-sm font-bold leading-relaxed text-slate-500">
                    Schedulerが遅れた場合に、既存Serviceを同じ安全条件のまま再実行します。pendingを無視する処理や直接修復は行いません。
                </p>
            </div>
            <div class="text-xs font-bold text-slate-400">最終診断 {{ $report->checkedAt->format('Y-m-d H:i:s') }}</div>
        </div>
        <div class="mt-4 grid gap-3 lg:grid-cols-3">
            <button type="button"
                    wire:click="ensureCurrentSeason"
                    wire:loading.attr="disabled"
                    wire:target="ensureCurrentSeason"
                    class="min-h-12 rounded-md bg-slate-950 px-4 py-3 text-sm font-black text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                <span wire:loading.remove wire:target="ensureCurrentSeason">現在Seasonを再確認</span>
                <span wire:loading wire:target="ensureCurrentSeason">確認中…</span>
            </button>
            <button type="button"
                    wire:click="retryCurrentRankingInitialization"
                    wire:loading.attr="disabled"
                    wire:target="retryCurrentRankingInitialization"
                    class="min-h-12 rounded-md bg-sky-700 px-4 py-3 text-sm font-black text-white shadow-sm transition hover:bg-sky-800 disabled:cursor-wait disabled:opacity-60">
                <span wire:loading.remove wire:target="retryCurrentRankingInitialization">Ranking初期化を再試行</span>
                <span wire:loading wire:target="retryCurrentRankingInitialization">再試行中…</span>
            </button>
            <button type="button"
                    wire:click="retryEndedSeasonFinalization"
                    wire:loading.attr="disabled"
                    wire:target="retryEndedSeasonFinalization"
                    class="min-h-12 rounded-md bg-amber-500 px-4 py-3 text-sm font-black text-slate-950 shadow-sm transition hover:bg-amber-400 disabled:cursor-wait disabled:opacity-60">
                <span wire:loading.remove wire:target="retryEndedSeasonFinalization">終了Season確定を再試行</span>
                <span wire:loading wire:target="retryEndedSeasonFinalization">再試行中…</span>
            </button>
        </div>
    </section>

    <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-black text-slate-500">Database</div>
            <div class="mt-2 break-words text-sm font-black text-slate-950">{{ $databaseMessage }}</div>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-black text-slate-500">現在Season</div>
            <div class="mt-2 text-xl font-black text-slate-950">{{ $current_season['key'] ?? '未確認' }}</div>
            @if ($current_season)
                <div class="mt-1 text-xs font-bold text-slate-500">{{ $current_season['starts_at'] }} ～ {{ $current_season['ends_at'] }}</div>
            @endif
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-black text-slate-500">Ranking</div>
            <div class="mt-2 text-xl font-black {{ ($current_season['ranking_initialized'] ?? false) ? 'text-emerald-700' : 'text-amber-700' }}">
                {{ ($current_season['ranking_initialized'] ?? false) ? 'READY' : 'NOT READY' }}
            </div>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-black text-slate-500">本日の公式戦（{{ $daily_usage['usage_date'] }}）</div>
            <div class="mt-2 text-xl font-black text-slate-950">{{ number_format($daily_usage['attempt_count']) }}戦</div>
            <div class="mt-1 text-xs font-bold text-slate-500">利用{{ number_format($daily_usage['player_count']) }}人 / 各間{{ $daily_usage['limit'] }}戦到達{{ number_format($daily_usage['limit_reached_count']) }}枠</div>
        </div>
    </section>

    <section class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="text-lg font-black text-slate-950">Health Check</h2>
            <p class="mt-1 text-xs font-bold text-slate-500">WARNINGは確認推奨、FAILは競技整合性または月次処理の要対応状態です。</p>
        </div>
        <div class="divide-y divide-slate-100">
            @foreach ($report->items as $item)
                @php
                    $itemPresentation = match ($item->status) {
                        'pass' => ['PASS', 'border-emerald-200 bg-emerald-50 text-emerald-800'],
                        'warning' => ['WARNING', 'border-amber-200 bg-amber-50 text-amber-900'],
                        default => ['FAIL・要対応', 'border-rose-200 bg-rose-50 text-rose-800'],
                    };
                @endphp
                <div class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="font-black text-slate-950">{{ $item->label }}</div>
                        <p class="mt-1 break-words text-sm font-semibold leading-relaxed text-slate-600">{{ $item->message }}</p>
                    </div>
                    <span class="shrink-0 rounded-md border px-2.5 py-1 text-xs font-black {{ $itemPresentation[1] }}">{{ $itemPresentation[0] }}</span>
                </div>
            @endforeach
        </div>
    </section>

    <section class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="text-lg font-black text-slate-950">現在Season・6Room概要</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-[760px] w-full text-left text-sm">
                <thead class="bg-slate-950 text-slate-100">
                    <tr>
                        <th class="px-4 py-3">Room</th>
                        <th class="px-4 py-3">登録者</th>
                        <th class="px-4 py-3">公式戦</th>
                        <th class="px-4 py-3">現在首位</th>
                        <th class="px-4 py-3">Ranking整合性</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($rooms as $room)
                        <tr>
                            <td class="px-4 py-3 font-black text-slate-950">{{ $room['room_label'] }}</td>
                            <td class="px-4 py-3 font-bold text-slate-700">{{ number_format($room['registered_count']) }}人</td>
                            <td class="px-4 py-3 font-bold text-slate-700">{{ number_format($room['official_battle_count']) }}戦</td>
                            <td class="px-4 py-3 font-bold text-slate-700">{{ $room['leader_name'] ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="font-black {{ $room['integrity_status'] === 'pass' ? 'text-emerald-700' : ($room['integrity_status'] === 'warning' ? 'text-amber-700' : 'text-rose-700') }}">
                                    {{ $room['integrity_label'] }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <section class="min-w-0 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-black text-slate-950">pending Battle</h2>
                <p class="mt-1 text-xs font-bold text-slate-500">started / resolvedを古い順に最大{{ $battle_list_limit }}件表示します。自動変更は行いません。</p>
            </div>
            @if ($pending_battles === [])
                <p class="p-6 text-center text-sm font-bold text-slate-500">未完了公式戦はありません。</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-[780px] w-full text-left text-xs">
                        <thead class="bg-slate-100 text-slate-600">
                            <tr><th class="px-3 py-3">ID</th><th class="px-3 py-3">Season / Room</th><th class="px-3 py-3">対戦</th><th class="px-3 py-3">状態</th><th class="px-3 py-3">開始 / 経過</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($pending_battles as $battle)
                                <tr>
                                    <td class="px-3 py-3 font-mono font-bold text-slate-600">{{ $battle['id'] }}</td>
                                    <td class="px-3 py-3"><div class="font-black text-slate-900">{{ $battle['season_key'] }}</div><div class="text-slate-500">{{ $battle['room_label'] }}</div></td>
                                    <td class="px-3 py-3 font-bold text-slate-700">{{ $battle['attacker_name'] }} → {{ $battle['defender_name'] }}</td>
                                    <td class="px-3 py-3 font-black text-amber-700">{{ $battle['status'] }}</td>
                                    <td class="px-3 py-3"><div class="font-bold text-slate-700">{{ $battle['started_at'] }}</div><div class="text-slate-500">{{ $battle['age_label'] }}</div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="min-w-0 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-black text-slate-950">failed Battle</h2>
                <p class="mt-1 text-xs font-bold text-slate-500">最近のfailed公式戦を最大{{ $battle_list_limit }}件表示します。BattleLogは削除・変更しません。</p>
            </div>
            @if ($failed_battles === [])
                <p class="p-6 text-center text-sm font-bold text-slate-500">failed公式戦はありません。</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-[780px] w-full text-left text-xs">
                        <thead class="bg-slate-100 text-slate-600">
                            <tr><th class="px-3 py-3">ID</th><th class="px-3 py-3">Season / Room</th><th class="px-3 py-3">対戦</th><th class="px-3 py-3">Failure</th><th class="px-3 py-3">失敗時刻</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($failed_battles as $battle)
                                <tr>
                                    <td class="px-3 py-3 font-mono font-bold text-slate-600">{{ $battle['id'] }}</td>
                                    <td class="px-3 py-3"><div class="font-black text-slate-900">{{ $battle['season_key'] }}</div><div class="text-slate-500">{{ $battle['room_label'] }}</div></td>
                                    <td class="px-3 py-3 font-bold text-slate-700">{{ $battle['attacker_name'] }} → {{ $battle['defender_name'] }}</td>
                                    <td class="px-3 py-3 font-mono font-bold text-rose-700">{{ $battle['failure_code'] ?? 'unknown' }}</td>
                                    <td class="px-3 py-3 font-bold text-slate-700">{{ $battle['failed_at'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</div>
