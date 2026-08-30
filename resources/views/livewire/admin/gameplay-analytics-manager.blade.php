<div class="space-y-6">
    <header class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-xs font-black tracking-[0.18em] text-violet-600">LIVE GAMEPLAY METRICS</p>
                <h1 class="mt-1 text-2xl font-black text-slate-950">戦技・探索実績</h1>
                <p class="mt-2 max-w-3xl text-sm font-bold leading-6 text-slate-600">
                    実際に実行された戦闘と探索要求を集計します。管理者・テストキャラは除外し、導入前の履歴は推測で補完しません。
                </p>
                <p class="mt-1 text-xs font-bold text-slate-400">
                    計測開始 {{ $measurementStartedAt ? \Illuminate\Support\Carbon::parse($measurementStartedAt)->format('Y/m/d H:i') : 'まだ実績がありません' }}
                    ・更新 {{ $generatedAt->format('Y/m/d H:i:s') }}
                </p>
            </div>
            <label class="text-xs font-black text-slate-600">
                集計期間
                <select wire:model.live="activityWindow" class="mt-1 rounded-md border-slate-300 bg-white text-sm font-bold">
                    <option value="7">直近7日</option>
                    <option value="30">直近30日</option>
                    <option value="90">直近90日</option>
                    <option value="all">全期間</option>
                </select>
            </label>
        </div>
    </header>

    @unless($ready)
        <div class="rounded-md border border-amber-200 bg-amber-50 p-5 text-sm font-bold text-amber-900">
            計測テーブルがまだ準備されていません。migration後から実績の記録を開始します。
        </div>
    @else
        <section class="space-y-4">
            <div>
                <h2 class="text-xl font-black text-slate-950">戦技の実戦実績</h2>
                <p class="mt-1 text-xs font-bold text-slate-500">戦技が一度でも発動した戦闘と、発動しなかった戦闘を同じ母数で比較します。</p>
            </div>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-6">
                @foreach([
                    ['戦闘数', number_format($jobArt['cards']['battles'])],
                    ['戦技発動戦', number_format($jobArt['cards']['art_battles'])],
                    ['発動戦率', $jobArt['cards']['activation_battle_rate'].'%'],
                    ['総発動数', number_format($jobArt['cards']['activations'])],
                    ['発動あり勝率', $jobArt['cards']['with_art_win_rate'] === null ? '-' : $jobArt['cards']['with_art_win_rate'].'%'],
                    ['発動なし勝率', $jobArt['cards']['without_art_win_rate'] === null ? '-' : $jobArt['cards']['without_art_win_rate'].'%'],
                ] as [$label, $value])
                    <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="text-[11px] font-black text-slate-500">{{ $label }}</div>
                        <div class="mt-2 text-xl font-black text-slate-950">{{ $value }}</div>
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-[1.4fr_1fr]">
                <div class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-4">
                        <h3 class="font-black text-slate-950">発動された戦技</h3>
                        <p class="mt-1 text-xs font-bold text-slate-400">命中率はHIT/MISS/EVADEを返す攻撃型だけを母数にします。急所命中率はHITを母数にし、支援型は「判定なし」です。</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead class="bg-slate-50 font-black text-slate-500"><tr><th class="px-4 py-3">戦技</th><th class="px-3 py-3 text-right">戦闘</th><th class="px-3 py-3 text-right">発動</th><th class="px-3 py-3 text-right">命中率</th><th class="px-3 py-3 text-right">急所命中</th><th class="px-3 py-3 text-right">勝率</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse($jobArt['skillRows'] as $row)
                                    <tr><td class="px-4 py-3 font-black text-slate-900">{{ $row['name'] }}</td><td class="px-3 py-3 text-right font-bold">{{ number_format($row['battles']) }}</td><td class="px-3 py-3 text-right font-bold">{{ number_format($row['activations']) }}</td><td class="px-3 py-3 text-right font-bold">{{ $row['hit_rate'] === null ? '判定なし' : $row['hit_rate'].'%' }}</td><td class="px-3 py-3 text-right font-bold">{{ $row['vital_hit_rate'] === null ? '—' : $row['vital_hit_rate'].'%（'.number_format($row['vital_hits']).'回）' }}</td><td class="px-3 py-3 text-right font-bold">{{ $row['win_rate'] }}%</td></tr>
                                @empty
                                    <tr><td colspan="6" class="px-4 py-10 text-center font-bold text-slate-400">期間内の戦技発動実績はありません。</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-4"><h3 class="font-black text-slate-950">戦闘経路別</h3></div>
                    <div class="divide-y divide-slate-100">
                        @forelse($jobArt['contextRows'] as $row)
                            <div class="grid grid-cols-[1fr_auto] gap-3 px-4 py-3">
                                <div><div class="font-black text-slate-900">{{ $row['label'] }}</div><div class="mt-1 text-[11px] font-bold text-slate-500">{{ number_format($row['battles']) }}戦・{{ number_format($row['activations']) }}回発動</div></div>
                                <div class="text-right text-xs font-black text-violet-700">発動戦 {{ $row['activation_battle_rate'] }}%<br><span class="text-slate-500">勝率 {{ $row['win_rate'] }}%</span></div>
                            </div>
                        @empty
                            <div class="px-4 py-10 text-center text-xs font-bold text-slate-400">実績はありません。</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <div>
                <h2 class="text-xl font-black text-slate-950">探索の実行実績</h2>
                <p class="mt-1 text-xs font-bold text-slate-500">1回探索とまとめて探索を、実行1回あたりの報酬・100戦あたりのドロップで比較できます。</p>
            </div>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-6">
                @foreach([
                    ['探索要求', number_format($exploration['cards']['requests'])],
                    ['要求戦数', number_format($exploration['cards']['requested_runs'])],
                    ['完了回数', number_format($exploration['cards']['completed_runs'])],
                    ['完了率', $exploration['cards']['completion_rate'].'%'],
                    ['1回探索', number_format($exploration['cards']['single_requests'])],
                    ['まとめ探索', number_format($exploration['cards']['batch_requests'])],
                ] as [$label, $value])
                    <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm"><div class="text-[11px] font-black text-slate-500">{{ $label }}</div><div class="mt-2 text-xl font-black text-slate-950">{{ $value }}</div></div>
                @endforeach
            </div>

            <div class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-[1150px] w-full text-left text-xs">
                        <thead class="bg-slate-50 font-black text-slate-500"><tr><th class="px-4 py-3">方式</th><th class="px-3 py-3 text-right">要求</th><th class="px-3 py-3 text-right">完了</th><th class="px-3 py-3 text-right">完了率</th><th class="px-3 py-3 text-right">EXP/回</th><th class="px-3 py-3 text-right">Gold/回</th><th class="px-3 py-3 text-right">職EXP/回</th><th class="px-3 py-3 text-right">装備/100回</th><th class="px-3 py-3 text-right">素材/100回</th><th class="px-3 py-3 text-right">印/100回</th><th class="px-3 py-3 text-right">地図/100回</th><th class="px-3 py-3 text-right">探索力/回</th><th class="px-3 py-3 text-right">危険度/回</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($exploration['modeRows'] as $row)
                                <tr><td class="px-4 py-3 font-black text-slate-900">{{ $row['label'] }}</td><td class="px-3 py-3 text-right">{{ number_format($row['requested']) }}</td><td class="px-3 py-3 text-right">{{ number_format($row['completed']) }}</td><td class="px-3 py-3 text-right font-black">{{ $row['completion_rate'] }}%</td><td class="px-3 py-3 text-right">{{ number_format($row['exp_per_run'], 1) }}</td><td class="px-3 py-3 text-right">{{ number_format($row['gold_per_run'], 1) }}</td><td class="px-3 py-3 text-right">{{ number_format($row['job_exp_per_run'], 1) }}</td><td class="px-3 py-3 text-right">{{ $row['equipment_per_100'] }}</td><td class="px-3 py-3 text-right">{{ $row['materials_per_100'] }}</td><td class="px-3 py-3 text-right">{{ $row['monster_marks_per_100'] }}</td><td class="px-3 py-3 text-right">{{ $row['maps_per_100'] }}</td><td class="px-3 py-3 text-right">{{ $row['average_stamina_cost'] === null ? '-' : $row['average_stamina_cost'] }}</td><td class="px-3 py-3 text-right">{{ $row['average_danger_delta'] === null ? '-' : ($row['average_danger_delta'] >= 0 ? '+' : '').$row['average_danger_delta'].'%' }}</td></tr>
                            @empty
                                <tr><td colspan="13" class="px-4 py-10 text-center font-bold text-slate-400">期間内の探索実績はありません。</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="font-black text-slate-950">探索種別</h3>
                    <div class="mt-3 space-y-2">@forelse($exploration['contextRows'] as $row)<div class="flex items-center justify-between rounded bg-slate-50 px-3 py-2 text-xs"><span class="font-black text-slate-800">{{ $row['label'] }}</span><span class="font-bold text-slate-600">{{ number_format($row['requests']) }}要求 / {{ number_format($row['completed']) }}回</span></div>@empty<div class="text-xs font-bold text-slate-400">実績はありません。</div>@endforelse</div>
                </div>
                <div class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="font-black text-slate-950">終了・停止理由</h3>
                    <div class="mt-3 space-y-2">@forelse($exploration['stopRows'] as $row)<div class="flex items-center justify-between rounded bg-slate-50 px-3 py-2 text-xs"><span class="font-black text-slate-800">{{ $row['label'] }}</span><span class="font-bold text-amber-700">{{ number_format($row['count']) }}件</span></div>@empty<div class="text-xs font-bold text-slate-400">途中停止はありません。</div>@endforelse</div>
                </div>
            </div>
        </section>
    @endunless
</div>
