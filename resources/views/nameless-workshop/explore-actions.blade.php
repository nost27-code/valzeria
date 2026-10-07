@if($nextBossChallenge ?? null)
    <form method="post" action="{{ route('nameless-workshop.act', 'fight') }}" class="mb-3 w-full rounded-lg border border-red-200 bg-red-50 p-3 text-center" data-next-nameless-boss
        x-data="{ submitting: false, staminaCurrent: {{ (int) ($stamina['current'] ?? 0) }}, get ready() { return {{ ($stamina['enabled'] ?? false) && $nextBossChallenge['blocked_reason'] === null ? 'true' : 'false' }} && this.staminaCurrent >= {{ $nextBossChallenge['cost'] }}; } }"
        @valzeria-stamina-sync.window="staminaCurrent = Math.max(0, Number($event.detail.current || 0))"
        @submit="if (submitting || !ready) { $event.preventDefault(); return; } submitting = true"
        @pageshow.window="submitting = false">
        @include('nameless-workshop.token')
        <input type="hidden" name="zone" value="{{ $nextBossChallenge['zone'] }}">
        <input type="hidden" name="depth" value="{{ $nextBossChallenge['depth'] }}">
        <input type="hidden" name="boss" value="1">
        <input type="hidden" name="batch_count" value="1">
        <p class="mb-2 text-xs font-bold text-slate-700">{{ $nextBossChallenge['zone_name'] }} · 深度{{ $nextBossChallenge['depth'] }} ／ 探索力 -{{ $nextBossChallenge['cost'] }}</p>
        <button type="submit" x-bind:disabled="submitting || !ready" @disabled(!($stamina['enabled'] ?? false) || $nextBossChallenge['blocked_reason'] !== null || (int) ($stamina['current'] ?? 0) < $nextBossChallenge['cost'])
            class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-lg border-2 border-red-800 bg-red-600 px-4 py-3 text-sm font-bold text-white shadow disabled:cursor-not-allowed disabled:opacity-50">
            <x-loading-spinner x-show="submitting" style="display: none;" />
            <span x-show="!submitting">次のボスに挑む</span><span x-show="submitting" style="display: none;">準備中...</span>
        </button>
        @if($nextBossChallenge['blocked_reason'])
            <p class="mt-2 text-xs font-bold text-red-700" role="alert">{{ $nextBossChallenge['blocked_reason'] }}</p>
        @elseif(!($stamina['enabled'] ?? false))
            <p class="mt-2 text-xs font-bold text-red-700" role="alert">遺跡探索には探索力モードが必要です。</p>
        @else
            <p x-show="staminaCurrent < {{ $nextBossChallenge['cost'] }}" @if((int) ($stamina['current'] ?? 0) >= $nextBossChallenge['cost']) style="display: none;" @endif class="mt-2 text-xs font-bold text-red-700" role="alert">探索力が足りません。回復を待ってください。</p>
        @endif
    </form>
@endif
<div class="flex flex-wrap justify-center gap-2">
    <a href="{{ route('nameless-workshop.return', ['tab' => 'dungeon']) }}" class="rounded-lg border border-slate-300 bg-white px-4 py-3 text-sm font-bold text-slate-700">探索タブへ</a>
    <a href="{{ route('nameless-workshop.return', ['tab' => 'town']) }}" class="rounded-lg bg-slate-800 px-4 py-3 text-sm font-bold text-white">街へ戻る・回復する</a>
    <a href="{{ route('nameless-workshop.index', ['tab' => 'relics']) }}" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-900">遺物を整理する</a>
</div>
