@php
    $jobArtV2UiEnabled = (bool) ($jobArtV2UiEnabled ?? false);
    $currentJobId = isset($currentJobId) ? (int) $currentJobId : null;
    $selectedId = (int) ($slot?->skill_id ?? 0);
    $slotPolicy = (string) ($slot?->activation_policy ?? 'normal');
    $slotPolicy = array_key_exists($slotPolicy, $activationPolicyLabels) ? $slotPolicy : 'normal';
    $slotConditionLabels = $slotConditionLabels ?? ['always' => '条件なし'];
    $slotCondition = (string) ($slot?->getAttribute('job_art_slot_condition') ?? 'always');
    $slotCondition = array_key_exists($slotCondition, $slotConditionLabels) ? $slotCondition : 'always';
    $slotArt = $contextArts->firstWhere('id', $selectedId) ?: $allAvailableArts->firstWhere('id', $selectedId);
    $hasArt = $slotArt !== null;
    $artCost = $hasArt ? (int) ($slotArt->getAttribute('job_art_effective_cost') ?? $slotArt->art_cost) : 0;
    $inactiveReason = (string) ($slot?->getAttribute('job_art_inactive_reason') ?? '');
    $inactiveReasonLabel = match ($inactiveReason) {
        'slot_limit' => $jobArtV2UiEnabled ? '5枠上限' : '枠数上限のため休止',
        'cost_limit' => $jobArtV2UiEnabled ? 'Cost上限超過' : 'Cost上限を超えたため休止',
        default => '',
    };
    $artOrigin = $hasArt ? ($slotArt->getAttribute('job_art_origin') ?: 'current') : '';
    $artSpCost = $hasArt
        ? (int) ($slotArt->getAttribute('job_art_display_sp_cost')
            ?? $slotArt->jobArtSpCostForMaxSp($maxSp, $artOrigin))
        : 0;
    $artInheritedRate = $hasArt ? (int) round(((float) ($slotArt->getAttribute('job_art_rate') ?: 1.0)) * 100) : 100;
    $artActivationRate = $hasArt
        ? (int) ($slotArt->getAttribute('job_art_display_activation_rate') ?? $slotArt->effectiveActivationRate())
        : 0;
    $costBadgeClass = match ($artCost) {
        1 => 'bg-emerald-50 text-emerald-700',
        2 => 'bg-sky-50 text-sky-700',
        3 => 'bg-amber-50 text-amber-800',
        default => 'bg-slate-100 text-slate-600',
    };
    $otherSlotsCost = max(0, (int) ($contextTotalCost ?? 0) - $artCost);
    $remainingForSlot = (int) $maxCost - $otherSlotsCost;
    $slotLocked = !$hasArt && $remainingForSlot <= 0;
    $v2Display = $jobArtV2UiEnabled && $hasArt
        ? $slotArt->getAttribute('job_art_v2_loadout_display')
        : null;
    $isCurrentJobArt = $hasArt && $currentJobId !== null && (int) $slotArt->job_id === $currentJobId;
    $originLabel = $jobArtV2UiEnabled
        ? (string) ($v2Display['source_badge'] ?? ($isCurrentJobArt ? '現在職' : '継承'))
        : ($artOrigin === 'current' ? '本職' : '継承 ' . $artInheritedRate . '%');
    $roleBadgeClass = match ($v2Display['role_key'] ?? null) {
        'producer' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'consumer' => 'border-sky-200 bg-sky-50 text-sky-700',
        'finisher' => 'border-amber-300 bg-amber-100 text-amber-900',
        default => 'border-slate-200 bg-slate-100 text-slate-600',
    };
    $isUltimate = (bool) ($v2Display['is_ultimate'] ?? false);
@endphp

<div
    data-job-art-slot-card="{{ $slotContext }}-{{ $slotNo }}"
    data-slot-context="{{ $slotContext }}"
    data-slot-no="{{ $slotNo }}"
    data-skill-id="{{ $selectedId }}"
    data-policy="{{ $slotPolicy }}"
    data-condition="{{ $slotCondition }}"
    data-inactive-reason="{{ $inactiveReason }}"
    class="rounded-lg border px-3 py-2.5 transition-colors {{ $jobArtV2UiEnabled ? 'min-w-0 ' : '' }}{{ $inactiveReason ? 'border-slate-300 bg-slate-100/80 opacity-75' : ($isUltimate ? 'border-amber-300 bg-gradient-to-br from-amber-50 to-white shadow-sm' : 'border-slate-100 bg-white') }}"
>
    <div class="mb-1.5 flex items-center justify-between gap-2">
        <span class="text-[10px] font-black tracking-widest {{ $jobArtV2UiEnabled ? ($isUltimate ? 'text-amber-600' : 'text-slate-400') : 'text-slate-300' }}">{{ $jobArtV2UiEnabled ? '[' . $slotNo . ']' : 'SLOT ' . $slotNo }}</span>
        @if($hasArt)
            <div class="flex items-center gap-1.5">
                @if($inactiveReason)
                    <span class="inline-flex items-center rounded bg-slate-600 px-1.5 py-0.5 text-[10px] font-black text-white">休止中</span>
                @endif
                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-black {{ $costBadgeClass }}">Cost {{ $artCost }}</span>
            </div>
        @else
            <span class="text-[11px] font-bold {{ $jobArtV2UiEnabled ? 'text-slate-400' : 'text-slate-300' }}">{{ $jobArtV2UiEnabled ? '空き' : '未設定' }}</span>
        @endif
    </div>

    @if($hasArt)
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0 flex-1">
                @if($jobArtV2UiEnabled)
                    <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                        <span class="min-w-0 break-words text-[15px] font-black text-slate-900">{{ $slotArt->name }}</span>
                        @if($v2Display)
                            <span class="inline-flex shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-black {{ $roleBadgeClass }}" data-job-art-v2-role>{{ $v2Display['role_label'] }}</span>
                        @endif
                        <span class="inline-flex shrink-0 rounded-full bg-white/90 px-2 py-0.5 text-[10px] font-black {{ ($v2Display['origin_key'] ?? null) === 'current' ? 'text-amber-700' : 'text-indigo-700' }}" data-job-art-lineage-badge>{{ $originLabel }}</span>
                    </div>
                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-[11px] font-black text-slate-600">
                        <span>Cost {{ $artCost }}</span>
                        <span>SP {{ $artSpCost }}</span>
                        <span>発動 {{ $artActivationRate }}%</span>
                        <span>{{ $activationPolicyLabels[$slotPolicy] ?? '通常' }}</span>
                        @if($slotArt->max_uses_per_battle)
                            <span>1戦{{ $slotArt->max_uses_per_battle }}回</span>
                        @endif
                    </div>
                @else
                    <div class="flex items-baseline gap-1.5 flex-wrap">
                        <span class="text-[15px] font-black text-slate-900">{{ $slotArt->name }}</span>
                        <span class="shrink-0 text-[10px] font-black {{ $artOrigin === 'current' ? 'text-amber-600' : 'text-indigo-600' }}">{{ $artOrigin === 'current' ? '本職' : '継承 ' . $artInheritedRate . '%' }}</span>
                    </div>
                    <div class="mt-0.5 text-[11px] font-bold text-slate-400">{{ $slotArt->jobClass?->name ?? '職業' }} · Rank{{ $slotArt->learn_rank }} · SP{{ $artSpCost }} · {{ $activationPolicyLabels[$slotPolicy] ?? '通常' }}</div>
                @endif
            </div>
            <button type="button"
                data-job-art-target-btn
                data-slot-context="{{ $slotContext }}"
                data-slot-no="{{ $slotNo }}"
                class="shrink-0 mt-0.5 inline-flex items-center rounded-md border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-[11px] font-black text-indigo-700 shadow-sm transition-colors hover:border-indigo-300 hover:bg-indigo-100">
                変更する
            </button>
        </div>

        @if($v2Display)
            <div class="mt-2 space-y-1 rounded-md border {{ $isUltimate ? 'border-amber-200 bg-amber-50/80' : 'border-slate-100 bg-slate-50/80' }} px-2.5 py-2 text-[11px] font-bold leading-relaxed text-slate-700" data-job-art-v2-details>
                @if($v2Display['resource_text'])
                    <div>{{ $v2Display['resource_text'] }}</div>
                @endif
                @foreach($v2Display['effect_texts'] ?? [] as $effectText)
                    <div>{{ $effectText }}</div>
                @endforeach
                @foreach($v2Display['field_texts'] as $fieldText)
                    <div>{{ $fieldText }}</div>
                @endforeach
                @foreach($v2Display['stance_texts'] as $stanceText)
                    <div>{{ $stanceText }}</div>
                @endforeach
                @if($v2Display['priority_text'])
                    <div class="font-black text-amber-800">{{ $v2Display['priority_text'] }}</div>
                @endif
            </div>
        @endif

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
        @if($jobArtV2UiEnabled)
            <details class="mt-2 rounded-lg border border-slate-200 bg-white px-2.5 py-2" @if($slotCondition !== 'always') open @endif>
                <summary class="cursor-pointer text-[11px] font-black text-slate-600">発動条件：{{ $slotConditionLabels[$slotCondition] }}</summary>
                <label class="mt-2 block text-[10px] font-bold text-slate-500">
                    前方枠の条件が不成立なら、次の枠を判定します。
                    <select
                        name="{{ $slotContext }}_condition_{{ $slotNo }}"
                        data-job-art-condition-select
                        data-slot-context="{{ $slotContext }}"
                        data-slot-no="{{ $slotNo }}"
                        class="mt-1.5 block w-full min-w-0 rounded-md border-slate-300 bg-white text-xs font-bold text-slate-700 focus:border-indigo-500 focus:ring-indigo-500">
                        @foreach($slotConditionLabels as $conditionKey => $conditionLabel)
                            <option value="{{ $conditionKey }}" @selected($slotCondition === $conditionKey)>{{ $conditionLabel }}</option>
                        @endforeach
                    </select>
                </label>
                <p class="mt-1.5 text-[10px] font-bold text-slate-400">条件も戦技と一緒にプリセットへ保存されます。</p>
            </details>
        @endif
        @if($inactiveReasonLabel)
            <p class="mt-1.5 rounded-md border border-slate-300 bg-slate-200/70 px-2 py-1.5 text-[11px] font-black leading-relaxed text-slate-700" data-job-art-inactive-reason>{{ $inactiveReasonLabel }}</p>
        @endif
    @elseif($slotLocked)
        <div class="mt-1 w-full rounded-md border border-dashed border-slate-200 bg-slate-50 py-2 text-center text-xs font-black text-slate-400">
            {{ $jobArtV2UiEnabled ? 'Cost上限のため戦技を設定できません' : 'コスト上限のため選べません' }}
        </div>
    @else
        <button type="button"
            data-job-art-target-btn
            data-slot-context="{{ $slotContext }}"
            data-slot-no="{{ $slotNo }}"
            class="mt-1 w-full rounded-md border border-dashed border-indigo-300 bg-indigo-50/60 py-2 text-xs font-black text-indigo-700 transition-colors hover:bg-indigo-100">
            {{ $jobArtV2UiEnabled ? '戦技を設定' : '↓ 下の一覧から選ぶ' }}
        </button>
    @endif
</div>
