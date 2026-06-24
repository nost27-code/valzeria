@if(count($recipes) === 0)
    <div class="text-center py-10 text-slate-500">
        <p>交換できる素材をまだ所持していません。</p>
    </div>
@else
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-2.5">
        @foreach($recipes as $recipe)
            @php
                $canExchange = $recipe['can_exchange'];
                $cardClass = $canExchange
                    ? 'border-emerald-200 bg-white'
                    : 'border-slate-200 bg-slate-50';
                $recipeId = $recipe['id'];
                $maxExchangeCount = max(1, (int) ($recipe['max_exchange_count'] ?? 1));
                $searchText = implode(' ', [
                    $recipe['type'] ?? '',
                    $recipe['type_label'] ?? '',
                    $recipe['group_label'] ?? '',
                    $recipe['tier_label'] ?? '',
                    $recipe['source_name'] ?? '',
                    $recipe['target_name'] ?? '',
                ]);
                $isMulti = count($recipe['source_materials'] ?? []) > 1;
            @endphp
            <div
                data-recipe-card
                data-recipe-type="{{ $recipe['type'] }}"
                data-search-text="{{ e($searchText) }}"
                x-show="(typeFilter === 'all' || typeFilter === '{{ $recipe['type'] }}') && (!searchTerm.trim() || $el.dataset.searchText.toLowerCase().includes(searchTerm.trim().toLowerCase())) && (!keywordFilter || $el.dataset.searchText.includes(keywordFilter))"
                class="rounded-lg border {{ $cardClass }} px-3 py-2.5 flex flex-col gap-2"
            >
                {{-- ヘッダー: バッジ + 選択チェックボックス --}}
                <div class="flex items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="inline-flex items-center rounded bg-slate-800 px-1.5 py-0.5 text-[10px] font-bold text-white leading-none">
                            {{ $recipe['type_label'] }}
                        </span>
                        <span class="inline-flex items-center rounded border border-[#d4af37]/60 bg-amber-50 px-1.5 py-0.5 text-[10px] font-bold text-amber-700 leading-none">
                            {{ $recipe['group_label'] }}
                        </span>
                        @if($recipe['tier_label'] !== '')
                            <span class="inline-flex items-center rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-700 leading-none">
                                {{ $recipe['tier_label'] }}
                            </span>
                        @endif
                    </div>
                    @if($canExchange)
                        <label class="inline-flex shrink-0 items-center gap-1 rounded border border-amber-200 bg-white px-2 py-0.5 text-[10px] font-extrabold text-amber-700 cursor-pointer">
                            <input
                                type="checkbox"
                                value="{{ $recipeId }}"
                                x-model="selectedRecipeIds"
                                :disabled="isSubmitting"
                                class="rounded border-slate-300 text-amber-600 focus:ring-amber-500 disabled:cursor-not-allowed"
                            >
                            選択
                        </label>
                    @endif
                </div>

                {{-- 交換行: 渡す → 受け取る --}}
                <div class="flex items-center gap-2">
                    {{-- 渡す --}}
                    <div class="flex-1 min-w-0">
                        <div class="text-[9px] font-black text-slate-400 uppercase tracking-wider leading-none mb-1">渡す</div>
                        @if($isMulti)
                            <div class="space-y-0.5">
                                @foreach($recipe['source_materials'] as $source)
                                    <div class="flex items-center justify-between gap-1">
                                        <span class="text-xs font-bold text-slate-700 truncate">{{ $source['name'] }}</span>
                                        <span class="text-[11px] font-mono font-bold shrink-0 {{ $source['owned'] >= $source['required'] ? 'text-amber-600' : 'text-red-500' }}">
                                            {{ $source['owned'] }}&thinsp;/&thinsp;{{ $source['required'] }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="flex items-baseline gap-1.5 flex-wrap">
                                <span class="text-sm font-extrabold text-slate-900 truncate">{{ $recipe['source_name'] }}</span>
                                <span class="text-[11px] font-mono font-bold shrink-0 {{ $canExchange ? 'text-amber-600' : 'text-red-500' }}">
                                    {{ $recipe['owned_quantity'] }}&thinsp;/&thinsp;{{ $recipe['source_quantity'] }}
                                </span>
                            </div>
                        @endif
                    </div>

                    {{-- 矢印 --}}
                    <div class="text-slate-300 font-black text-base shrink-0">→</div>

                    {{-- 受け取る --}}
                    <div class="shrink-0 text-right min-w-[80px]">
                        <div class="text-[9px] font-black text-slate-400 uppercase tracking-wider leading-none mb-1">受け取る</div>
                        <div class="text-sm font-extrabold text-slate-900 leading-tight">{{ $recipe['target_name'] }}</div>
                        <div class="text-[11px] font-mono font-bold text-emerald-600">+{{ $recipe['target_quantity'] }}</div>
                        <div class="text-[10px] font-bold text-slate-400">
                            所持 {{ number_format($recipe['target_owned_quantity'] ?? 0) }}
                        </div>
                    </div>
                </div>

                {{-- フッター: 交換コントロール or 不足表示 --}}
                @if($canExchange)
                    <div class="flex items-center gap-2 pt-1 border-t border-emerald-100">
                        <div class="flex items-center rounded-lg border border-slate-200 overflow-hidden bg-white shadow-sm">
                            <button
                                type="button"
                                @click="decreaseRecipeQuantity(@js($recipeId), {{ $maxExchangeCount }})"
                                :disabled="isSubmitting || quantityFor(@js($recipeId)) <= 1"
                                class="w-8 h-8 flex items-center justify-center border-r border-slate-200 text-base font-black text-slate-600 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed"
                            >-</button>
                            <input
                                type="number"
                                min="1"
                                max="{{ $maxExchangeCount }}"
                                :value="quantityFor(@js($recipeId))"
                                @input="setRecipeQuantity(@js($recipeId), $event.target.value, {{ $maxExchangeCount }})"
                                :disabled="isSubmitting"
                                class="w-12 h-8 border-0 text-center text-sm font-black text-slate-900 focus:ring-0 disabled:cursor-not-allowed disabled:bg-slate-100"
                            >
                            <button
                                type="button"
                                @click="increaseRecipeQuantity(@js($recipeId), {{ $maxExchangeCount }})"
                                :disabled="isSubmitting || quantityFor(@js($recipeId)) >= {{ $maxExchangeCount }}"
                                class="w-8 h-8 flex items-center justify-center border-l border-slate-200 text-base font-black text-amber-600 hover:bg-amber-50 disabled:opacity-40 disabled:cursor-not-allowed"
                            >+</button>
                        </div>
                        <span class="text-[10px] font-bold text-slate-400">最大{{ number_format($maxExchangeCount) }}回</span>
                        <button
                            type="button"
                            class="ml-auto h-8 rounded-lg bg-amber-600 px-4 text-sm font-bold text-white shadow-sm hover:bg-amber-700 active:scale-[0.99] transition"
                            @click="selected = {
                                id: @js($recipeId),
                                sourceName: @js($recipe['source_name']),
                                sourceQuantity: {{ $recipe['source_quantity'] }},
                                targetName: @js($recipe['target_name']),
                                targetQuantity: {{ $recipe['target_quantity'] }},
                                quantity: quantityFor(@js($recipeId))
                            }; modalOpen = true"
                        >交換する</button>
                    </div>
                @else
                    <div class="flex items-center gap-1.5 pt-1 border-t border-slate-100">
                        <span class="text-[10px] font-black text-red-400">✕</span>
                        <span class="text-xs font-bold text-slate-400">あと{{ $recipe['missing_quantity'] }}個足りません</span>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
