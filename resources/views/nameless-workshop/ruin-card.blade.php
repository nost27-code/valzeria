<section class="relative overflow-hidden rounded-xl border border-slate-300 bg-white shadow" data-nameless-ruin-card>
    <img src="{{ asset($facility['image']) }}" alt="" width="600" height="450" loading="lazy" decoding="async" class="absolute inset-0 h-full w-full object-cover opacity-20 pointer-events-none">
    <div class="relative p-3">
        <div class="flex items-center gap-3">
            <img src="{{ asset($facility['symbol_image']) }}" alt="" width="160" height="160" loading="lazy" decoding="async" class="h-16 w-16 shrink-0 object-contain drop-shadow-sm">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="text-base font-black text-slate-800">{{ $facility['name'] }}</h3><span class="text-[10px] font-bold text-amber-800">深度{{ $facility['depth'] }}まで</span></div>
                <p class="mt-1 text-xs text-slate-600">{{ $facility['desc'] }}</p>
            </div>
        </div>
        <details class="my-2 rounded border border-amber-200 bg-white/80" data-nameless-ruin-loot>
            <summary class="min-h-11 cursor-pointer px-2 py-2 text-xs font-bold text-amber-900">入手できる遺物</summary>
            <div class="flex flex-wrap gap-1 px-2 pb-2">@foreach($facility['effects'] as $effect)<span class="rounded bg-white/80 px-1.5 py-0.5 text-[10px] text-amber-900">{{ $effect }}</span>@endforeach</div>
        </details>
        @include('nameless-workshop.explore-form', ['zoneKey' => $facility['zone_key'], 'zoneName' => $facility['name'], 'unlocked' => $facility['depth'], 'inventoryBlockReason' => $facility['inventory_block_reason'] ?? null, 'inventoryCapacityChecked' => true])
        @if($facility['next_zone_name'] ?? null)<p class="mt-2 text-xs font-bold text-amber-900">深度{{ config('nameless_relics.next_zone_unlock_depth') }}のボス撃破で、新たな遺跡への道が開きます。</p>@endif
    </div>
</section>
