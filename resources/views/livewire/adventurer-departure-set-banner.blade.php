<div class="contents">
    @if(!empty($departureSetBanner))
        <section class="overflow-hidden rounded-xl border border-amber-300 bg-gradient-to-br from-amber-50 via-white to-sky-50 shadow-sm">
            <div class="flex items-center gap-3 p-3">
                @if(!empty($departureSetBanner['icon_image']))
                    <img src="{{ asset($departureSetBanner['icon_image']) }}" alt="" class="h-12 w-12 shrink-0 rounded-lg bg-white p-1 object-contain shadow-sm">
                @endif
                <div class="min-w-0 flex-1">
                    <div class="text-[11px] font-black text-amber-700">冒険のスタートを、もっと軽やかに。</div>
                    <h2 class="mt-0.5 text-sm font-black leading-tight text-slate-900">{{ $departureSetBanner['name'] }}</h2>
                    <p class="mt-1 text-[11px] font-bold leading-relaxed text-slate-600">探索・倉庫・見た目をまとめて強化できる、序盤冒険者向けの特別セットです。</p>
                </div>
                <a href="{{ route('kiseki.support') }}#adventurer-departure-set" class="shrink-0 rounded-lg bg-amber-600 px-3 py-2 text-center text-[11px] font-black text-white shadow-sm transition hover:bg-amber-700 active:scale-95">
                    <span>セットを見る</span>
                </a>
            </div>
        </section>
    @endif
</div>
