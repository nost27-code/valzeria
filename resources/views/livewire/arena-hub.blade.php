<div class="w-full" data-arena-hub data-arena-mode="{{ $mode }}">
    @if($sixHeroesEnabled && $legacyArenaAvailable)
        <div
            class="mb-4 rounded-xl border border-amber-200 bg-amber-50/70 p-2 shadow-sm"
            data-arena-mode-switcher
        >
            <div class="grid grid-cols-2 gap-2" role="tablist" aria-label="闘技場の種類">
                <button
                    type="button"
                    role="tab"
                    aria-selected="{{ $mode === 'six_heroes' ? 'true' : 'false' }}"
                    wire:click="selectMode('six_heroes')"
                    class="min-w-0 rounded-lg px-3 py-2.5 text-center transition active:scale-[0.98] {{ $mode === 'six_heroes' ? 'bg-[#8a5a00] text-white shadow' : 'bg-white text-slate-700 ring-1 ring-inset ring-amber-200 hover:bg-amber-50' }}"
                    data-arena-mode-button="six_heroes"
                >
                    <span class="block text-sm font-black">六英雄戦</span>
                    <span class="mt-0.5 block text-[10px] font-bold opacity-80">月間・6部門</span>
                </button>
                <button
                    type="button"
                    role="tab"
                    aria-selected="{{ $mode === 'legacy' ? 'true' : 'false' }}"
                    wire:click="selectMode('legacy')"
                    class="min-w-0 rounded-lg px-3 py-2.5 text-center transition active:scale-[0.98] {{ $mode === 'legacy' ? 'bg-slate-800 text-white shadow' : 'bg-white text-slate-700 ring-1 ring-inset ring-amber-200 hover:bg-amber-50' }}"
                    data-arena-mode-button="legacy"
                >
                    <span class="block text-sm font-black">通常闘技場</span>
                    <span class="mt-0.5 block text-[10px] font-bold opacity-80">通算ランキング</span>
                </button>
            </div>

            <p
                class="mt-1.5 px-1 text-center text-[10px] font-medium leading-relaxed text-slate-500"
                data-arena-schedule-notice
            >
                <span class="block sm:inline">※ 8月はプレシーズンとして競技を行いますが、英雄記録の対象外です。</span>
                <span class="block sm:ml-1 sm:inline">月間英雄の記録は9月より開始し、通常闘技場は8月末で停止します。</span>
            </p>
        </div>
    @endif

    @if($sixHeroesEnabled && $mode === 'six_heroes')
        <div class="w-full pb-20" data-six-hero-home-tab>
            <livewire:six-hero-hall-screen :key="'arena-hub-six-heroes'" />
        </div>
    @else
        <div class="w-full pb-20" data-legacy-arena-home-tab>
            <livewire:colosseum-screen :key="'arena-hub-legacy'" />
        </div>
    @endif
</div>
