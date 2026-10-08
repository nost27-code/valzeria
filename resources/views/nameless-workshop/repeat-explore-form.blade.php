@php
    $quickRepeatCounts = \App\Services\ExplorationService::QUICK_REPEAT_COUNTS;
    $minCustomRepeatCount = \App\Services\ExplorationService::MIN_CUSTOM_REPEAT_COUNT;
    $maxRepeatCount = \App\Services\ExplorationService::MAX_REPEAT_COUNT;
    $initialCount = $selectedNamelessExploreCount;
    $runCost = (int) config('nameless_relics.stamina_cost');
    $maxHp = max(1, (int) $finalStats['max_hp']);
    $maxSp = max(0, (int) ($finalStats['max_mp'] ?? 0));
    $lowPercent = \App\Services\ExplorationService::LOW_RESOURCE_WARNING_PERCENT;
    $hpBlocked = $initialCount > 1 && $character->current_hp <= max(1, (int) floor($maxHp * $lowPercent / 100));
    $initialReady = ($stamina['enabled'] ?? false) && $repeatExploration['blocked_reason'] === null
        && ! $hpBlocked;
@endphp
<form method="POST" action="{{ route('nameless-workshop.act', 'fight') }}" data-nameless-repeat-form
    x-data="{
        submitting: false,
        selectedCount: {{ $initialCount }}, customCount: {{ in_array($initialCount, $quickRepeatCounts) ? \App\Services\ExplorationService::DEFAULT_CUSTOM_REPEAT_COUNT : $initialCount }},
        customSelected: {{ in_array($initialCount, $quickRepeatCounts) ? 'false' : 'true' }},
        staminaCurrent: {{ (int) ($stamina['current'] ?? 0) }}, staminaCost: {{ $runCost }},
        currentHp: {{ (int) $character->current_hp }}, maxHp: {{ $maxHp }},
        currentSp: {{ (int) $character->current_mp }}, maxSp: {{ $maxSp }},
        get effectiveCount() {
            if (!this.customSelected) return this.selectedCount;
            const parsed = Number.parseInt(this.customCount, 10);
            return Number.isFinite(parsed) ? Math.min({{ $maxRepeatCount }}, Math.max({{ $minCustomRepeatCount }}, parsed)) : {{ \App\Services\ExplorationService::DEFAULT_CUSTOM_REPEAT_COUNT }};
        },
        get requiredStamina() { return this.staminaCost * this.effectiveCount; },
        get staminaAfter() { return Math.max(0, this.staminaCurrent - this.requiredStamina); },
        get hpPercent() { return Math.floor(Math.max(0, this.currentHp) / this.maxHp * 100); },
        get spPercent() { return this.maxSp > 0 ? Math.floor(Math.max(0, this.currentSp) / this.maxSp * 100) : 100; },
        get buttonLabel() { if (this.staminaCurrent < this.requiredStamina) return '探索力不足'; return this.effectiveCount === 1 ? '1回探索する' : this.effectiveCount + '回まとめて探索する'; },
        get resourceWarning() {
            const hpLow = this.hpPercent <= {{ $lowPercent }}, spLow = this.spPercent <= {{ $lowPercent }};
            if (hpLow && spLow) return 'HP/SPが少ないため、途中で敗北する可能性があります。';
            if (hpLow) return 'HPが少ないため、途中で敗北する可能性があります。';
            if (spLow) return 'SPが少ないため、途中で敗北する可能性があります。';
            return '';
        },
        get hpBlocked() { return this.effectiveCount > 1 && this.currentHp <= Math.max(1, Math.floor(this.maxHp * {{ $lowPercent }} / 100)); },
        get ready() { return {{ ($stamina['enabled'] ?? false) && $repeatExploration['blocked_reason'] === null ? 'true' : 'false' }} && !this.hpBlocked; },
        syncButtonState() { this.$root.querySelector('[name=batch_count]').value = this.effectiveCount; },
        selectFixedCount(count) { this.customSelected = false; this.selectedCount = count; },
        selectCustomCount() { this.customSelected = true; this.customCount = this.effectiveCount; },
        normalizeCustomCount() { this.customCount = this.effectiveCount; },
        adjustCustomCount(delta) { this.customSelected = true; this.customCount = Math.min({{ $maxRepeatCount }}, Math.max({{ $minCustomRepeatCount }}, this.effectiveCount + delta)); }
    }"
    @valzeria-stamina-sync.window="staminaCurrent = Math.max(0, Number($event.detail.current || 0))"
    @battle-stamina-updated.window="staminaCurrent = Math.max(0, Number($event.detail.current || 0))"
    @battle-hp-updated.window="currentHp = Math.max(0, Number($event.detail.current || 0)); maxHp = Math.max(1, Number($event.detail.max || 1))"
    @battle-sp-updated.window="currentSp = Math.max(0, Number($event.detail.current || 0)); maxSp = Math.max(0, Number($event.detail.max || 0))"
    @submit="if (submitting || !ready) { $event.preventDefault(); return; } if (staminaCurrent < requiredStamina) { $event.preventDefault(); $dispatch('valzeria-stamina-recovery-open', { current: staminaCurrent, required: requiredStamina }); return; } $el.querySelector('[name=batch_count]').value = effectiveCount; submitting = true"
    @pageshow.window="submitting = false; if (customSelected) { const restored = Number.parseInt($root.querySelector('[data-exploration-repeat-count]').value, 10); if (Number.isFinite(restored)) customCount = restored; normalizeCustomCount(); } else { $root.querySelector('[data-exploration-repeat-count]').value = customCount; }"
    data-required-stamina="{{ $initialCount * $runCost }}" x-bind:data-required-stamina="requiredStamina"
    class="w-full max-w-md rounded-xl border border-amber-200 bg-amber-50/70 p-3 shadow-sm">
    @include('nameless-workshop.token')
    <input type="hidden" name="zone" value="{{ $repeatExploration['zone'] }}">
    <input type="hidden" name="depth" value="{{ $repeatExploration['depth'] }}">
    <input type="hidden" name="boss" value="0">
    <input type="hidden" name="batch_count" value="{{ $initialCount }}" x-bind:value="effectiveCount">

    @include('partials.exploration-repeat-selector')

    <p x-show="resourceWarning" x-text="resourceWarning" x-cloak class="mt-2 text-center text-xs font-black leading-5 text-amber-700" role="alert"></p>
    <button type="submit" x-bind:disabled="submitting || !ready" @disabled(!$initialReady)
        class="mt-2 flex min-h-14 w-full items-center justify-center gap-2 rounded-lg border-2 border-amber-700 bg-amber-600 px-4 py-3 text-base font-black text-white shadow-md transition hover:bg-amber-700 active:scale-[0.99] disabled:cursor-not-allowed disabled:border-slate-400 disabled:bg-slate-400">
        <x-loading-spinner x-show="submitting" style="display: none;" size="h-4 w-4" />
        <img src="{{ asset('images/icon/icon_005.webp') }}" alt="" class="h-5 w-5 object-contain">
        <span x-text="submitting ? '探索中...' : buttonLabel">{{ (int) ($stamina['current'] ?? 0) < $initialCount * $runCost ? '探索力不足' : ($initialCount === 1 ? '1回探索する' : $initialCount.'回まとめて探索する') }}</span>
    </button>
    <p class="mt-1.5 flex items-center justify-center gap-1 text-xs font-black text-slate-600">
        <img src="{{ asset('images/icon/icon_082.webp') }}" alt="" class="h-4 w-4 object-contain">
        <span>探索力</span>
        <span x-text="'-' + requiredStamina.toLocaleString()">-{{ number_format($initialCount * $runCost) }}</span>
        <span class="text-slate-400">｜</span>
        <span x-text="staminaCurrent.toLocaleString() + ' → ' + staminaAfter.toLocaleString()">{{ number_format((int) ($stamina['current'] ?? 0)) }} → {{ number_format(max(0, (int) ($stamina['current'] ?? 0) - $initialCount * $runCost)) }}</span>
    </p>
    @if($repeatExploration['blocked_reason'])
        <p class="mt-2 text-center text-xs font-bold text-red-700" role="alert">{{ $repeatExploration['blocked_reason'] }}</p>
    @elseif(!($stamina['enabled'] ?? false))
        <p class="mt-2 text-center text-xs font-bold text-red-700" role="alert">遺跡探索には探索力モードが必要です。</p>
    @else
        <p x-show="staminaCurrent < requiredStamina" x-cloak class="mt-2 text-center text-xs font-bold text-red-700" role="alert">探索力が足りません。探索回数を減らすか、探索力を回復してください。</p>
        <p x-show="hpBlocked" x-cloak class="mt-2 text-center text-xs font-bold text-amber-700" role="alert">HPが30%以下のため連続探索できません。街で回復してください。</p>
    @endif
</form>
