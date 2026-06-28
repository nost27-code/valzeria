<x-layouts.facility title="酒場" headerIcon="🍺"
    bgImage="images/facilities/sakaba01.webp"
    pageBgImage="images/facilities/sakaba01.webp"
    pageBgOverlay="bg-stone-950/30"
    exitTextClass="text-amber-200/80 hover:text-amber-300">
    <div class="py-8 w-full mx-auto sm:px-6 lg:px-8">
        <div class="bg-white/90 backdrop-blur-sm rounded-xl border border-[#d4af37]/60 shadow-2xl overflow-hidden">
            <div class="p-6">
                <div class="flex items-start justify-between gap-4 mb-5">
                    <div>
                        <h2 class="text-2xl font-extrabold text-slate-800">酒場</h2>
                        <p class="mt-1.5 text-sm text-slate-500 leading-relaxed">
                            冒険者たちが集うにぎやかな酒場。旅の噂や、思わぬ出会いがあるかもしれない。
                        </p>
                    </div>
                    <div class="shrink-0 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs font-bold text-amber-700">
                        訪問 {{ number_format($visit->visit_count) }}回
                    </div>
                </div>

                <div class="rounded-lg bg-amber-50/80 border border-amber-200/60 px-4 py-3 mb-5">
                    @if($npcs->isEmpty())
                        <p class="font-bold text-slate-700">今日は知った顔の冒険者はいないようだ。</p>
                        <p class="mt-1 text-sm text-slate-500">もう少し冒険を進めれば、新たな出会いがあるかもしれない。</p>
                    @else
                        <p class="font-bold text-slate-700">今夜は <span class="text-amber-700">{{ $npcs->count() }}人</span> の冒険者がいるようだ。</p>
                    @endif
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    @foreach($npcs as $npc)
                        <a href="{{ route('tavern.talk', $npc) }}"
                           class="group block rounded-xl border border-amber-200/70 bg-white/80 backdrop-blur-sm p-4 shadow-sm hover:border-[#d4af37] hover:bg-white hover:shadow-md transition-all active:scale-[0.98]">
                            <div class="flex items-start gap-3">
                                <img src="{{ asset($npc->image_path) }}"
                                     alt="{{ $npc->npc_name }}"
                                     loading="lazy"
                                     class="h-16 w-16 shrink-0 rounded-lg border border-amber-100 bg-amber-50 object-cover shadow-sm">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-1.5 mb-1">
                                        <div class="font-extrabold text-slate-800">{{ $npc->npc_name }}</div>
                                        <span class="text-[11px] rounded-full bg-amber-100 text-amber-700 border border-amber-200 px-2 py-0.5 font-bold">{{ $npc->npc_title }}</span>
                                    </div>
                                    <p class="text-xs text-slate-500 leading-relaxed line-clamp-2">{{ $npc->description }}</p>
                                </div>
                            </div>
                            <div class="mt-3 text-xs font-bold text-amber-600 group-hover:text-amber-700 transition-colors">話しかける →</div>
                        </a>
                    @endforeach
                </div>

                <div class="mt-6 pt-5 border-t border-slate-100 flex flex-col sm:flex-row gap-3 justify-center">
                    <a href="{{ route('tavern.roster') }}" class="inline-flex justify-center items-center gap-2 rounded-lg bg-slate-800 px-5 py-2.5 text-sm font-bold text-white shadow-lg hover:bg-slate-700 active:scale-95 transition">
                        📖 冒険者名簿（{{ $roster['registered'] }} / {{ $roster['total'] }}）
                    </a>
                    <a href="{{ route('home') }}" class="inline-flex justify-center items-center rounded-lg bg-white/80 px-5 py-2.5 text-sm font-bold text-slate-700 border border-slate-200 shadow hover:bg-white active:scale-95 transition">
                        街へ戻る
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-layouts.facility>
