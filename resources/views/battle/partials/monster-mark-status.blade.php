@php
    $entries = collect($summary['entries'] ?? []);
    $alchemy = $summary['alchemy'] ?? null;
    $earnedCounts = collect($battleResult['batch_explore']['monster_mark_drops'] ?? [])
        ->pluck('name')
        ->filter()
        ->when(!empty($battleResult['monster_mark_drop']['name']), function ($names) use ($battleResult) {
            return $names->push((string) $battleResult['monster_mark_drop']['name']);
        })
        ->countBy();
@endphp

<section data-monster-mark-battle-summary class="mb-4 -mx-3 overflow-hidden rounded-lg border border-violet-200 bg-violet-50/70 shadow-sm">
    <div class="flex items-center justify-between gap-2 border-b border-violet-100 bg-white/80 px-3 py-2">
        <h3 class="flex shrink-0 items-center gap-1.5 text-xs font-black text-violet-950">
            <img src="{{ asset('images/icon/icon_078.webp') }}" alt="" class="h-4 w-4 object-contain">
            印の状況
        </h3>
        <span class="min-w-0 truncate text-[10px] font-bold text-violet-600">{{ $summaryAreaName }}</span>
    </div>

    <div class="grid grid-cols-2 bg-white">
        <div class="min-w-0 px-3 py-2">
            <div class="text-[9px] font-black text-violet-600">エリアの印</div>
            <div class="text-sm font-black text-slate-900">現在 {{ number_format((int) ($summary['current_total'] ?? 0)) }}個</div>
            <div class="text-[9px] font-bold text-slate-500">{{ number_format((int) ($summary['discovered_types'] ?? 0)) }} / {{ number_format((int) ($summary['total_types'] ?? 0)) }}種を発見</div>
        </div>
        <div class="min-w-0 px-3 py-2">
            <div class="text-[9px] font-black text-violet-600">錬成可能な余剰印・全体</div>
            <div class="text-sm font-black text-violet-900">
                {{ number_format((int) ($alchemy['surplus_total'] ?? $summary['surplus_total'] ?? 0)) }}個
            </div>
            <div class="text-[9px] font-bold text-slate-500">
                @if(($alchemy['at_cap'] ?? false) === true)
                    全能力が個別上限に到達
                @elseif($alchemy !== null)
                    次の錬成まであと{{ number_format((int) ($alchemy['remaining_to_next'] ?? 0)) }}個
                @else
                    このエリアの余剰 {{ number_format((int) ($summary['surplus_total'] ?? 0)) }}個
                @endif
            </div>
        </div>
    </div>

    <details class="border-t border-violet-100 bg-white/60">
        <summary class="cursor-pointer px-3 py-2 text-[11px] font-black text-violet-900 marker:text-violet-500">
            このエリアの内訳を見る
        </summary>
        <div class="grid gap-1.5 px-2 pb-2">
            @foreach($entries as $entry)
                @php($earnedCount = (int) ($earnedCounts[$entry['mark_name']] ?? 0))
                <div class="rounded-md border {{ $earnedCount > 0 ? 'border-amber-300 bg-amber-50' : 'border-violet-100 bg-white' }} px-2 py-1.5">
                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                        <span class="min-w-0 text-xs font-black text-slate-900">{{ $entry['mark_name'] }}</span>
                        <span class="shrink-0 text-xs font-black text-violet-900">現在 {{ number_format((int) $entry['current_quantity']) }}個</span>
                    </div>
                    <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[9px] font-bold text-slate-500">
                        @if($earnedCount > 0)
                            <span class="rounded-full bg-amber-200 px-2 py-0.5 text-amber-900">+{{ number_format($earnedCount) }}獲得</span>
                        @endif
                        @if(!empty($entry['is_complete']))
                            <span>最大解放</span>
                        @else
                            <span>次の解放まで{{ number_format((int) ($entry['next_required'] ?? 0)) }}個</span>
                        @endif
                        @if((int) ($entry['surplus_quantity'] ?? 0) > 0)
                            <span class="text-violet-700">余剰 {{ number_format((int) $entry['surplus_quantity']) }}個</span>
                        @endif
                        @if((int) ($entry['spent_quantity'] ?? 0) > 0)
                            <span>累計発見 {{ number_format((int) $entry['lifetime_quantity']) }}個</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </details>
</section>
