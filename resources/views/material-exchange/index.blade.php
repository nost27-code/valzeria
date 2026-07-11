@php
    $headerIconImage = 'images/icon/icon_011.webp';
    $bgImage = 'images/facilities/item.webp';
    $title = '素材交換所 (' . ($currentCity->name ?? '冒険都市ヴァルゼリア') . ')';
    $recipeCount = count($recipes);
    $typeFilters = [
        'all' => '全て',
        'fragment_synthesis' => '欠片合成',
        'enhancement_stone' => '強化石',
        'low_refining_core' => '粗精錬核',
        'refining_core_part' => '精錬材',
        'refining_core' => '精錬核',
        'secret_crystal' => '秘境晶',
        'city_path_stone' => '導石',
        'accessory_evolution_material' => '装飾素材',
        'recovery_brewing' => '回復調合',
        'enemy_to_common' => '共通化',
    ];
    $keywordFilters = ['強化石', '守護石', '調律石', '粗精錬核', '精錬核', '覇王黒晶', '蒼炉魔晶', '星樹氷晶', '薬草', '共通素材', '小鬼の牙', '魔鉱片', '導石'];
@endphp
<x-layouts.facility :title="$title" :headerIconImage="$headerIconImage" :bgImage="$bgImage">
    <div class="w-full mx-auto pb-10" x-data="{
        modalOpen: false,
        materialModalOpen: false,
        selected: null,
        materialDetail: null,
        selectedRecipeIds: [],
        recipeQuantities: {},
        typeFilter: 'all',
        searchTerm: '',
        keywordFilter: '',
        isSubmitting: false,
        openMaterialDetail(detail) {
            this.materialDetail = detail;
            this.materialModalOpen = true;
        },
        quantityFor(recipeId) {
            return Math.max(1, parseInt(this.recipeQuantities[recipeId] || 1, 10));
        },
        setRecipeQuantity(recipeId, value, max) {
            const upper = Math.max(1, parseInt(max || 1, 10));
            const normalized = Math.max(1, Math.min(upper, parseInt(value || 1, 10)));
            this.recipeQuantities = { ...this.recipeQuantities, [recipeId]: normalized };
        },
        increaseRecipeQuantity(recipeId, max) {
            this.setRecipeQuantity(recipeId, this.quantityFor(recipeId) + 1, max);
        },
        decreaseRecipeQuantity(recipeId, max) {
            this.setRecipeQuantity(recipeId, this.quantityFor(recipeId) - 1, max);
        },
        selectedTotalQuantity() {
            return this.selectedRecipeIds.reduce((sum, recipeId) => sum + this.quantityFor(recipeId), 0);
        }
    }">
        <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-[#d4af37]/50">
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
                <div>
                    <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                        <img src="{{ asset('images/icon/icon_011.webp') }}" alt="" class="w-7 h-7 object-contain"> 素材交換
                    </h2>
                    <p class="mt-2 text-sm text-slate-600 leading-relaxed">
                        敵素材の共通化、強化石系素材の合成・精製、回復調合、導石・秘境晶・装飾素材の錬成を行います。
                    </p>
                </div>
                <div class="text-xs sm:text-sm text-slate-600 bg-slate-100 border border-slate-200 px-3 py-2 rounded">
                    表示:
                    <span class="font-bold text-slate-900"
                          x-text="$refs.recipeList ? [...$refs.recipeList.querySelectorAll('[data-recipe-card]')].filter((card) => (typeFilter === 'all' || card.dataset.recipeType === typeFilter) && (!searchTerm.trim() || card.dataset.searchText.toLowerCase().includes(searchTerm.trim().toLowerCase())) && (!keywordFilter || card.dataset.searchText.includes(keywordFilter))).length : 0">
                        {{ $recipeCount }}
                    </span>
                    / <span id="material-exchange-recipe-count" class="font-bold text-slate-900">{{ $recipeCount }}</span> 件
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-5">
                @foreach($typeFilters as $type => $label)
                    <button type="button" @click="typeFilter = @js($type)" :class="typeFilter === @js($type) ? 'bg-amber-600 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-2 py-2.5 text-xs sm:text-sm font-bold transition">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <div class="mb-5 rounded-lg border border-slate-200 bg-slate-50 p-3">
                <div class="flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2">
                    <span class="text-sm">🔍</span>
                    <input
                        type="search"
                        x-model.debounce.150ms="searchTerm"
                        placeholder="素材名で検索（例: 強化石、小鬼の牙、魔鉱片、導石）"
                        class="min-w-0 flex-1 border-0 bg-transparent text-sm font-bold text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-0"
                    >
                    <button type="button" x-show="searchTerm !== '' || keywordFilter !== ''" @click="searchTerm = ''; keywordFilter = ''" class="rounded bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600 active:scale-95">
                        クリア
                    </button>
                </div>
                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach($keywordFilters as $keyword)
                        <button type="button"
                                @click="keywordFilter = keywordFilter === @js($keyword) ? '' : @js($keyword)"
                                :class="keywordFilter === @js($keyword) ? 'bg-amber-600 text-white border-amber-600' : 'bg-white text-slate-700 border-slate-200'"
                                class="rounded-full border px-2.5 py-1 text-[11px] font-extrabold transition active:scale-95">
                            {{ $keyword }}
                        </button>
                    @endforeach
                </div>
            </div>

            @if(session('status'))
                <div class="material-exchange-message bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded mb-4 font-bold">
                    {{ session('status') }}
                </div>
            @endif
            @if(session('error'))
                <div class="material-exchange-message bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4 font-bold">
                    {{ session('error') }}
                </div>
            @endif
            <div id="material-exchange-inline-message" class="material-exchange-message hidden px-4 py-3 rounded mb-4 font-bold border"></div>

            <div id="material-exchange-recipes" x-ref="recipeList" data-material-exchange-recipes>
                @include('material-exchange.partials.recipe-list', ['recipes' => $recipes])
            </div>

            <div
                x-show="selectedRecipeIds.length > 0"
                x-transition
                style="display: none;"
                class="sticky bottom-4 z-20 mt-6 rounded-lg border border-amber-200 bg-white/95 p-3 shadow-lg backdrop-blur"
            >
                <form method="POST" action="{{ route('material-exchange.bulk') }}" id="material-exchange-bulk-form" class="flex flex-col sm:flex-row sm:items-center gap-3">
                    @csrf
                    <template x-for="recipeId in selectedRecipeIds" :key="recipeId">
                        <div>
                            <input type="hidden" name="recipe_ids[]" :value="recipeId">
                            <input type="hidden" name="quantities[]" :value="quantityFor(recipeId)">
                        </div>
                    </template>
                    <div class="text-sm font-bold text-slate-700">
                        選択中: <span class="font-mono text-amber-700" x-text="selectedRecipeIds.length"></span>件 /
                        <span class="font-mono text-amber-700" x-text="selectedTotalQuantity()"></span>回分
                    </div>
                    <div class="flex flex-1 gap-2 sm:justify-end">
                        <button
                            type="button"
                            @click="selectedRecipeIds = []"
                            :disabled="isSubmitting"
                            class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            解除
                        </button>
                        <button
                            type="submit"
                            :disabled="isSubmitting || selectedRecipeIds.length === 0"
                            class="flex-1 sm:flex-none rounded-lg bg-amber-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:bg-slate-400"
                        >
                            <span x-text="isSubmitting ? '一括交換中...' : '一括交換する'">一括交換する</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

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
                    <form method="POST" action="{{ route('material-exchange.exchange') }}" id="material-exchange-form">
                        @csrf
                        <input type="hidden" name="recipe_id" :value="selected?.id">
                        <input type="hidden" name="quantity" :value="selected?.quantity || 1">

                        <div class="border-b border-slate-200 px-5 py-5">
                            <h3 class="text-lg font-extrabold text-slate-900" id="modal-title">交換の確認</h3>
                            <p class="mt-3 text-sm text-slate-600 leading-relaxed">
                                <span class="font-bold text-slate-900" x-text="selected?.sourceName"></span>
                                <span class="font-mono font-bold text-red-600" x-text="' x' + ((selected?.sourceQuantity || 0) * (selected?.quantity || 1))"></span>
                                を渡して、<br>
                                <span class="font-bold text-amber-700" x-text="selected?.targetName"></span>
                                <span class="font-mono font-bold text-emerald-700" x-text="' x' + ((selected?.targetQuantity || 0) * (selected?.quantity || 1))"></span>
                                を受け取ります。
                                <span class="mt-2 block text-xs font-bold text-slate-500">
                                    交換回数: <span class="font-mono text-slate-800" x-text="selected?.quantity || 1"></span>回
                                </span>
                                <span class="mt-1 block text-xs font-bold text-amber-700" x-show="(selected?.goldCost || 0) > 0">
                                    費用: <span class="font-mono" x-text="((selected?.goldCost || 0) * (selected?.quantity || 1)).toLocaleString()"></span>G
                                </span>
                            </p>
                        </div>
                        <div class="bg-slate-50 px-5 py-4 sm:flex sm:flex-row-reverse sm:gap-3">
                            <button type="submit" :disabled="isSubmitting" class="inline-flex w-full justify-center rounded-md bg-amber-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-amber-700 disabled:bg-slate-400 disabled:cursor-not-allowed sm:w-auto">
                                <span x-text="isSubmitting ? '交換中...' : '交換実行'">交換実行</span>
                            </button>
                            <button type="button" @click="modalOpen = false" class="mt-3 inline-flex w-full justify-center rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 sm:mt-0 sm:w-auto">
                                キャンセル
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div x-show="materialModalOpen" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="material-modal-title" role="dialog" aria-modal="true" @keydown.escape.window="materialModalOpen = false">
            <div class="flex min-h-screen items-center justify-center p-4 text-center">
                <div
                    x-show="materialModalOpen"
                    x-transition.opacity
                    class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm"
                    @click="materialModalOpen = false"
                    aria-hidden="true"
                ></div>

                <div
                    x-show="materialModalOpen"
                    x-transition
                    class="relative z-10 inline-block w-full max-w-lg transform overflow-hidden rounded-lg bg-white text-left align-middle shadow-xl transition-all"
                >
                    <div class="border-b border-slate-200 px-5 py-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <div x-show="materialDetail?.iconImage" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-amber-100 bg-amber-50 p-1.5">
                                    <img :src="materialDetail?.iconImage ? '{{ url('/') }}/' + materialDetail.iconImage : ''" alt="" class="h-full w-full object-contain">
                                </div>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="inline-flex items-center rounded bg-slate-800 px-1.5 py-0.5 text-[10px] font-bold text-white leading-none" x-text="materialDetail?.typeLabel"></span>
                                        <span class="inline-flex items-center rounded border border-[#d4af37]/60 bg-amber-50 px-1.5 py-0.5 text-[10px] font-bold text-amber-700 leading-none" x-text="materialDetail?.groupLabel"></span>
                                    </div>
                                    <h3 class="mt-2 text-lg font-extrabold text-slate-900 leading-tight" id="material-modal-title" x-text="materialDetail?.name"></h3>
                                </div>
                            </div>
                            <button type="button" @click="materialModalOpen = false" class="shrink-0 rounded-full border border-slate-200 bg-white px-2.5 py-1 text-sm font-black text-slate-500 hover:bg-slate-50" aria-label="閉じる">×</button>
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-2 text-xs">
                            <div class="rounded border border-slate-200 bg-slate-50 px-3 py-2">
                                <div class="text-[10px] font-black text-slate-400">受け取り</div>
                                <div class="mt-1 font-mono text-sm font-black text-emerald-700" x-text="'+' + (materialDetail?.targetQuantity || 0)"></div>
                            </div>
                            <div class="rounded border border-slate-200 bg-slate-50 px-3 py-2">
                                <div class="text-[10px] font-black text-slate-400">所持</div>
                                <div class="mt-1 font-mono text-sm font-black text-slate-800" x-text="(materialDetail?.ownedQuantity || 0).toLocaleString()"></div>
                            </div>
                        </div>

                        <div class="mt-4 rounded border border-amber-100 bg-amber-50 px-3 py-2.5">
                            <div class="text-[10px] font-black text-amber-600">用途</div>
                            <div class="mt-1 text-sm font-bold leading-relaxed text-amber-900" x-text="materialDetail?.usage || 'この交換で受け取る素材です。'"></div>
                        </div>

                        <div class="mt-4 rounded border border-slate-200 bg-white px-3 py-2.5">
                            <div class="text-[10px] font-black text-slate-400">この交換で渡す素材</div>
                            <div class="mt-2 space-y-1">
                                <template x-for="source in (materialDetail?.sources || [])" :key="source.material_code">
                                    <div class="flex items-center justify-between gap-2 text-xs">
                                        <span class="min-w-0 inline-flex items-center gap-1.5 font-bold text-slate-700">
                                            <img x-show="source.icon_image" :src="source.icon_image ? '{{ url('/') }}/' + source.icon_image : ''" alt="" class="h-4 w-4 shrink-0 object-contain">
                                            <span class="truncate" x-text="source.name"></span>
                                        </span>
                                        <span class="shrink-0 font-mono font-black" :class="source.owned >= source.required ? 'text-amber-700' : 'text-red-500'" x-text="source.owned + ' / ' + source.required"></span>
                                    </div>
                                </template>
                            </div>
                            <div class="mt-2 text-xs font-bold text-amber-700" x-show="(materialDetail?.goldCost || 0) > 0">
                                費用: <span class="font-mono" x-text="(materialDetail?.goldCost || 0).toLocaleString()"></span>G
                            </div>
                        </div>
                    </div>
                    <div class="bg-slate-50 px-5 py-4 text-right">
                        <button type="button" @click="materialModalOpen = false" class="inline-flex w-full justify-center rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 sm:w-auto">
                            閉じる
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            (() => {
                if (window.__valzeriaMaterialExchangeAsyncBound) {
                    return;
                }
                window.__valzeriaMaterialExchangeAsyncBound = true;

                function showMaterialExchangeMessage(text, isSuccess) {
                    document.querySelectorAll('.material-exchange-message').forEach((message) => {
                        if (message.id !== 'material-exchange-inline-message') {
                            message.remove();
                        }
                    });

                    const message = document.getElementById('material-exchange-inline-message');
                    if (!message) return;
                    message.textContent = text;
                    message.classList.remove('hidden', 'bg-emerald-50', 'border-emerald-200', 'text-emerald-800', 'bg-red-50', 'border-red-200', 'text-red-700');
                    message.classList.add(...(isSuccess
                        ? ['bg-emerald-50', 'border-emerald-200', 'text-emerald-800']
                        : ['bg-red-50', 'border-red-200', 'text-red-700']));
                }

                document.addEventListener('submit', async function(event) {
                    const form = event.target.closest('#material-exchange-form, #material-exchange-bulk-form');
                    if (!form) return;

                    event.preventDefault();

                    const root = form.closest('[x-data]');
                    const alpine = root && window.Alpine ? window.Alpine.$data(root) : null;
                    if (alpine) {
                        alpine.isSubmitting = true;
                    }

                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: new FormData(form),
                        });
                        const data = await response.json();

                        if (!response.ok || data.success !== true) {
                            throw new Error(data.message || '交換に失敗しました。');
                        }

                        const recipes = document.getElementById('material-exchange-recipes');
                        if (recipes && typeof data.recipes_html === 'string') {
                            recipes.innerHTML = data.recipes_html;
                            if (window.Alpine) {
                                window.Alpine.initTree(recipes);
                            }
                        }

                        const count = document.getElementById('material-exchange-recipe-count');
                        if (count) {
                            count.textContent = String(data.recipe_count ?? 0);
                        }

                        showMaterialExchangeMessage(data.message || '交換しました。', true);
                        if (alpine) {
                            alpine.modalOpen = false;
                            alpine.selected = null;
                            alpine.selectedRecipeIds = [];
                            alpine.recipeQuantities = {};
                        }
                    } catch (error) {
                        showMaterialExchangeMessage(error.message || '交換に失敗しました。', false);
                    } finally {
                        if (alpine) {
                            alpine.isSubmitting = false;
                        }
                    }
                });
            })();
        </script>
    </div>
</x-layouts.facility>
