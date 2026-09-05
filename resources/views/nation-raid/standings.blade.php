<section class="rounded-xl border border-slate-200 bg-white px-4 py-4 shadow-sm" data-nation-raid-standings>
    <h2 class="text-sm font-black text-slate-950">{{ ($screen['standings']['is_final'] ?? false) ? '最終戦績' : 'みんなの戦績' }}</h2>
    @if($screen['standings'] === null)
        <p class="mt-2 text-xs text-slate-600">戦績を確認中です。しばらく待ってから読み直してください。</p>
    @else
        @php($own = $screen['own_progress'])
        <dl class="mt-3 grid grid-cols-3 gap-2 border-b border-slate-100 pb-3 text-center text-xs">
            <div><dt class="text-slate-500">自分の累計</dt><dd class="mt-1 font-black tabular-nums">{{ number_format($own['damage'] ?? 0) }}</dd></div>
            <div><dt class="text-slate-500">1行動最大</dt><dd class="mt-1 font-black tabular-nums">{{ number_format($own['max_action_damage'] ?? 0) }}</dd></div>
            <div><dt class="text-slate-500">出撃回数</dt><dd class="mt-1 font-black tabular-nums">{{ $own['resolved_sorties'] ?? 0 }}回</dd></div>
        </dl>
        @foreach(['nation_total' => '国家総ダメージ', 'personal_total' => '個人累計ダメージ', 'max_action' => '1行動最大ダメージ', 'nation_per_capita' => '国家一人あたり（参考）'] as $key => $label)
            @continue($key === 'nation_total' && ($hideNationTotal ?? false))
            <details class="border-b border-slate-100 py-3 last:border-0">
                <summary class="cursor-pointer text-xs font-black text-slate-800">{{ $label }}</summary>
                @if($key === 'nation_per_capita')
                    <p class="mt-2 text-[11px] text-slate-500">開始時に固定した基準人数で計算します。国家連携分は含まず、この順位に報酬は付きません。</p>
                @elseif($key === 'nation_total')
                    <p class="mt-2 text-[11px] text-slate-500">開始時の国家帰属で集計し、国家連携のダメージも含みます。</p>
                @else
                    <p class="mt-2 text-[11px] text-slate-500">国家連携分を含まない個人の記録です。</p>
                @endif
                <ol class="mt-2 divide-y divide-slate-100">
                    @forelse($screen['standings'][$key] as $row)
                        <li class="grid min-w-0 grid-cols-[2.5rem_minmax(0,1fr)_auto] items-center gap-2 py-2 text-xs">
                            <span class="font-black text-slate-500">{{ $row['rank'] === null ? '—' : $row['rank'].'位' }}</span>
                            <span class="min-w-0 break-words font-bold text-slate-900">{{ $row['name'] }}</span>
                            <span class="text-right font-black tabular-nums text-slate-800">
                                @if($key === 'nation_per_capita')
                                    {{ $row['rank'] === null ? '基準人数未確定' : number_format(intdiv($row['damage'], $row['denominator'])) }}
                                    @if($row['rank'] !== null)<span class="block text-[10px] font-normal text-slate-500">基準 {{ $row['denominator'] }}人</span>@endif
                                @else
                                    {{ number_format($row['damage']) }}
                                @endif
                            </span>
                        </li>
                    @empty
                        <li class="py-2 text-xs text-slate-500">まだ出撃記録がありません。</li>
                    @endforelse
                </ol>
            </details>
        @endforeach
        <p class="mt-3 text-[11px] text-slate-500">無所属・国家集計外からの貢献：{{ number_format($screen['standings']['unaffiliated_damage']) }}</p>
    @endif
</section>
