<details class="rounded-lg border border-indigo-200 bg-white shadow-sm" data-job-art-v2-recommendations>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-black text-slate-900 marker:content-none">
        <span>
            <span class="block text-[10px] uppercase tracking-[0.16em] text-indigo-500">Battle style guide</span>
            おすすめ戦型を見る
        </span>
        <span class="shrink-0 rounded-full bg-indigo-50 px-2.5 py-1 text-[11px] text-indigo-700">3つの組み方</span>
    </summary>

    <div class="space-y-3 border-t border-indigo-100 px-3 py-3 sm:px-4" data-job-art-v2-recommendation-list>
        <p class="text-[11px] font-bold leading-relaxed text-slate-500">戦技は並び順で戦い方が変わります。説明を参考に、上の5枠を手動で設定してください。</p>

        @foreach($recommendedBattleStyles as $style)
            @php
                $accentClasses = match ($style['key']) {
                    'finisher' => 'border-amber-200 bg-amber-50/70',
                    'cycle' => 'border-emerald-200 bg-emerald-50/70',
                    default => 'border-sky-200 bg-sky-50/70',
                };
                $titleClasses = match ($style['key']) {
                    'finisher' => 'text-amber-800',
                    'cycle' => 'text-emerald-800',
                    default => 'text-sky-800',
                };
            @endphp
            <article class="min-w-0 rounded-lg border p-3 {{ $accentClasses }}" data-job-art-v2-style="{{ $style['key'] }}">
                <div class="flex min-w-0 items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h3 class="text-base font-black {{ $titleClasses }}">{{ $style['name'] }}</h3>
                        <p class="text-xs font-black text-slate-700">{{ $style['catch'] }}</p>
                    </div>
                    <span class="shrink-0 rounded-full bg-white/90 px-2 py-1 text-[10px] font-black text-slate-600">奥義：{{ $style['ultimate_outlook'] }}</span>
                </div>

                <p class="mt-2 text-xs font-bold leading-relaxed text-slate-700">{{ $style['description'] }}</p>

                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach($style['traits'] as $trait)
                        <span class="rounded-full border border-white/90 bg-white/80 px-2 py-1 text-[10px] font-black text-slate-600">{{ $trait }}</span>
                    @endforeach
                </div>

                <dl class="mt-3 space-y-2 rounded-md border border-white/90 bg-white/75 px-2.5 py-2 text-[11px]">
                    <div>
                        <dt class="font-black text-slate-500">向いている戦闘</dt>
                        <dd class="mt-0.5 font-bold text-slate-800">{{ $style['suited_for'] }}</dd>
                    </div>
                    <div>
                        <dt class="font-black text-slate-500">判定の優先順</dt>
                        <dd class="mt-1 space-y-1" data-job-art-v2-priority-steps>
                            @foreach($style['steps'] as $index => $step)
                                <div class="flex min-w-0 items-start gap-2 rounded bg-white/80 px-2 py-1.5">
                                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-slate-800 text-[10px] font-black text-white">{{ $index + 1 }}</span>
                                    <span class="min-w-0 flex-1 font-black text-slate-800">
                                        {{ $step['role_label'] }}
                                        @if($step['art_name'])
                                            <span class="ml-1 break-words font-bold text-slate-500">{{ $step['art_name'] }}</span>
                                        @endif
                                    </span>
                                    @if($step['conditional_priority'])
                                        <span class="shrink-0 text-[9px] font-black text-amber-700">条件成立で優先</span>
                                    @endif
                                </div>
                            @endforeach
                        </dd>
                    </div>
                </dl>

                <p class="mt-2 text-[11px] font-bold leading-relaxed text-slate-700">{{ $style['priority_note'] }}</p>
                @if($style['job_note'])
                    <p class="mt-2 rounded-md border border-white/90 bg-white/80 px-2.5 py-2 text-[11px] font-black leading-relaxed text-slate-800" data-job-art-v2-job-note>{{ $style['job_note'] }}</p>
                @endif
            </article>
        @endforeach
    </div>
</details>
