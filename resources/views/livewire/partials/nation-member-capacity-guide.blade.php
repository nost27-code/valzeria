<details class="group overflow-hidden rounded-xl border border-amber-200 bg-amber-50/60" data-nation-capacity-guide>
    <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 text-sm font-black text-amber-950">
        <span>国家Lvと国民数上限</span>
        <span class="flex shrink-0 items-center gap-2 text-xs text-amber-800">
            Lv1：20人 → Lv50：40人
            <span class="text-base transition-transform group-open:rotate-180" aria-hidden="true">⌄</span>
        </span>
    </summary>
    <div class="border-t border-amber-200 bg-white px-3 py-3">
        <p class="text-xs font-bold leading-relaxed text-stone-600">都市素材の納品で国家発展EXPを貯め、国家Lvが上がると国民数の上限が段階的に増えます。</p>
        <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
            @foreach($nationMemberCapacityRanges as $range)
                @php
                    $levelLabel = $range['to_level'] > $range['from_level']
                        ? $range['from_level'].'〜'.$range['to_level']
                        : (string) $range['from_level'];
                @endphp
                <div class="flex items-center justify-between gap-2 rounded-lg bg-stone-50 px-2.5 py-2 text-xs font-bold text-stone-600">
                    <span>Lv{{ $levelLabel }}</span>
                    <strong class="text-sm font-black text-stone-900">{{ $range['member_capacity'] }}人</strong>
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-[11px] font-bold leading-relaxed text-stone-500">現在の開放上限は国家Lv50の40人です。表にないLvでは、直前の上限が引き継がれます。</p>
        <p class="mt-2 rounded-lg border border-sky-200 bg-sky-50 px-2.5 py-2 text-[11px] font-bold leading-relaxed text-sky-900">国民数上限のほかにも、国家Lvに応じて開放される機能を予定しています。これらは現在準備中のため、公開までしばらくお待ちください。</p>
    </div>
</details>
