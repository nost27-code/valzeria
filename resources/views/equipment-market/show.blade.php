@php
    $snapshot = $listing->item_snapshot ?? [];
    $categoryLabels = ['sword' => '剣', 'axe' => '斧', 'dagger' => '短剣', 'bow' => '弓', 'staff' => '杖', 'magic_device' => '魔導具', 'gun' => '銃', 'spear' => '槍', 'fist' => '拳甲', 'katana' => '刀'];
    $weaponIcon = \App\Models\Item::weaponIconPathForCategory($listing->weapon_category);
@endphp
<x-layouts.facility title="装備市場" headerIconImage="images/icon/icon_032.webp" bgImage="images/facilities/item.webp">
    <div class="mx-auto w-full max-w-xl pb-10"><a href="{{ route('equipment-market.index') }}" class="mb-3 inline-block text-sm font-black text-violet-700 hover:underline">← 装備市場へ戻る</a>
        <article class="rounded-lg border border-violet-200 bg-white p-5 shadow-sm"><div class="text-xs font-black tracking-wide text-violet-700">EQUIPMENT MARKET</div><div class="mt-1 flex items-start gap-2">@if($weaponIcon)<img src="{{ asset($weaponIcon) }}" alt="" class="mt-0.5 h-7 w-7 shrink-0 object-contain">@endif<h1 class="text-xl font-black text-slate-900">{{ $listing->display_name_snapshot }}</h1></div><div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-sm font-bold text-slate-500"><span>出品者：{{ $listing->seller?->name ?? '不明' }}</span><span>武器種：{{ $categoryLabels[$listing->weapon_category] ?? ($listing->weapon_category ?: '武器') }}</span><span>強化：{{ $listing->enhance_level > 0 ? '+' . $listing->enhance_level : 'なし' }}</span></div>
            <div class="mt-4 space-y-2 text-sm font-bold">
                @include('equipment-market.partials.effect-badges', ['base' => $snapshot['base_performance_lines'] ?? [], 'engraving' => $snapshot['engraving_effect_lines'] ?? [], 'slayer' => $snapshot['slayer_effect_lines'] ?? []])
                <div class="rounded-lg border border-slate-200 bg-white p-3 text-xs text-slate-600">品質：{{ ['normal'=>'通常品','good'=>'良品','excellent'=>'逸品'][$listing->quality_key] ?? $listing->quality_key }}</div>
            </div>
            <div class="mt-4 rounded-lg border border-violet-100 bg-violet-50 p-4"><div class="text-xs font-bold text-violet-700">出品者が設定した販売価格</div><div class="text-2xl font-black text-violet-800">{{ number_format($listing->listing_price) }}G</div><div class="mt-1 text-xs font-bold text-violet-600">査定額 {{ number_format($listing->appraisal_price) }}G（購入者が支払う金額）</div></div>
            @if($listing->appraisal_version >= 2 && $listing->body_appraisal_price !== null && $listing->trait_appraisal_price !== null)
                <div class="mt-3 grid grid-cols-2 gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs font-bold text-slate-600"><span>装備本体査定<br><span class="text-sm text-slate-800">{{ number_format($listing->body_appraisal_price) }}G</span></span><span>個体特性査定<br><span class="text-sm text-slate-800">{{ number_format($listing->trait_appraisal_price) }}G</span></span><span class="col-span-2 border-t border-slate-200 pt-2">この出品は査定額の{{ number_format($listing->appraisalRatioPercent(), 1) }}%で設定されています。</span></div>
            @endif
            @if(session('error'))<div class="mt-4 rounded bg-red-50 p-3 text-sm font-bold text-red-700">{{ session('error') }}</div>@endif
            @if($listing->status === 'active' && $listing->expires_at->isFuture() && (int)$listing->seller_character_id !== (int)$character->id)<form method="POST" action="{{ route('equipment-market.buy', $listing) }}" class="mt-4" onsubmit="return confirm('表示価格のGoldを支払って購入します。購入後72時間は再出品できません。')">@csrf<button class="w-full rounded-md bg-violet-600 px-4 py-3 text-sm font-black text-white hover:bg-violet-700">{{ number_format($listing->listing_price) }}Gで購入する</button></form>@elseif((int)$listing->seller_character_id === (int)$character->id)<p class="mt-4 text-center text-sm font-black text-slate-500">自分の出品です。</p>@else<p class="mt-4 text-center text-sm font-black text-slate-500">この出品は購入できません。</p>@endif
        </article></div>
</x-layouts.facility>
