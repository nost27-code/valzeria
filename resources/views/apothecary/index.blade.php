@php
    $title = '薬屋';
    $headerIconImage = 'images/facilities/shop_item_symbol.webp';
    $bgImage = 'images/facilities/item.webp';
    $remainingPercent = $activeSupport ? max(0, min(100, (int) round(($activeSupport['remaining'] / 30) * 100))) : 0;
@endphp
<x-layouts.facility :title="$title" :headerIconImage="$headerIconImage" :bgImage="$bgImage">
    <div class="w-full mx-auto pb-10" x-data="{
        modalOpen: false,
        selected: null,
        activateModalOpen: false,
        activateSelected: null,
        clearModalOpen: false,
        quantities: {},
        autoRenew: {{ $activeSupport && $activeSupport['auto_renew'] ? 'true' : 'false' }},
        autoRenewBusy: false,
        quantityFor(code) {
            return Math.max(1, parseInt(this.quantities[code] || 1, 10));
        },
        setQuantity(code, value, max) {
            const upper = Math.max(1, parseInt(max || 1, 10));
            const normalized = Math.max(1, Math.min(upper, parseInt(value || 1, 10)));
            this.quantities = { ...this.quantities, [code]: normalized };
        },
        increaseQuantity(code, max) {
            this.setQuantity(code, this.quantityFor(code) + 1, max);
        },
        decreaseQuantity(code, max) {
            this.setQuantity(code, this.quantityFor(code) - 1, max);
        },
        async toggleAutoRenew() {
            if (this.autoRenewBusy) return;
            const next = !this.autoRenew;
            this.autoRenewBusy = true;
            try {
                const response = await fetch(@js(route('apothecary.auto-renew')), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': @js(csrf_token()),
                    },
                    body: new URLSearchParams({ item_key: @js($activeSupport['item_key'] ?? ''), auto_renew: next ? '1' : '0' }),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || data.success !== true) {
                    throw new Error(data.message || '自動継続の変更に失敗しました。');
                }
                this.autoRenew = data.auto_renew;
            } catch (error) {
                alert(error.message || '自動継続の変更に失敗しました。');
            } finally {
                this.autoRenewBusy = false;
            }
        }
    }">
        <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-[#d4af37]/50">
            <div class="mb-5">
                <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                    <img src="{{ asset($headerIconImage) }}" alt="" class="w-7 h-7 object-contain"> 薬屋
                </h2>
                <p class="mt-2 text-sm text-slate-600 leading-relaxed">
                    探索の前に、一つだけ補助品を持ち込めます。効果は実際に戦った30戦だけ続きます。
                </p>
            </div>

            @if(session('status'))
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded mb-4 font-bold">
                    {{ session('status') }}
                </div>
            @endif
            @if(session('error'))
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4 font-bold">
                    {{ session('error') }}
                </div>
            @endif

            {{-- 探索補助の現在状態 --}}
            <div class="mb-5 rounded-lg border {{ $activeSupport ? 'border-emerald-200 bg-emerald-50/50' : 'border-slate-200 bg-slate-50' }} p-3.5">
                <div class="text-[10px] font-black uppercase tracking-wider text-slate-400">探索補助</div>
                @if($activeSupport)
                    <div class="mt-1.5 flex flex-wrap items-center justify-between gap-2">
                        <span class="text-base font-extrabold text-emerald-800">{{ $activeSupport['name'] }}</span>
                        <span class="text-xs font-mono font-bold text-slate-600">残り {{ $activeSupport['remaining'] }}&thinsp;/&thinsp;30戦</span>
                    </div>
                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-white border border-emerald-100">
                        <div class="h-full rounded-full bg-emerald-500 transition-all" style="width: {{ $remainingPercent }}%"></div>
                    </div>
                    <p class="mt-2 text-xs leading-relaxed text-slate-600">
                        {{ $activeSupport['description'] }}@if($activeSupport['procs_remaining'] !== null)
                            <span class="ml-1 font-bold text-emerald-700">残り発動{{ $activeSupport['procs_remaining'] }}回</span>
                        @endif
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button
                            type="button"
                            @click="toggleAutoRenew()"
                            :disabled="autoRenewBusy"
                            :class="autoRenew ? 'border-emerald-300 bg-emerald-600 text-white' : 'border-slate-300 bg-white text-slate-700'"
                            class="rounded-lg border px-3 py-1.5 text-xs font-bold shadow-sm transition hover:opacity-90 disabled:cursor-wait disabled:opacity-60"
                        >
                            <span x-text="'自動継続: ' + (autoRenew ? 'ON' : 'OFF')"></span>
                        </button>
                        <button
                            type="button"
                            @click="clearModalOpen = true"
                            class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-bold text-red-700 shadow-sm transition hover:bg-red-50"
                        >解除</button>
                    </div>
                @else
                    <p class="mt-1.5 text-sm font-bold text-slate-500">今は補助品を使っていません。</p>
                @endif
            </div>

            <h3 class="mb-3 text-base font-black text-slate-800">探索補助品を調合</h3>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-2.5">
                @foreach($recipes as $recipe)
                    @php
                        $recipeCode = $recipe['code'];
                        $canCraft = $recipe['unlocked'] && $recipe['max_craft_count'] > 0;
                        $cardClass = !$recipe['unlocked']
                            ? 'border-slate-200 bg-slate-50 opacity-70'
                            : ($canCraft ? 'border-emerald-200 bg-white' : 'border-slate-200 bg-white');
                        $isActiveSupport = $activeSupport && $activeSupport['name'] === $recipe['name'];
                    @endphp
                    <div class="rounded-lg border {{ $cardClass }} px-3 py-2.5 flex flex-col gap-2">
                        {{-- ヘッダー: バッジ --}}
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="inline-flex items-center rounded bg-slate-800 px-1.5 py-0.5 text-[10px] font-bold text-white leading-none">
                                    探索補助品
                                </span>
                                @if($recipe['variant_label'])
                                    <span class="inline-flex items-center rounded border border-[#d4af37]/60 bg-amber-50 px-1.5 py-0.5 text-[10px] font-bold text-amber-700 leading-none">
                                        {{ $recipe['variant_label'] }}
                                    </span>
                                @endif
                                @if($recipe['gold_fee'] > 0)
                                    <span class="inline-flex items-center rounded bg-yellow-100 px-1.5 py-0.5 text-[10px] font-bold text-yellow-700 leading-none">
                                        {{ number_format($recipe['gold_fee']) }}G
                                    </span>
                                @endif
                            </div>
                            @if($isActiveSupport)
                                <span class="shrink-0 rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-[10px] font-black text-emerald-700 leading-none">
                                    使用中（所持{{ number_format($recipe['owned_item_count']) }}個）
                                </span>
                            @else
                                <button
                                    type="button"
                                    @if($recipe['owned_item_count'] > 0)
                                        @click="activateSelected = { itemKey: @js($recipe['support_key']), name: @js($recipe['name']) }; activateModalOpen = true"
                                    @else
                                        disabled
                                    @endif
                                    class="shrink-0 text-[10px] font-bold leading-none underline underline-offset-2 transition {{ $recipe['owned_item_count'] > 0 ? 'text-emerald-700 decoration-emerald-300 hover:text-emerald-900' : 'text-slate-400 decoration-slate-300 cursor-not-allowed' }}"
                                >
                                    使用する（所持{{ number_format($recipe['owned_item_count']) }}個）
                                </button>
                            @endif
                        </div>

                        {{-- 品名 + 効果 --}}
                        <div>
                            <div class="text-sm font-extrabold text-slate-900 leading-tight">{{ $recipe['name'] }}</div>
                            <p class="mt-1 text-xs leading-relaxed text-slate-600">{{ $recipe['description'] }}</p>
                        </div>

                        @if(!$recipe['unlocked'])
                            <div class="flex items-center gap-1.5 pt-1 border-t border-slate-100">
                                <span class="text-[10px] font-black text-slate-400">🔒</span>
                                <span class="text-xs font-bold text-slate-500">{{ $recipe['unlock_text'] }}</span>
                            </div>
                        @else
                            {{-- 必要素材 --}}
                            <div class="min-w-0">
                                <div class="text-[9px] font-black text-slate-400 uppercase tracking-wider leading-none mb-1">
                                    必要素材（1回で{{ $recipe['output_quantity'] }}個完成）
                                </div>
                                <div class="space-y-0.5">
                                    @foreach($recipe['requirements'] as $material)
                                        <div class="flex items-center justify-between gap-1">
                                            <span class="min-w-0 inline-flex items-center gap-1 text-xs font-bold text-slate-700 truncate">
                                                @if(!empty($material['icon_image']))
                                                    <img src="{{ asset($material['icon_image']) }}" alt="" class="h-4 w-4 shrink-0 object-contain">
                                                @endif
                                                <span class="truncate">{{ $material['name'] }}</span>
                                            </span>
                                            <span class="text-[11px] font-mono font-bold shrink-0 {{ $material['owned'] >= $material['quantity'] ? 'text-amber-600' : 'text-red-500' }}">
                                                {{ $material['owned'] }}&thinsp;/&thinsp;{{ $material['quantity'] }}
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            {{-- フッター: 調合コントロール or 不足表示 --}}
                            @if($canCraft)
                                <div class="flex items-center gap-2 pt-1 border-t border-emerald-100">
                                    <div class="flex items-center rounded-lg border border-slate-200 overflow-hidden bg-white shadow-sm">
                                        <button
                                            type="button"
                                            @click="decreaseQuantity(@js($recipeCode), {{ $recipe['max_craft_count'] }})"
                                            :disabled="quantityFor(@js($recipeCode)) <= 1"
                                            class="w-8 h-8 flex items-center justify-center border-r border-slate-200 text-base font-black text-slate-600 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed"
                                        >-</button>
                                        <input
                                            type="number"
                                            min="1"
                                            max="{{ $recipe['max_craft_count'] }}"
                                            :value="quantityFor(@js($recipeCode))"
                                            @input="setQuantity(@js($recipeCode), $event.target.value, {{ $recipe['max_craft_count'] }})"
                                            class="w-12 h-8 border-0 text-center text-sm font-black text-slate-900 focus:ring-0"
                                        >
                                        <button
                                            type="button"
                                            @click="increaseQuantity(@js($recipeCode), {{ $recipe['max_craft_count'] }})"
                                            :disabled="quantityFor(@js($recipeCode)) >= {{ $recipe['max_craft_count'] }}"
                                            class="w-8 h-8 flex items-center justify-center border-l border-slate-200 text-base font-black text-amber-600 hover:bg-amber-50 disabled:opacity-40 disabled:cursor-not-allowed"
                                        >+</button>
                                    </div>
                                    <span class="text-[10px] font-bold text-slate-400">最大{{ number_format($recipe['max_craft_count']) }}回</span>
                                    <button
                                        type="button"
                                        class="ml-auto h-8 rounded-lg bg-emerald-600 px-4 text-sm font-bold text-white shadow-sm hover:bg-emerald-700 active:scale-[0.99] transition"
                                        @click="selected = {
                                            code: @js($recipeCode),
                                            name: @js($recipe['name']),
                                            goldFee: {{ $recipe['gold_fee'] }},
                                            outputQuantity: {{ $recipe['output_quantity'] }},
                                            quantity: quantityFor(@js($recipeCode))
                                        }; modalOpen = true"
                                    >調合する</button>
                                </div>
                            @else
                                <div class="flex items-center gap-1.5 pt-1 border-t border-slate-100">
                                    <span class="text-[10px] font-black text-red-400">✕</span>
                                    <span class="text-xs font-bold text-slate-400">素材またはGoldが足りません</span>
                                </div>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- 調合確認モーダル --}}
        <div x-show="modalOpen" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4 text-center">
                <div
                    x-show="modalOpen"
                    x-transition.opacity
                    class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm"
                    @click="modalOpen = false"
                    aria-hidden="true"
                ></div>

                <div
                    x-show="modalOpen"
                    x-transition
                    class="relative z-10 inline-block w-full max-w-lg transform overflow-hidden rounded-lg bg-white text-left align-middle shadow-xl transition-all"
                >
                    <form method="POST" action="{{ route('apothecary.craft') }}" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
                        @csrf
                        <input type="hidden" name="recipe_code" :value="selected?.code">
                        <input type="hidden" name="count" :value="selected?.quantity || 1">

                        <div class="border-b border-slate-200 px-5 py-5">
                            <h3 class="text-lg font-extrabold text-slate-900" id="modal-title">調合の確認</h3>
                            <p class="mt-3 text-sm text-slate-600 leading-relaxed">
                                <span class="font-bold text-slate-900" x-text="selected?.name"></span>
                                を<span class="font-mono font-bold text-emerald-700" x-text="(selected?.outputQuantity || 0) * (selected?.quantity || 1)"></span>個
                                （<span x-text="selected?.quantity || 1"></span>回分）調合します。
                            </p>
                            <p class="mt-3 rounded bg-amber-50 px-3 py-2 text-sm font-bold text-amber-800">
                                調合費用: <span x-text="((selected?.goldFee || 0) * (selected?.quantity || 1)).toLocaleString()"></span>G
                            </p>
                        </div>
                        <div class="bg-slate-50 px-5 py-4 sm:flex sm:flex-row-reverse sm:gap-3">
                            <button type="submit" :disabled="submitting" class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700 disabled:cursor-wait disabled:opacity-70 sm:w-auto">
                                <x-loading-spinner x-show="submitting" style="display: none;" />
                                <span x-show="!submitting">調合実行</span>
                                <span x-show="submitting" style="display: none;">調合中...</span>
                            </button>
                            <button type="button" :disabled="submitting" @click="modalOpen = false" class="mt-3 inline-flex w-full justify-center rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60 sm:mt-0 sm:w-auto">
                                キャンセル
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- 使用確認モーダル --}}
        <div x-show="activateModalOpen" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="activate-modal-title" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4 text-center">
                <div
                    x-show="activateModalOpen"
                    x-transition.opacity
                    class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm"
                    @click="activateModalOpen = false"
                    aria-hidden="true"
                ></div>

                <div
                    x-show="activateModalOpen"
                    x-transition
                    class="relative z-10 inline-block w-full max-w-lg transform overflow-hidden rounded-lg bg-white text-left align-middle shadow-xl transition-all"
                >
                    <form method="POST" action="{{ route('apothecary.activate') }}" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
                        @csrf
                        <input type="hidden" name="item_key" :value="activateSelected?.itemKey">

                        <div class="border-b border-slate-200 px-5 py-5">
                            <h3 class="text-lg font-extrabold text-slate-900" id="activate-modal-title">探索補助品の使用</h3>
                            <p class="mt-3 text-sm text-slate-600 leading-relaxed">
                                <span class="font-bold text-slate-900" x-text="activateSelected?.name"></span>
                                を使用しますか？
                            </p>
                            <p class="mt-3 rounded bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800" x-show="{{ $activeSupport ? 'true' : 'false' }}">
                                有効中の探索補助品がある場合、残り戦数と未使用の発動回数は失われます。
                            </p>
                        </div>
                        <div class="bg-slate-50 px-5 py-4 sm:flex sm:flex-row-reverse sm:gap-3">
                            <button type="submit" :disabled="submitting" class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700 disabled:cursor-wait disabled:opacity-70 sm:w-auto">
                                <x-loading-spinner x-show="submitting" style="display: none;" />
                                <span x-show="!submitting">使用する</span>
                                <span x-show="submitting" style="display: none;">使用中...</span>
                            </button>
                            <button type="button" :disabled="submitting" @click="activateModalOpen = false" class="mt-3 inline-flex w-full justify-center rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60 sm:mt-0 sm:w-auto">
                                キャンセル
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- 解除確認モーダル --}}
        <div x-show="clearModalOpen" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="clear-modal-title" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4 text-center">
                <div
                    x-show="clearModalOpen"
                    x-transition.opacity
                    class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm"
                    @click="clearModalOpen = false"
                    aria-hidden="true"
                ></div>

                <div
                    x-show="clearModalOpen"
                    x-transition
                    class="relative z-10 inline-block w-full max-w-lg transform overflow-hidden rounded-lg bg-white text-left align-middle shadow-xl transition-all"
                >
                    <form method="POST" action="{{ route('apothecary.clear') }}" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
                        @csrf

                        <div class="border-b border-slate-200 px-5 py-5">
                            <h3 class="text-lg font-extrabold text-slate-900" id="clear-modal-title">探索補助品の解除</h3>
                            <p class="mt-3 text-sm text-slate-600 leading-relaxed">
                                残り戦数と未使用の発動回数は失われます。解除しますか？
                            </p>
                        </div>
                        <div class="bg-slate-50 px-5 py-4 sm:flex sm:flex-row-reverse sm:gap-3">
                            <button type="submit" :disabled="submitting" class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-red-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-red-700 disabled:cursor-wait disabled:opacity-70 sm:w-auto">
                                <x-loading-spinner x-show="submitting" style="display: none;" />
                                <span x-show="!submitting">解除する</span>
                                <span x-show="submitting" style="display: none;">解除中...</span>
                            </button>
                            <button type="button" :disabled="submitting" @click="clearModalOpen = false" class="mt-3 inline-flex w-full justify-center rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60 sm:mt-0 sm:w-auto">
                                キャンセル
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-layouts.facility>
