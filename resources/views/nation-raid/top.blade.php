<x-layouts.facility title="国家対抗レイド" subtitle="全冒険者で挑む黒天竜" :exit-url="route('home')" exitLabel="街へ戻る">
    <div class="mx-auto max-w-3xl space-y-5 pb-6" data-nation-raid-top>
        @include('nation-raid.partials.navigation', ['eventId' => $event->id, 'active' => 'top', 'finished' => $event->status === 'completed'])
        <header>
            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-600">
                <span class="font-bold text-sky-800">{{ $portal['status_label'] }}</span>
                <span>{{ $event->starts_at->format('n/j H:i') }} 〜 {{ $event->ends_at->format('n/j H:i') }}</span>
            </div>
            <h1 class="mt-2 break-words text-xl font-black text-slate-900">{{ $event->name }}</h1>
        </header>
        @if($portal['preparation'])
            <section class="rounded-2xl border border-indigo-200 bg-indigo-50 p-4 sm:p-5" aria-label="レイド兵站準備度" data-raid-logistics-readiness>
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <p class="text-xs font-bold text-indigo-700">{{ $portal['preparation']['nation_name'] }}のレイド兵站準備度</p>
                        <p class="mt-1 text-2xl font-black tabular-nums text-indigo-950">{{ $portal['preparation']['readiness_percent'] }}%</p>
                    </div>
                    <p class="text-xs font-bold text-indigo-800">{{ number_format($portal['preparation']['contribution_count']) }} / {{ number_format($portal['preparation']['contribution_target']) }}点</p>
                </div>
                <progress class="mt-3 h-3 w-full accent-indigo-700" max="100" value="{{ $portal['preparation']['readiness_percent'] }}">{{ $portal['preparation']['readiness_percent'] }}%</progress>
                @if($event->status === 'scheduled')
                    <p class="mt-3 text-xs font-bold leading-relaxed text-indigo-900">準備期間中、1日最初の通常探索勝利で兵站が1点進みます。あなたの協力は {{ $portal['preparation']['own_contribution_count'] }} / {{ $portal['preparation']['own_contribution_max'] }}点です。</p>
                @else
                    <p class="mt-3 text-xs font-bold leading-relaxed text-indigo-900">無料出撃は1日{{ $portal['preparation']['daily_free_grant'] }}回分、{{ $portal['preparation']['free_balance_cap'] }}回分まで繰り越せます。</p>
                @endif
                @if($portal['preparation']['benefits_held'])
                    <p class="mt-2 text-xs font-black text-amber-800">前回の侵攻被害が残っているため、今回の兵站上乗せは復興まで保留されています。</p>
                @endif
            </section>
        @endif
        @if($portal['outcome'])
            <section class="rounded-2xl border border-slate-200 bg-white p-4" aria-label="レイド全体進行">
                <div class="flex items-baseline justify-between gap-3"><h2 class="font-black text-slate-900">{{ $portal['outcome']['label'] }}</h2><span class="font-black tabular-nums text-sky-800">{{ $portal['outcome']['progress_percent'] }}%</span></div>
                <progress class="mt-3 h-3 w-full accent-sky-700" max="10000" value="{{ $portal['outcome']['progress_bps'] }}">{{ $portal['outcome']['progress_percent'] }}%</progress>
                @if($event->status === 'finalizing')<p class="mt-2 text-xs font-bold text-slate-600">戦果集計中。終了30分後を目安に確定します。</p>@endif
            </section>
        @endif
        @if($portal['reconstruction'])
            <section class="rounded-2xl border border-rose-200 bg-rose-50 p-4" aria-label="国家の侵攻被害と復興">
                <h2 class="font-black text-rose-950">侵攻被害 {{ $portal['reconstruction']['damage'] }}</h2>
                <p class="mt-2 text-xs font-bold leading-relaxed text-rose-900">通常探索の勝利で復興が進みます。1人1日{{ $portal['reconstruction']['daily_cap'] }}点まで。</p>
                <p class="mt-2 text-sm font-black tabular-nums text-rose-950">復興 {{ number_format($portal['reconstruction']['completed']) }} / {{ number_format($portal['reconstruction']['required']) }}点</p>
            </section>
        @endif
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white p-4 sm:p-6" aria-label="レイドボスの戦況">
            @if($portal['encounter'])
                @php($encounter = $portal['encounter'])
                <div class="grid items-center gap-4 sm:grid-cols-[14rem_minmax(0,1fr)]">
                    <img src="{{ asset($encounter['form']['image_path']) }}" alt="{{ $event->boss_name }} {{ $encounter['form']['ordinal'] }}" width="256" height="256" class="mx-auto h-48 w-48 object-contain sm:h-56 sm:w-56">
                    <div class="min-w-0">
                        <p class="text-xs font-bold text-sky-800">第{{ $encounter['stage'] }} / {{ $event->stage_count }}再臨《{{ $encounter['stage_name'] }}》</p>
                        <h2 class="mt-2 text-base font-black leading-relaxed text-slate-900">{{ $event->boss_name }}</h2>
                        <p class="mt-1 text-xs text-slate-600">{{ $encounter['form']['ordinal'] }}《{{ $encounter['form']['name'] }}》</p>
                        <dl class="mt-4">
                            <dt class="text-xs font-bold text-slate-500">ボスの残りHP</dt>
                            <dd class="mt-1 flex flex-wrap items-baseline gap-x-2 font-black tabular-nums text-slate-900">
                                <span class="text-2xl">{{ number_format($encounter['current_hp']) }}</span>
                                <span class="text-sm text-slate-500">/ {{ number_format($encounter['max_hp']) }}</span>
                            </dd>
                        </dl>
                        <progress class="mt-2 h-2 w-full accent-sky-700" aria-label="ボスの残りHP" max="{{ $encounter['max_hp'] }}" value="{{ $encounter['current_hp'] }}">{{ $portal['hp_percent'] }}%</progress>
                    </div>
                </div>
            @else
                <h2 class="font-black text-slate-900">{{ $event->boss_name }}</h2>
                <p class="mt-2 text-sm text-slate-600">ボスの戦況を確認中です。</p>
            @endif
        </section>
        <section aria-label="レイドの行き先" class="grid gap-3 sm:grid-cols-3">
            @if($portal['can_prepare'])
                <a href="{{ route('nation-raid.show', $event) }}" class="flex min-h-20 items-center justify-between gap-3 rounded-xl bg-slate-900 p-4 text-white hover:bg-slate-800">
                    <span><strong class="block text-base">戦闘</strong><span class="mt-1 block text-xs text-slate-200">装備を確認して出撃準備へ</span></span><span aria-hidden="true">›</span>
                </a>
            @else
                <div class="flex min-h-20 items-center rounded-xl border border-slate-200 bg-slate-100 p-4 text-slate-500" aria-disabled="true">
                    <span><strong class="block text-base">戦闘</strong><span class="mt-1 block text-xs">{{ $portal['status_label'] }}</span></span>
                </div>
            @endif
            <a href="{{ route('nation-raid.rankings', $event) }}" class="flex min-h-20 items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 text-slate-900 hover:bg-slate-50">
                <span><strong class="block text-base">ランキング</strong><span class="mt-1 block text-xs text-slate-600">各国の総ダメージ・連携状況</span></span><span aria-hidden="true">›</span>
            </a>
            <a href="{{ route('nation-raid.rewards', $event) }}" class="flex min-h-20 items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 text-slate-900 hover:bg-slate-50">
                <span><strong class="block text-base">報酬</strong><span class="mt-1 block text-xs text-slate-600">到達目標・戦果の受取</span></span><span aria-hidden="true">›</span>
            </a>
        </section>
        @if($portal['own_nation'])
            <section class="border-y border-slate-200 py-4" aria-label="自国の戦果">
                <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                    <h2 class="min-w-0 break-words font-black text-slate-800">{{ $portal['own_nation']['name'] }} <span class="text-sky-800">{{ $portal['own_nation']['rank'] }}位</span></h2>
                    <span class="font-black tabular-nums">{{ number_format($portal['own_nation']['damage']) }} <span class="text-xs font-normal text-slate-500">ダメージ</span></span>
                </div>
                @if($portal['own_nation']['damage_gap'] !== null)
                    <p class="mt-2 text-xs text-slate-600">上の順位まであと{{ number_format($portal['own_nation']['damage_gap']) }}ダメージ</p>
                @endif
                <div class="mt-2">@include('nation-raid.partials.coordination-badge', ['coordination' => $portal['own_nation']['coordination']])</div>
            </section>
        @endif
        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
            <span>{{ $portal['as_of'] }} 時点の戦況</span>
            <a href="{{ route('nation-raid.top', $event) }}" class="inline-flex min-h-11 items-center font-bold text-sky-800 underline underline-offset-4">最新の戦況へ更新</a>
        </div>
        <a href="{{ route('nation-raid.history') }}" class="inline-flex min-h-11 items-center text-sm font-bold text-sky-800 underline underline-offset-4">過去の戦果・未受取報酬</a>
    </div>
</x-layouts.facility>
