<x-layouts.facility title="酒場" headerIcon="🍺" bgImage="images/facilities/tavern.webp">
    <div class="py-8 w-full mx-auto sm:px-6 lg:px-8">
        <div class="bg-white rounded-lg border border-[#d4af37] shadow-sm overflow-hidden">
            <div class="p-6">
                <div class="flex items-start justify-between gap-4 mb-5">
                    <div>
                        <h2 class="text-2xl font-extrabold text-slate-800">酒場</h2>
                        <p class="mt-2 text-sm text-slate-600 leading-relaxed">
                            冒険者たちが集うにぎやかな酒場。旅の噂や、思わぬ出会いがあるかもしれない。
                        </p>
                    </div>
                    <div class="shrink-0 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs font-bold text-amber-700">
                        訪問 {{ number_format($visit->visit_count) }}回
                    </div>
                </div>

                <div class="rounded-lg bg-slate-50 border border-slate-200 p-4 mb-5">
                    @if($npcs->isEmpty())
                        <p class="font-bold text-slate-700">今日は知った顔の冒険者はいないようだ。</p>
                        <p class="mt-2 text-sm text-slate-500">もう少し冒険を進めれば、新たな出会いがあるかもしれない。</p>
                    @else
                        <p class="font-bold text-slate-700">今日は {{ $npcs->count() }}人 の冒険者がいるようだ。</p>
                    @endif
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    @foreach($npcs as $npc)
                        <a href="{{ route('tavern.talk', $npc) }}"
                           class="block rounded-lg border border-amber-200 bg-white p-4 shadow-sm hover:border-[#d4af37] hover:shadow transition-all active:scale-[0.99]">
                            <div class="flex items-center justify-between gap-2">
                                <div class="font-extrabold text-slate-800">{{ $npc->npc_name }}</div>
                                <span class="text-[11px] rounded bg-slate-100 text-slate-600 px-2 py-0.5 font-bold">{{ $npc->npc_title }}</span>
                            </div>
                            <p class="mt-2 text-xs text-slate-500 leading-relaxed line-clamp-2">{{ $npc->description }}</p>
                            <div class="mt-3 text-xs font-bold text-amber-700">話しかける</div>
                        </a>
                    @endforeach
                </div>

                <div class="mt-6 flex flex-col sm:flex-row gap-3 justify-center">
                    <a href="{{ route('tavern.roster') }}" class="inline-flex justify-center rounded-lg bg-slate-800 px-5 py-2.5 text-sm font-bold text-white shadow hover:bg-slate-700 active:scale-95 transition">
                        冒険者名簿を見る（{{ $roster['registered'] }} / {{ $roster['total'] }}）
                    </a>
                    <a href="{{ route('home') }}" class="inline-flex justify-center rounded-lg bg-white px-5 py-2.5 text-sm font-bold text-slate-700 border border-slate-200 shadow-sm hover:bg-slate-50 active:scale-95 transition">
                        街へ戻る
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-layouts.facility>
