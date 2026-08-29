@php
    $jobArtV2UiEnabled = (bool) ($jobArtV2UiEnabled ?? false);
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
    $originLabel = $jobArtV2UiEnabled
        ? (string) ($v2Display['source_lineage_name'] ?? '')
        : ($artOrigin === 'current' ? '本職' : '継承 ' . $artInheritedRate . '%');
    $roleBadgeClass = match ($v2Display['role_key'] ?? null) {
        'producer' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'consumer' => 'border-sky-200 bg-sky-50 text-sky-700',
        'finisher' => 'border-amber-300 bg-amber-100 text-amber-900',
        default => 'border-slate-200 bg-slate-100 text-slate-600',
    };
    $isUltimate = (bool) ($v2Display['is_ultimate'] ?? false);
    $jobArtIconPath = $hasArt ? (string) ($slotArt->getAttribute('job_art_icon_path') ?? '') : '';
    $spOutputUiEnabled = (bool) ($spOutputUiEnabled ?? false);
    $selectedSpOutput = (string) ($selectedSpOutput ?? 'none');
    $artSpOutputCosts = $hasArt ? (array) (($spOutputCardCosts ?? [])[$selectedId] ?? []) : [];
@endphp

<div
    data-job-art-slot-card="{{ $slotContext }}-{{ $slotNo }}"
    data-slot-context="{{ $slotContext }}"
    data-slot-no="{{ $slotNo }}"
    data-skill-id="{{ $selectedId }}"
    data-skill-name="{{ $hasArt ? $slotArt->name : '' }}"
    data-effective-cost="{{ $artCost }}"
    data-role-label="{{ $hasArt ? (string) ($v2Display['role_label'] ?? '') : '' }}"
    data-lineage-label="{{ $hasArt ? $originLabel : '' }}"
    data-policy="{{ $slotPolicy }}"
    data-condition="{{ $slotCondition }}"
    data-inactive-reason="{{ $inactiveReason }}"
    class="relative border px-3 py-2.5 transition-[border-color,background-color,box-shadow,transform,opacity] duration-150 data-[job-art-drop-target]:border-indigo-300 data-[job-art-drop-target]:shadow-[0_-3px_0_0_rgb(129_140_248)] {{ $jobArtV2UiEnabled ? 'min-w-0 rounded-xl shadow-sm ' : 'rounded-lg ' }}{{ $inactiveReason ? 'border-slate-300 bg-slate-100/80 opacity-75' : ($isUltimate ? 'border-amber-300 bg-gradient-to-br from-amber-50 to-white' : ($jobArtV2UiEnabled ? 'border-slate-200 bg-white' : 'border-slate-100 bg-white')) }}"
>
    @if($hasArt)
        @if($jobArtV2UiEnabled)
            <div class="grid min-w-0 {{ $jobArtIconPath !== '' ? 'grid-cols-[auto_48px_minmax(0,1fr)_auto]' : 'grid-cols-[auto_minmax(0,1fr)_auto]' }} items-center gap-2" data-job-art-compact-slot>
                <div class="flex items-center gap-0.5">
                    <button
                        type="button"
                        data-job-art-drag-handle
                        draggable="true"
                        aria-label="{{ $slotArt->name }}をドラッグして並び替える"
                        title="ドラッグして並び替え"
                        class="group inline-flex h-8 w-5 shrink-0 touch-none cursor-grab items-center justify-center rounded-md text-slate-400 transition-colors hover:bg-indigo-50 hover:text-indigo-600 active:cursor-grabbing"
                    >
                        <span aria-hidden="true" class="grid grid-cols-2 gap-[2px]">
                            @for($dot = 0; $dot < 6; $dot++)
                                <span class="h-1 w-1 rounded-full bg-current"></span>
                            @endfor
                        </span>
                    </button>
                    <span data-job-art-slot-index class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md border-2 bg-white text-xs font-black {{ $isUltimate ? 'border-amber-500 text-amber-700' : 'border-indigo-400 text-indigo-700' }}">{{ $slotNo }}</span>
                </div>
                <span data-job-art-drag-label class="absolute left-9 top-1 z-20 hidden rounded-full bg-indigo-600 px-2 py-0.5 text-[9px] font-black text-white shadow-sm">移動中</span>

                @if($jobArtIconPath !== '')
                    <img
                        src="{{ asset($jobArtIconPath) }}"
                        alt=""
                        width="48"
                        height="48"
                        loading="lazy"
                        decoding="async"
                        class="h-12 w-12 shrink-0 object-contain"
                        data-job-art-icon
                        data-job-art-slot-icon
                    >
                @endif

                <div class="min-w-0">
                    <div class="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-1">
                        <span class="min-w-0 break-words text-[15px] font-black leading-tight text-slate-950 sm:text-base">{{ $slotArt->name }}</span>
                        @if($v2Display)
                            <span class="inline-flex shrink-0 rounded-md border px-1.5 py-0.5 text-[9px] font-black {{ $roleBadgeClass }}" data-job-art-v2-role>{{ $v2Display['role_label'] }}</span>
                        @endif
                        @if($originLabel !== '')
                            <span class="inline-flex shrink-0 text-[9px] font-black text-indigo-700" data-job-art-lineage-badge>{{ $originLabel }}系譜</span>
                        @endif
                    </div>
                    @if($v2Display)
                        <p data-job-art-slot-summary data-job-art-v2-details class="mt-1 min-w-0 break-words text-[10px] font-bold leading-[1.55] text-slate-500 sm:text-[11px]">@include('job-arts.partials.effect-text', ['text' => $v2Display['display_description'] ?? $v2Display['card_description']])</p>
                    @endif
                </div>

                <div class="flex shrink-0 flex-col items-end gap-1">
                    <div class="flex items-center gap-1">
                        @if($inactiveReason)
                            <span class="inline-flex items-center rounded bg-slate-600 px-1.5 py-0.5 text-[9px] font-black text-white">休止中</span>
                        @endif
                        <span class="inline-flex items-center rounded-md px-1.5 py-1 text-[10px] font-black {{ $costBadgeClass }}">Cost {{ $artCost }}</span>
                    </div>
                    <button type="button"
                        data-job-art-target-btn
                        data-slot-context="{{ $slotContext }}"
                        data-slot-no="{{ $slotNo }}"
                        class="inline-flex shrink-0 items-center px-1 py-0.5 text-[9px] font-black text-indigo-600 transition-colors hover:text-indigo-900">
                        変更
                    </button>
                </div>
            </div>

            @if($spOutputUiEnabled && $artSpOutputCosts !== [])
                @include('job-arts.partials.sp-output-card-cost', [
                    'artSpOutputCosts' => $artSpOutputCosts,
                    'selectedSpOutput' => $selectedSpOutput,
                ])
            @endif

            @if($jobArtV2CardDetailsEnabled ?? false)
            <button
                type="button"
                data-job-art-slot-accordion-toggle
                aria-expanded="false"
                aria-label="{{ $slotArt->name }}の詳細を開く"
                class="mt-2 inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2 py-1 text-[11px] font-black text-slate-600 shadow-sm transition-colors hover:border-slate-300 hover:bg-slate-50"
            >
                <span data-job-art-slot-accordion-label>詳細</span>
                <span data-job-art-slot-accordion-icon aria-hidden="true">⌄</span>
            </button>
            <details data-job-art-slot-expanded class="mt-2 hidden rounded-lg border border-slate-200 bg-slate-50/70 px-2.5 py-2">
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
        @else
            <div class="mb-1.5 flex items-center justify-between gap-2">
                <span data-job-art-slot-index class="text-[10px] font-black tracking-widest text-slate-300">SLOT {{ $slotNo }}</span>
                <div class="flex items-center gap-1.5">
                @if($inactiveReason)
                    <span class="inline-flex items-center rounded bg-slate-600 px-1.5 py-0.5 text-[10px] font-black text-white">休止中</span>
                @endif
                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-black {{ $costBadgeClass }}">Cost {{ $artCost }}</span>
            </div>
            </div>
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-baseline gap-1.5">
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
        @endif
    @elseif($slotLocked)
        <div class="mb-1.5 flex items-center justify-between gap-2">
            <span data-job-art-slot-index class="{{ $jobArtV2UiEnabled ? 'inline-flex h-6 w-6 items-center justify-center rounded bg-slate-400 text-[11px] text-white' : 'text-[10px] tracking-widest text-slate-300' }} font-black">{{ $jobArtV2UiEnabled ? $slotNo : 'SLOT ' . $slotNo }}</span>
            <span class="text-[11px] font-bold text-slate-400">空き</span>
        </div>
        <div class="mt-1 w-full rounded-md border border-dashed border-slate-200 bg-slate-50 py-2 text-center text-xs font-black text-slate-400">
            {{ $jobArtV2UiEnabled ? 'Cost上限のため戦技を設定できません' : 'コスト上限のため選べません' }}
        </div>
    @else
        <div class="mb-1.5 flex items-center justify-between gap-2">
            <span data-job-art-slot-index class="{{ $jobArtV2UiEnabled ? 'inline-flex h-6 w-6 items-center justify-center rounded bg-slate-400 text-[11px] text-white' : 'text-[10px] tracking-widest text-slate-300' }} font-black">{{ $jobArtV2UiEnabled ? $slotNo : 'SLOT ' . $slotNo }}</span>
            <span class="text-[11px] font-bold {{ $jobArtV2UiEnabled ? 'text-slate-400' : 'text-slate-300' }}">{{ $jobArtV2UiEnabled ? '空き' : '未設定' }}</span>
        </div>
        <button type="button"
            data-job-art-target-btn
            data-slot-context="{{ $slotContext }}"
            data-slot-no="{{ $slotNo }}"
            class="mt-1 w-full rounded-md border border-dashed border-indigo-300 bg-indigo-50/60 py-2 text-xs font-black text-indigo-700 transition-colors hover:bg-indigo-100">
            {{ $jobArtV2UiEnabled ? '戦技を設定' : '↓ 下の一覧から選ぶ' }}
        </button>
    @endif
</div>
