<x-layouts.facility title="冒険者名簿" headerIconImage="images/icon/icon_013.webp"
    bgImage="images/facilities/sakaba01.webp"
    pageBgImage="images/facilities/sakaba01.webp"
    pageBgOverlay="bg-stone-950/30"
    exitTextClass="text-amber-200/80 hover:text-amber-300">
    <div class="py-8 w-full mx-auto sm:px-6 lg:px-8">
        <div class="bg-white/90 backdrop-blur-sm rounded-xl border border-[#d4af37]/60 shadow-2xl overflow-hidden">
            <div class="p-6">
                <div class="flex items-center justify-between gap-3 mb-5">
                    <div>
                        <h2 class="text-2xl font-extrabold text-slate-800">冒険者名簿</h2>
                        <p class="text-sm text-slate-500 mt-0.5">登録数：{{ $registered }} / {{ $total }}</p>
                    </div>
                    <a href="{{ route('tavern.index') }}" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-bold text-white shadow hover:bg-slate-700 active:scale-95 transition">酒場へ</a>
                </div>

                @if(session('error'))
                    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">{{ session('error') }}</div>
                @endif

                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    @foreach($npcs as $npc)
                        @php $encounter = $encounters->get($npc->npc_id); @endphp
                        @if($encounter)
                            <a href="{{ route('tavern.roster.detail', $npc) }}" class="group flex items-center justify-between gap-2 rounded-xl border border-slate-200/80 bg-white/80 backdrop-blur-sm p-3 hover:border-[#d4af37] hover:bg-white hover:shadow-sm transition active:scale-[0.99]">
                                <div class="flex min-w-0 items-center gap-3">
                                    <img src="{{ asset($npc->image_path) }}"
                                         alt="{{ $npc->npc_name }}"
                                         loading="lazy"
                                         class="h-12 w-12 shrink-0 rounded-lg border border-slate-100 bg-slate-50 object-cover shadow-sm">
                                    <div class="min-w-0">
                                        <div class="font-bold text-slate-800 text-sm">
                                            <span class="text-slate-400 font-mono text-xs mr-1">{{ sprintf('%03d', $npc->npc_id) }}</span>{{ $npc->npc_name }}
                                        </div>
                                        <div class="text-xs text-slate-500 mt-0.5">{{ $npc->npc_title }} · 会話 {{ $encounter->encounter_count }}回</div>
                                    </div>
                                </div>
                                <span class="text-xs font-bold text-amber-600 group-hover:text-amber-700 shrink-0 transition-colors">詳細 →</span>
                            </a>
                        @else
                            <div class="flex items-center gap-3 rounded-xl border border-slate-200/60 bg-slate-50/60 p-3 opacity-60">
                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-100 text-lg font-black text-slate-300">?</div>
                                <div>
                                    <div class="font-bold text-slate-400 text-sm">
                                        <span class="font-mono text-xs mr-1">{{ sprintf('%03d', $npc->npc_id) }}</span>？？？
                                    </div>
                                    <div class="text-xs text-slate-400 mt-0.5">未遭遇</div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-layouts.facility>
