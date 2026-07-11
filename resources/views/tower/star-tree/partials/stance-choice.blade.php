@php
    $pendingTowerStance = $pendingTowerStance ?? null;
    $towerStanceState = $towerStanceState ?? ['display_totals' => []];
    $towerStanceChoices = collect($towerStanceChoices ?? ($pendingTowerStance['choices'] ?? []));
    $displayTotals = collect($towerStanceState['display_totals'] ?? []);
@endphp

@if($pendingTowerStance)
    <section class="mb-5 rounded-lg border border-teal-200 bg-teal-50/90 px-4 py-3 text-left shadow-sm">
        <div class="mb-3 flex items-start justify-between gap-3">
            <div>
                <div class="text-xs font-black text-teal-700">{{ number_format((int) ($pendingTowerStance['floor'] ?? 0)) }}階の節目</div>
                <h2 class="mt-0.5 text-base font-black text-slate-950">星樹の構えを選べる</h2>
                <p class="mt-1 text-xs font-bold leading-5 text-slate-600">
                    星樹の枝葉が道を示している。構えをひとつ選べるようだ。何も選ばず進むこともできる。
                </p>
            </div>
            <span class="shrink-0 rounded-full bg-teal-600 px-3 py-1 text-[11px] font-black text-white">挑戦中のみ</span>
        </div>
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach($towerStanceChoices as $stance)
                <label class="flex h-full cursor-pointer items-start justify-between gap-3 rounded-lg border border-teal-100 bg-white px-3 py-2 text-left text-sm shadow-sm transition has-[:checked]:border-teal-400 has-[:checked]:bg-teal-50 hover:border-teal-300 hover:bg-teal-50">
                    <input
                        type="radio"
                        name="tower_stance_choice"
                        value="{{ (string) ($stance['key'] ?? 'none') }}"
                        class="sr-only"
                        data-tower-stance-option
                        @checked((string) ($stance['key'] ?? 'none') === 'none')
                    >
                    <span class="min-w-0">
                        <span class="block font-black text-slate-950">{{ $stance['name'] ?? '構えなし' }}</span>
                        <span class="mt-0.5 block text-xs font-bold leading-5 text-slate-500">{{ $stance['summary'] ?? '' }}</span>
                    </span>
                    <span class="shrink-0 rounded-full bg-teal-600 px-2.5 py-1 text-[11px] font-black text-white" data-tower-stance-label>選択</span>
                </label>
            @endforeach
        </div>
    </section>
@elseif($displayTotals->isNotEmpty())
    <section class="mb-5 rounded-lg border border-teal-100 bg-white px-4 py-3 text-left shadow-sm">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-black text-teal-700">現在の星樹の構え</span>
            @foreach($displayTotals as $total)
                <span class="rounded-full {{ (int) ($total['rate'] ?? 0) >= 0 ? 'bg-teal-50 text-teal-700 border-teal-100' : 'bg-rose-50 text-rose-700 border-rose-100' }} border px-2.5 py-1 text-[11px] font-black">
                    {{ $total['text'] }}
                </span>
            @endforeach
        </div>
    </section>
@endif
