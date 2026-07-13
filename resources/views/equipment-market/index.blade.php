@php
    $tabs = ['buy' => '買う', 'sell' => '売る', 'listings' => '出品中', 'history' => '履歴'];
    $statusLabels = ['active' => '出品中', 'sold' => '売却済み', 'cancelled' => '取消済み', 'expired' => '期限切れ', 'admin_cancelled' => '運営取消'];
    $categoryLabels = ['sword' => '剣', 'axe' => '斧', 'dagger' => '短剣', 'bow' => '弓', 'staff' => '杖', 'magic_device' => '魔導具', 'gun' => '銃', 'spear' => '槍', 'fist' => '拳甲', 'katana' => '刀'];
    $sortLabels = ['price_asc' => '価格が安い順', 'price_desc' => '価格が高い順', 'newest' => '新着順', 'rank_desc' => 'ランクが高い順', 'engraving_desc' => '銘段階が高い順', 'slayer_desc' => '特攻段階が高い順'];
    $activeFilters = collect(['name' => '武器名', 'weapon_category' => '武器種', 'weapon_rank' => 'ランク', 'engraving_id' => '銘', 'slayer_type_id' => '特攻', 'min_price' => '最低価格', 'max_price' => '最高価格'])
        ->filter(fn ($label, $key) => request()->filled($key));
@endphp

<x-layouts.facility title="装備市場" headerIconImage="images/icon/icon_032.webp" bgImage="images/facilities/item.webp">
    <div class="w-full mx-auto pb-10">
        <div class="mb-2 flex justify-end px-1 text-sm font-black text-slate-950">所持：{{ number_format((int) $character->money) }}G</div>
        <div class="rounded-lg border border-violet-200 bg-white p-4 shadow-sm sm:p-6" x-data="{ tab: '{{ $tab }}' }">
            <div class="mb-5 flex items-start justify-between gap-3">
                <div>
                    <div class="text-xs font-black tracking-wide text-violet-700">EQUIPMENT MARKET</div>
                    <h2 class="mt-1 text-2xl font-black text-slate-900">銘・特攻付き武器市場</h2>
                    <p class="mt-1 text-sm font-bold leading-relaxed text-slate-500">銘または特攻が付いた武器を、出品者名を公開した上で売買できます。売買成立時のみ、売り手の受取額から10%の手数料が差し引かれます。</p>
                </div>
                <a href="{{ route('market.index') }}" class="shrink-0 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-black text-amber-700 hover:bg-amber-100">素材市場へ</a>
            </div>

            @foreach(['status' => 'emerald', 'error' => 'red'] as $flash => $color)
                @if(session($flash)) <div class="mb-4 rounded-lg border border-{{ $color }}-200 bg-{{ $color }}-50 px-4 py-3 text-sm font-bold text-{{ $color }}-800">{{ session($flash) }}</div> @endif
            @endforeach
            @if($errors->any()) <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">{{ $errors->first() }}</div> @endif

            <div class="mb-5 grid grid-cols-4 gap-1 rounded-lg bg-slate-100 p-1">
                @foreach($tabs as $key => $label)
                    <button type="button" @click="tab = '{{ $key }}'" :class="tab === '{{ $key }}' ? 'bg-white text-violet-700 shadow-sm' : 'text-slate-500 hover:bg-white/70'" class="rounded-md px-1 py-2.5 text-center text-xs font-black transition">{{ $label }}</button>
                @endforeach
            </div>

            <section x-show="tab === 'buy'" @if($tab !== 'buy') style="display:none" @endif>
                <form method="GET" class="mb-4 space-y-2 rounded-lg bg-slate-50 p-3 text-xs">
                    <input type="hidden" name="tab" value="buy">
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <label class="block"><span class="mb-1 block font-black text-slate-500">武器名</span>
                            <input name="name" value="{{ request('name') }}" placeholder="部分一致" class="w-full rounded border-slate-300 text-sm font-bold focus:border-violet-400 focus:ring-violet-400">
                        </label>
                        <label class="block"><span class="mb-1 block font-black text-slate-500">武器種</span>
                            <select name="weapon_category" class="w-full rounded border-slate-300 text-sm font-bold"><option value="">すべて</option>@foreach($categoryOptions as $category)<option value="{{ $category }}" @selected(request('weapon_category') === $category)>{{ $categoryLabels[$category] ?? $category }}</option>@endforeach</select>
                        </label>
                        <label class="block"><span class="mb-1 block font-black text-slate-500">ランク</span>
                            <select name="weapon_rank" class="w-full rounded border-slate-300 text-sm font-bold"><option value="">全ランク</option>@foreach(['G','F','E','D','C','B','A','S','SS','SSS','EPIC'] as $rank)<option value="{{ $rank }}" @selected(request('weapon_rank') === $rank)>{{ $rank }}</option>@endforeach</select>
                        </label>
                        <label class="block"><span class="mb-1 block font-black text-slate-500">並び順</span>
                            <select name="sort" class="w-full rounded border-slate-300 text-sm font-bold">@foreach($sortLabels as $key => $label)<option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>@endforeach</select>
                        </label>
                    </div>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <label class="block"><span class="mb-1 block font-black text-slate-500">銘</span>
                            <select name="engraving_id" class="w-full rounded border-slate-300 text-sm font-bold"><option value="">すべて</option>@foreach($engravingOptions as $opt)<option value="{{ $opt['id'] }}" @selected((string) request('engraving_id') === (string) $opt['id'])>{{ $opt['name'] }}</option>@endforeach</select>
                        </label>
                        <label class="block"><span class="mb-1 block font-black text-slate-500">特攻</span>
                            <select name="slayer_type_id" class="w-full rounded border-slate-300 text-sm font-bold"><option value="">すべて</option>@foreach($slayerOptions as $opt)<option value="{{ $opt['id'] }}" @selected((string) request('slayer_type_id') === (string) $opt['id'])>{{ $opt['name'] }}</option>@endforeach</select>
                        </label>
                        <label class="block"><span class="mb-1 block font-black text-slate-500">最低価格</span>
                            <input name="min_price" type="number" min="1" value="{{ request('min_price') }}" placeholder="下限なし" class="w-full rounded border-slate-300 text-sm font-bold">
                        </label>
                        <label class="block"><span class="mb-1 block font-black text-slate-500">最高価格</span>
                            <input name="max_price" type="number" min="1" value="{{ request('max_price') }}" placeholder="上限なし" class="w-full rounded border-slate-300 text-sm font-bold">
                        </label>
                    </div>
                    <div class="flex items-center gap-2 pt-1">
                        <button class="rounded bg-violet-600 px-4 py-2 font-black text-white hover:bg-violet-700">絞り込む</button>
                        @if($activeFilters->isNotEmpty())
                            <a href="{{ route('equipment-market.index', ['tab' => 'buy', 'sort' => $sort]) }}" class="rounded border border-slate-300 px-4 py-2 font-black text-slate-600 hover:bg-slate-100">条件をリセット</a>
                        @endif
                    </div>
                    @if($activeFilters->isNotEmpty())
                        <div class="flex flex-wrap gap-1 pt-1">
                            @foreach($activeFilters as $key => $label)
                                @php
                                    $filterValue = match ($key) {
                                        'engraving_id' => $engravingOptions->firstWhere('id', (int) request($key))['name'] ?? request($key),
                                        'slayer_type_id' => $slayerOptions->firstWhere('id', (int) request($key))['name'] ?? request($key),
                                        'weapon_category' => $categoryLabels[request($key)] ?? request($key),
                                        default => request($key),
                                    };
                                @endphp
                                <span class="rounded-full bg-violet-100 px-2 py-1 text-[11px] font-black text-violet-700">{{ $label }}：{{ $filterValue }}</span>
                            @endforeach
                        </div>
                    @endif
                </form>
                <div class="mb-2 px-1 text-xs font-bold text-slate-500">{{ number_format($listingsCount) }}件がヒット{{ $listingsCount > 100 ? '（先頭100件を表示）' : '' }}</div>
                <div class="space-y-2">
                    @forelse($listings as $listing)
                        @php
                            $snapshot = $listing->item_snapshot ?? [];
                            $weaponIcon = \App\Models\Item::weaponIconPathForCategory($listing->weapon_category);
                        @endphp
                        <article class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                            <div class="flex items-start justify-between gap-3"><div class="flex min-w-0 items-start gap-2">@if($weaponIcon)<img src="{{ asset($weaponIcon) }}" alt="" class="mt-0.5 h-6 w-6 shrink-0 object-contain">@endif<div class="min-w-0"><h3 class="truncate text-sm font-black text-slate-900">{{ $listing->display_name_snapshot }}</h3><div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs font-bold text-slate-500"><span>出品者：{{ $listing->seller?->name ?? '不明' }}</span><span>武器種：{{ $categoryLabels[$listing->weapon_category] ?? ($listing->weapon_category ?: '武器') }}</span><span>強化：{{ $listing->enhance_level > 0 ? '+' . $listing->enhance_level : 'なし' }}</span></div></div></div><div class="shrink-0 text-right"><div class="text-base font-black text-violet-700">販売 {{ number_format($listing->listing_price) }}G</div></div></div>
                            @include('equipment-market.partials.effect-badges', ['base' => $snapshot['base_performance_lines'] ?? [], 'engraving' => $snapshot['engraving_effect_lines'] ?? [], 'slayer' => $snapshot['slayer_effect_lines'] ?? []])
                            <a href="{{ route('equipment-market.show', $listing) }}" class="mt-3 inline-flex w-full justify-center rounded-md border border-violet-200 bg-violet-50 px-3 py-2 text-xs font-black text-violet-700 hover:bg-violet-100">詳細を見る</a>
                        </article>
                    @empty <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm font-bold text-slate-500">条件に合う出品はありません。</div>@endforelse
                </div>
            </section>

            <section x-show="tab === 'sell'" @if($tab !== 'sell') style="display:none" @endif class="space-y-3">
                <div class="rounded-lg border border-violet-100 bg-violet-50 px-3 py-2 text-xs font-bold leading-relaxed text-violet-800">販売価格は査定範囲内で変更できます。入力した価格で売れた場合のみ、10%の成立手数料がかかります。出品するだけでは費用はかかりません。</div>
                @forelse($sellable as $item)
                    @php
                        $appraisal = $item->market_appraisal;
                    @endphp
                    @if($appraisal)
                        <form method="POST" action="{{ route('equipment-market.store') }}" class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm" x-data="{ price: {{ $appraisal['appraisal_price'] }}, min: {{ $appraisal['minimum_price'] }}, max: {{ $appraisal['maximum_price'] }}, get fee(){ return Math.floor(this.price * 0.1) }, get proceeds(){ return this.price - this.fee } }" onsubmit="return confirm('この武器を出品します。出品時の費用はかかりません。')">
                            @csrf <input type="hidden" name="character_item_id" value="{{ $item->id }}">
                            <div class="flex items-start gap-2">@if($item->item?->iconImagePath())<img src="{{ asset($item->item->iconImagePath()) }}" alt="" class="mt-0.5 h-6 w-6 shrink-0 object-contain">@endif<div class="text-sm font-black text-slate-900">{{ $item->displayName() }}</div></div>
                            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs font-bold text-slate-500"><span>武器種：{{ $categoryLabels[$item->item->weapon_category] ?? ($item->item->weapon_category ?: '武器') }}</span><span>強化：{{ $item->enhance_level > 0 ? '+' . $item->enhance_level : 'なし' }}</span></div>
                            @include('equipment-market.partials.effect-badges', ['base' => $item->basePerformanceLines(), 'engraving' => $item->engravingEffectLines(), 'slayer' => $item->slayerEffectLines()])
                            <div class="mt-3 grid grid-cols-2 gap-2 rounded bg-slate-50 p-2 text-xs font-bold text-slate-600"><span>査定額<br>{{ number_format($appraisal['appraisal_price']) }}G</span><span>設定できる価格<br>{{ number_format($appraisal['minimum_price']) }}〜{{ number_format($appraisal['maximum_price']) }}G</span></div>
                            <div class="mt-3"><label class="mb-1 block text-xs font-black text-slate-700">販売価格 <span class="font-bold text-violet-700">（この価格は変更できます）</span></label><div class="flex gap-2"><input type="number" name="listing_price" x-model.number="price" :min="min" :max="max" required class="min-w-0 flex-1 rounded border-slate-300 text-right font-black focus:border-violet-400 focus:ring-violet-400"><span class="self-center text-sm font-black text-slate-600">G</span><button class="rounded bg-violet-600 px-4 py-2 text-sm font-black text-white hover:bg-violet-700">出品</button></div></div>
                            <p class="mt-2 text-right text-[11px] font-bold text-slate-500">成立手数料 <span x-text="fee.toLocaleString()"></span>G / 受取予定 <span class="text-violet-700" x-text="proceeds.toLocaleString()"></span>G</p>
                        </form>
                    @endif
                @empty <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm font-bold text-slate-500">出品可能な銘・特攻付き武器はありません。</div>@endforelse
            </section>

            <section x-show="tab === 'listings'" @if($tab !== 'listings') style="display:none" @endif class="space-y-2">
                @forelse($ownListings as $listing)<div class="rounded-lg border border-slate-200 bg-white p-3"><div class="flex items-start justify-between gap-3"><div><div class="text-sm font-black">{{ $listing->display_name_snapshot }}</div><div class="mt-1 text-xs font-bold text-slate-500">{{ number_format($listing->listing_price) }}G ・期限 {{ $listing->expires_at?->format('m/d H:i') }}</div></div><span class="rounded bg-slate-100 px-2 py-1 text-xs font-black text-slate-600">{{ $statusLabels[$listing->status] ?? $listing->status }}</span></div>@if($listing->status === 'active')<form method="POST" action="{{ route('equipment-market.cancel', $listing) }}" class="mt-3">@csrf<button class="rounded border border-red-200 px-3 py-1.5 text-xs font-black text-red-700 hover:bg-red-50">取消</button></form>@endif</div>@empty <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm font-bold text-slate-500">出品中の武器はありません。</div>@endforelse
            </section>

            <section x-show="tab === 'history'" @if($tab !== 'history') style="display:none" @endif class="space-y-2">
                @forelse($history as $transaction)<div class="rounded-lg border border-slate-200 bg-white p-3"><div class="text-sm font-black {{ (int)$transaction->buyer_character_id === (int)$character->id ? 'text-violet-700' : 'text-emerald-700' }}">{{ (int)$transaction->buyer_character_id === (int)$character->id ? '購入' : '売却' }}：{{ $transaction->item_snapshot['display_name'] ?? '武器' }}</div><div class="mt-1 text-xs font-bold text-slate-500">販売 {{ number_format($transaction->sale_price) }}G ・手数料 {{ number_format($transaction->fee_amount) }}G ・{{ (int)$transaction->buyer_character_id === (int)$character->id ? '支払' : '受取' }} {{ number_format((int)$transaction->buyer_character_id === (int)$character->id ? $transaction->sale_price : $transaction->seller_proceeds) }}G</div></div>@empty <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm font-bold text-slate-500">取引履歴はありません。</div>@endforelse
            </section>
        </div>
    </div>
</x-layouts.facility>
