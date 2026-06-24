<x-layouts.facility title="冒険者名簿" headerIconImage="images/icon/icon_013.webp" bgImage="images/facilities/tavern.webp">
    <div class="py-8 w-full mx-auto sm:px-6 lg:px-8">
        <div class="bg-white rounded-lg border border-[#d4af37] shadow-sm overflow-hidden">
            <div class="p-6">
                <div class="flex items-center justify-between gap-3 mb-5">
                    <div>
                        <h2 class="text-2xl font-extrabold text-slate-800">冒険者名簿</h2>
                        <p class="text-sm text-slate-500 mt-1">登録数：{{ $registered }} / {{ $total }}</p>
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
                            <a href="{{ route('tavern.roster.detail', $npc) }}" class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white p-3 hover:border-[#d4af37] hover:shadow-sm transition active:scale-[0.99]">
                                <div class="min-w-0">
                                    <div class="font-bold text-slate-800">{{ sprintf('%03d', $npc->npc_id) }} {{ $npc->npc_name }}</div>
                                    <div class="text-xs text-slate-500">{{ $npc->npc_title }} / 会話 {{ $encounter->encounter_count }}回</div>
                                </div>
                                <span class="text-xs font-bold text-amber-700">詳細</span>
                            </a>
                        @else
                            <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 opacity-80">
                                <div>
                                    <div class="font-bold text-slate-500">{{ sprintf('%03d', $npc->npc_id) }} ？？？</div>
                                    <div class="text-xs text-slate-400">未遭遇</div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-layouts.facility>
