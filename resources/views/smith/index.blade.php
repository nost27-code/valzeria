@php
    $headerIconImage = 'images/icon/icon_034.webp';
    $bgImage = 'images/card_bg/shop_blacksmith.webp';
    $title = '合成屋 (' . ($currentCity->name ?? '冒険都市ヴァルゼリア') . ')';

    // 進化元 × タイプでグループ化
    $grouped = collect($evolutionCandidates)->groupBy(function ($c) {
        return ($c['equipment_type'] ?? '') . '::' . ($c['from_equipment_id'] ?? $c['from_name']) . '::' . ($c['from_rank'] ?? '');
    })->values();

    $candidateCount = count($evolutionCandidates);
    $evolvableCount = collect($evolutionCandidates)->where('can_evolve', true)->count();
@endphp
<x-layouts.facility :title="$title" :headerIconImage="$headerIconImage" :bgImage="$bgImage">
    <div class="w-full mx-auto pb-10" x-data="{ modalOpen: false, selected: null, typeFilter: 'all', statusFilter: 'all', srcPopup: { open: false, sources: [], label: '', required: 0 } }">
        <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-[#d4af37]/50">
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
                <div>
                    <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                        <img src="{{ asset('images/icon/icon_034.webp') }}" alt="" class="w-7 h-7 object-contain"> 進化合成
                    </h2>
                    <p class="mt-2 text-sm text-slate-600 leading-relaxed">
                        この装備をベースに、素材を使って上位装備へ進化します。
                    </p>
                    <p class="mt-2 text-sm font-black text-amber-700">
                        所持Gold {{ number_format((int) ($character->money ?? 0)) }}G
                    </p>
                </div>
                <div class="text-xs sm:text-sm text-slate-600 bg-slate-100 border border-slate-200 px-3 py-2 rounded">
                    候補: <span class="font-bold text-slate-900">{{ $candidateCount }}</span> 件
                    <span class="mx-1 text-slate-300">/</span>
                    進化可能: <span class="font-bold text-emerald-700">{{ $evolvableCount }}</span> 件
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 mb-5">
                <a href="{{ route('blacksmith.index') }}" class="text-center rounded-lg border border-slate-300 bg-slate-50 px-2 py-3 text-xs sm:text-sm font-bold text-slate-700 transition hover:bg-slate-100">
                    武器強化
                </a>
                <a href="{{ route('smith.index') }}" class="text-center rounded-lg bg-slate-900 px-2 py-3 text-xs sm:text-sm font-bold text-white shadow-sm">
                    進化合成
                </a>
            </div>

            <div class="grid grid-cols-4 gap-2 mb-5">
                <button type="button" @click="typeFilter = 'all'" :class="typeFilter === 'all' ? 'bg-amber-600 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-2 py-2.5 text-xs sm:text-sm font-bold transition">
                    全て
                </button>
                <button type="button" @click="typeFilter = 'weapon'" :class="typeFilter === 'weapon' ? 'bg-amber-600 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-2 py-2.5 text-xs sm:text-sm font-bold transition">
                    武器
                </button>
                <button type="button" @click="typeFilter = 'armor'" :class="typeFilter === 'armor' ? 'bg-amber-600 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-2 py-2.5 text-xs sm:text-sm font-bold transition">
                    防具
                </button>
                <button type="button" @click="typeFilter = 'accessory'" :class="typeFilter === 'accessory' ? 'bg-amber-600 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-2 py-2.5 text-xs sm:text-sm font-bold transition">
                    装飾品
                </button>
            </div>

            <div class="grid grid-cols-2 gap-2 mb-5">
                <button type="button" @click="statusFilter = 'all'" :class="statusFilter === 'all' ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-2 py-2.5 text-xs sm:text-sm font-bold transition">
                    全候補
                </button>
                <button type="button" @click="statusFilter = 'ready'" :class="statusFilter === 'ready' ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-700'" class="rounded-lg px-2 py-2.5 text-xs sm:text-sm font-bold transition">
                    進化可能のみ
                </button>
            </div>

            @if(session('status'))
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded mb-4 font-bold">
                    合成成功！ {{ session('status') }}
                </div>
            @endif
            @if(session('error'))
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4 font-bold">
                    {{ session('error') }}
                </div>
            @endif

            @if($candidateCount === 0)
                <div class="text-center py-10 text-slate-500">
                    <p>進化できる装備はまだありません。</p>
                </div>
            @else
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-2.5">
                    @foreach($grouped as $group)
                        @php
                            $paths = $group->values()->all();
                            $first = $paths[0];
                            $groupType = $first['equipment_type'];
                            $groupHasEvolvable = collect($paths)->contains('can_evolve', true);
                            $pathCount = count($paths);
                            $multiPath = $pathCount > 1;
                            $groupKey = 'evo_' . $groupType . '_' . ($first['from_equipment_id'] ?? md5($first['from_name'])) . '_' . ($first['from_rank'] ?? '');
                        @endphp
                        <div
                            x-show="(typeFilter === 'all' || typeFilter === '{{ $groupType }}') && (statusFilter === 'all' || {{ $groupHasEvolvable ? 'true' : 'false' }})"
                            x-data="{
                                activeTab: -1,
                                storageKey: '{{ $groupKey }}',
                                init() {
                                    const saved = localStorage.getItem(this.storageKey);
                                    this.activeTab = saved !== null ? parseInt(saved) : -1;
                                    this.$watch('activeTab', val => localStorage.setItem(this.storageKey, val));
                                }
                            }"
                            class="rounded-lg border {{ $groupHasEvolvable ? 'border-emerald-200 bg-white' : (collect($paths)->where('sort_status', 1)->isNotEmpty() ? 'border-amber-200 bg-amber-50/30' : 'border-slate-200 bg-slate-50') }} flex flex-col"
                        >
                            {{-- グループヘッダー --}}
                            <div class="px-3 pt-2.5 pb-2">
                                <div class="flex flex-wrap items-center gap-1.5 mb-1">
                                    <span class="inline-flex items-center rounded bg-slate-800 px-1.5 py-0.5 text-[10px] font-bold text-white leading-none">{{ $first['equipment_type_label'] }}</span>
                                    <span class="inline-flex items-center rounded border border-[#d4af37]/60 bg-amber-50 px-1.5 py-0.5 text-[10px] font-bold text-amber-700 leading-none">{{ $first['from_rank'] ?? '-' }}→{{ $first['to_rank'] ?? '-' }}</span>
                                    @if($first['has_equipped_source'] ?? false)
                                        <span class="inline-flex items-center rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-800 leading-none">装備中</span>
                                    @endif
                                    @if(!($first['can_equip_source'] ?? true))
                                        <span class="inline-flex items-center rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-bold text-slate-600 leading-none">現職不可</span>
                                    @endif
                                    @if($groupHasEvolvable)
                                        <span class="inline-flex items-center rounded bg-emerald-600 px-1.5 py-0.5 text-[10px] font-bold text-white leading-none">進化可能</span>
                                    @endif
                                    @if($multiPath)
                                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-700 leading-none">{{ $pathCount }}ルートで分岐</span>
                                    @endif
                                </div>
                                <div class="text-base font-extrabold text-slate-900 leading-tight">
                                    [{{ $first['from_rank'] ?? '-' }}] {{ $first['from_name'] }}
                                </div>
                            </div>

                            {{-- 進化先アコーディオン --}}
                            @if($multiPath)
                                <div class="border-t border-slate-100">
                                    <div class="px-3 py-1.5 bg-slate-50 border-b border-slate-100">
                                        <span class="text-[10px] font-bold text-slate-500">進化先を選ぶ（タップで詳細）</span>
                                    </div>
                                @foreach($paths as $pi => $candidate)
                                    @php
                                        $canEvolve = $candidate['can_evolve'];
                                        $toDisplayName = $candidate['to_display_name'] ?? $candidate['to_name'];
                                        if (mb_strrchr($toDisplayName, '・') !== false) {
                                            $shortName = ltrim(mb_strrchr($toDisplayName, '・'), '・');
                                        } elseif (mb_strpos($toDisplayName, '未鑑定の') === 0) {
                                            $shortName = mb_substr($toDisplayName, mb_strlen('未鑑定の'));
                                        } else {
                                            $shortName = $toDisplayName;
                                        }
                                        if (mb_strlen($shortName) > 15) { $shortName = mb_substr($shortName, 0, 14) . '…'; }
                                        $stone = $candidate['evolution_stone_requirement'] ?? null;
                                        $canUseStone = $candidate['can_use_evolution_stone'] ?? false;
                                    @endphp

                                    {{-- アコーディオン行ヘッダー --}}
                                    <div
                                        class="border-b border-slate-100 last:border-b-0"
                                        :class="activeTab === {{ $pi }} ? '{{ $canEvolve ? 'bg-emerald-50' : 'bg-amber-50' }}' : 'bg-white hover:bg-slate-50'"
                                    >
                                        <button
                                            type="button"
                                            class="w-full flex items-center justify-between gap-2 px-3 py-2.5 text-left"
                                            @click="activeTab = (activeTab === {{ $pi }}) ? -1 : {{ $pi }}"
                                        >
                                            <div class="flex items-center gap-2 min-w-0">
                                                <span
                                                    class="shrink-0 w-5 h-5 rounded-full text-[10px] font-bold flex items-center justify-center leading-none"
                                                    :class="activeTab === {{ $pi }} ? '{{ $canEvolve ? 'bg-emerald-600 text-white' : 'bg-amber-600 text-white' }}' : 'bg-slate-200 text-slate-600'"
                                                >{{ $pi + 1 }}</span>
                                                <span class="text-xs font-bold truncate {{ $canEvolve ? 'text-emerald-800' : 'text-slate-700' }}">{{ $shortName }}</span>
                                                @if($canEvolve)
                                                    <span class="shrink-0 text-[10px] font-bold text-emerald-600">進化可</span>
                                                @endif
                                            </div>
                                            <div class="flex items-center gap-2 shrink-0">
                                                @if(!$canEvolve)
                                                    <span class="text-[10px] font-bold text-red-400">{{ $candidate['unavailable_reason'] }}</span>
                                                @endif
                                                <span class="text-slate-400 text-xs transition-transform" :class="activeTab === {{ $pi }} ? 'rotate-180' : ''">▼</span>
                                            </div>
                                        </button>

                                        {{-- アコーディオン展開コンテンツ --}}
                                        <div x-show="activeTab === {{ $pi }}" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="border-t border-slate-100 px-3 pb-3 pt-2 flex flex-col gap-2">
                                            <div class="text-sm font-bold text-amber-700 leading-tight">→ [{{ $candidate['to_rank'] ?? '-' }}] {{ $toDisplayName }}</div>
                                            @include('smith._evolution_detail', ['candidate' => $candidate, 'stone' => $stone, 'canUseStone' => $canUseStone, 'canEvolve' => $canEvolve])
                                        </div>
                                    </div>
                                @endforeach
                                </div>

                            @else
                                {{-- 分岐なし: 従来通りシンプル表示 --}}
                                @php
                                    $candidate = $paths[0];
                                    $canEvolve = $candidate['can_evolve'];
                                    $stone = $candidate['evolution_stone_requirement'] ?? null;
                                    $canUseStone = $candidate['can_use_evolution_stone'] ?? false;
                                    $toDisplayName = $candidate['to_display_name'] ?? $candidate['to_name'];
                                @endphp
                                <div class="border-t border-slate-100 px-3 pb-3 pt-2 flex flex-col gap-2">
                                    <div class="text-sm font-bold text-amber-700 leading-tight">→ [{{ $candidate['to_rank'] ?? '-' }}] {{ $toDisplayName }}</div>
                                    @include('smith._evolution_detail', ['candidate' => $candidate, 'stone' => $stone, 'canUseStone' => $canUseStone, 'canEvolve' => $canEvolve])
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- 入手場所ポップアップ --}}
        <div x-show="srcPopup.open" style="display:none;" @click="srcPopup.open = false"
             class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4">
            <div @click.stop class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100">
                    <span class="text-sm font-black text-slate-800" x-text="srcPopup.label + ' の入手場所'"></span>
                    <button @click="srcPopup.open = false" class="w-7 h-7 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 font-black text-base hover:bg-slate-200">✕</button>
                </div>
                <div class="max-h-[55vh] overflow-y-auto px-4 py-3 flex flex-wrap gap-1.5">
                    <template x-for="src in srcPopup.sources" :key="typeof src === 'object' ? src.label : src">
                        <template x-if="typeof src === 'object' && src.url">
                            <a :href="src.url + (src.url.includes('?') ? '&' : '?') + 'required=' + encodeURIComponent(srcPopup.required || 0)"
                               class="rounded-lg border border-amber-300 bg-amber-50 px-2.5 py-1 text-xs font-black text-amber-800 shadow-sm transition hover:bg-amber-100 active:scale-95"
                               x-text="src.label"></a>
                        </template>
                    </template>
                    <template x-for="src in srcPopup.sources" :key="'plain-' + (typeof src === 'object' ? src.label : src)">
                        <template x-if="typeof src !== 'object' || !src.url">
                            <span class="rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-bold text-slate-700" x-text="typeof src === 'object' ? src.label : src"></span>
                        </template>
                    </template>
                </div>
                <div class="border-t border-slate-100 px-4 py-3 text-center">
                    <button type="button" @click="srcPopup.open = false" class="text-xs font-black text-slate-500 underline decoration-dotted underline-offset-4 hover:text-slate-800">
                        閉じる
                    </button>
                </div>
            </div>
        </div>

        {{-- 合成確認モーダル --}}
        <div x-show="modalOpen" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center px-4 py-6 text-center">
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
                    <form method="POST" action="{{ route('smith.craft') }}" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
                        @csrf
                        <input type="hidden" name="recipe_type" :value="selected?.recipeType">
                        <input type="hidden" name="recipe_id" :value="selected?.recipeId">

                        <div class="border-b border-slate-200 px-5 py-5">
                            <h3 class="text-lg font-extrabold text-slate-900" id="modal-title">合成の確認</h3>
                            <p class="mt-3 text-sm text-slate-600 leading-relaxed">
                                <span class="font-bold text-slate-900" x-text="selected?.fromName"></span> を素材にして、<br>
                                <span class="font-bold text-amber-700" x-text="selected?.toName"></span> を作成します。
                            </p>
                            <p class="mt-3 rounded bg-amber-50 px-3 py-2 text-sm font-bold text-amber-800">
                                合成費用: <span x-text="selected?.goldCost"></span>
                            </p>
                            <p class="mt-3 text-xs text-slate-500">
                                装備中の進化元はロック中でも使用でき、進化後の装備へ自動で付け替えます。+1以上の同名装備も進化元として使用できます。合成後の装備は +0 です。
                            </p>
                        </div>
                        <div class="bg-slate-50 px-5 py-4 sm:flex sm:flex-row-reverse sm:gap-3">
                            <button type="submit" :disabled="submitting" class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-amber-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-amber-700 disabled:cursor-wait disabled:opacity-70 sm:w-auto">
                                <x-loading-spinner x-show="submitting" style="display: none;" />
                                <span x-show="!submitting">合成実行</span>
                                <span x-show="submitting" style="display: none;">合成中...</span>
                            </button>
                            <button type="button" :disabled="submitting" @click="modalOpen = false" class="mt-3 inline-flex w-full justify-center rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60 sm:mt-0 sm:w-auto">
                                キャンセル
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-layouts.facility>
