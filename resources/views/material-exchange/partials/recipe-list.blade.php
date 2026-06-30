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
                    $recipe['target_usage'] ?? '',
                ]);
                $isMulti = count($recipe['source_materials'] ?? []) > 1;
                $goldCost = (int) ($recipe['gold_cost'] ?? 0);
                $missingGold = (int) ($recipe['missing_gold'] ?? 0);
                $targetUsage = $recipe['target_usage'] ?? null;
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
                        @if($goldCost > 0)
                            <span class="inline-flex items-center rounded bg-yellow-100 px-1.5 py-0.5 text-[10px] font-bold text-yellow-700 leading-none">
                                {{ number_format($goldCost) }}G
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

                {{-- 交換行: 作る素材 + 必要素材 --}}
                <div class="grid grid-cols-[minmax(0,0.95fr)_minmax(0,1.35fr)] items-start gap-3">
                    {{-- 作る --}}
                    <div class="min-w-0">
                        <div class="text-[9px] font-black text-slate-400 uppercase tracking-wider leading-none mb-1">作る</div>
                        <button
                            type="button"
                            class="inline-flex max-w-full items-center gap-1 text-left text-sm font-extrabold text-amber-700 leading-tight underline decoration-amber-300 underline-offset-2 hover:text-amber-900 active:scale-[0.99]"
                            @click="openMaterialDetail({
                                name: @js($recipe['target_name']),
                                code: @js($recipe['target_code'] ?? ''),
                                iconImage: @js($recipe['target_icon_image'] ?? null),
                                kind: @js($recipe['target_kind'] ?? ''),
                                typeLabel: @js($recipe['type_label'] ?? ''),
                                groupLabel: @js($recipe['group_label'] ?? ''),
                                usage: @js($targetUsage),
                                targetQuantity: {{ (int) ($recipe['target_quantity'] ?? 0) }},
                                ownedQuantity: {{ (int) ($recipe['target_owned_quantity'] ?? 0) }},
                                goldCost: {{ $goldCost }},
                                sources: @js($recipe['source_materials'] ?? [])
                            })"
                        >
                            @if(!empty($recipe['target_icon_image']))
                                <img src="{{ asset($recipe['target_icon_image']) }}" alt="" class="h-5 w-5 shrink-0 object-contain">
                            @endif
                            <span class="truncate">{{ $recipe['target_name'] }}</span>
                        </button>
                        <div class="mt-0.5 text-[11px] font-mono font-bold text-emerald-600">+{{ $recipe['target_quantity'] }}</div>
                        <div class="text-[10px] font-bold text-slate-400">
                            所持 {{ number_format($recipe['target_owned_quantity'] ?? 0) }}
                        </div>
                    </div>

                    {{-- 必要 --}}
                    <div class="min-w-0">
                        <div class="text-[9px] font-black text-slate-400 uppercase tracking-wider leading-none mb-1">必要</div>
                        @if($isMulti)
                            <div class="space-y-0.5">
                                @foreach($recipe['source_materials'] as $source)
                                    <div class="flex items-center justify-between gap-1">
                                        <span class="min-w-0 inline-flex items-center gap-1 text-xs font-bold text-slate-700 truncate">
                                            @if(!empty($source['icon_image']))
                                                <img src="{{ asset($source['icon_image']) }}" alt="" class="h-4 w-4 shrink-0 object-contain">
                                            @endif
                                            <span class="truncate">{{ $source['name'] }}</span>
                                        </span>
                                        <span class="text-[11px] font-mono font-bold shrink-0 {{ $source['owned'] >= $source['required'] ? 'text-amber-600' : 'text-red-500' }}">
                                            {{ $source['owned'] }}&thinsp;/&thinsp;{{ $source['required'] }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="flex items-center gap-1.5 flex-wrap">
                                @if(!empty($recipe['source_materials'][0]['icon_image'] ?? null))
                                    <img src="{{ asset($recipe['source_materials'][0]['icon_image']) }}" alt="" class="h-5 w-5 shrink-0 object-contain">
                                @endif
                                <span class="text-sm font-extrabold text-slate-900 truncate">{{ $recipe['source_name'] }}</span>
                                <span class="text-[11px] font-mono font-bold shrink-0 {{ $canExchange ? 'text-amber-600' : 'text-red-500' }}">
                                    {{ $recipe['owned_quantity'] }}&thinsp;/&thinsp;{{ $recipe['source_quantity'] }}
                                </span>
                            </div>
                        @endif
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
                                goldCost: {{ $goldCost }},
                                quantity: quantityFor(@js($recipeId))
                            }; modalOpen = true"
                        >交換する</button>
                    </div>
                @else
                    <div class="flex items-center gap-1.5 pt-1 border-t border-slate-100">
                        <span class="text-[10px] font-black text-red-400">✕</span>
                        <span class="text-xs font-bold text-slate-400">
                            @if($missingGold > 0 && (int) ($recipe['missing_quantity'] ?? 0) <= 0)
                                あと{{ number_format($missingGold) }}G足りません
                            @elseif($missingGold > 0)
                                素材あと{{ $recipe['missing_quantity'] }}個 / {{ number_format($missingGold) }}G足りません
                            @else
                                あと{{ $recipe['missing_quantity'] }}個足りません
                            @endif
                        </span>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
