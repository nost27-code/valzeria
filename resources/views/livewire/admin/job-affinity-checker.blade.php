<div class="w-full px-4 py-8 sm:px-6 lg:px-8">
    @php
        $fieldClass = 'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow-sm focus:border-amber-400 focus:ring-2 focus:ring-amber-200';
        $severityClass = [
            'warning' => 'border-amber-200 bg-amber-50 text-amber-950',
            'info' => 'border-sky-200 bg-sky-50 text-sky-950',
        ];
    @endphp

    <div class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <div class="text-xs font-bold tracking-[0.35em] text-orange-600">JOB AFFINITY</div>
            <h1 class="mt-2 text-3xl font-black text-slate-950">職業別相性チェック</h1>
            <p class="mt-2 text-sm font-semibold text-slate-600">チャンプ戦・闘技場ランク戦で使う戦型相性を、職業ごとに確認します。</p>
        </div>

        <div class="grid gap-2 sm:grid-cols-[180px_minmax(220px,320px)]">
            <select wire:model.live="rankFilter" class="{{ $fieldClass }}">
                <option value="all">全ランク</option>
                @foreach($rankLabels as $key => $label)
                    <option value="{{ $key }}">{{ $label }}職</option>
                @endforeach
            </select>
            <input type="search" wire:model.live.debounce.250ms="search" class="{{ $fieldClass }}" placeholder="職業名・キーで検索">
        </div>
    </div>

    <section class="mb-6 grid gap-4 lg:grid-cols-[1.3fr_0.7fr]">
        <div class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-black text-slate-950">相性ルール</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <div class="rounded-md border border-emerald-200 bg-emerald-50 p-4">
                    <div class="text-xs font-black text-emerald-700">剛力</div>
                    <div class="mt-1 text-sm font-bold text-slate-900">技巧に強い</div>
                </div>
                <div class="rounded-md border border-sky-200 bg-sky-50 p-4">
                    <div class="text-xs font-black text-sky-700">技巧</div>
                    <div class="mt-1 text-sm font-bold text-slate-900">魔導に強い</div>
                </div>
                <div class="rounded-md border border-purple-200 bg-purple-50 p-4">
                    <div class="text-xs font-black text-purple-700">魔導</div>
                    <div class="mt-1 text-sm font-bold text-slate-900">剛力に強い</div>
                </div>
            </div>
            <p class="mt-4 text-xs font-semibold leading-relaxed text-slate-500">最大補正は±10%。ハイブリッド職は重みに応じて補正が薄まり、通常攻撃の物理/魔法とは別軸で判定します。</p>
        </div>

        <div class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-black text-slate-950">診断</h2>
                <span class="text-xs font-bold text-slate-500">{{ count($diagnostics) }}件</span>
            </div>
            <div class="mt-4 space-y-3">
                @forelse($diagnostics as $diagnostic)
                    <div class="rounded-md border p-3 {{ $severityClass[$diagnostic['severity']] ?? $severityClass['info'] }}">
                        <div class="text-sm font-black">{{ $diagnostic['title'] }}</div>
                        <div class="mt-1 text-xs font-semibold opacity-80">{{ $diagnostic['body'] }}</div>
                    </div>
                @empty
                    <div class="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm font-bold text-emerald-800">
                        目立った偏りはありません。
                    </div>
                @endforelse
            </div>
        </div>
    </section>

    <section class="mb-6 rounded-md border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="text-lg font-black text-slate-950">職業プロファイル</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-left text-xs font-black uppercase tracking-wide text-slate-500">
                        <th class="min-w-[220px] border-b border-slate-200 px-4 py-3">職業</th>
                        <th class="min-w-[110px] border-b border-slate-200 px-3 py-3">主戦型</th>
                        <th class="min-w-[230px] border-b border-slate-200 px-3 py-3">戦型重み</th>
                        <th class="min-w-[90px] border-b border-slate-200 px-3 py-3">通常攻撃</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($jobs as $job)
                        @php $profile = $jobProfiles[$job->id]; @endphp
                        <tr>
                            <td class="px-4 py-3">
                                <div class="font-black text-slate-950">{{ $job->name }}</div>
                                <div class="mt-1 text-xs font-bold text-slate-500">#{{ $job->id }} / {{ $rankLabels[$job->rank] ?? $job->rank }} / {{ $job->key }}</div>
                            </td>
                            <td class="px-3 py-3">
                                <span class="inline-flex rounded-md bg-slate-900 px-2.5 py-1 text-xs font-black text-amber-200">{{ $profile['dominant_label'] }}</span>
                                @if($profile['is_hybrid'])
                                    <span class="ml-1 inline-flex rounded-md bg-amber-100 px-2 py-1 text-[11px] font-black text-amber-700">混合</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 font-bold text-slate-700">{{ $profile['weight_text'] }}</td>
                            <td class="px-3 py-3 font-black {{ $profile['attack_type'] === '魔法' ? 'text-purple-700' : 'text-red-700' }}">{{ $profile['attack_type'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-md border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="text-lg font-black text-slate-950">相性マトリクス</h2>
            <p class="mt-1 text-xs font-semibold text-slate-500">行が攻撃側、列が防御側です。</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead>
                    <tr class="bg-slate-50 text-left font-black text-slate-500">
                        <th class="sticky left-0 z-10 min-w-[160px] border-b border-slate-200 bg-slate-50 px-3 py-3">攻撃側</th>
                        @foreach($jobs as $defender)
                            <th class="min-w-[96px] border-b border-slate-200 px-2 py-3 text-center">{{ $defender->name }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($jobs as $attacker)
                        <tr>
                            <th class="sticky left-0 z-10 border-r border-slate-100 bg-white px-3 py-3 text-left font-black text-slate-950">{{ $attacker->name }}</th>
                            @foreach($jobs as $defender)
                                @php $info = $this->affinityInfo($attacker, $defender); @endphp
                                <td class="px-2 py-2 text-center">
                                    <div class="mx-auto w-20 rounded-md px-2 py-1 font-black {{ $info['class'] }}">{{ $info['text'] }}</div>
                                    <div class="mt-1 font-bold text-slate-400">{{ $info['label'] }}</div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
