@php
    $activeLineages = collect($activeLineages ?? [])->values();
@endphp

<div class="rounded-lg border border-indigo-100 bg-indigo-50/60 px-3 py-2.5" data-job-art-active-lineage-summary>
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span class="text-[11px] font-black text-slate-800">有効な系譜</span>
        <span class="text-[10px] font-bold text-slate-500">このセットで資源・共通効果が有効になります</span>
    </div>

    @if($activeLineages->isEmpty())
        <p class="mt-1.5 text-[11px] font-bold text-slate-500">資源を使う戦技はまだセットされていません。</p>
    @else
        <div class="mt-2 flex flex-wrap gap-1.5" aria-label="現在有効な系譜">
            @foreach($activeLineages as $lineage)
                <span
                    class="inline-flex items-center gap-1.5 rounded-full border border-indigo-200 bg-white px-2 py-1 text-[10px] font-black text-indigo-800 shadow-sm"
                    data-job-art-active-lineage="{{ $lineage['lineage_key'] }}"
                >
                    @if($lineage['icon_path'] ?? null)
                        <img
                            src="{{ asset($lineage['icon_path']) }}"
                            alt=""
                            width="22"
                            height="22"
                            class="h-[22px] w-[22px] shrink-0 object-contain"
                            loading="lazy"
                            decoding="async"
                        >
                    @endif
                    <span>{{ $lineage['lineage_name'] }}系譜</span>
                    <span class="font-bold text-slate-500">{{ $lineage['resource_name'] }}</span>
                </span>
            @endforeach
        </div>
    @endif
</div>
