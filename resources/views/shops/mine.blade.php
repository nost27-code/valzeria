@php
    $bannerPresets = [
        'default' => ['label' => '砂金', 'style' => 'background:linear-gradient(135deg,#fffdf5,#fff3cf)'],
        'forest' => ['label' => '森', 'style' => 'background:linear-gradient(135deg,#edf9f1,#cfeedd)'],
        'forge' => ['label' => '鍛冶炉', 'style' => 'background:linear-gradient(135deg,#fff1ea,#f8d4c0)'],
        'night' => ['label' => '夜', 'style' => 'background:linear-gradient(135deg,#eef2ff,#dce5fb)'],
    ];
    $currentBanner = $bannerPresets[$shop->banner_key] ?? $bannerPresets['default'];
    $inputClass = 'mt-1 w-full rounded-lg border-2 border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-100';
@endphp

<x-layouts.facility title="自分の商店を編集" headerIconImage="images/icon/icon_032.webp" bgImage="images/facilities/item.webp">
    <div class="mx-auto w-full max-w-3xl space-y-4 pb-10">
        <div class="flex items-center justify-between gap-3">
            <a href="{{ route('shopping-street.index') }}" class="text-sm font-black text-slate-600">← 商店街へ</a>
            <a href="{{ route('shops.show', $shop) }}" class="rounded-lg border-2 border-amber-300 bg-amber-50 px-3 py-2 text-xs font-black text-amber-800">自分の商店を見る</a>
        </div>

        @if(session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{ session('status') }}</div>
        @endif
        @if(session('error') || $errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-bold text-red-700">{{ session('error') ?? $errors->first() }}</div>
        @endif

        <section class="overflow-hidden rounded-xl border-2 border-slate-300 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-4 py-4" style="{{ $currentBanner['style'] }}">
                <div class="text-xs font-black tracking-wide text-slate-600">SHOP SETTINGS</div>
                <h2 class="mt-1 text-lg font-black text-slate-900">商店情報を編集</h2>
                <p class="mt-1 text-xs font-bold text-slate-600">下の白い入力欄をタップして編集できます。</p>
            </div>

            <form method="POST" action="{{ route('shops.update', $shop) }}" class="space-y-4 p-4">
                @csrf
                @method('PATCH')
                <label class="block">
                    <span class="text-sm font-black text-slate-800">商店名 <span class="text-amber-600">編集</span></span>
                    <input name="name" value="{{ old('name', $shop->name) }}" maxlength="20" required class="{{ $inputClass }}">
                    <span class="mt-1 block text-[11px] font-bold text-slate-500">2〜20文字。店名は7日に一度変更できます。</span>
                </label>
                <label class="block">
                    <span class="text-sm font-black text-slate-800">商店の説明 <span class="text-amber-600">編集</span></span>
                    <input name="description" value="{{ old('description', $shop->description) }}" maxlength="100" placeholder="どんな品を扱う店か書けます" class="{{ $inputClass }}">
                    <span class="mt-1 block text-[11px] font-bold text-slate-500">最大100文字。商店街の一覧にも表示されます。</span>
                </label>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <label class="block rounded-lg bg-slate-50 p-3">
                        <span class="text-sm font-black text-slate-800">店の種類</span>
                        <select name="shop_type" class="{{ $inputClass }}">@foreach(['general'=>'総合','material'=>'素材','equipment'=>'装備','valmon'=>'ヴァルモン'] as $key=>$label)<option value="{{ $key }}" @selected($shop->shop_type === $key)>{{ $label }}</option>@endforeach</select>
                    </label>
                    <label class="block rounded-lg bg-slate-50 p-3">
                        <span class="text-sm font-black text-slate-800">店の印</span>
                        <select name="icon_key" class="{{ $inputClass }}">@foreach(['general'=>'総合','material'=>'素材','equipment'=>'装備','valmon'=>'ヴァルモン'] as $key=>$label)<option value="{{ $key }}" @selected($shop->icon_key === $key)>{{ $label }}</option>@endforeach</select>
                    </label>
                    <label class="block rounded-lg bg-slate-50 p-3">
                        <span class="text-sm font-black text-slate-800">背景カラー</span>
                        <select name="banner_key" class="{{ $inputClass }}">@foreach($bannerPresets as $key=>$preset)<option value="{{ $key }}" @selected($shop->banner_key === $key)>{{ $preset['label'] }}</option>@endforeach</select>
                    </label>
                </div>
                <button class="w-full rounded-lg bg-amber-600 px-4 py-3 text-sm font-black text-white shadow-sm transition hover:bg-amber-700">商店情報を保存する</button>
            </form>
        </section>

        <section class="rounded-xl border-2 border-slate-300 bg-white p-4 shadow-sm">
            <div><h2 class="text-lg font-black text-slate-900">倉庫から出品する</h2><p class="mt-1 text-xs font-bold leading-relaxed text-slate-500">出品した品は、既存の市場と個人商店に同時に表示されます。</p></div>

            <div class="mt-5 space-y-3" x-data="{ openSection: null }">
                <div class="overflow-hidden rounded-xl border-2 border-slate-200">
                    <button type="button" @click="openSection = openSection === 'materials' ? null : 'materials'" :aria-expanded="openSection === 'materials'" class="flex w-full items-center justify-between gap-3 bg-slate-50 px-4 py-4 text-left">
                        <span><span class="block text-base font-black text-slate-900">素材から選ぶ</span><span class="mt-1 block text-xs font-bold text-slate-500">所持素材 {{ number_format($materials->count()) }}種類</span></span>
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white text-lg font-black text-slate-600" x-text="openSection === 'materials' ? '−' : '＋'">＋</span>
                    </button>
                    <div x-show="openSection === 'materials'" x-cloak style="display: none;" class="border-t-2 border-slate-200 p-3">
                        <p class="mb-3 text-xs font-bold text-slate-500">出品数と単価を入力してから出品します。</p>
                        <div class="space-y-3">
                        @forelse($materials as $row)
                            @php($material = $row->material)
                            <form method="POST" action="{{ route('shops.materials.list') }}" class="rounded-xl border-2 border-slate-200 bg-slate-50 p-3">
                                @csrf
                                <input type="hidden" name="material_id" value="{{ $material->id }}">
                                <div class="text-sm font-black text-slate-900">{{ $material->displayName() }}</div>
                                <div class="mt-1 text-xs font-bold text-slate-500">所持 {{ number_format($row->quantity) }}個 ・ 相場 {{ number_format($material->marketMinPrice()) }}〜{{ number_format($material->marketMaxPrice()) }}G</div>
                                <div class="mt-3 grid grid-cols-2 gap-3">
                                    <label class="block rounded-lg bg-white p-2"><span class="text-xs font-black text-slate-700">出品数</span><input name="quantity" type="number" min="1" max="{{ $row->quantity }}" value="1" class="{{ $inputClass }}"><span class="mt-1 block text-[11px] font-bold text-slate-500">最大 {{ number_format($row->quantity) }}個</span></label>
                                    <label class="block rounded-lg bg-white p-2"><span class="text-xs font-black text-slate-700">単価（G）</span><input name="unit_price" type="number" min="{{ $material->marketMinPrice() }}" max="{{ $material->marketMaxPrice() }}" value="{{ $material->marketMinPrice() }}" class="{{ $inputClass }}"><span class="mt-1 block text-[11px] font-bold text-slate-500">1個あたりの価格</span></label>
                                </div>
                                <button class="mt-3 w-full rounded-lg bg-slate-800 px-3 py-3 text-sm font-black text-white">この素材を出品する</button>
                            </form>
                        @empty
                            <p class="rounded-lg border border-dashed border-slate-200 p-3 text-sm font-bold text-slate-500">出品できる素材はありません。</p>
                        @endforelse
                        </div>
                    </div>
                </div>

                <div class="overflow-hidden rounded-xl border-2 border-slate-200">
                    <button type="button" @click="openSection = openSection === 'weapons' ? null : 'weapons'" :aria-expanded="openSection === 'weapons'" class="flex w-full items-center justify-between gap-3 bg-slate-50 px-4 py-4 text-left">
                        <span><span class="block text-base font-black text-slate-900">武器から選ぶ</span><span class="mt-1 block text-xs font-bold text-slate-500">出品可能な武器 {{ number_format($weapons->count()) }}本</span></span>
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white text-lg font-black text-slate-600" x-text="openSection === 'weapons' ? '−' : '＋'">＋</span>
                    </button>
                    <div x-show="openSection === 'weapons'" x-cloak style="display: none;" class="border-t-2 border-slate-200 p-3">
                        <p class="mb-3 text-xs font-bold text-slate-500">出品可能な銘・特攻付き武器だけが表示されます。</p>
                        <div class="space-y-3">
                        @forelse($weapons as $weapon)
                            @php($appraisal = $weapon->market_appraisal)
                            <form method="POST" action="{{ route('shops.equipment.list') }}" class="rounded-xl border-2 border-slate-200 bg-slate-50 p-3">
                                @csrf
                                <input type="hidden" name="character_item_id" value="{{ $weapon->id }}">
                                <div class="text-sm font-black text-slate-900">{{ $weapon->displayName() }}</div>
                                @if($appraisal)
                                    <div class="mt-1 text-xs font-bold text-slate-500">査定範囲 {{ number_format($appraisal['minimum_price']) }}〜{{ number_format($appraisal['maximum_price']) }}G</div>
                                    <label class="mt-3 block rounded-lg bg-white p-2"><span class="text-xs font-black text-slate-700">販売価格（G）</span><input name="listing_price" type="number" min="{{ $appraisal['minimum_price'] }}" max="{{ $appraisal['maximum_price'] }}" value="{{ $appraisal['minimum_price'] }}" class="{{ $inputClass }}"></label>
                                    <button class="mt-3 w-full rounded-lg bg-slate-800 px-3 py-3 text-sm font-black text-white">この武器を出品する</button>
                                @else
                                    <div class="mt-1 text-xs font-bold text-red-600">この武器は査定できません。</div>
                                @endif
                            </form>
                        @empty
                            <p class="rounded-lg border border-dashed border-slate-200 p-3 text-sm font-bold text-slate-500">出品できる武器はありません。</p>
                        @endforelse
                        </div>
                    </div>
                </div>

                <div class="overflow-hidden rounded-xl border-2 border-slate-200">
                    <button type="button" @click="openSection = openSection === 'eggs' ? null : 'eggs'" :aria-expanded="openSection === 'eggs'" class="flex w-full items-center justify-between gap-3 bg-slate-50 px-4 py-4 text-left">
                        <span><span class="block text-base font-black text-slate-900">ヴァルモンの卵から選ぶ</span><span class="mt-1 block text-xs font-bold text-slate-500">出品可能な卵 {{ number_format($eggs->count()) }}個</span></span>
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white text-lg font-black text-slate-600" x-text="openSection === 'eggs' ? '−' : '＋'">＋</span>
                    </button>
                    <div x-show="openSection === 'eggs'" x-cloak style="display: none;" class="border-t-2 border-slate-200 p-3">
                        <div class="space-y-3">
                        @forelse($eggs as $egg)
                            <form method="POST" action="{{ route('shops.eggs.list') }}" class="rounded-xl border-2 border-slate-200 bg-slate-50 p-3">
                                @csrf
                                <input type="hidden" name="egg_id" value="{{ $egg->id }}">
                                <div class="text-sm font-black text-slate-900">{{ $egg->master?->name }}の卵</div>
                                <div class="mt-3 grid grid-cols-2 gap-3">
                                    <label class="block rounded-lg bg-white p-2"><span class="text-xs font-black text-slate-700">販売価格（G）</span><input name="listing_price" type="number" min="1" placeholder="例：1000" required class="{{ $inputClass }}"></label>
                                    <label class="block rounded-lg bg-white p-2"><span class="text-xs font-black text-slate-700">出品期間</span><select name="listing_hours" class="{{ $inputClass }}"><option value="48">48時間</option><option value="24">24時間</option><option value="12">12時間</option></select></label>
                                </div>
                                <button class="mt-3 w-full rounded-lg bg-slate-800 px-3 py-3 text-sm font-black text-white">この卵を出品する</button>
                            </form>
                        @empty
                            <p class="rounded-lg border border-dashed border-slate-200 p-3 text-sm font-bold text-slate-500">出品できる保管済みの卵はありません。</p>
                        @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="rounded-xl border-2 border-slate-300 bg-white p-4 shadow-sm">
            <h2 class="text-lg font-black text-slate-900">自分の出品</h2>
            <div class="mt-3 space-y-2">
                @foreach($materialListings as $listing)<div class="rounded-lg border border-slate-200 p-3 text-sm font-bold">素材：{{ $listing->material?->displayName() }} ×{{ number_format($listing->remaining_quantity) }} ・ {{ number_format($listing->unit_price) }}G（{{ $listing->status }}）</div>@endforeach
                @foreach($equipmentListings as $listing)<div class="rounded-lg border border-slate-200 p-3 text-sm font-bold">武器：{{ $listing->display_name_snapshot }} ・ {{ number_format($listing->listing_price) }}G（{{ $listing->status }}）</div>@endforeach
                @foreach($eggListings as $listing)<div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 p-3 text-sm font-bold"><span>ヴァルモンの卵：{{ $listing->display_name_snapshot }} ・ {{ number_format($listing->listing_price) }}G（{{ $listing->status }}）</span>@if($listing->status === 'active')<form method="POST" action="{{ route('shops.eggs.cancel', $listing) }}">@csrf<button class="text-xs font-black text-red-600">取消</button></form>@endif</div>@endforeach
                @if($materialListings->isEmpty() && $equipmentListings->isEmpty() && $eggListings->isEmpty())<p class="rounded-lg border border-dashed border-slate-200 p-4 text-sm font-bold text-slate-500">現在出品中の品はありません。</p>@endif
            </div>
        </section>
    </div>
</x-layouts.facility>
