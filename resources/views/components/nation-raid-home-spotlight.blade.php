@props(['initiallyHome' => false])

@php($activeRaidEvent = app(\App\Services\Nation\Raid\NationRaidEntryService::class)->featuredEvent())
@if($activeRaidEvent !== null)
    @php($preparationHours = (int) data_get($activeRaidEvent->ruleset_snapshot, 'raid_cycle.logistics_preparation.duration_hours', 72))
    @php($isLogisticsPreparation = $activeRaidEvent->status === 'scheduled'
        && is_array($activeRaidEvent->ruleset_snapshot['raid_cycle'] ?? null)
        && now()->gte($activeRaidEvent->starts_at->copy()->subHours($preparationHours)))
    @php($bossImage = data_get($activeRaidEvent->ruleset_snapshot, 'forms.sealed_scale.image_path'))
    <section
        x-show="currentLocation === 'home'"
        style="{{ $initiallyHome ? '' : 'display: none;' }}"
        class="overflow-hidden rounded-xl border border-amber-300 bg-slate-950 text-white shadow-sm"
        data-home-nation-raid-spotlight
    >
        <a
            href="{{ route('nation-raid.top', $activeRaidEvent) }}"
            class="group flex min-h-20 w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-slate-900 active:scale-[0.99]"
            aria-label="国家対抗レイドを開く"
        >
            @if(is_string($bossImage) && $bossImage !== '')
                <img src="{{ asset($bossImage) }}" alt="" width="48" height="48" class="h-12 w-12 shrink-0 object-contain" aria-hidden="true">
            @else
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-amber-300/50 bg-white/10 text-2xl" aria-hidden="true">⚙️</span>
            @endif
            <span class="min-w-0 flex-1">
                <span class="inline-flex rounded-full bg-amber-300 px-2 py-0.5 text-[10px] font-black text-slate-950">{{ $isLogisticsPreparation ? '兵站準備' : ($activeRaidEvent->status === 'scheduled' ? '開催予定' : '開催中') }}</span>
                <span class="mt-1 block text-base font-black leading-tight">国家対抗レイド</span>
                <span class="mt-1 block text-xs font-bold leading-relaxed text-slate-300">{{ $isLogisticsPreparation ? '探索で兵站を整え、次の開戦に備えよう。' : ($activeRaidEvent->status === 'scheduled' ? '開戦予定と報酬を確認できます。' : '全冒険者でレイドボスへ挑戦中。現在の戦況を確認できます。') }}</span>
            </span>
            <span class="shrink-0 text-xs font-black text-amber-300">戦況へ ›</span>
        </a>
    </section>
@elseif(!config('features.nation_competitive_raid_enabled', false) && app(\App\Services\Nation\Raid\NationRaidEntryService::class)->isPreviewPublished())
    <section x-show="currentLocation === 'home'" style="{{ $initiallyHome ? '' : 'display: none;' }}"
        class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" data-home-nation-raid-preview>
        <a href="{{ route('nation-raid.preview') }}" class="flex min-h-20 items-center gap-3 px-4 py-3 hover:bg-slate-50" aria-label="国家対抗レイドの事前案内を開く">
            <img src="{{ asset(config('nation_raid_preview.boss_image')) }}" alt="" width="56" height="56" class="h-14 w-14 shrink-0 object-contain">
            <span class="min-w-0 flex-1">
                <span class="text-xs font-bold text-sky-800">{{ config('nation_raid_preview.starts_at_label') }}開始予定</span>
                <span class="mt-1 block text-base font-black text-slate-900">国家対抗レイド</span>
                <span class="mt-1 block text-xs text-slate-600">天墜機神と予定報酬を先行公開。開戦を待とう。</span>
            </span>
            <span class="shrink-0 text-xs font-bold text-sky-800">案内へ ›</span>
        </a>
    </section>
@endif
