@php
    $exploreStats = $stats ?? $finalStats ?? app(\App\Services\CharacterStatusService::class)->getFinalStats($character);
    $exploreStamina = $stamina ?? app(\App\Services\ExplorationStaminaService::class)->summary($character);
    $initialCount = \App\Services\ExplorationService::normalizeRepeatCount($selectedCount ?? session('nameless_exploration_selected_count.'.$character->id, 1));
    $runCost = (int) config('nameless_relics.stamina_cost');
    $bossCost = (int) config('nameless_relics.boss_stamina_cost');
    $inventoryBlockReason = ($inventoryCapacityChecked ?? false) ? ($inventoryBlockReason ?? null) : app(\App\Services\NamelessWorkshopService::class)->inventoryBlockReason($character);
    $cooldown = $character->exploration_cooldown_until?->isFuture() ? max(0, (int) now()->diffInSeconds($character->exploration_cooldown_until)) : 0;
@endphp
<form method="post" action="{{ route('nameless-workshop.act', 'fight') }}" class="w-full min-w-0 rounded-lg border border-amber-200 bg-white/90 p-1.5 shadow-sm" data-compact-exploration-actions
    x-data="{
        submitting: false, bossAttempt: false, remaining: {{ $cooldown }}, inventoryAvailable: {{ $inventoryBlockReason === null ? 'true' : 'false' }},
        selectedCount: {{ in_array($initialCount, [1, 10]) ? $initialCount : 1 }}, customSelected: {{ in_array($initialCount, [1, 10]) ? 'false' : 'true' }}, customCount: {{ $initialCount > 1 ? $initialCount : \App\Services\ExplorationService::DEFAULT_CUSTOM_REPEAT_COUNT }},
        staminaCurrent: {{ (int) ($exploreStamina['current'] ?? 0) }},
        get effectiveCount() { if (!this.customSelected) return this.selectedCount; const n = Number.parseInt(this.customCount, 10); return Math.min({{ \App\Services\ExplorationService::MAX_REPEAT_COUNT }}, Math.max(2, Number.isFinite(n) ? n : 50)); },
        get requiredStamina() { return this.effectiveCount * {{ $runCost }}; },
        get enoughStamina() { return this.staminaCurrent >= this.requiredStamina; },
        get hpAllowsRepeat() { return this.effectiveCount === 1 || {{ (int) $character->current_hp }} > {{ max(1, (int) floor($exploreStats['max_hp'] * (\App\Services\ExplorationService::LOW_RESOURCE_WARNING_PERCENT / 100))) }}; },
        get ready() { return this.inventoryAvailable && {{ ($exploreStamina['enabled'] ?? false) && !$character->is_frozen && $character->current_hp > 0 ? 'true' : 'false' }} && this.remaining === 0 && this.enoughStamina && this.hpAllowsRepeat; },
        get bossReady() { return this.inventoryAvailable && {{ ($exploreStamina['enabled'] ?? false) && !$character->is_frozen && $character->current_hp > 0 ? 'true' : 'false' }} && this.remaining === 0 && this.staminaCurrent >= {{ $bossCost }}; },
        selectFixedCount(n) { this.customSelected = false; this.selectedCount = n; },
        selectCustomCount() { this.customSelected = true; },
        normalizeCustomCount() { this.customCount = this.effectiveCount; },
        adjustCustomCount(n) { this.customSelected = true; this.customCount = Math.min(50, Math.max(2, this.effectiveCount + n)); }
    }"
    x-init="if (remaining > 0) { const timer = setInterval(() => { remaining = Math.max(0, remaining - 1); if (!remaining) clearInterval(timer); }, 1000); }"
    @valzeria-stamina-sync.window="staminaCurrent = Math.max(0, Number($event.detail.current || 0))"
    @submit="bossAttempt = $event.submitter?.value === '1'; if (submitting || !(bossAttempt ? bossReady : ready)) { $event.preventDefault(); return; } $el.querySelector('[name=batch_count]').value = bossAttempt ? 1 : effectiveCount; submitting = true">
    @include('nameless-workshop.token')
    @if($inventoryBlockReason)<p class="mb-2 text-xs font-bold text-red-700" role="alert">{{ $inventoryBlockReason }} <a class="underline" href="{{ route('nameless-workshop.index') }}">鍛冶屋へ</a></p>@endif
    <input type="hidden" name="zone" value="{{ $zoneKey }}">
    <input type="hidden" name="batch_count" value="1" x-bind:value="bossAttempt ? 1 : effectiveCount">
    <label class="mb-2 flex items-center justify-between gap-2 text-xs font-bold text-slate-600">探索する深度
        <select name="depth" class="rounded border-slate-300 py-1 text-xs" aria-label="{{ $zoneName }}の探索深度">
            @for($d = 1; $d <= $unlocked; $d++)<option value="{{ $d }}" @selected($d === (int) ($selectedDepth ?? $unlocked))>深度 {{ $d }}</option>@endfor
        </select>
    </label>
    <div class="mb-1 flex justify-between text-[10px] font-black text-slate-600"><span>探索回数</span><span>直接入力（2〜50回）</span></div>
    <div class="grid items-stretch gap-1.5 sm:grid-cols-[minmax(0,1fr)_72px]">
        <div class="grid h-11 overflow-hidden rounded border border-slate-300 bg-white" style="grid-template-columns:48px 48px minmax(144px,1fr)" role="group" aria-label="探索回数">
            @foreach(\App\Services\ExplorationService::QUICK_REPEAT_COUNTS as $n)
                <button type="button" @click="selectFixedCount({{ $n }})" x-bind:aria-pressed="!customSelected && selectedCount === {{ $n }}" x-bind:class="!customSelected && selectedCount === {{ $n }} ? 'bg-sky-700 text-white shadow-inner' : 'bg-white text-slate-700 hover:bg-slate-50'" class="min-h-11 border-r border-slate-200 px-0.5 py-1.5 text-[11px] font-black transition">{{ $n }}回</button>
            @endforeach
            <div class="flex h-11 min-w-0 items-stretch" x-bind:class="customSelected ? 'bg-sky-700 text-white shadow-inner' : 'bg-white text-slate-700'">
                <button type="button" @click="adjustCustomCount(-1)" x-bind:disabled="effectiveCount <= 2" aria-label="探索回数を1減らす" class="h-11 w-11 shrink-0 border-r border-slate-300 text-lg font-black disabled:opacity-40">−</button>
                <label class="flex min-w-0 flex-1 items-center"><input type="number" min="2" max="50" step="1" inputmode="numeric" data-exploration-repeat-count aria-label="任意の探索回数（2〜50回）" x-model.number="customCount" @focus="selectCustomCount(); $event.target.select()" @input="customSelected = true" @change="normalizeCustomCount()" @blur="normalizeCustomCount()" @keydown.enter.prevent="normalizeCustomCount()" class="h-11 min-w-0 w-full border-0 bg-transparent px-1 text-right text-sm font-black text-current [appearance:textfield] focus:ring-0 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"><span class="pr-2 text-[11px] font-black">回</span></label>
                <button type="button" @click="adjustCustomCount(1)" x-bind:disabled="effectiveCount >= 50" aria-label="探索回数を1増やす" class="h-11 w-11 shrink-0 border-l border-slate-300 text-lg font-black disabled:opacity-40">＋</button>
            </div>
        </div>
        <button type="submit" name="boss" value="0" @click="bossAttempt = false" x-bind:disabled="submitting || !ready" x-bind:aria-label="effectiveCount === 1 ? '1回探索する' : effectiveCount + '回まとめて探索する'" class="h-11 rounded-md border-2 border-amber-700 bg-amber-600 px-2 text-xs font-black text-white shadow hover:bg-amber-700 disabled:opacity-40"><span x-text="submitting ? '探索中' : '探索'">探索</span></button>
    </div>
    <p class="mt-1 text-center text-[10px] font-black text-slate-600">探索力 <span x-text="'-' + requiredStamina + ' ｜ ' + staminaCurrent + ' → ' + Math.max(0, staminaCurrent - requiredStamina)">-{{ $runCost }}</span></p>
    <p x-show="!enoughStamina" x-cloak class="mt-1 text-center text-xs text-red-600" role="alert">選択した回数分の探索力が足りません。</p>
    <p x-show="!hpAllowsRepeat" x-cloak class="mt-1 text-center text-xs text-amber-700" role="alert">HPが30%以下のため連続探索できません。街で回復してください。</p>
    <p x-show="remaining > 0" x-cloak x-text="'待機中 あと' + remaining + '秒'" class="text-center text-xs text-slate-500"></p>
    <button type="submit" name="boss" value="1" @click="bossAttempt = true" x-bind:disabled="submitting || !bossReady"
        x-bind:class="bossReady ? 'cursor-pointer active:scale-95' : 'cursor-not-allowed opacity-70'"
        class="mt-2 inline-flex w-full items-center justify-center gap-2 px-4 py-1.5 rounded text-sm font-bold shadow transition-all duration-150 text-center disabled:cursor-not-allowed disabled:opacity-70" style="background-color: #dc2626; border: 2px solid #991b1b; color: white;">
        <x-loading-spinner x-show="submitting && bossAttempt" style="display: none;" />
        <span x-show="!submitting || !bossAttempt">ボスに挑む</span>
        <span x-show="submitting && bossAttempt" style="display: none;">準備中...</span>
    </button>
    @if($exploreStamina['enabled'] ?? false)
        <button type="button" @click="$dispatch('valzeria-stamina-recovery-open', { current: staminaCurrent, required: requiredStamina })" x-bind:disabled="submitting" class="mt-2 min-h-11 w-full rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-bold text-sky-800 disabled:opacity-50">探索力を回復する</button>
    @endif
</form>
@if($exploreStamina['enabled'] ?? false)
    @include('partials.exploration-stamina-recovery', ['stamina' => $exploreStamina])
@endif
