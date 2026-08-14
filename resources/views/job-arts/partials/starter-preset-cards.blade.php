<div class="space-y-5">
    @foreach(collect($starterPresets)->groupBy('lineage_key') as $lineageKey => $lineagePresets)
        <section class="min-w-0" data-job-art-starter-lineage="{{ $lineageKey }}">
            <div class="mb-2 flex flex-wrap items-end justify-between gap-2 border-b border-slate-200 pb-2">
                <div>
                    <h3 class="text-sm font-black text-slate-950">{{ $lineagePresets->first()['lineage_name'] ?? '' }}系譜</h3>
                    <p class="mt-0.5 text-[10px] font-bold text-slate-500">使用資源：{{ $lineagePresets->first()['resource_name'] ?? '' }}</p>
                </div>
                <span class="text-[10px] font-black text-slate-400">3構成</span>
            </div>
            <div class="grid min-w-0 gap-3 lg:grid-cols-3">
                @foreach($lineagePresets as $preset)
                    @php
                        $currentVariant = $preset['current_variant'] ?? null;
                        $nextVariant = $preset['next_variant'] ?? null;
                        $completionVariant = $preset['completion_variant'] ?? null;
                        $visibleVariant = $currentVariant ?? $nextVariant ?? $completionVariant;
                    @endphp
                    <article class="flex min-w-0 flex-col rounded-xl border border-slate-200 bg-white p-3 shadow-sm" data-job-art-starter-preset="{{ $preset['key'] }}" data-job-art-starter-status="{{ $preset['status'] }}">
                        <div class="flex min-w-0 items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <h3 class="text-sm font-black text-slate-950">{{ $preset['name'] }}</h3>
                                    <span class="rounded-full px-2 py-0.5 text-[9px] font-black {{ $preset['status'] === 'COMPLETE' ? 'bg-emerald-100 text-emerald-800' : ($preset['can_apply'] ? 'bg-sky-100 text-sky-800' : 'bg-slate-100 text-slate-600') }}">{{ $preset['status_label'] }}</span>
                                </div>
                                <p class="mt-0.5 break-words text-[11px] font-black text-indigo-700">{{ $preset['build_name'] }}</p>
                            </div>
                            <span class="shrink-0 rounded bg-slate-100 px-2 py-1 text-[10px] font-black text-slate-600">Cost {{ $visibleVariant['cost'] ?? 0 }}</span>
                        </div>

                        <div class="mt-2 flex flex-wrap gap-1">
                            @foreach($preset['tags'] as $tag)
                                <span class="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[9px] font-black text-slate-600">{{ $tag }}</span>
                            @endforeach
                        </div>
                        <p class="mt-2 text-[10px] font-black text-slate-500">用途：{{ $preset['purpose'] }}</p>
                        <p class="mt-1 text-[11px] font-bold leading-relaxed text-slate-700">{{ $preset['description'] }}</p>

                        @if($currentVariant)
                            <details open class="group mt-3 rounded-lg border border-emerald-200 bg-emerald-50/60" data-job-art-starter-current-variant="{{ $currentVariant['key'] }}">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-2.5 py-2 text-[11px] font-black text-emerald-900">
                                    <span>現在使える構成［{{ $currentVariant['label'] }}］</span>
                                    <span class="flex items-center gap-2"><span>習得 5 / 5</span><span class="text-emerald-500 transition-transform group-open:rotate-180" aria-hidden="true">⌄</span></span>
                                </summary>
                                <ol class="space-y-1.5 border-t border-emerald-200 p-2">
                                    @foreach($currentVariant['arts'] as $art)
                                        <li class="flex min-w-0 items-start gap-2 rounded-md bg-white px-2 py-1.5 text-[11px]">
                                            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded bg-slate-700 text-[10px] font-black text-white">{{ $art['slot_no'] }}</span>
                                            <div class="min-w-0 flex-1">
                                                <div class="break-words font-black text-slate-900">{{ $art['name'] }} <span class="text-[10px] text-slate-500">{{ $art['role_label'] }}</span></div>
                                                <div class="mt-0.5 text-[10px] font-bold text-slate-500">{{ $art['job_name'] }} / Rank{{ $art['rank'] }} / Cost{{ $art['cost'] }}</div>
                                                @if($art['resource_text'])
                                                    <div class="mt-0.5 break-words font-bold text-slate-600">@include('job-arts.partials.effect-text', ['text' => $art['resource_text']])</div>
                                                @endif
                                                @if(($art['condition_key'] ?? 'always') !== 'always')
                                                    <div class="mt-0.5 break-words font-bold text-emerald-700">優先条件：{{ $art['condition_label'] }}</div>
                                                @endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ol>
                            </details>
                        @else
                            <div class="mt-3 rounded-lg border border-rose-200 bg-rose-50 p-2.5" data-job-art-starter-locked>
                                <p class="text-[10px] font-black text-rose-800">{{ $preset['unavailable_reason'] }}</p>
                                @if($visibleVariant)
                                    <p class="mt-1 text-[10px] font-bold text-rose-700">目標：{{ $visibleVariant['label'] }}　習得 {{ $visibleVariant['learned_count'] }} / 5</p>
                                @endif
                            </div>
                        @endif

                        @if($nextVariant)
                            <details class="group mt-2 rounded-lg border border-sky-200 bg-sky-50/50" data-job-art-starter-next-variant="{{ $nextVariant['key'] }}">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-2.5 py-2 text-[10px] font-black text-sky-900">
                                    <span>次の構成［{{ $nextVariant['label'] }}］</span>
                                    <span class="flex items-center gap-2"><span>あと{{ $nextVariant['missing_count'] }}戦技</span><span class="text-sky-500 transition-transform group-open:rotate-180" aria-hidden="true">⌄</span></span>
                                </summary>
                                <ol class="space-y-1 border-t border-sky-200 p-2">
                                    @foreach($nextVariant['arts'] as $art)
                                        <li class="flex min-w-0 items-center gap-2 rounded-md px-2 py-1.5 text-[10px] {{ $art['is_learned'] ? 'bg-white text-slate-800' : 'bg-slate-100 text-slate-400' }}">
                                            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded {{ $art['is_learned'] ? 'bg-slate-700 text-white' : 'bg-slate-300 text-slate-600' }} text-[9px] font-black">{{ $art['slot_no'] }}</span>
                                            <div class="min-w-0 flex-1"><span class="font-black">{{ $art['name'] }}</span><span class="ml-1 font-bold">{{ $art['job_name'] }} Rank{{ $art['rank'] }}</span></div>
                                            <span class="shrink-0 font-black {{ $art['is_learned'] ? 'text-emerald-700' : 'text-slate-400' }}">{{ $art['is_learned'] ? '習得済み' : '未習得' }}</span>
                                        </li>
                                    @endforeach
                                </ol>
                            </details>
                        @endif

                        @if($completionVariant && ($currentVariant['key'] ?? null) !== 'crown' && ($nextVariant['key'] ?? null) !== 'crown')
                            <details class="group mt-2 rounded-lg border border-amber-200 bg-amber-50/50" data-job-art-starter-completion>
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-2.5 py-2 text-[10px] font-black text-amber-900">
                                    <span>完成形を見る［{{ $completionVariant['label'] }}］</span>
                                    <span class="flex items-center gap-2"><span>習得 {{ $completionVariant['learned_count'] }} / 5</span><span class="text-amber-500 transition-transform group-open:rotate-180" aria-hidden="true">⌄</span></span>
                                </summary>
                                <ol class="space-y-1 border-t border-amber-200 p-2">
                                    @foreach($completionVariant['arts'] as $art)
                                        <li class="flex min-w-0 items-center gap-2 rounded-md px-2 py-1.5 text-[10px] {{ $art['is_learned'] ? 'bg-white text-slate-800' : 'bg-slate-100 text-slate-400' }}">
                                            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded {{ $art['is_learned'] ? 'bg-slate-700 text-white' : 'bg-slate-300 text-slate-600' }} text-[9px] font-black">{{ $art['slot_no'] }}</span>
                                            <div class="min-w-0 flex-1"><span class="font-black">{{ $art['name'] }}</span><span class="ml-1 font-bold">{{ $art['job_name'] }} Rank{{ $art['rank'] }}</span></div>
                                            <span class="shrink-0 font-black {{ $art['is_learned'] ? 'text-emerald-700' : 'text-slate-400' }}">{{ $art['is_learned'] ? '習得済み' : '未習得' }}</span>
                                        </li>
                                    @endforeach
                                </ol>
                            </details>
                        @endif

                        <form method="POST" action="{{ route('job-arts.starter-presets.apply', $preset['style_key']) }}" class="mt-auto pt-3" onsubmit="return confirm('{{ $slotContextLabel }}セットを「{{ $preset['lineage_name'] }}｜{{ $preset['name'] }}｜{{ $preset['build_name'] }}」で置き換えます。続けますか？');">
                            @csrf
                            <input type="hidden" name="lineage" value="{{ $preset['lineage_key'] }}">
                            <input type="hidden" name="slot_context" value="{{ $slotContext }}">
                            @if($currentVariant)
                                <input type="hidden" name="variant" value="{{ $currentVariant['key'] }}">
                            @endif
                            <button type="submit" @disabled(!$preset['can_apply']) class="w-full rounded-md bg-indigo-700 px-3 py-2 text-xs font-black text-white shadow-sm hover:bg-indigo-800 disabled:cursor-not-allowed disabled:bg-slate-300">
                                {{ $currentVariant ? $currentVariant['label'].'をセット' : '習得後にセットできます' }}
                            </button>
                        </form>
                    </article>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
