<x-layouts.facility title="補給商会" headerIconImage="images/icon/icon_007.webp" bgImage="images/bg-castle.webp">

    <div x-data="{ confirming: null, submitting: false }">

    @if(session('status'))
        <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">
            {{ session('status') }}
        </div>
    @endif
    @if(session('error'))
        <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
            {{ session('error') }}
        </div>
    @endif

    {{-- 輝石残高 --}}
    <div class="mb-4 flex items-center gap-3 rounded-xl bg-slate-800 px-4 py-3">
        <img src="{{ asset('images/icon/kiseki.webp') }}" alt="輝石" class="h-5 w-5 object-contain">
        <div>
            <div class="text-[10px] font-bold text-slate-400 leading-none">所持輝石</div>
            <div class="mt-0.5 text-xl font-black text-white tabular-nums leading-none">{{ number_format(($character->paid_kiseki ?? 0) + ($character->free_kiseki ?? 0)) }}</div>
        </div>
        <div class="ml-auto text-right text-[10px] font-bold text-slate-400 leading-5">
            <div>有償 {{ number_format($character->paid_kiseki ?? 0) }}</div>
            <div>無償 {{ number_format($character->free_kiseki ?? 0) }}</div>
        </div>
    </div>

    {{-- Gold残高 --}}
    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
        <div class="text-[10px] font-bold text-amber-700 leading-none">所持Gold</div>
        <div class="mt-0.5 text-xl font-black text-amber-900 tabular-nums leading-none">{{ number_format((int) ($character->money ?? 0)) }}G</div>
    </div>

    {{-- 方針 --}}
    <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-[11px] font-bold leading-relaxed text-amber-800">
        補給商会は保管・補給・全滅時ロスト救済のための商品を取り扱っています。ステータス、装備現物、進化素材、経験値、ドロップ率は販売しません。
    </div>

    {{-- 商品一覧 --}}
    <div class="space-y-6">
        @foreach($supportCatalog as $category => $items)
            <section>
                <div class="mb-2.5 flex items-center gap-2">
                    <span class="text-[10px] font-black tracking-widest text-slate-400 uppercase">{{ $category }}</span>
                    <div class="h-px flex-1 bg-slate-200"></div>
                </div>
                <div class="space-y-2">
                    @foreach($items as $supportItem)
                        @php
                            $limitText = null;
                            if (!empty($supportItem['purchase_limit'])) {
                                $remaining = max(0, (int) $supportItem['purchase_limit'] - (int) ($supportItem['purchased_count'] ?? 0));
                                $limitText = "残り{$remaining}回";
                            } elseif (!empty($supportItem['daily_purchase_limit'])) {
                                $dailyLimit = (int) $supportItem['daily_purchase_limit'];
                                $remaining = max(0, $dailyLimit - (int) ($supportItem['daily_purchased_count'] ?? 0));
                                $limitText = "1日{$dailyLimit}個 / 残り{$remaining}個";
                            } elseif (!empty($supportItem['daily_use_limit'])) {
                                $limitText = ((bool) ($supportItem['used_today'] ?? false)) ? '本日使用済み' : '1日1回まで';
                            }
                            $canPurchase = (bool) ($supportItem['can_purchase'] ?? false);
                            $originalPrice = isset($supportItem['original_price']) ? (int) $supportItem['original_price'] : null;
                            $currentPrice = (int) ($supportItem['price'] ?? 0);
                            $isDiscounted = $originalPrice !== null && $originalPrice > $currentPrice;
                            $saleEndsAt = $supportItem['sale_ends_at'] ?? null;
                            $currencyLabel = (string) ($supportItem['currency_label'] ?? '輝石');
                            $currencySuffix = (string) ($supportItem['currency_suffix'] ?? '');
                            $currencyIcon = array_key_exists('currency_icon_image', $supportItem) ? $supportItem['currency_icon_image'] : 'images/icon/kiseki.webp';
                        @endphp
                        <div class="rounded-xl border {{ $canPurchase ? 'border-slate-200 bg-white' : 'border-slate-100 bg-slate-50/50' }} shadow-sm transition">
                            <div class="flex gap-3 p-3">
                                {{-- アイコン --}}
                                @if(!empty($supportItem['icon_image']))
                                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-amber-100 bg-amber-50/80 p-1 shadow-inner">
                                        <img src="{{ asset($supportItem['icon_image']) }}" alt="" class="h-full w-full object-contain">
                                    </div>
                                @endif
                                {{-- メイン --}}
                                <div class="min-w-0 flex-1">
                                    {{-- 名前行 + 価格 --}}
                                    <div class="flex items-start gap-2">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-baseline gap-1.5">
                                                <span class="text-sm font-black leading-tight text-slate-900">{{ $supportItem['name'] }}</span>
                                                @if($limitText)
                                                    <span class="rounded border border-slate-200 bg-white px-1.5 py-px text-[10px] font-black leading-none text-slate-500">{{ $limitText }}</span>
                                                @endif
                                            </div>
                                        </div>
                                        {{-- 価格 --}}
                                        <div class="flex shrink-0 flex-col items-end gap-0.5 tabular-nums">
                                            @if($isDiscounted)
                                                <div class="flex items-center gap-1 text-[10px] font-black leading-none text-slate-400">
                                                    @if($currencyIcon)
                                                        <img src="{{ asset($currencyIcon) }}" alt="" class="h-3 w-3 object-contain opacity-60">
                                                    @endif
                                                    <span class="line-through decoration-red-500 decoration-2">{{ number_format($originalPrice) }}</span>
                                                    @if($currencySuffix !== '')
                                                        <span>{{ $currencySuffix }}</span>
                                                    @endif
                                                    <span class="rounded bg-red-50 px-1 py-px text-[9px] text-red-600">
                                                        @if($saleEndsAt)
                                                            7月5日23:59までセール
                                                        @else
                                                            割引
                                                        @endif
                                                    </span>
                                                </div>
                                            @endif
                                            <div class="flex items-center gap-0.5">
                                                @if($currencyIcon)
                                                    <img src="{{ asset($currencyIcon) }}" alt="" class="h-4 w-4 object-contain">
                                                @endif
                                                <span class="text-base font-black {{ $isDiscounted ? 'text-red-600' : 'text-slate-900' }}">{{ number_format($currentPrice) }}</span>
                                                @if($currencySuffix !== '')
                                                    <span class="text-xs font-black text-slate-700">{{ $currencySuffix }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    {{-- 説明 --}}
                                    <p class="mt-0.5 text-[11px] font-bold leading-relaxed text-slate-500">{{ $supportItem['description'] }}</p>
                                    {{-- 所持数 --}}
                                    @if($supportItem['key'] === 'rescue_insurance')
                                        <p class="mt-1 text-[11px] font-black text-sky-700">
                                            所持数: {{ number_format($supportCounts['rescue_insurance'] ?? 0) }}
                                            @if($insuranceEnabled)
                                                <span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] text-emerald-700">次の探索に適用中</span>
                                            @endif
                                        </p>
                                    @elseif($supportItem['key'] === 'emergency_rescue_request')
                                        <p class="mt-1 text-[11px] font-black text-sky-700">所持数: {{ number_format($supportCounts['emergency_rescue_request'] ?? 0) }}</p>
                                    @elseif(($supportItem['effect_type'] ?? null) === 'explore_stamina_recovery')
                                        <p class="mt-1 text-[11px] font-black text-sky-700">所持数: {{ number_format($supportCounts[$supportItem['key']] ?? 0) }}</p>
                                    @endif
                                    {{-- ボタン行 --}}
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <button
                                            type="button"
                                            @click="confirming = @js($supportItem)"
                                            @disabled(!$canPurchase)
                                            class="rounded-lg px-4 py-1.5 text-xs font-black shadow-sm transition {{ $canPurchase ? 'bg-amber-600 text-white hover:bg-amber-700 active:scale-95' : 'cursor-not-allowed bg-slate-200 text-slate-400' }}">
                                            {{ $canPurchase ? '購入する' : '購入不可' }}
                                        </button>
                                        @if(!$canPurchase && !empty($supportItem['disabled_reason']))
                                            <span class="text-[10px] font-bold leading-tight text-red-600">
                                                @if(str_contains((string) $supportItem['disabled_reason'], '輝石が不足'))
                                                    輝石不足。<a href="{{ route('kiseki.shop') }}" class="underline underline-offset-1 hover:text-red-700">こちらで購入</a>
                                                @elseif(str_contains((string) $supportItem['disabled_reason'], 'Goldが不足'))
                                                    Gold不足。素材売却や探索でGoldを集めてください。
                                                @else
                                                    {{ $supportItem['disabled_reason'] }}
                                                @endif
                                            </span>
                                        @endif
                                        @if($supportItem['key'] === 'rescue_insurance')
                                            <form method="POST" action="{{ route('kiseki.support.rescue-insurance.use') }}" onsubmit="const b=this.querySelector('button[type=submit]');if(b){b.disabled=true;b.textContent='使用中...'}">
                                                @csrf
                                                <button type="submit"
                                                        @disabled(($supportCounts['rescue_insurance'] ?? 0) <= 0 || $insuranceEnabled)
                                                        class="rounded-lg border px-3 py-1.5 text-xs font-black transition {{ (($supportCounts['rescue_insurance'] ?? 0) > 0 && !$insuranceEnabled) ? 'border-emerald-300 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 active:scale-95' : 'cursor-not-allowed border-slate-200 bg-slate-100 text-slate-400' }}">
                                                    探索前に使用
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    <div class="mt-5 space-y-0.5 text-[10px] font-bold leading-relaxed text-slate-400">
        <p>・輝石消費は無償輝石が優先され、不足分のみ有償輝石から消費されます。</p>
        <p>・Gold商品の購入では所持Goldを消費します。</p>
        <p>・冒険者補給箱を購入しても、探索へ持ち込める薬草・回復薬・魔力水は各10個までです。</p>
        <p>・緊急救助要請は初期実装では、全滅時に所持していれば自動使用されます。</p>
    </div>

    {{-- 購入確認モーダル --}}
    <div x-show="confirming" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4">
        <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-2xl">
            <div class="flex items-center gap-3">
                <div x-show="confirming?.icon_image" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-amber-100 bg-amber-50 p-1.5">
                    <img :src="confirming?.icon_image ? '{{ url('/') }}/' + confirming.icon_image : ''" alt="" class="h-full w-full object-contain">
                </div>
                <div class="text-base font-black text-slate-950" x-text="`${confirming?.name ?? ''}を購入しますか？`"></div>
            </div>
            <p class="mt-3 text-xs font-bold leading-relaxed text-slate-600" x-text="confirming?.description ?? ''"></p>
            <div class="mt-3 flex items-center gap-1.5 rounded-lg bg-slate-50 px-3 py-2 text-sm font-black text-slate-800">
                <template x-if="confirming?.currency_icon_image">
                    <img :src="'{{ url('/') }}/' + confirming.currency_icon_image" alt="" class="h-4 w-4 object-contain">
                </template>
                <span x-text="`消費${confirming?.currency_label ?? '輝石'}:`"></span>
                <template x-if="confirming?.original_price && Number(confirming.original_price) > Number(confirming?.price ?? 0)">
                    <span class="inline-flex items-center gap-1">
                        <span class="text-xs text-slate-400 line-through decoration-red-500 decoration-2" x-text="Number(confirming.original_price).toLocaleString() + (confirming?.currency_suffix ?? '')"></span>
                        <span class="rounded bg-red-50 px-1 py-px text-[10px] text-red-600" x-text="confirming?.sale_ends_at ? '7月5日23:59までセール' : '割引'"></span>
                    </span>
                </template>
                <span class="text-red-600" x-text="Number(confirming?.price ?? 0).toLocaleString() + (confirming?.currency_suffix ?? '')"></span>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-3">
                <button type="button" @click="confirming = null" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-black text-slate-600 hover:bg-slate-50 transition">
                    キャンセル
                </button>
                <form method="POST" action="{{ route('kiseki.support.purchase') }}" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
                    @csrf
                    <input type="hidden" name="item_key" :value="confirming?.key">
                    <button type="submit" :disabled="submitting" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-amber-600 px-4 py-2.5 text-sm font-black text-white shadow-sm transition hover:bg-amber-700 disabled:cursor-wait disabled:opacity-60">
                        <x-loading-spinner x-show="submitting" style="display: none;" />
                        <span x-show="!submitting">購入する</span>
                        <span x-show="submitting" style="display: none;">処理中...</span>
                    </button>
                </form>
            </div>
        </div>
    </div>

    </div>

</x-layouts.facility>
