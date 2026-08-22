<div
    x-data="{
        fixedLocation: @js($placeholderLocation ?? null),
        targetLocation: @js($placeholderLocation ?? null),
        timedOut: false,
        timeoutId: null,
        startTimeout() {
            clearTimeout(this.timeoutId);
            this.timedOut = false;
            this.timeoutId = setTimeout(() => this.timedOut = true, 15000);
        },
        startFor(location) {
            const normalized = location === 'job' ? 'town' : location;
            if (this.fixedLocation !== null && normalized !== this.fixedLocation) return;
            this.targetLocation = normalized;
            this.startTimeout();
        },
    }"
    x-init="if (targetLocation !== null && currentLocation === targetLocation) startTimeout()"
    @main-tab-selected.window="startFor($event.detail.location)"
    class="flex min-h-[50vh] items-center justify-center rounded-xl border border-[#d4af37] bg-white"
    role="status"
    :aria-label="timedOut ? 'タブの再読み込み待ち' : 'タブを読み込み中'"
>
    <div x-show="!timedOut" class="flex items-center gap-3 text-sm font-black text-[#9a6b00]">
        <svg class="h-7 w-7 animate-spin text-[#d4af37]" fill="none" viewBox="0 0 24 24" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
        </svg>
        読み込み中…
    </div>

    <div x-cloak x-show="timedOut" class="px-5 py-8 text-center">
        <p class="text-sm font-black text-slate-700">読み込みに時間がかかっています</p>
        <button
            type="button"
            @click="startTimeout(); $dispatch('changeTab', { newLocation: targetLocation })"
            class="mt-4 rounded-lg border border-[#d4af37] bg-[#fff8dc] px-4 py-2 text-sm font-black text-[#7a5200] shadow-sm transition active:scale-95"
        >
            もう一度読み込む
        </button>
    </div>
</div>
