@php
    $activeLineages = collect($activeLineages ?? [])->values();
@endphp

<div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2.5" data-job-art-active-lineage-summary>
    <span class="shrink-0 text-[11px] font-black text-slate-800">有効系譜</span>

    @if($activeLineages->isEmpty())
        <p class="min-w-0 flex-1 text-[10px] font-bold text-slate-400">セットすると系譜が表示されます</p>
    @else
        <div class="flex min-w-0 flex-1 flex-wrap gap-1.5" aria-label="現在有効な系譜">
            @foreach($activeLineages as $lineage)
                <span
                    class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2 py-1 text-[10px] font-black text-indigo-800 shadow-sm"
                    data-job-art-active-lineage="{{ $lineage['lineage_key'] }}"
                >
                    @if($lineage['icon_path'] ?? null)
                        <img
                            src="{{ asset($lineage['icon_path']) }}"
                            alt=""
                            width="20"
                            height="20"
                            class="h-5 w-5 shrink-0 object-contain"
                            loading="lazy"
                            decoding="async"
                        >
                    @endif
                    <span>{{ $lineage['lineage_name'] }}系譜</span>
                    <span class="font-bold text-slate-400">{{ $lineage['resource_name'] }}</span>
                </span>
            @endforeach
        </div>
    @endif
</div>
