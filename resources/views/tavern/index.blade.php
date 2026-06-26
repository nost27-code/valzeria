<x-layouts.facility title="酒場" headerIcon="🍺" bgImage="images/facilities/tavern.webp">
    <div class="py-8 w-full mx-auto sm:px-6 lg:px-8">
        <div class="bg-stone-900/95 rounded-xl border border-amber-700/40 shadow-2xl overflow-hidden">
            <div class="p-6">

                {{-- ヘッダー --}}
                <div class="flex items-start justify-between gap-4 mb-6">
                    <div>
                        <h2 class="text-2xl font-extrabold text-amber-100 tracking-wide">🍺 酒場</h2>
                        <p class="mt-1.5 text-sm text-amber-200/60 leading-relaxed">
                            冒険者たちが集うにぎやかな酒場。旅の噂や、思わぬ出会いがあるかもしれない。
                        </p>
                    </div>
                    <div class="shrink-0 rounded-lg bg-amber-900/50 border border-amber-700/40 px-3 py-2 text-xs font-bold text-amber-400">
                        訪問 {{ number_format($visit->visit_count) }}回
                    </div>
                </div>

                {{-- 今日の顔ぶれ --}}
                <div class="rounded-lg bg-amber-950/60 border border-amber-800/40 px-4 py-3 mb-6 flex items-center gap-2">
                    <span class="text-amber-500 text-base">🕯</span>
                    @if($npcs->isEmpty())
                        <p class="text-sm font-bold text-amber-200/70">今日は知った顔の冒険者はいないようだ。</p>
                    @else
                        <p class="text-sm font-bold text-amber-200/80">今夜は <span class="text-amber-400">{{ $npcs->count() }}人</span> の冒険者がいるようだ。話しかけてみよう。</p>
                    @endif
                </div>

                {{-- NPCカード一覧 --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    @foreach($npcs as $npc)
                        <a href="{{ route('tavern.talk', $npc) }}"
                           class="group block rounded-xl border border-amber-800/40 bg-stone-800/70 p-4 shadow hover:border-amber-500/60 hover:bg-stone-800 hover:shadow-amber-900/40 hover:shadow-lg transition-all active:scale-[0.98]">
                            <div class="flex items-start gap-3">
                                <div class="relative shrink-0">
                                    <img src="{{ asset($npc->image_path) }}"
                                         alt="{{ $npc->npc_name }}"
                                         loading="lazy"
                                         class="h-16 w-16 rounded-xl border border-amber-700/30 bg-stone-900 object-cover shadow-md group-hover:border-amber-500/50 transition-colors">
                                    <div class="absolute inset-0 rounded-xl ring-1 ring-amber-400/10 group-hover:ring-amber-400/30 transition"></div>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2 mb-1">
                                        <div class="font-extrabold text-amber-100">{{ $npc->npc_name }}</div>
                                        <span class="text-[10px] rounded-full bg-amber-900/70 border border-amber-700/40 text-amber-400 px-2 py-0.5 font-bold">{{ $npc->npc_title }}</span>
                                    </div>
                                    <p class="text-xs text-stone-400 leading-relaxed line-clamp-2">{{ $npc->description }}</p>
                                </div>
                            </div>
                            <div class="mt-3 text-xs font-bold text-amber-600 group-hover:text-amber-400 transition-colors">
                                話しかける →
                            </div>
                        </a>
                    @endforeach
                </div>

                {{-- 区切り --}}
                <div class="my-6 border-t border-amber-900/50"></div>

                {{-- ボタン --}}
                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <a href="{{ route('tavern.roster') }}"
                       class="inline-flex justify-center items-center gap-2 rounded-lg bg-amber-700 hover:bg-amber-600 px-5 py-2.5 text-sm font-bold text-amber-50 shadow-lg active:scale-95 transition">
                        📖 冒険者名簿（{{ $roster['registered'] }} / {{ $roster['total'] }}）
                    </a>
                    <a href="{{ route('home') }}"
                       class="inline-flex justify-center items-center rounded-lg bg-stone-700 hover:bg-stone-600 border border-stone-500/50 px-5 py-2.5 text-sm font-bold text-stone-200 shadow active:scale-95 transition">
                        街へ戻る
                    </a>
                </div>

            </div>
        </div>
    </div>
</x-layouts.facility>
