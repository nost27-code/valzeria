@php
    $selectedSpOutput = (string) ($selectedSpOutput ?? 'none');
    $artSpOutputCosts = (array) ($artSpOutputCosts ?? []);
    $selectedCost = $artSpOutputCosts[$selectedSpOutput] ?? ($artSpOutputCosts['none'] ?? null);
@endphp

@if(is_array($selectedCost))
    <div
        class="mt-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-lg border border-cyan-100 bg-cyan-50/70 px-2.5 py-1.5"
        data-job-art-sp-output-cost
        data-job-art-sp-output-costs='@json($artSpOutputCosts)'
    >
        <span class="text-[10px] font-black text-cyan-950 sm:text-[11px]">
            <span data-job-art-sp-output-cost-label>{{ $selectedCost['label'] ?? $selectedSpOutput }}</span>時の合計消費SP
            <strong class="ml-1 text-xs text-cyan-800" data-job-art-sp-output-total>{{ number_format((int) ($selectedCost['total'] ?? 0)) }}</strong>
        </span>
        <span class="text-[9px] font-bold text-slate-600 sm:text-[10px]" data-job-art-sp-output-breakdown>
            @if($selectedCost['eligible'] ?? false)
                固定 {{ number_format((int) ($selectedCost['fixed'] ?? 0)) }} ＋ 追加 {{ number_format((int) ($selectedCost['variable'] ?? 0)) }}
            @else
                固定 {{ number_format((int) ($selectedCost['fixed'] ?? 0)) }}のみ（戦技出力対象外）
            @endif
        </span>
    </div>
@endif
