<x-layouts.facility title="酒場の会話" headerIcon="💬" bgImage="images/facilities/tavern.webp">
    <div class="py-8 w-full mx-auto sm:px-6 lg:px-8">
        <div class="bg-white rounded-lg border border-[#d4af37] shadow-sm overflow-hidden">
            <div class="p-6">
                <a href="{{ route('tavern.index') }}" class="inline-flex items-center text-sm font-bold text-slate-500 hover:text-slate-800 mb-5">← 酒場へ戻る</a>

                <div class="rounded-lg border border-amber-200 bg-amber-50 p-5">
                    <div class="flex flex-wrap items-center gap-2 mb-3">
                        <h2 class="text-2xl font-extrabold text-slate-800">{{ $npc->npc_name }}</h2>
                        <span class="text-xs rounded bg-white border border-amber-200 text-amber-700 px-2 py-0.5 font-bold">{{ $npc->npc_title }}</span>
                    </div>
                    <div class="bg-white border border-amber-100 rounded-lg p-4 text-slate-700 font-medium leading-loose whitespace-pre-line">
                        {{ $npc->talk_text }}
                    </div>
                    @if($isFirst)
                        <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">
                            {{ $npc->npc_name }}が冒険者名簿に登録されました。
                        </div>
                    @endif
                </div>

                @if($npc->hint_text)
                    <div class="mt-5 rounded-lg border border-amber-100 bg-amber-50 p-4">
                        <div class="text-sm font-extrabold text-amber-800 mb-1">ヒント</div>
                        <p class="text-sm text-amber-700 leading-relaxed">{{ $npc->hint_text }}</p>
                    </div>
                @endif

                <div class="mt-6 flex flex-col sm:flex-row gap-3 justify-center">
                    <a href="{{ route('tavern.roster.detail', $npc) }}" class="inline-flex justify-center rounded-lg bg-slate-800 px-5 py-2.5 text-sm font-bold text-white shadow hover:bg-slate-700 active:scale-95 transition">
                        名簿で見る
                    </a>
                    <a href="{{ route('tavern.index') }}" class="inline-flex justify-center rounded-lg bg-white px-5 py-2.5 text-sm font-bold text-slate-700 border border-slate-200 shadow-sm hover:bg-slate-50 active:scale-95 transition">
                        酒場へ戻る
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-layouts.facility>
