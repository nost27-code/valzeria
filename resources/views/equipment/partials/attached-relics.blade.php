@php($attachedRelics = $equipment->relationLoaded('relics') ? $equipment->relics->sortBy('slot_number') : collect())
@if($attachedRelics->isNotEmpty())
    <details class="rounded-lg border border-slate-200 bg-white" data-equipment-attached-relics="{{ $equipment->id }}">
        <summary class="cursor-pointer px-2 py-1.5 text-[11px] font-bold text-slate-700">装着遺物（{{ $attachedRelics->count() }}／{{ app(\App\Services\NamelessRelicEquipmentService::class)->slotsFor($equipment) }}）</summary>
        <div class="divide-y divide-slate-100 px-2">
            @foreach($attachedRelics as $relic)
                <div class="flex items-center gap-1.5 py-1.5">
                    <x-relic-icon :effect-key="$relic->effect_key" :size="24" />
                    <div class="min-w-0 text-[11px] leading-snug">
                        <div class="break-words font-bold text-slate-800">{{ $relic->displayName() }}</div>
                        <div class="break-words text-slate-600">{{ $relic->effectSummary() }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </details>
@endif
