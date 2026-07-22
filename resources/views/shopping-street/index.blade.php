<x-layouts.facility title="商店街" headerIconImage="images/icon/icon_032.webp" bgImage="images/facilities/item.webp">
    <div class="mx-auto w-full space-y-4 pb-10">
        <div class="rounded-lg border border-amber-200 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <div><div class="text-xs font-black tracking-wide text-amber-700">SHOPPING STREET</div><h2 class="text-xl font-black text-slate-900">冒険者たちの商店街</h2></div>
                <a href="{{ route('shops.mine') }}" class="rounded-md bg-amber-600 px-3 py-2 text-xs font-black text-white">{{ $ownShop ? '自分の商店を編集' : '商店を開く' }}</a>
            </div>
            <form class="mt-3 flex gap-2" method="GET"><input name="q" value="{{ $query }}" placeholder="店名・店主名で探す" class="min-w-0 flex-1 rounded border-slate-300 text-sm"><button class="rounded bg-slate-700 px-3 text-xs font-black text-white">検索</button></form>
        </div>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <a href="{{ route('market.index') }}" class="rounded-lg border bg-white p-3 text-center text-sm font-black text-slate-700">素材市場</a>
            <a href="{{ route('equipment-market.index') }}" class="rounded-lg border bg-white p-3 text-center text-sm font-black text-slate-700">装備市場</a>
            <a href="{{ route('market.npc-requests.index') }}" class="rounded-lg border bg-white p-3 text-center text-sm font-black text-slate-700">調達依頼</a>
            <a href="{{ route('shops.index') }}" class="rounded-lg border bg-white p-3 text-center text-sm font-black text-slate-700">個人店舗</a>
        </div>
        @if($recentEggs->isNotEmpty())
            <section class="rounded-lg border border-violet-200 bg-violet-50 p-4"><h3 class="font-black text-violet-900">新着のヴァルモンの卵</h3><div class="mt-2 space-y-2">@foreach($recentEggs as $listing)<a href="{{ route('shops.show', $listing->shop_id) }}" class="flex justify-between rounded bg-white px-3 py-2 text-sm font-bold text-slate-700"><span>{{ $listing->display_name_snapshot }}・{{ $listing->shop?->name }}</span><span>{{ number_format($listing->listing_price) }}G</span></a>@endforeach</div></section>
        @endif
        <section class="space-y-2"><h3 class="px-1 font-black text-slate-800">個人店舗</h3>@forelse($shops as $shop)@php($bannerStyle = match($shop->banner_key) { 'forest' => 'background:linear-gradient(135deg,#edf9f1,#cfeedd)', 'forge' => 'background:linear-gradient(135deg,#fff1ea,#f8d4c0)', 'night' => 'background:linear-gradient(135deg,#eef2ff,#dce5fb)', default => 'background:linear-gradient(135deg,#fffdf5,#fff3cf)' })<a href="{{ route('shops.show', $shop) }}" style="{{ $bannerStyle }}" class="block rounded-lg border border-amber-200 p-3 shadow-sm transition hover:border-amber-400"><div class="flex items-center gap-3"><img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($shop->character?->icon_path) }}" alt="{{ $shop->character?->name ?? '店主' }}" class="h-12 w-12 shrink-0 rounded-full border-2 border-amber-300 bg-amber-50 object-contain shadow-sm"><div class="min-w-0 flex-1"><div class="flex items-center gap-2"><span class="truncate font-black text-slate-900">{{ $shop->name }}</span><span class="shrink-0 rounded-full bg-white/70 px-2 py-0.5 text-[10px] font-black text-amber-800">個人の店</span></div><div class="mt-1 truncate text-xs font-bold text-slate-600">店主：{{ $shop->character?->name }} ・ {{ $shop->description }}</div></div><span class="shrink-0 text-xs font-black text-amber-700">見る</span></div></a>@empty<div class="rounded-lg border border-dashed bg-white p-8 text-center text-sm font-bold text-slate-500">営業中の商店はまだありません。</div>@endforelse</section>
    </div>
</x-layouts.facility>
