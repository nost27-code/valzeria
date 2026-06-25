@php
    $headerIconImage = 'images/icon/icon_011.webp';
    $bgImage = 'images/facilities/item.webp';
    $title = '素材交換所 (' . ($currentCity->name ?? '冒険都市ヴァルゼリア') . ')';
    $recipeCount = count($recipes);
@endphp
<x-layouts.facility :title="$title" :headerIconImage="$headerIconImage" :bgImage="$bgImage">
    <div class="w-full mx-auto pb-10" x-data="{
        modalOpen: false,
        selected: null,
        selectedRecipeIds: [],
        recipeQuantities: {},
        typeFilter: 'all',
        searchTerm: '',
        keywordFilter: '',
        isSubmitting: false,
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
                        装備の欠片の上位変換、秘境晶・導石・装飾素材の錬成、敵部位からの調合素材づくりや回復アイテム調合を行います。
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
                <button type="button" @click="typeFilter = 'all'" :class="typeFilter === 'all' ? 'bg-amber-600 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-2 py-2.5 text-xs sm:text-sm font-bold transition">
                    全て
                </button>
                <button type="button" @click="typeFilter = 'upgrade'" :class="typeFilter === 'upgrade' ? 'bg-amber-600 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-2 py-2.5 text-xs sm:text-sm font-bold transition">
                    上位変換
                </button>
                <button type="button" @click="typeFilter = 'secret_crystal'" :class="typeFilter === 'secret_crystal' ? 'bg-amber-600 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-2 py-2.5 text-xs sm:text-sm font-bold transition">
                    秘境晶
                </button>
                <button type="button" @click="typeFilter = 'city_path_stone'" :class="typeFilter === 'city_path_stone' ? 'bg-amber-600 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-2 py-2.5 text-xs sm:text-sm font-bold transition">
                    導石錬成
                </button>
                <button type="button" @click="typeFilter = 'enemy_part'" :class="typeFilter === 'enemy_part' ? 'bg-amber-600 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-2 py-2.5 text-xs sm:text-sm font-bold transition">
                    部位変換
                </button>
                <button type="button" @click="typeFilter = 'recovery_brewing'" :class="typeFilter === 'recovery_brewing' ? 'bg-amber-600 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-2 py-2.5 text-xs sm:text-sm font-bold transition">
                    回復調合
                </button>
            </div>

            <div class="mb-5 rounded-lg border border-slate-200 bg-slate-50 p-3">
                <div class="flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2">
                    <span class="text-sm">🔍</span>
                    <input
                        type="search"
                        x-model.debounce.150ms="searchTerm"
                        placeholder="素材名で検索（例: 装備の欠片、牙、毒素材、薬草）"
                        class="min-w-0 flex-1 border-0 bg-transparent text-sm font-bold text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-0"
                    >
                    <button type="button" x-show="searchTerm !== '' || keywordFilter !== ''" @click="searchTerm = ''; keywordFilter = ''" class="rounded bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600 active:scale-95">
                        クリア
                    </button>
                </div>
                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach(['装備の欠片', '上質', '強装備', '導石', '牙', '毒素材', '魔粉素材', '薬草'] as $keyword)
                        <button type="button"
                                @click="keywordFilter = keywordFilter === @js($keyword) ? '' : @js($keyword)"
                                :class="keywordFilter === @js($keyword) ? 'bg-amber-600 text-white border-amber-600' : 'bg-white text-slate-700 border-slate-200'"
                                class="rounded-full border px-2.5 py-1 text-[11px] font-extrabold transition active:scale-95">
                            {{ $keyword }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-5 text-xs text-slate-600">
                <div class="rounded border border-slate-200 bg-slate-50 px-3 py-2">
                    上位変換: <span class="font-bold text-slate-900">装備の欠片 10 → 上質 1 / 上質 10 → 強装備 1</span>
                </div>
                <div class="rounded border border-slate-200 bg-slate-50 px-3 py-2">
                    秘境晶交換: <span class="font-bold text-slate-900">対応する秘境晶片 5 → 秘境晶 1</span>
                </div>
                <div class="rounded border border-slate-200 bg-slate-50 px-3 py-2">
                    導石錬成: <span class="font-bold text-slate-900">指定都市素材 7 + 3 → 導石 1</span>
                </div>
                <div class="rounded border border-slate-200 bg-slate-50 px-3 py-2">
                    部位変換: <span class="font-bold text-slate-900">敵部位 1 → 調合素材 1</span>
                </div>
                <div class="rounded border border-slate-200 bg-slate-50 px-3 py-2">
                    回復調合: <span class="font-bold text-slate-900">調合素材 → 薬草・回復薬・魔力水</span>
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
