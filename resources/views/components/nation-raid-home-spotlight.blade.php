@props(['initiallyHome' => false])

@php($activeRaidEvent = app(\App\Services\Nation\Raid\NationRaidEntryService::class)->activeEvent())
@if($activeRaidEvent !== null)
    <section
        x-show="currentLocation === 'home'"
        style="{{ $initiallyHome ? '' : 'display: none;' }}"
        class="overflow-hidden rounded-xl border border-amber-300 bg-slate-950 text-white shadow-sm"
        data-home-nation-raid-spotlight
    >
        <a
            href="{{ route('nation-raid.top', $activeRaidEvent) }}"
            class="group flex min-h-20 w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-slate-900 active:scale-[0.99]"
            aria-label="開催中の国家対抗レイドを開く"
        >
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-amber-300/50 bg-white/10 text-2xl" aria-hidden="true">🐉</span>
            <span class="min-w-0 flex-1">
                <span class="inline-flex rounded-full bg-amber-300 px-2 py-0.5 text-[10px] font-black text-slate-950">開催中</span>
                <span class="mt-1 block text-base font-black leading-tight">国家対抗レイド</span>
                <span class="mt-1 block text-xs font-bold leading-relaxed text-slate-300">全冒険者で黒天竜へ挑戦中。現在の戦況を確認できます。</span>
            </span>
            <span class="shrink-0 text-xs font-black text-amber-300">戦況へ ›</span>
        </a>
    </section>
@elseif(!config('features.nation_competitive_raid_enabled', false) && app(\App\Services\Nation\Raid\NationRaidEntryService::class)->isPreviewPublished())
    <section x-show="currentLocation === 'home'" style="{{ $initiallyHome ? '' : 'display: none;' }}"
        class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" data-home-nation-raid-preview>
        <a href="{{ route('nation-raid.preview') }}" class="flex min-h-20 items-center gap-3 px-4 py-3 hover:bg-slate-50" aria-label="国家対抗レイドの事前案内を開く">
            <img src="{{ asset('images/raid/valgreid_form_01.webp') }}" alt="" width="56" height="56" class="h-14 w-14 shrink-0 object-contain">
            <span class="min-w-0 flex-1">
                <span class="text-xs font-bold text-sky-800">9/6 21:00開始予定</span>
                <span class="mt-1 block text-base font-black text-slate-900">国家対抗レイド</span>
                <span class="mt-1 block text-xs text-slate-600">黒天竜と予定報酬を先行公開。開戦を待とう。</span>
            </span>
            <span class="shrink-0 text-xs font-bold text-sky-800">案内へ ›</span>
        </a>
    </section>
@endif
