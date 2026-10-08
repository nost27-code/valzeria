@if($repeatExploration ?? null)
    @include('nameless-workshop.repeat-explore-form')
@endif
@if($nextBossChallenge ?? null)
    <form method="post" action="{{ route('nameless-workshop.act', 'fight') }}" class="mb-3 w-full rounded-lg border border-red-200 bg-red-50 p-3 text-center" data-next-nameless-boss
        x-data="{ submitting: false, staminaCurrent: {{ (int) ($stamina['current'] ?? 0) }}, get ready() { return {{ ($stamina['enabled'] ?? false) && $nextBossChallenge['blocked_reason'] === null ? 'true' : 'false' }}; } }"
        @valzeria-stamina-sync.window="staminaCurrent = Math.max(0, Number($event.detail.current || 0))"
        @submit="if (submitting || !ready) { $event.preventDefault(); return; } if (staminaCurrent < {{ $nextBossChallenge['cost'] }}) { $event.preventDefault(); $dispatch('valzeria-stamina-recovery-open', { current: staminaCurrent, required: {{ $nextBossChallenge['cost'] }} }); return; } submitting = true"
        @pageshow.window="submitting = false">
        @include('nameless-workshop.token')
        <input type="hidden" name="zone" value="{{ $nextBossChallenge['zone'] }}">
        <input type="hidden" name="depth" value="{{ $nextBossChallenge['depth'] }}">
        <input type="hidden" name="boss" value="1">
        <input type="hidden" name="batch_count" value="1">
        <p class="mb-2 text-xs font-bold text-slate-700">{{ $nextBossChallenge['zone_name'] }} · 深度{{ $nextBossChallenge['depth'] }} ／ 探索力 -{{ $nextBossChallenge['cost'] }}</p>
        <button type="submit" x-bind:disabled="submitting || !ready" @disabled(!($stamina['enabled'] ?? false) || $nextBossChallenge['blocked_reason'] !== null)
            class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-lg border-2 border-red-800 bg-red-600 px-4 py-3 text-sm font-bold text-white shadow disabled:cursor-not-allowed disabled:opacity-50">
            <x-loading-spinner x-show="submitting" style="display: none;" />
            <span x-show="!submitting" x-text="staminaCurrent < {{ $nextBossChallenge['cost'] }} ? '探索力不足' : '次のボスに挑む'">{{ (int) ($stamina['current'] ?? 0) < $nextBossChallenge['cost'] ? '探索力不足' : '次のボスに挑む' }}</span><span x-show="submitting" style="display: none;">準備中...</span>
        </button>
        @if($nextBossChallenge['blocked_reason'])
            <p class="mt-2 text-xs font-bold text-red-700" role="alert">{{ $nextBossChallenge['blocked_reason'] }}</p>
        @elseif(!($stamina['enabled'] ?? false))
            <p class="mt-2 text-xs font-bold text-red-700" role="alert">遺跡探索には探索力モードが必要です。</p>
        @else
            <p x-show="staminaCurrent < {{ $nextBossChallenge['cost'] }}" @if((int) ($stamina['current'] ?? 0) >= $nextBossChallenge['cost']) style="display: none;" @endif class="mt-2 text-xs font-bold text-red-700" role="alert">探索力が足りません。探索力を回復してください。</p>
        @endif
    </form>
@endif
@if($stamina['enabled'] ?? false)
    @include('partials.exploration-stamina-recovery')
@endif
