@php
    $material = $item['material'];
    $rankClasses = [
        '不足' => 'border-red-200 bg-red-50 text-red-700',
        '高' => 'border-amber-200 bg-amber-50 text-amber-700',
        '普通' => 'border-blue-200 bg-blue-50 text-blue-700',
        '低' => 'border-slate-200 bg-slate-50 text-slate-500',
    ];
    $lowestPrice = $item['lowest_price'];
@endphp

<div class="rounded-lg border border-slate-200 bg-white px-3 py-3 shadow-sm">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                <span class="min-w-0 truncate text-base font-black text-slate-900">{{ $material->displayName() }}</span>
                <span class="rounded-full border px-2 py-0.5 text-[11px] font-black {{ $rankClasses[$item['demand_rank']] ?? $rankClasses['低'] }}">
                    需要{{ $item['demand_rank'] }}
                </span>
            </div>
            @if(! empty($item['demand_tags']))
                <div class="mt-1 flex flex-wrap gap-1">
                    @foreach(array_slice($item['demand_tags'], 0, 4) as $tag)
                        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-600">{{ $tag }}</span>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="shrink-0 text-right text-[11px] font-bold text-slate-400">
            スコア {{ number_format((int) $item['demand_score']) }}
        </div>
    </div>

    <div class="mt-2 grid grid-cols-2 gap-2 text-xs font-bold text-slate-600 sm:grid-cols-4">
        <div class="rounded bg-slate-50 px-2 py-1.5">
            <div class="text-[10px] text-slate-400">所持</div>
            <div class="text-sm font-black text-slate-900">{{ number_format((int) $item['owned_quantity']) }}個</div>
        </div>
        <div class="rounded bg-slate-50 px-2 py-1.5">
            <div class="text-[10px] text-slate-400">市場在庫</div>
            <div class="text-sm font-black text-slate-900">{{ number_format((int) $item['active_market_quantity']) }}個</div>
        </div>
        <div class="rounded bg-slate-50 px-2 py-1.5">
            <div class="text-[10px] text-slate-400">最安</div>
            <div class="text-sm font-black {{ $lowestPrice !== null ? 'text-amber-700' : 'text-slate-400' }}">
                {{ $lowestPrice !== null ? number_format((int) $lowestPrice).'G' : '出品なし' }}
            </div>
        </div>
        <div class="rounded bg-slate-50 px-2 py-1.5">
            <div class="text-[10px] text-slate-400">24h販売</div>
            <div class="text-sm font-black text-slate-900">{{ number_format((int) $item['sold_quantity_24h']) }}個</div>
        </div>
    </div>

    <div class="mt-2 space-y-1 border-t border-slate-100 pt-2 text-xs font-bold leading-relaxed text-slate-500">
        <div><span class="text-slate-400">用途：</span>{{ $item['usage_summary'] }}</div>
        <div><span class="text-slate-400">入手：</span>{{ $item['acquisition_summary'] }}</div>
    </div>

    <div class="mt-3 flex gap-2">
        <a href="{{ route('market.materials.show', $material) }}" wire:navigate
           class="inline-flex flex-1 items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-600 shadow-sm transition hover:bg-slate-50">
            詳細
        </a>
        @if((int) $item['owned_quantity'] > 0)
            <a href="{{ route('market.index', ['tab' => 'sell', 'material_id' => $material->id]) }}" wire:navigate
               class="inline-flex flex-1 items-center justify-center rounded-md bg-emerald-600 px-3 py-2 text-xs font-black text-white shadow-sm transition hover:bg-emerald-700">
                出品する
            </a>
        @else
            <a href="{{ route('market.materials.show', $material) }}"
               class="inline-flex flex-1 items-center justify-center rounded-md bg-slate-800 px-3 py-2 text-xs font-black text-white shadow-sm transition hover:bg-slate-900">
                入手先を見る
            </a>
        @endif
    </div>
</div>
