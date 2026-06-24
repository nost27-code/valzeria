<div class="space-y-3">
    @php
        $hasRecentSales = collect($demandItems)->sum('sold_quantity_24h') > 0;
    @endphp

    <div>
        <h3 class="text-xl font-black text-slate-900">需要掲示板</h3>
        <p class="mt-1 text-sm font-bold leading-relaxed text-slate-500">
            市場で不足している素材や、最近よく売れている素材を確認できます。
        </p>
    </div>

    <div class="rounded-lg border border-amber-100 bg-amber-50/70 px-3 py-2.5 text-xs font-bold leading-relaxed text-amber-800">
        需要は市場在庫・直近販売数・用途から算出した目安です。@unless($hasRecentSales)市場データが少ないため、需要は参考値です。@endunless
    </div>

    @forelse($demandItems as $item)
        @include('market.partials.demand-material-card', ['item' => $item])
    @empty
        <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm font-bold text-slate-500">
            現在、表示できる素材がありません。
        </div>
    @endforelse
</div>
