{{-- 進化詳細パーシャル: smith/index.blade.php から @include で使用 --}}
{{-- 変数: $candidate, $stone, $canUseStone, $canEvolve --}}
<div class="space-y-2">
    @php
        $baseOk = $candidate['owned_equipment_count'] >= ($candidate['required_base_equipment_count'] ?? 1);
        $sourceOptions = $candidate['source_options'] ?? [];
        $singleSourceOption = count($sourceOptions) === 1 ? $sourceOptions[0] : null;
        $requiredBase = $candidate['required_base_equipment_count'] ?? 1;
        $goldOk = (int) ($candidate['owned_gold'] ?? 0) >= (int) ($candidate['gold_cost'] ?? 0);
    @endphp

    {{-- 能力変化：進化で得られる効果を最初に見せる --}}
    @if(!empty($candidate['stat_changes']))
        <div class="rounded-lg border border-emerald-100 bg-emerald-50/60 px-2 py-1.5">
            <div class="mb-1 text-[10px] font-black text-emerald-700">能力変化</div>
            <div class="flex flex-wrap gap-1">
                @foreach($candidate['stat_changes'] as $stat)
                    @php
                        $diff = (int) $stat['diff'];
                        $badgeClass = $diff > 0
                            ? 'bg-white border-emerald-200 text-emerald-700'
                            : ($diff < 0 ? 'bg-white border-red-200 text-red-600' : 'bg-white border-slate-200 text-slate-500');
                    @endphp
                    <span class="inline-flex items-center gap-0.5 rounded border {{ $badgeClass }} px-1.5 py-0.5 text-[10px] font-bold font-mono">
                        {{ $stat['label'] }}&nbsp;{{ $stat['from'] }}→{{ $stat['to'] }}&nbsp;<span class="font-black">{{ $diff > 0 ? '+' : '' }}{{ $diff }}</span>
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    @if($singleSourceOption && !empty($singleSourceOption['affix_lines']))
        {{-- 候補が1件のみ：現在の銘効果（進化後も引き継がれる） --}}
        <div class="flex flex-wrap items-center gap-1">
            <span class="text-[10px] font-bold text-indigo-500 shrink-0">銘の効果</span>
            @foreach($singleSourceOption['affix_lines'] as $affixLine)
                <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-bold text-indigo-600">{{ $affixLine }}</span>
            @endforeach
        </div>
    @endif

    @if($singleSourceOption)
        @php
            $sourceSellPrice = (int) ($singleSourceOption['sell_price'] ?? 0);
            $sourceCanSell = (bool) ($singleSourceOption['can_sell'] ?? false);
            $sourceSellDisabledTitle = ($singleSourceOption['is_equipped'] ?? false)
                ? '装備中は売却不可'
                : (($singleSourceOption['is_locked'] ?? false) ? '保護中は売却不可' : '売却不可');
        @endphp
        <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-2 py-1.5" x-data="{ confirmSell: false }">
            <div class="min-w-0">
                <div class="truncate text-[11px] font-black text-slate-700">{{ $singleSourceOption['display_name'] }}</div>
                <div class="text-[10px] font-bold {{ $sourceSellPrice > 0 ? 'text-orange-600' : 'text-slate-400' }}">
                    {{ $sourceSellPrice > 0 ? '売却額 ' . number_format($sourceSellPrice) . 'G' : '売却不可' }}
                </div>
            </div>
            <form action="{{ route('equipment.sell', $singleSourceOption['id']) }}" method="POST" class="shrink-0" data-smith-sell-form data-source-item-id="{{ (int) $singleSourceOption['id'] }}">
                @csrf
                <input type="hidden" name="return_to_smith" value="1">
                <button type="button"
                    @click="confirmSell = true"
                    @if(!$sourceCanSell) disabled title="{{ $sourceSellDisabledTitle }}" @endif
                    class="h-8 rounded px-3 text-xs font-black transition active:scale-95 {{ $sourceCanSell ? 'bg-orange-600 text-white hover:bg-orange-700' : 'bg-slate-100 text-slate-400 cursor-not-allowed' }}">
                    売却する
                </button>
                @if($sourceCanSell)
                    <div x-show="confirmSell" x-cloak @keydown.escape.window="confirmSell = false" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4 py-6">
                        <div class="w-full max-w-sm rounded-xl border border-orange-200 bg-white p-4 shadow-2xl" @click.outside="confirmSell = false">
                            <div class="text-sm font-extrabold text-slate-900">装備を売却しますか？</div>
                            <div class="mt-2 rounded-lg bg-orange-50 px-3 py-2 text-sm text-slate-700">
                                <div class="font-bold text-slate-900">{{ $singleSourceOption['display_name'] }}</div>
                                <div class="mt-1 text-orange-700">売却額: <span class="font-extrabold">{{ number_format($sourceSellPrice) }}G</span></div>
                            </div>
                            <p class="mt-3 text-xs leading-relaxed text-slate-500">
                                売却した装備は所持品からなくなります。よろしければ確定してください。
                            </p>
                            <div class="mt-4 flex items-center justify-end gap-2">
                                <button type="button" @click="confirmSell = false" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50">
                                    キャンセル
                                </button>
                                <button type="submit" data-smith-sell-submit class="rounded-lg bg-orange-600 px-3 py-2 text-xs font-extrabold text-white shadow-sm hover:bg-orange-700">
                                    売却する
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            </form>
        </div>
    @elseif(count($sourceOptions) > 1)
        <div class="space-y-1 rounded-lg border border-slate-200 bg-slate-50 p-2">
            <div class="text-[10px] font-black text-slate-500">進化させる装備を選択（{{ count($sourceOptions) }}件から）</div>
            @foreach($sourceOptions as $sourceOption)
                @php
                    $sourceSellPrice = (int) ($sourceOption['sell_price'] ?? 0);
                    $sourceCanSell = (bool) ($sourceOption['can_sell'] ?? false);
                    $sourceSellDisabledTitle = ($sourceOption['is_equipped'] ?? false)
                        ? '装備中は売却不可'
                        : (($sourceOption['is_locked'] ?? false) ? '保護中は売却不可' : '売却不可');
                @endphp
                <div class="rounded border border-white bg-white px-2 py-1.5 shadow-sm" x-data="{ confirmSell: false }">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <div class="break-words text-xs font-black text-slate-800">{{ $sourceOption['display_name'] }}</div>
                            @if(!empty($sourceOption['evolved_display_name']))
                                <div class="mt-0.5 break-words text-[11px] font-black text-amber-700">→ [{{ $candidate['to_rank'] ?? '-' }}] {{ $sourceOption['evolved_display_name'] }}</div>
                            @endif
                            @if((int) ($sourceOption['enhance_level'] ?? 0) > 0)
                                <div class="mt-0.5 text-[10px] font-black text-emerald-700">強化値: +{{ (int) $sourceOption['enhance_level'] }} → +{{ (int) ($sourceOption['inherited_enhance_level'] ?? 0) }} を引き継ぐ</div>
                            @endif
                            <div class="mt-1 flex flex-wrap gap-1">
                                @if($sourceOption['is_locked'])
                                    <span class="text-sm leading-none text-yellow-500" title="保護中" aria-label="保護中">★</span>
                                @else
                                    <span class="text-sm leading-none text-slate-300" title="保護なし" aria-label="保護なし">☆</span>
                                @endif
                                @if($sourceOption['has_affix'])
                                    <span class="rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-bold text-indigo-700">銘付き</span>
                                @endif
                            </div>
                            @if(!empty($sourceOption['affix_lines']))
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @foreach($sourceOption['affix_lines'] as $affixLine)
                                        <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-bold text-indigo-600">{{ $affixLine }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="mt-1 flex shrink-0 items-center gap-1 sm:mt-0">
                            <form action="{{ route('equipment.sell', $sourceOption['id']) }}" method="POST" data-smith-sell-form data-source-item-id="{{ (int) $sourceOption['id'] }}">
                                @csrf
                                <input type="hidden" name="return_to_smith" value="1">
                                <button type="button"
                                    @click="confirmSell = true"
                                    @if(!$sourceCanSell) disabled title="{{ $sourceSellDisabledTitle }}" @endif
                                    class="h-8 rounded px-3 text-xs font-black transition active:scale-95 {{ $sourceCanSell ? 'bg-orange-600 text-white hover:bg-orange-700' : 'bg-slate-100 text-slate-400 cursor-not-allowed' }}">
                                    {{ $sourceSellPrice > 0 ? '売却' : '売却不可' }}
                                </button>
                                @if($sourceCanSell)
                                    <div x-show="confirmSell" x-cloak @keydown.escape.window="confirmSell = false" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4 py-6">
                                        <div class="w-full max-w-sm rounded-xl border border-orange-200 bg-white p-4 shadow-2xl" @click.outside="confirmSell = false">
                                            <div class="text-sm font-extrabold text-slate-900">装備を売却しますか？</div>
                                            <div class="mt-2 rounded-lg bg-orange-50 px-3 py-2 text-sm text-slate-700">
                                                <div class="font-bold text-slate-900">{{ $sourceOption['display_name'] }}</div>
                                                <div class="mt-1 text-orange-700">売却額: <span class="font-extrabold">{{ number_format($sourceSellPrice) }}G</span></div>
                                            </div>
                                            <p class="mt-3 text-xs leading-relaxed text-slate-500">
                                                売却した装備は所持品からなくなります。よろしければ確定してください。
                                            </p>
                                            <div class="mt-4 flex items-center justify-end gap-2">
                                                <button type="button" @click="confirmSell = false" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50">
                                                    キャンセル
                                                </button>
                                                <button type="submit" data-smith-sell-submit class="rounded-lg bg-orange-600 px-3 py-2 text-xs font-extrabold text-white shadow-sm hover:bg-orange-700">
                                                    売却する
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </form>
                            @if($canEvolve)
                                <button
                                    type="button"
                                    class="h-8 rounded bg-amber-600 px-3 text-xs font-bold text-white shadow-sm transition hover:bg-amber-700 active:scale-[0.99]"
                                    @click="selected = {
                                        recipeType: @js($candidate['equipment_type']),
                                        recipeId: @js($candidate['recipe_id']),
                                        sourceCharacterItemId: {{ (int) $sourceOption['id'] }},
                                        fromName: @js($sourceOption['display_name']),
                                        toName: @js($sourceOption['evolved_display_name'] ?? $candidate['to_name']),
                                        enhancementLabel: @js((int) ($sourceOption['enhance_level'] ?? 0) > 0 ? '強化値: +' . (int) $sourceOption['enhance_level'] . ' → +' . (int) ($sourceOption['inherited_enhance_level'] ?? 0) . ' を引き継ぎます。' : null),
                                        goldCost: @js(number_format((int) ($candidate['gold_cost'] ?? 0)) . 'G')
                                    }; modalOpen = true"
                                >この装備で合成</button>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- 必要素材：コスト情報をひとつの箱にまとめる --}}
    <div class="space-y-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2 py-1.5">
        <div class="text-[10px] font-black text-slate-500">必要素材</div>

        @if($requiredBase > 1 || !$baseOk)
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-bold text-slate-600">素材となる装備の所持数</span>
                <span class="font-mono text-xs font-bold shrink-0 {{ $baseOk ? 'text-emerald-600' : 'text-red-600' }}">{{ $candidate['owned_equipment_count'] }}&thinsp;/&thinsp;{{ $requiredBase }}</span>
            </div>
        @endif

        @if((int) ($stone['required'] ?? 0) > 0)
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-1.5 min-w-0">
                    <span class="text-xs font-bold text-slate-600 truncate">{{ $stone['name'] ?? '装備の欠片' }}</span>
                    @if(!empty($stone['sources']))
                        <button type="button"
                            @click.stop="srcPopup = { open: true, sources: @js($stone['sources']), label: @js($stone['name'] ?? '装備の欠片'), required: {{ (int) ($stone['required'] ?? 0) }} }"
                            class="shrink-0 text-[10px] font-bold text-amber-400 underline decoration-dotted hover:text-amber-600">入手場所</button>
                    @endif
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <span class="font-mono text-xs font-bold {{ $canUseStone ? 'text-emerald-600' : 'text-red-600' }}">{{ $stone['owned'] ?? 0 }}&thinsp;/&thinsp;{{ $stone['required'] ?? 0 }}</span>
                    <span class="text-[10px] font-black {{ $canUseStone ? 'text-emerald-600' : 'text-red-500' }}">{{ $canUseStone ? 'OK' : '不足' }}</span>
                </div>
            </div>
        @endif

        @foreach($candidate['required_materials'] as $material)
            @php $materialOk = $material['owned'] >= $material['required']; @endphp
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-1.5 min-w-0">
                    @php $materialIcon = $material['icon_image'] ?? \App\Models\Material::iconImagePathFor($material['material_code'] ?? null, $material['name'] ?? null); @endphp
                    @if($materialIcon)
                        <img src="{{ asset($materialIcon) }}" alt="" class="h-4 w-4 shrink-0 object-contain">
                    @endif
                    <span class="text-xs font-bold text-slate-600 truncate">{{ $material['name'] }}@if(!$material['is_consumed'])<span class="text-[9px] text-slate-400 ml-0.5">消費なし</span>@endif</span>
                    @if(!empty($material['sources']))
                        <button type="button"
                            @click.stop="srcPopup = { open: true, sources: @js($material['sources']), label: @js($material['name']), required: {{ (int) ($material['required'] ?? 0) }} }"
                            class="shrink-0 text-[10px] font-bold text-amber-400 underline decoration-dotted hover:text-amber-600">入手場所</button>
                    @endif
                </div>
                <div class="flex items-center gap-1.5 shrink-0">
                    <span class="font-mono text-xs font-bold {{ $materialOk ? 'text-emerald-600' : 'text-red-600' }}">{{ $material['owned'] }}&thinsp;/&thinsp;{{ $material['required'] }}</span>
                    @if($material['missing'] > 0)
                        <span class="rounded bg-red-50 px-1 py-0.5 text-[10px] font-black text-red-600">-{{ $material['missing'] }}</span>
                    @endif
                </div>
            </div>
        @endforeach

        <div class="flex items-center justify-between gap-2 border-t border-slate-200 pt-1.5">
            <span class="text-xs font-bold text-slate-600 truncate">合成費用</span>
            <div class="flex items-center gap-1.5 shrink-0">
                <span class="font-mono text-xs font-bold {{ $goldOk ? 'text-emerald-600' : 'text-red-600' }}">{{ number_format((int) ($candidate['gold_cost'] ?? 0)) }}G</span>
                @if(!$goldOk)
                    <span class="text-[10px] font-bold text-red-400">不足</span>
                @endif
            </div>
        </div>
    </div>

    {{-- ボタン / 不足理由 --}}
    @if($canEvolve)
        @if(empty($sourceOptions))
            @php
                $toActionName = $candidate['to_preview_display_name'] ?? $candidate['to_name'];
            @endphp
            <button
                type="button"
                class="w-full h-9 bg-amber-600 hover:bg-amber-700 active:scale-[0.99] text-white text-sm font-bold rounded-lg shadow-sm transition"
                @click="selected = {
                    recipeType: @js($candidate['equipment_type']),
                    recipeId: @js($candidate['recipe_id']),
                    sourceCharacterItemId: null,
                    fromName: @js($candidate['from_name']),
                    toName: @js($toActionName),
                    enhancementLabel: null,
                    goldCost: @js(number_format((int) ($candidate['gold_cost'] ?? 0)) . 'G')
                }; modalOpen = true"
            >合成する</button>
        @elseif($singleSourceOption)
            <button
                type="button"
                class="w-full h-9 bg-amber-600 hover:bg-amber-700 active:scale-[0.99] text-white text-sm font-bold rounded-lg shadow-sm transition"
                @click="selected = {
                    recipeType: @js($candidate['equipment_type']),
                    recipeId: @js($candidate['recipe_id']),
                    sourceCharacterItemId: {{ (int) $singleSourceOption['id'] }},
                    fromName: @js($singleSourceOption['display_name']),
                    toName: @js($singleSourceOption['evolved_display_name'] ?? $candidate['to_name']),
                    enhancementLabel: @js((int) ($singleSourceOption['enhance_level'] ?? 0) > 0 ? '強化値: +' . (int) $singleSourceOption['enhance_level'] . ' → +' . (int) ($singleSourceOption['inherited_enhance_level'] ?? 0) . ' を引き継ぎます。' : null),
                    goldCost: @js(number_format((int) ($candidate['gold_cost'] ?? 0)) . 'G')
                }; modalOpen = true"
            >合成する</button>
        @endif
    @else
        <div class="text-xs font-bold text-slate-400 text-center py-1 border-t border-slate-100">
            {{ $candidate['unavailable_reason'] }}
        </div>
    @endif
</div>
