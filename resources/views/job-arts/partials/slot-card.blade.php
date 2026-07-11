@php
    $selectedId = (int) ($slot?->skill_id ?? 0);
    $slotPolicy = (string) ($slot?->activation_policy ?? 'normal');
    $slotPolicy = array_key_exists($slotPolicy, $activationPolicyLabels) ? $slotPolicy : 'normal';
    $slotArt = $contextArts->firstWhere('id', $selectedId) ?: $allAvailableArts->firstWhere('id', $selectedId);
    $hasArt = $slotArt !== null;
    $artCost = $hasArt ? (int) $slotArt->art_cost : 0;
    $artOrigin = $hasArt ? ($slotArt->getAttribute('job_art_origin') ?: 'current') : '';
    $artSpCost = $hasArt ? $slotArt->jobArtSpCostForMaxSp($maxSp, $artOrigin) : 0;
    $artInheritedRate = $hasArt ? (int) round(((float) ($slotArt->getAttribute('job_art_rate') ?: 1.0)) * 100) : 100;
    $costBadgeClass = match ($artCost) {
        1 => 'bg-emerald-50 text-emerald-700',
        2 => 'bg-sky-50 text-sky-700',
        3 => 'bg-amber-50 text-amber-800',
        default => 'bg-slate-100 text-slate-600',
    };
    $otherSlotsCost = max(0, (int) ($contextTotalCost ?? 0) - $artCost);
    $remainingForSlot = \App\Services\JobArtService::MAX_COST - $otherSlotsCost;
    $slotLocked = !$hasArt && $remainingForSlot <= 0;
@endphp

<div
    data-job-art-slot-card="{{ $slotContext }}-{{ $slotNo }}"
    data-slot-context="{{ $slotContext }}"
    data-slot-no="{{ $slotNo }}"
    data-skill-id="{{ $selectedId }}"
    data-policy="{{ $slotPolicy }}"
    class="rounded-lg border border-slate-100 bg-white px-3 py-2.5 transition-colors"
>
    <div class="mb-1.5 flex items-center justify-between gap-2">
        <span class="text-[10px] font-black tracking-widest text-slate-300">SLOT {{ $slotNo }}</span>
        @if($hasArt)
            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-black {{ $costBadgeClass }}">Cost {{ $artCost }}</span>
        @else
            <span class="text-[11px] font-bold text-slate-300">未設定</span>
        @endif
    </div>

    @if($hasArt)
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0 flex-1">
                <div class="flex items-baseline gap-1.5 flex-wrap">
                    <span class="text-[15px] font-black text-slate-900">{{ $slotArt->name }}</span>
                    <span class="shrink-0 text-[10px] font-black {{ $artOrigin === 'current' ? 'text-amber-600' : 'text-indigo-600' }}">{{ $artOrigin === 'current' ? '本職' : '継承 ' . $artInheritedRate . '%' }}</span>
                </div>
                <div class="mt-0.5 text-[11px] font-bold text-slate-400">{{ $slotArt->jobClass?->name ?? '職業' }} · Rank{{ $slotArt->learn_rank }} · SP{{ $artSpCost }} · {{ $activationPolicyLabels[$slotPolicy] ?? '通常' }}</div>
            </div>
            <button type="button"
                data-job-art-target-btn
                data-slot-context="{{ $slotContext }}"
                data-slot-no="{{ $slotNo }}"
                class="shrink-0 mt-0.5 inline-flex items-center rounded-md border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-[11px] font-black text-indigo-700 shadow-sm transition-colors hover:border-indigo-300 hover:bg-indigo-100">
                変更する
            </button>
        </div>

        <div class="mt-2 grid grid-cols-3 gap-1.5 rounded-lg bg-slate-100 p-1">
            @foreach($activationPolicyLabels as $policyKey => $policyLabel)
                <label class="block flex-1">
                    <input type="radio"
                        name="{{ $slotContext }}_policy_{{ $slotNo }}_picker"
                        value="{{ $policyKey }}"
                        data-job-art-policy-radio
                        data-slot-context="{{ $slotContext }}"
                        data-slot-no="{{ $slotNo }}"
                        class="peer sr-only"
                        @checked($slotPolicy === $policyKey)>
                    <span class="flex h-8 items-center justify-center rounded-md border border-slate-200 bg-white text-[11px] font-black text-slate-500 shadow-sm cursor-pointer transition-colors peer-checked:border-indigo-500 peer-checked:bg-indigo-600 peer-checked:text-white">{{ $policyLabel }}</span>
                </label>
            @endforeach
        </div>
        <p class="mt-1.5 rounded-md bg-indigo-50 px-2 py-1.5 text-[11px] font-bold leading-relaxed text-indigo-700" data-job-art-policy-desc>{{ $activationPolicyDescriptions[$slotPolicy] ?? '' }}</p>
    @elseif($slotLocked)
        <div class="mt-1 w-full rounded-md border border-dashed border-slate-200 bg-slate-50 py-2 text-center text-xs font-black text-slate-400">
            コスト上限のため選べません
        </div>
    @else
        <button type="button"
            data-job-art-target-btn
            data-slot-context="{{ $slotContext }}"
            data-slot-no="{{ $slotNo }}"
            class="mt-1 w-full rounded-md border border-dashed border-indigo-300 bg-indigo-50/60 py-2 text-xs font-black text-indigo-700 transition-colors hover:bg-indigo-100">
            ↓ 下の一覧から選ぶ
        </button>
    @endif
</div>
