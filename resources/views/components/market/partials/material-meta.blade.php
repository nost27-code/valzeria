@props([
    'info',
    'compact' => false,
])

@php
    $usageTags = array_slice($info['usage_tags'] ?? [], 0, $compact ? 2 : 4);
    $acquisitionTags = array_slice($info['acquisition_tags'] ?? [], 0, $compact ? 2 : 4);
@endphp

<div {{ $attributes->merge(['class' => 'space-y-1.5']) }}>
    <div class="flex min-w-0 items-start gap-1.5 text-[11px] font-bold text-slate-500">
        <span class="shrink-0 text-slate-400">用途</span>
        <div class="min-w-0 flex-1">
            @if($usageTags !== [])
                <div class="flex flex-wrap gap-1">
                    @foreach($usageTags as $tag)
                        <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-black text-amber-700">{{ $tag }}</span>
                    @endforeach
                </div>
            @else
                <div class="truncate">{{ $info['usage_summary'] ?? '-' }}</div>
            @endif
        </div>
    </div>

    <div class="flex min-w-0 items-start gap-1.5 text-[11px] font-bold text-slate-500">
        <span class="shrink-0 text-slate-400">入手</span>
        <div class="min-w-0 flex-1">
            @if($acquisitionTags !== [])
                <div class="flex flex-wrap gap-1">
                    @foreach($acquisitionTags as $tag)
                        <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-black text-emerald-700">{{ $tag }}</span>
                    @endforeach
                </div>
            @else
                <div class="truncate">{{ $info['acquisition_summary'] ?? '-' }}</div>
            @endif
        </div>
    </div>
</div>
