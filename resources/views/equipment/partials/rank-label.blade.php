@php
    $rank = strtoupper((string) (
        $item->weapon_rank
        ?? $item->armor_rank
        ?? $item->accessory_rank
        ?? $item->rarity
        ?? ''
    ));

    $rankColor = match ($rank) {
        'G' => '#d1d5db',
        'F' => '#b0bec5',
        'E' => '#64748b',
        'D' => '#94a3b8',
        'C' => '#22c55e',
        'B' => '#3b82f6',
        'A' => '#ef4444',
        'S' => '#d4af37',
        'SS' => '#c084fc',
        'SSS' => '#f97316',
        'EPIC' => '#e11d48',
        default => '#94a3b8',
    };
@endphp

@if($rank !== '' && $rank !== 'NORMAL')
    <span class="inline-flex h-5 min-w-5 shrink-0 items-center justify-center border border-black/20 px-1 text-[10px] font-black leading-none text-white shadow-sm"
          style="background-color: {{ $rankColor }};">
        {{ $rank }}
    </span>
@endif
