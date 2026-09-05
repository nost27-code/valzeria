@php($nationRaidPublished = app(\App\Services\Nation\Raid\NationRaidEntryService::class)->isPublished())
<section class="overflow-hidden rounded-2xl border border-indigo-200 bg-white shadow-sm" data-nation-raid-shortcut data-nation-raid-entry-state="{{ $nationRaidPublished ? 'published' : 'preparing' }}">
    <button
        type="button"
        wire:click="openNationCompetitiveRaid"
        wire:loading.attr="disabled"
        wire:target="openNationCompetitiveRaid"
        class="group flex min-h-20 w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-indigo-50/70 active:scale-[0.99] disabled:opacity-60"
        aria-label="国家対抗レイドを開く"
    >
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-2xl shadow-sm" aria-hidden="true">🐉</span>
        <span class="min-w-0 flex-1">
            <span class="flex flex-wrap items-center gap-2">
                <span class="text-base font-black text-slate-950">国家対抗レイド</span>
                @unless($nationRaidPublished)
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-black text-slate-600">準備中</span>
                @endunless
                @if($nationRaidPublished && !config('features.nation_competitive_raid_enabled', false))
                    <span class="rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-black text-sky-800">事前公開</span>
                @endif
            </span>
            <span class="mt-1 block text-xs font-bold leading-relaxed text-slate-600 sm:text-sm">全国家の冒険者で黒天竜へ挑む。無所属でも参加できます。</span>
        </span>
        <svg class="h-5 w-5 shrink-0 text-indigo-300 transition group-hover:translate-x-0.5" aria-hidden="true" viewBox="0 0 20 20" fill="none">
            <path d="m7.5 5 5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </button>
</section>
