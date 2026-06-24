<x-layouts.facility title="冒険者名簿 詳細" headerIconImage="images/icon/icon_013.webp" bgImage="images/facilities/tavern.webp">
    <div class="py-8 w-full mx-auto sm:px-6 lg:px-8">
        <div class="bg-white rounded-lg border border-[#d4af37] shadow-sm overflow-hidden">
            <div class="p-6">
                <a href="{{ route('tavern.roster') }}" class="inline-flex items-center text-sm font-bold text-slate-500 hover:text-slate-800 mb-5">← 名簿へ戻る</a>

                <div class="rounded-lg border border-slate-200 bg-white p-5">
                    <div class="flex flex-wrap items-center gap-2 mb-3">
                        <h2 class="text-2xl font-extrabold text-slate-800">{{ $npc->npc_name }}</h2>
                        <span class="text-xs rounded bg-slate-100 text-slate-600 px-2 py-0.5 font-bold">{{ $npc->npc_title }}</span>
                    </div>

                    <dl class="grid grid-cols-1 gap-4 text-sm">
                        <div>
                            <dt class="font-bold text-slate-500">説明</dt>
                            <dd class="mt-1 text-slate-700 leading-relaxed">{{ $npc->description }}</dd>
                        </div>
                        <div>
                            <dt class="font-bold text-slate-500">初遭遇日</dt>
                            <dd class="mt-1 text-slate-700">{{ $encounter->first_encountered_at?->format('Y/m/d H:i') }}</dd>
                        </div>
                        @if($npc->relation_text)
                            <div>
                                <dt class="font-bold text-slate-500">関係性</dt>
                                <dd class="mt-1 text-slate-700 leading-relaxed">{{ $npc->relation_text }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt class="font-bold text-slate-500">会話文</dt>
                            <dd class="mt-1 rounded-lg bg-slate-50 border border-slate-200 p-3 text-slate-700 leading-loose whitespace-pre-line">{{ $npc->talk_text }}</dd>
                        </div>
                        @if($npc->hint_text)
                            <div>
                                <dt class="font-bold text-slate-500">ヒント</dt>
                                <dd class="mt-1 text-amber-700 leading-relaxed">{{ $npc->hint_text }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
    </div>
</x-layouts.facility>
