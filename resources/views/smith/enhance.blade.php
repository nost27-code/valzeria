@php
    $headerIcon = '🔨';
    $bgImage = 'images/card_bg/shop_blacksmith.webp';
    $title = '鍛冶屋 (' . ($currentCity->name ?? '冒険都市ヴァルゼリア') . ')';
    $typeTabs = [
        'weapon' => '武器',
        'armor' => '防具',
        'accessory' => '装飾品',
    ];
    $statLabels = [
        'hp' => 'HP',
        'mp' => 'SP',
        'str' => '攻撃',
        'def' => '防御',
        'agi' => '敏捷',
        'mag' => '魔力',
        'spr' => '精神',
        'luk' => '運',
    ];
@endphp

<x-layouts.facility :title="$title" :headerIcon="$headerIcon" :bgImage="$bgImage">
    <div
        class="w-full mx-auto pb-10"
        x-data="{
            helpOpen: false,
            init() {
                const params = new URLSearchParams(window.location.search);
                if (params.has('sort')) {
                    return;
                }

                try {
                    const savedSort = window.localStorage.getItem('valzeria.blacksmith.enhance.sort');
                    if (['rank_desc', 'enhance_asc', 'enhance_desc', 'name_asc'].includes(savedSort)) {
                        params.set('sort', savedSort);
                        window.location.replace(`${window.location.pathname}?${params.toString()}`);
                    }
                } catch (error) {
                    // 保存領域が利用できない環境では、おすすめ順を表示する。
                }
            },
        }"
    >
        <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-[#d4af37]/50">
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
                <div>
                    <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                        <span class="text-2xl">🔨</span> 装備強化
                    </h2>
                    <p class="mt-2 text-sm text-slate-600 leading-relaxed">
                        武器は到達強化値ごとの固定素材と固定Goldを使い、防具・装飾品は従来のレシピで、装備ランクごとの上限まで強化します（+5〜+30）。輝石は使用しません。
                    </p>
                    <p class="mt-2 text-sm font-black text-amber-700">
                        手持ち {{ number_format((int) ($goldSummary['hand_gold'] ?? 0)) }}G / 銀行 {{ number_format((int) ($goldSummary['bank_gold'] ?? 0)) }}G
                    </p>
                </div>
                <div class="flex items-center gap-2 self-end sm:self-start">
                    <a href="{{ route('blacksmith.help') }}" @click.prevent="helpOpen = true" class="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2.5 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100" title="装備強化の解説">
                        <span class="text-sm leading-none">?</span> 解説
                    </a>
                    <div class="rounded border border-slate-200 bg-slate-100 px-3 py-2 text-xs text-slate-600 sm:text-sm">
                        候補: <span class="font-bold text-slate-900">{{ $candidateCount }}</span> 件
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-2 mb-5">
                <a href="{{ route('blacksmith.index') }}" class="text-center rounded-lg bg-slate-900 px-2 py-3 text-xs sm:text-sm font-bold text-white shadow-sm">
                    装備強化
                </a>
                <a href="{{ route('blacksmith.traits.index') }}" class="text-center rounded-lg border border-slate-300 bg-slate-50 px-2 py-3 text-xs sm:text-sm font-bold text-slate-700 transition hover:bg-slate-100">
                    銘・特攻・耐性を鍛える
                </a>
                <a href="{{ route('smith.index') }}" class="text-center rounded-lg border border-slate-300 bg-slate-50 px-2 py-3 text-xs sm:text-sm font-bold text-slate-700 transition hover:bg-slate-100">
                    進化合成
                </a>
            </div>

            <div class="grid grid-cols-3 gap-2 mb-5 rounded-lg border border-slate-200 bg-slate-50 p-1">
                @foreach($typeTabs as $type => $label)
                    <a
                        href="{{ route('blacksmith.index', ['type' => $type, 'sort' => $enhanceSort]) }}"
                        class="rounded-md border px-2 py-2.5 text-center text-xs font-black transition sm:text-sm {{ $initialType === $type ? 'border-slate-300 bg-white text-slate-900 shadow-sm' : 'border-transparent text-slate-500' }}"
                    >
                        {{ $label }}
                        <span class="ml-1 font-mono text-[11px] opacity-70">{{ $typeCounts[$type] }}</span>
                    </a>
                @endforeach
            </div>

            @if($candidateCount > 1)
                <form method="GET" action="{{ route('blacksmith.index') }}" class="mb-4 flex items-center justify-end gap-2">
                    <input type="hidden" name="type" value="{{ $initialType }}">
                    <label for="enhance-sort" class="text-xs font-bold text-slate-600">並び順</label>
                    <select
                        id="enhance-sort"
                        name="sort"
                        onchange="try { window.localStorage.setItem('valzeria.blacksmith.enhance.sort', this.value); } catch (error) {} this.form.submit()"
                        class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 shadow-sm"
                    >
                        <option value="recommended" @selected($enhanceSort === 'recommended')>おすすめ順</option>
                        <option value="rank_desc" @selected($enhanceSort === 'rank_desc')>ランクが高い順</option>
                        <option value="enhance_asc" @selected($enhanceSort === 'enhance_asc')>強化値が低い順</option>
                        <option value="enhance_desc" @selected($enhanceSort === 'enhance_desc')>強化値が高い順</option>
                        <option value="name_asc" @selected($enhanceSort === 'name_asc')>名前順</option>
                    </select>
                </form>
            @endif

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

            @if($candidateCount === 0)
                <div class="text-center py-10 text-slate-500">
                    <p>強化できる装備を所持していません。</p>
                </div>
            @else
                <div class="space-y-3">
                    @if(($typeCounts[$initialType] ?? 0) === 0)
                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm font-bold text-slate-500">
                            強化できる{{ $typeTabs[$initialType] ?? '装備' }}を所持していません。
                        </div>
                    @endif

                    @foreach($enhancementCandidates as $candidate)
                        @php
                            $level = (int) $candidate['current_level'];
                            $nextLevel = (int) $candidate['next_level'];
                            $maxLevel = (int) $candidate['max_level'];
                            $cardClass = $candidate['can_enhance']
                                ? 'border-emerald-300 bg-emerald-50/40'
                                : ($level >= $maxLevel ? 'border-amber-200 bg-amber-50/40' : 'border-slate-200 bg-slate-50');
                        @endphp
                        <div
                            class="rounded-lg border {{ $cardClass }} p-4"
                        >
                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2 mb-1">
                                        <span class="inline-flex items-center rounded bg-slate-800 px-2 py-0.5 text-xs font-bold text-white">
                                            {{ $candidate['type_label'] }}
                                        </span>
                                        <span class="inline-flex items-center rounded border border-[#d4af37]/60 bg-white px-2 py-0.5 text-xs font-bold text-amber-700">
                                            {{ $candidate['rank'] }}
                                        </span>
                                        <span class="inline-flex items-center rounded bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-700">
                                            +{{ $level }} / +{{ $maxLevel }}
                                        </span>
                                        @if($level < $maxLevel)
                                            <span class="inline-flex items-center rounded bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-800">
                                                次: +{{ $nextLevel }}
                                            </span>
                                        @endif
                                        @if($candidate['is_equipped'])
                                            <span class="inline-flex items-center rounded bg-amber-200 px-2 py-0.5 text-xs font-bold text-amber-800">
                                                装備中
                                            </span>
                                        @endif
                                        @if($candidate['is_locked'])
                                            <span class="inline-flex items-center rounded bg-yellow-100 px-2 py-0.5 text-xs font-bold text-yellow-800 border border-yellow-200">
                                                ★ 保護中
                                            </span>
                                        @endif
                                    </div>
                                    <h3 class="text-lg font-extrabold text-slate-900">
                                        [{{ $candidate['rank'] }}] {{ $candidate['display_name_without_rank'] ?? $candidate['name'] }}
                                    </h3>
                                    <p class="text-xs text-slate-500 mt-1">
                                        カテゴリ: {{ $candidate['category'] }}
                                    </p>
                                </div>

                                <div class="sm:w-40 shrink-0">
                                    @if($candidate['can_enhance'])
                                        @php
                                            $enhanceConfirmText = $candidate['name'] . " を +{$nextLevel} に強化しますか？";
                                            if (($candidate['bank_gold_used'] ?? 0) > 0) {
                                                $enhanceConfirmText .= "\n\n手持ちから " . number_format((int) ($candidate['hand_gold_used'] ?? 0)) . 'G';
                                                $enhanceConfirmText .= "\n銀行預金から " . number_format((int) $candidate['bank_gold_used']) . 'G';
                                                $enhanceConfirmText .= "\n\n不足分だけ銀行預金を使用します。";
                                            }
                                        @endphp
                                        <form method="POST" action="{{ route('blacksmith.enhance', $candidate['character_item']) }}" onsubmit='return confirm(@js($enhanceConfirmText));'>
                                            @csrf
                                            <input type="hidden" name="use_bank" value="{{ ($candidate['bank_gold_used'] ?? 0) > 0 ? 1 : 0 }}">
                                            <button type="submit" class="w-full rounded-lg bg-amber-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-amber-700 active:scale-[0.99]">
                                                +{{ $nextLevel }}へ強化
                                            </button>
                                        </form>
                                    @else
                                        <div class="rounded-lg border border-slate-200 bg-white px-3 py-3 text-center text-sm font-bold text-slate-500">
                                            {{ $candidate['unavailable_reason'] }}
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div class="rounded border border-slate-200 bg-white/80 px-3 py-3">
                                    <div class="text-xs font-bold text-slate-500 mb-2">必要素材</div>
                                    @if(empty($candidate['requirements']))
                                        <div class="text-sm font-bold text-slate-600">これ以上強化できません。</div>
                                    @else
                                        <div class="space-y-2">
                                            @foreach($candidate['requirements'] as $material)
                                                <div class="flex items-center justify-between gap-3 text-sm">
                                                    <span class="flex min-w-0 items-center gap-1.5 font-bold text-slate-700">
                                                        @php $materialIcon = $material['icon_image'] ?? \App\Models\Material::iconImagePathFor($material['material_code'] ?? null, $material['name'] ?? null); @endphp
                                                        @if($materialIcon)
                                                            <img src="{{ asset($materialIcon) }}" alt="" class="h-5 w-5 shrink-0 object-contain">
                                                        @endif
                                                        <span class="truncate">{{ $material['name'] }}</span>
                                                    </span>
                                                    <span class="font-mono font-bold {{ $material['missing'] === 0 ? 'text-amber-700' : 'text-red-600' }}">
                                                        {{ $material['owned'] }} / {{ $material['required'] }}
                                                    </span>
                                                </div>
                                                @if($material['missing'] > 0)
                                                    <p class="text-right text-xs font-bold text-red-600">{{ $material['name'] }}が{{ $material['missing'] }}個不足しています。</p>
                                                @endif
                                            @endforeach
                                            @if(($candidate['gold_cost'] ?? 0) > 0)
                                                <div class="flex items-center justify-between gap-3 border-t border-slate-100 pt-2 text-sm">
                                                    <span class="font-bold text-slate-700">必要Gold</span>
                                                    <span class="font-mono font-bold {{ ($candidate['missing_gold'] ?? 0) === 0 ? 'text-amber-700' : 'text-red-600' }}">
                                                        {{ number_format($candidate['gold_cost'] ?? 0) }}G
                                                    </span>
                                                </div>
                                                <div class="flex items-center justify-between gap-3 text-sm">
                                                    <span class="font-bold text-slate-700">利用可能Gold</span>
                                                    <span class="font-mono font-bold {{ ($candidate['missing_gold'] ?? 0) === 0 ? 'text-amber-700' : 'text-red-600' }}">
                                                        {{ number_format($candidate['owned_gold'] ?? 0) }}G
                                                    </span>
                                                </div>
                                                @if(($candidate['bank_gold_used'] ?? 0) > 0 && ($candidate['missing_gold'] ?? 0) === 0)
                                                    <p class="text-right text-xs font-bold text-amber-700">
                                                        手持ちから{{ number_format((int) ($candidate['hand_gold_used'] ?? 0)) }}G / 銀行預金から{{ number_format((int) $candidate['bank_gold_used']) }}G
                                                    </p>
                                                @endif
                                                @if(($candidate['missing_gold'] ?? 0) > 0)
                                                    <p class="text-right text-xs font-bold text-red-600">Goldが{{ number_format($candidate['missing_gold']) }}G不足しています。</p>
                                                @endif
                                            @endif
                                        </div>
                                    @endif
                                </div>

                                <div class="rounded border border-slate-200 bg-white/80 px-3 py-3">
                                    <div class="text-xs font-bold text-slate-500 mb-2">強化後の性能</div>
                                    @if(empty($candidate['stats']))
                                        <div class="text-sm font-bold text-slate-600">能力補正なし</div>
                                    @elseif($level >= $maxLevel)
                                        <div class="flex flex-wrap gap-2">
                                            @foreach($candidate['stats'] as $key => $stat)
                                                <span class="inline-flex items-center rounded bg-slate-100 px-2 py-1 text-xs font-bold text-slate-700">
                                                    {{ $statLabels[$key] ?? $key }} +{{ $stat['current'] }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="flex flex-wrap gap-2">
                                            @foreach($candidate['stats'] as $key => $stat)
                                                <span class="inline-flex items-center rounded bg-amber-50 border border-amber-100 px-2 py-1 text-xs font-bold text-amber-800">
                                                    {{ $statLabels[$key] ?? $key }} +{{ $stat['current'] }} → +{{ $stat['next'] }}
                                                </span>
                                            @endforeach
                                        </div>
                                        <div class="mt-2 text-xs font-bold text-slate-500">
                                            効果: {{ $candidate['effect'] }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach

                    @if($hasMoreEnhancementCandidates)
                        <div class="pt-2 text-center">
                            <a
                                href="{{ route('blacksmith.index', ['type' => $initialType, 'sort' => $enhanceSort, 'limit' => min($typeCounts[$initialType], $enhanceLimit + 20)]) }}"
                                class="w-full rounded-lg border border-slate-300 bg-white px-4 py-3 text-sm font-black text-slate-700 shadow-sm transition hover:bg-slate-50 sm:w-auto sm:min-w-64"
                            >
                                さらに20件表示（{{ count($enhancementCandidates) }} / {{ $typeCounts[$initialType] }}件）
                            </a>
                        </div>
                    @endif
                </div>
            @endif
            @include('smith.partials.operation-help-modal', ['helpType' => 'enhance'])
        </div>
    </div>
</x-layouts.facility>
