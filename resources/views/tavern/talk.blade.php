<x-layouts.facility title="酒場の会話" headerIcon="💬"
    bgImage="images/facilities/sakaba01.webp"
    pageBgImage="images/facilities/sakaba01.webp"
    pageBgOverlay="bg-stone-950/30"
    exitTextClass="text-amber-200/80 hover:text-amber-300">
    <div class="py-8 w-full mx-auto sm:px-6 lg:px-8">
        <div class="bg-white/90 backdrop-blur-sm rounded-xl border border-[#d4af37]/60 shadow-2xl overflow-hidden">
            <div class="p-6">
                <a href="{{ route('tavern.index') }}" class="inline-flex items-center text-sm font-bold text-slate-500 hover:text-slate-800 mb-5 transition-colors">← 酒場へ戻る</a>

                <div class="rounded-xl border border-amber-200/70 bg-amber-50/80 p-5 mb-4">
                    <div class="flex items-center gap-4 pb-4 mb-4 border-b border-amber-200/50">
                        <img src="{{ asset($npc->image_path) }}"
                             alt="{{ $npc->npc_name }}"
                             class="h-20 w-20 shrink-0 rounded-xl border border-amber-200 bg-white object-cover shadow-md">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <h2 class="text-2xl font-extrabold text-slate-800">{{ $npc->npc_name }}</h2>
                                <span class="text-xs rounded-full bg-white border border-amber-200 text-amber-700 px-2.5 py-0.5 font-bold">{{ $npc->npc_title }}</span>
                            </div>
                            <p class="text-sm text-amber-800/70 leading-relaxed">{{ $npc->description }}</p>
                        </div>
                    </div>
                    <div class="bg-white/90 border border-amber-100 rounded-lg p-5 text-slate-700 font-medium leading-loose whitespace-pre-line shadow-inner">
                        {{ $npc->talk_text }}
                    </div>
                    @if($isFirst)
                        <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700 flex items-center gap-2">
                            <span>✦</span> {{ $npc->npc_name }}が冒険者名簿に登録されました。
                        </div>
                    @endif
                </div>

                @if($npc->hint_text)
                    <div class="mb-4 rounded-xl border border-amber-200/60 bg-amber-50/70 backdrop-blur-sm p-4">
                        <div class="text-sm font-extrabold text-amber-700 mb-1.5">📜 ヒント</div>
                        <p class="text-sm text-amber-800/80 leading-relaxed">{{ $npc->hint_text }}</p>
                    </div>
                @endif

                <div class="pt-4 border-t border-slate-100 flex flex-col sm:flex-row gap-3 justify-center">
                    <a href="{{ route('tavern.roster.detail', $npc) }}" class="inline-flex justify-center items-center gap-2 rounded-lg bg-slate-800 px-5 py-2.5 text-sm font-bold text-white shadow-lg hover:bg-slate-700 active:scale-95 transition">
                        📖 名簿で見る
                    </a>
                    <a href="{{ route('tavern.index') }}" class="inline-flex justify-center items-center rounded-lg bg-white/80 px-5 py-2.5 text-sm font-bold text-slate-700 border border-slate-200 shadow hover:bg-white active:scale-95 transition">
                        酒場へ戻る
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-layouts.facility>
