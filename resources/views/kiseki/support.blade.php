<x-layouts.facility title="補給商会" headerIconImage="images/icon/icon_007.webp" bgImage="images/bg-castle.webp">

    <div x-data="{ confirming: null, submitting: false }">

    {{-- フラッシュメッセージ --}}
    @if(session('status'))
        <div class="mb-4 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-bold">
            {{ session('status') }}
        </div>
    @endif
    @if(session('error'))
        <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm font-bold">
            {{ session('error') }}
        </div>
    @endif

    {{-- 輝石残高 --}}
    <div class="mb-6 flex items-center gap-3 rounded-xl bg-slate-800 px-5 py-3">
        <img src="{{ asset('images/icon/kiseki.webp') }}" alt="輝石" class="h-6 w-6 object-contain">
        <span class="text-xs text-slate-400 font-bold">所持輝石</span>
        <span class="text-xl font-black text-white tabular-nums">{{ number_format(($character->paid_kiseki ?? 0) + ($character->free_kiseki ?? 0)) }}</span>
        <span class="ml-auto text-xs text-slate-400">
            有償 {{ number_format($character->paid_kiseki ?? 0) }} ／ 無償 {{ number_format($character->free_kiseki ?? 0) }}
        </span>
    </div>

    {{-- 方針 --}}
    <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-bold leading-relaxed text-amber-800">
        補給商会は保管・補給・全滅時ロスト救済のための商品を取り扱っています。ステータス、装備現物、進化素材、経験値、ドロップ率は販売しません。
    </div>

    {{-- 商品一覧 --}}
    <div class="space-y-6">
        @foreach($supportCatalog as $category => $items)
            <section class="space-y-3">
                <h2 class="text-sm font-black text-slate-700 border-b border-slate-200 pb-1">{{ $category }}</h2>
                @foreach($items as $supportItem)
                    @php
                        $limitText = null;
                        if (!empty($supportItem['purchase_limit'])) {
                            $remaining = max(0, (int) $supportItem['purchase_limit'] - (int) ($supportItem['purchased_count'] ?? 0));
                            $limitText = "残り{$remaining}回";
                        } elseif (!empty($supportItem['daily_purchase_limit'])) {
                            $limitText = ((int) ($supportItem['daily_purchased_count'] ?? 0) > 0) ? '本日購入済み' : '1日1回';
                        } elseif (!empty($supportItem['daily_use_limit'])) {
                            $limitText = ((bool) ($supportItem['used_today'] ?? false)) ? '本日使用済み' : '1日1回まで';
                        }
                    @endphp
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-lg font-black text-slate-900">{{ $supportItem['name'] }}</h3>
                                    @if($limitText)
                                        <span class="rounded border border-slate-200 bg-slate-50 px-2 py-1 text-[11px] font-black text-slate-600">{{ $limitText }}</span>
                                    @endif
                                </div>
                                <p class="mt-2 text-sm font-bold leading-relaxed text-slate-600">{{ $supportItem['description'] }}</p>
                                @if($supportItem['key'] === 'rescue_insurance')
                                    <div class="mt-2 text-xs font-black text-sky-700">
                                        所持数: {{ number_format($supportCounts['rescue_insurance'] ?? 0) }}
                                        @if($insuranceEnabled)
                                            <span class="ml-2 rounded bg-emerald-100 px-2 py-1 text-emerald-700">次の探索に適用中</span>
                                        @endif
                                    </div>
                                @elseif($supportItem['key'] === 'emergency_rescue_request')
                                    <div class="mt-2 text-xs font-black text-sky-700">所持数: {{ number_format($supportCounts['emergency_rescue_request'] ?? 0) }}</div>
                                @endif
                            </div>
                            <div class="w-full sm:w-44">
                                <div class="mb-2 flex items-center justify-end gap-1 text-xl font-black text-slate-900">
                                    <img src="{{ asset('images/icon/kiseki.webp') }}" alt="輝石" class="h-6 w-6 object-contain">
                                    {{ number_format($supportItem['price']) }}
                                </div>
                                <button
                                    type="button"
                                    @click="confirming = @js($supportItem)"
                                    @disabled(!($supportItem['can_purchase'] ?? false))
                                    class="w-full rounded-lg px-4 py-3 text-sm font-black text-white shadow-sm transition disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500 enabled:bg-blue-700 enabled:hover:bg-blue-800">
                                    {{ ($supportItem['can_purchase'] ?? false) ? '購入する' : '購入不可' }}
                                </button>
                                @if(!($supportItem['can_purchase'] ?? false))
                                    <div class="mt-2 text-xs font-bold text-red-600">
                                        @if(str_contains((string) ($supportItem['disabled_reason'] ?? ''), '輝石が不足'))
                                            輝石が不足しています。
                                            <a href="{{ route('kiseki.shop') }}" class="underline underline-offset-2 hover:text-red-700">
                                                こちらで輝石を購入
                                            </a>
                                            してから再度お試しください。
                                        @else
                                            {{ $supportItem['disabled_reason'] }}
                                        @endif
                                    </div>
                                @endif
                                @if($supportItem['key'] === 'rescue_insurance')
                                    <form method="POST" action="{{ route('kiseki.support.rescue-insurance.use') }}" onsubmit="const button = this.querySelector('button[type=submit]'); if (button) { button.disabled = true; button.textContent = '使用中...'; }" class="mt-2">
                                        @csrf
                                        <button type="submit"
                                                @disabled(($supportCounts['rescue_insurance'] ?? 0) <= 0 || $insuranceEnabled)
                                                class="w-full rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-2 text-xs font-black text-emerald-700 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400">
                                            探索前に使用
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </section>
        @endforeach
    </div>

    <div class="mt-6 text-xs font-bold leading-relaxed text-slate-400">
        ・輝石消費は無償輝石が優先され、不足分のみ有償輝石から消費されます。<br>
        ・冒険者補給箱を購入しても、探索へ持ち込める薬草・回復薬・魔力水は各10個までです。<br>
        ・緊急救助要請は初期実装では、全滅時に所持していれば自動使用されます。
    </div>

    {{-- 購入確認モーダル --}}
    <div x-show="confirming" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4">
        <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl">
            <div class="text-lg font-black text-slate-950" x-text="`${confirming?.name ?? ''}を購入しますか？`"></div>
            <p class="mt-3 text-sm font-bold leading-relaxed text-slate-600" x-text="confirming?.description ?? ''"></p>
            <div class="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-sm font-black text-slate-800">
                消費輝石: <span x-text="confirming?.price ?? 0"></span>
            </div>
            <div class="mt-5 grid grid-cols-2 gap-3">
                <button type="button" @click="confirming = null" class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-black text-slate-600 hover:bg-slate-50">
                    キャンセル
                </button>
                <form method="POST" action="{{ route('kiseki.support.purchase') }}" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
                    @csrf
                    <input type="hidden" name="item_key" :value="confirming?.key">
                    <button type="submit" :disabled="submitting" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-blue-700 px-4 py-3 text-sm font-black text-white shadow-sm hover:bg-blue-800 disabled:cursor-wait disabled:opacity-60">
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
