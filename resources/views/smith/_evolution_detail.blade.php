{{-- 進化詳細パーシャル: smith/index.blade.php から @include で使用 --}}
{{-- 変数: $candidate, $stone, $canUseStone, $canEvolve --}}
<div class="space-y-1.5">
    {{-- 進化元 --}}
    @php $baseOk = $candidate['owned_equipment_count'] >= ($candidate['required_base_equipment_count'] ?? 1); @endphp
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-bold text-slate-600 truncate">進化元：[{{ $candidate['from_rank'] ?? '-' }}] {{ $candidate['from_name'] }}</span>
        <span class="font-mono text-xs font-bold shrink-0 {{ $baseOk ? 'text-emerald-600' : 'text-red-600' }}">{{ $candidate['owned_equipment_count'] }}&thinsp;/&thinsp;{{ $candidate['required_base_equipment_count'] ?? 1 }}</span>
    </div>

    {{-- 欠片 --}}
    @if((int) ($stone['required'] ?? 0) > 0)
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-1.5 min-w-0">
                <span class="text-xs font-bold text-slate-600 truncate">{{ $stone['name'] ?? '装備の欠片' }}</span>
                @if(!empty($stone['sources']))
                    <button type="button"
                        @click.stop="srcPopup = { open: true, sources: @js($stone['sources']), label: @js($stone['name'] ?? '装備の欠片'), required: {{ (int) ($stone['required'] ?? 0) }} }"
                        class="shrink-0 text-[10px] font-bold text-amber-400 underline decoration-dotted hover:text-amber-600">入手場所</button>
                @endif
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <span class="font-mono text-xs font-bold {{ $canUseStone ? 'text-emerald-600' : 'text-red-600' }}">{{ $stone['owned'] ?? 0 }}&thinsp;/&thinsp;{{ $stone['required'] ?? 0 }}</span>
                <span class="text-[10px] font-black {{ $canUseStone ? 'text-emerald-600' : 'text-red-500' }}">{{ $canUseStone ? 'OK' : '不足' }}</span>
            </div>
        </div>
    @endif

    {{-- その他素材 --}}
    @foreach($candidate['required_materials'] as $material)
        @php $materialOk = $material['owned'] >= $material['required']; @endphp
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-1.5 min-w-0">
                @php $materialIcon = $material['icon_image'] ?? \App\Models\Material::iconImagePathFor($material['material_code'] ?? null, $material['name'] ?? null); @endphp
                @if($materialIcon)
                    <img src="{{ asset($materialIcon) }}" alt="" class="h-4 w-4 shrink-0 object-contain">
                @endif
                <span class="text-xs font-bold text-slate-600 truncate">{{ $material['name'] }}@if(!$material['is_consumed'])<span class="text-[9px] text-slate-400 ml-0.5">消費なし</span>@endif</span>
                @if(!empty($material['sources']))
                    <button type="button"
                        @click.stop="srcPopup = { open: true, sources: @js($material['sources']), label: @js($material['name']), required: {{ (int) ($material['required'] ?? 0) }} }"
                        class="shrink-0 text-[10px] font-bold text-amber-400 underline decoration-dotted hover:text-amber-600">入手場所</button>
                @endif
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <span class="font-mono text-xs font-bold {{ $materialOk ? 'text-emerald-600' : 'text-red-600' }}">{{ $material['owned'] }}&thinsp;/&thinsp;{{ $material['required'] }}</span>
                @if($material['missing'] > 0)
                    <span class="rounded bg-red-50 px-1 py-0.5 text-[10px] font-black text-red-600">-{{ $material['missing'] }}</span>
                @endif
            </div>
        </div>
    @endforeach

    {{-- Gold --}}
    @php $goldOk = (int) ($candidate['owned_gold'] ?? 0) >= (int) ($candidate['gold_cost'] ?? 0); @endphp
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-bold text-slate-600 truncate">合成費用</span>
        <div class="flex items-center gap-1.5 shrink-0">
            <span class="font-mono text-xs font-bold {{ $goldOk ? 'text-emerald-600' : 'text-red-600' }}">{{ number_format((int) ($candidate['gold_cost'] ?? 0)) }}G</span>
            @if(!$goldOk)
                <span class="text-[10px] font-bold text-red-400">不足</span>
            @endif
        </div>
    </div>
</div>

{{-- 能力変化 --}}
@if(!empty($candidate['stat_changes']))
    <div class="flex flex-wrap gap-1 border-t border-slate-100 pt-2">
        @foreach($candidate['stat_changes'] as $stat)
            @php
                $diff = (int) $stat['diff'];
                $badgeClass = $diff > 0
                    ? 'bg-emerald-50 border-emerald-200 text-emerald-700'
                    : ($diff < 0 ? 'bg-red-50 border-red-200 text-red-600' : 'bg-slate-50 border-slate-200 text-slate-500');
            @endphp
            <span class="inline-flex items-center gap-0.5 rounded border {{ $badgeClass }} px-1.5 py-0.5 text-[10px] font-bold font-mono">
                {{ $stat['label'] }}&nbsp;{{ $stat['from'] }}→{{ $stat['to'] }}&nbsp;<span class="font-black">{{ $diff > 0 ? '+' : '' }}{{ $diff }}</span>
            </span>
        @endforeach
    </div>
@endif

{{-- ボタン / 不足理由 --}}
@if($canEvolve)
    <button
        type="button"
        class="w-full h-9 bg-amber-600 hover:bg-amber-700 active:scale-[0.99] text-white text-sm font-bold rounded-lg shadow-sm transition"
        @click="selected = {
            recipeType: '{{ $candidate['equipment_type'] }}',
            recipeId: '{{ $candidate['recipe_id'] }}',
            fromName: '{{ addslashes($candidate['from_name']) }}',
            toName: '{{ addslashes($candidate['to_name']) }}',
            goldCost: '{{ number_format((int) ($candidate['gold_cost'] ?? 0)) }}G'
        }; modalOpen = true"
    >合成する</button>
@else
    <div class="text-xs font-bold text-slate-400 text-center py-1 border-t border-slate-100">
        {{ $candidate['unavailable_reason'] }}
    </div>
@endif
