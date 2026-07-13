@php
    $headerIconImage = 'images/icon/icon_034.webp';
    $bgImage = 'images/card_bg/shop_blacksmith.webp';
    $title = '合成屋 (' . ($currentCity->name ?? '冒険都市ヴァルゼリア') . ')';

    // 所持している装備個体ごとに表示する。1個体に複数ルートがある場合だけ、カード内で分岐させる。
    $displayCandidates = collect($evolutionCandidates)->flatMap(function ($candidate) {
        $sourceOptions = $candidate['source_options'] ?? [];
        if (empty($sourceOptions)) {
            return [$candidate];
        }

        return collect($sourceOptions)->map(function ($sourceOption) use ($candidate) {
            $row = $candidate;
            $row['source_options'] = [$sourceOption];
            $row['display_source_item_id'] = $sourceOption['id'] ?? null;
            $row['from_display_name'] = $sourceOption['display_name'] ?? ($candidate['from_display_name'] ?? $candidate['from_name']);
            $row['to_preview_display_name'] = $sourceOption['evolved_display_name'] ?? ($candidate['to_preview_display_name'] ?? null);
            $row['has_equipped_source'] = (bool) ($sourceOption['is_equipped'] ?? false);

            return $row;
        });
    })->values();

    $grouped = $displayCandidates->groupBy(function ($c) {
        return ($c['equipment_type'] ?? '')
            . '::source::' . ($c['display_source_item_id'] ?? 'unknown')
            . '::' . ($c['from_equipment_id'] ?? $c['from_name']);
    })->values();

    $candidateCount = $grouped->count();
    $evolvableCount = $grouped->filter(fn ($group) => collect($group)->contains('can_evolve', true))->count();
@endphp
<x-layouts.facility :title="$title" :headerIconImage="$headerIconImage" :bgImage="$bgImage">
    <div class="w-full mx-auto pb-10" x-data="{ modalOpen: false, helpOpen: false, selected: null, typeFilter: 'all', statusFilter: 'all', srcPopup: { open: false, sources: [], label: '', required: 0 } }">
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
                <div class="flex items-center gap-2 self-end sm:self-start">
                    <a href="{{ route('smith.help') }}" @click.prevent="helpOpen = true" class="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2.5 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100" title="進化合成の解説">
                        <span class="text-sm leading-none">?</span> 解説
                    </a>
                    <div class="rounded border border-slate-200 bg-slate-100 px-3 py-2 text-xs text-slate-600 sm:text-sm">
                        候補: <span class="font-bold text-slate-900">{{ $candidateCount }}</span> 件
                        <span class="mx-1 text-slate-300">/</span>
                        進化可能: <span class="font-bold text-emerald-700">{{ $evolvableCount }}</span> 件
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-2 mb-5">
                <a href="{{ route('blacksmith.index') }}" class="text-center rounded-lg border border-slate-300 bg-slate-50 px-2 py-3 text-xs sm:text-sm font-bold text-slate-700 transition hover:bg-slate-100">
                    武器強化
                </a>
                <a href="{{ route('blacksmith.traits.index') }}" class="text-center rounded-lg border border-slate-300 bg-slate-50 px-2 py-3 text-xs sm:text-sm font-bold text-slate-700 transition hover:bg-slate-100">
                    銘・特攻を鍛える
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

            <div class="mb-5 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-[11px] font-bold text-slate-600">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="inline-flex items-center gap-1">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                        <span>緑枠: 進化可能</span>
                    </span>
                    <span class="inline-flex items-center gap-1">
                        <span class="h-2 w-2 rounded-full bg-orange-500"></span>
                        <span>オレンジ枠: あと少しで進化可能</span>
                    </span>
                    <span class="text-slate-400">素材やGoldの不足が少ない候補です。</span>
                </div>
            </div>

            <div id="smithBulkSellBar" class="hidden fixed inset-x-3 bottom-20 z-40 mx-auto max-w-xl rounded-xl border border-orange-200 bg-orange-50/95 px-3 py-2 shadow-2xl backdrop-blur sm:bottom-6 sm:px-4">
                <div class="flex items-center justify-between gap-2">
                    <div class="text-xs font-bold text-orange-800">
                        売却選択:
                        <span data-smith-bulk-sell-count class="font-black">0</span>件 /
                        <span data-smith-bulk-sell-total class="font-black">0G</span>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <button type="button" data-smith-bulk-sell-clear class="rounded border border-orange-200 bg-white px-2.5 py-1.5 text-xs font-bold text-orange-700 hover:bg-orange-100">
                            選択解除
                        </button>
                        <button type="button" data-smith-bulk-sell-submit data-csrf="{{ csrf_token() }}" class="rounded bg-orange-600 px-3 py-1.5 text-xs font-black text-white shadow-sm hover:bg-orange-700 disabled:cursor-wait disabled:opacity-60">
                            売却
                        </button>
                    </div>
                </div>
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
            <div id="smith-async-message" class="pointer-events-none fixed left-3 right-3 top-3 z-[70] hidden rounded-xl px-4 py-3 text-sm font-black shadow-2xl transition-all duration-200 sm:left-1/2 sm:right-auto sm:w-full sm:max-w-md sm:-translate-x-1/2"></div>

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
                            $groupHasEquipped = collect($paths)->contains('has_equipped_source', true);
                            $groupIsNear = !$groupHasEvolvable && collect($paths)->where('sort_status', 1)->isNotEmpty();
                            $pathCount = count($paths);
                            $multiPath = $pathCount > 1;
                            $groupKey = 'evo_' . $groupType . '_' . ($first['display_source_item_id'] ?? ($first['from_equipment_id'] ?? md5($first['from_name']))) . '_' . ($first['from_rank'] ?? '');
                            $fromEquipmentIcon = ($first['from_item'] ?? null)?->iconImagePath();
                            $firstSourceOptions = $first['source_options'] ?? [];
                            $groupSourceOption = count($firstSourceOptions) === 1 ? $firstSourceOptions[0] : null;
                            $fromDisplayName = count($firstSourceOptions) === 1
                                ? ($firstSourceOptions[0]['display_name'] ?? ($first['from_display_name'] ?? $first['from_name']))
                                : ($first['from_display_name'] ?? $first['from_name']);
                            $fromNameForRankBadge = count($firstSourceOptions) === 1
                                ? ($firstSourceOptions[0]['display_name_without_rank'] ?? $fromDisplayName)
                                : $fromDisplayName;
                            $groupSourceItemId = (int) ($first['display_source_item_id'] ?? ($groupSourceOption['id'] ?? 0));
                            $groupCanSell = (bool) ($groupSourceOption['can_sell'] ?? false);
                            $groupSellPrice = (int) ($groupSourceOption['sell_price'] ?? 0);
                            $groupSellDisabledTitle = ($groupSourceOption['is_equipped'] ?? false)
                                ? '装備中は売却不可'
                                : (($groupSourceOption['is_locked'] ?? false) ? '保護中は売却不可' : '売却不可');
                        @endphp
                        <div
                            data-smith-source-card
                            data-source-item-id="{{ $groupSourceItemId ?: '' }}"
                            x-show="(typeFilter === 'all' || typeFilter === '{{ $groupType }}') && (statusFilter === 'all' || {{ $groupHasEvolvable ? 'true' : 'false' }})"
                            x-data="{
                                groupOpen: false,
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
                            <div class="flex w-full items-center justify-between gap-2 px-3 py-2.5 text-left">
                                <button
                                    type="button"
                                    class="flex min-w-0 flex-1 items-center gap-1.5 text-left text-base font-extrabold leading-tight text-slate-900"
                                    @click="groupOpen = !groupOpen"
                                >
                                    @if($fromEquipmentIcon)
                                        <img src="{{ asset($fromEquipmentIcon) }}" alt="" class="h-6 w-6 shrink-0 object-contain">
                                    @endif
                                    <span class="truncate">[{{ $first['from_rank'] ?? '-' }}] {{ $fromNameForRankBadge }}</span>
                                    @if($groupHasEquipped)
                                        <span class="shrink-0 rounded border border-sky-200 bg-sky-50 px-1.5 py-0.5 text-[10px] font-black text-sky-700">装備中</span>
                                    @endif
                                </button>
                                <span class="flex shrink-0 items-center gap-2">
                                    @if($groupSourceItemId > 0)
                                        <label class="flex h-7 w-7 items-center justify-center rounded border {{ $groupCanSell ? 'border-orange-200 bg-orange-50 text-orange-700' : 'border-slate-200 bg-slate-50 text-slate-300' }}" title="{{ $groupCanSell ? 'まとめ売りに選択' : $groupSellDisabledTitle }}">
                                            <input
                                                type="checkbox"
                                                class="h-4 w-4 rounded border-orange-300 text-orange-600 focus:ring-orange-500 disabled:cursor-not-allowed disabled:opacity-40"
                                                data-smith-bulk-sell-checkbox
                                                data-source-item-id="{{ $groupSourceItemId }}"
                                                data-sell-url="{{ route('equipment.sell', $groupSourceItemId) }}"
                                                data-sell-price="{{ $groupSellPrice }}"
                                                data-source-name="{{ $fromDisplayName }}"
                                                @disabled(!$groupCanSell)
                                            >
                                        </label>
                                    @endif
                                    @if($groupHasEvolvable)
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shadow-[0_0_0_2px_rgba(16,185,129,0.12)]" title="進化可能" aria-label="進化可能"></span>
                                    @elseif($groupIsNear)
                                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500 shadow-[0_0_0_2px_rgba(245,158,11,0.14)]" title="あと少しで進化可能" aria-label="あと少しで進化可能"></span>
                                    @endif
                                    <button type="button" class="text-xs text-slate-400 transition-transform" :class="groupOpen ? 'rotate-180' : ''" @click="groupOpen = !groupOpen">▼</button>
                                </span>
                            </div>

                            {{-- 進化先アコーディオン --}}
                            <div x-show="groupOpen" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
                            @if($multiPath)
                                <div class="border-t border-slate-100">
                                    <div class="px-3 py-1.5 bg-slate-50 border-b border-slate-100">
                                        <span class="text-[10px] font-bold text-slate-500">進化先を選ぶ（タップで詳細）</span>
                                    </div>
                                @foreach($paths as $pi => $candidate)
                                    @php
                                        $canEvolve = $candidate['can_evolve'];
                                        $sourceOptions = $candidate['source_options'] ?? [];
                                        $singleSourceOption = count($sourceOptions) === 1 ? $sourceOptions[0] : null;
                                        $toDisplayName = $singleSourceOption['evolved_display_name'] ?? $candidate['to_preview_display_name'] ?? $candidate['to_display_name'] ?? $candidate['to_name'];
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
                                        $toEquipmentIcon = ($candidate['to_item'] ?? null)?->iconImagePath();
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
                                                @if($toEquipmentIcon)
                                                    <img src="{{ asset($toEquipmentIcon) }}" alt="" class="h-5 w-5 shrink-0 object-contain">
                                                @endif
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
                                            <div class="flex items-center gap-1.5 text-sm font-bold text-amber-700 leading-tight">
                                                @if($toEquipmentIcon)
                                                    <img src="{{ asset($toEquipmentIcon) }}" alt="" class="h-5 w-5 shrink-0 object-contain">
                                                @endif
                                                <span>→ [{{ $candidate['to_rank'] ?? '-' }}] {{ $toDisplayName }}</span>
                                            </div>
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
                                    $sourceOptions = $candidate['source_options'] ?? [];
                                    $singleSourceOption = count($sourceOptions) === 1 ? $sourceOptions[0] : null;
                                    $toDisplayName = $singleSourceOption['evolved_display_name'] ?? $candidate['to_preview_display_name'] ?? $candidate['to_display_name'] ?? $candidate['to_name'];
                                    $toEquipmentIcon = ($candidate['to_item'] ?? null)?->iconImagePath();
                                @endphp
                                <div class="border-t border-slate-100 px-3 pb-3 pt-2 flex flex-col gap-2">
                                    <div class="flex items-center gap-1.5 text-sm font-bold text-amber-700 leading-tight">
                                        @if($toEquipmentIcon)
                                            <img src="{{ asset($toEquipmentIcon) }}" alt="" class="h-5 w-5 shrink-0 object-contain">
                                        @endif
                                        <span>→ [{{ $candidate['to_rank'] ?? '-' }}] {{ $toDisplayName }}</span>
                                    </div>
                                    @include('smith._evolution_detail', ['candidate' => $candidate, 'stone' => $stone, 'canUseStone' => $canUseStone, 'canEvolve' => $canEvolve])
                                </div>
                            @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- 一括売却確認モーダル --}}
        <div id="smithBulkSellConfirmModal" class="fixed inset-0 z-50 hidden overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="smith-bulk-sell-title">
            <div class="flex min-h-screen items-center justify-center px-4 py-6 text-center">
                <button type="button" data-smith-bulk-modal-close class="fixed inset-0 cursor-default bg-slate-900/50 backdrop-blur-sm" aria-label="閉じる"></button>
                <div class="relative z-10 inline-block w-full max-w-lg transform overflow-hidden rounded-lg bg-white text-left align-middle shadow-xl transition-all">
                    <div class="border-b border-slate-200 px-5 py-5">
                        <div class="text-xs font-black tracking-wide text-orange-700">装備売却</div>
                        <h3 class="mt-1 text-lg font-extrabold text-slate-900" id="smith-bulk-sell-title">選択した装備を売却しますか？</h3>
                        <div class="mt-3 rounded-lg border border-orange-100 bg-orange-50 px-3 py-3 text-sm font-bold text-orange-800">
                            <div class="flex items-center justify-between gap-3">
                                <span>売却件数</span>
                                <span data-smith-bulk-confirm-count class="font-mono text-base font-black">0</span>
                            </div>
                            <div class="mt-1 flex items-center justify-between gap-3">
                                <span>合計売却額</span>
                                <span data-smith-bulk-confirm-total class="font-mono text-base font-black">0G</span>
                            </div>
                        </div>
                        <p class="mt-3 text-xs leading-relaxed text-slate-500">
                            売却した装備は所持品からなくなります。保護中・装備中・売却不可の装備は選択できません。
                        </p>
                    </div>
                    <div class="bg-slate-50 px-5 py-4 sm:flex sm:flex-row-reverse sm:gap-3">
                        <button type="button" data-smith-bulk-modal-confirm class="inline-flex w-full items-center justify-center rounded-md bg-orange-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-orange-700 disabled:cursor-wait disabled:opacity-70 sm:w-auto">
                            売却する
                        </button>
                        <button type="button" data-smith-bulk-modal-close class="mt-3 inline-flex w-full justify-center rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60 sm:mt-0 sm:w-auto">
                            キャンセル
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            (() => {
                const message = document.getElementById('smith-async-message');
                let messageTimer = null;

                function showSmithMessage(text, success = true) {
                    if (!message) return;

                    if (messageTimer) {
                        clearTimeout(messageTimer);
                    }

                    message.textContent = text;
                    message.classList.remove(
                        'hidden',
                        'translate-y-[-12px]',
                        'opacity-0',
                        'bg-emerald-600',
                        'border',
                        'border-emerald-400',
                        'text-white',
                        'bg-red-600',
                        'border-red-400'
                    );
                    message.classList.add(
                        success ? 'bg-emerald-600' : 'bg-red-600',
                        'border',
                        success ? 'border-emerald-400' : 'border-red-400',
                        'text-white'
                    );

                    messageTimer = setTimeout(() => {
                        message.classList.add('opacity-0', 'translate-y-[-12px]');
                        setTimeout(() => message.classList.add('hidden'), 180);
                    }, 3200);
                }

                function removeSourceCard(sourceId, form) {
                    const card = sourceId
                        ? document.querySelector(`[data-smith-source-card][data-source-item-id="${sourceId}"]`)
                        : form.closest('[data-smith-source-card]');

                    if (!card) return;

                    card.querySelectorAll('[data-smith-bulk-sell-checkbox]').forEach((checkbox) => {
                        checkbox.checked = false;
                        checkbox.disabled = true;
                    });
                    card.classList.add('opacity-40', 'pointer-events-none');
                    setTimeout(() => card.remove(), 180);
                }

                function bulkSellCheckboxes() {
                    return Array.from(document.querySelectorAll('[data-smith-bulk-sell-checkbox]'));
                }

                function selectedBulkSellCheckboxes() {
                    return bulkSellCheckboxes().filter((checkbox) => checkbox.checked && !checkbox.disabled);
                }

                function updateBulkSellBar() {
                    const selected = selectedBulkSellCheckboxes();
                    const bar = document.getElementById('smithBulkSellBar');
                    if (!bar) return;

                    const total = selected.reduce((sum, checkbox) => sum + Number(checkbox.dataset.sellPrice || 0), 0);
                    bar.classList.toggle('hidden', selected.length === 0);
                    document.body.classList.toggle('pb-24', selected.length > 0);
                    bar.querySelector('[data-smith-bulk-sell-count]').textContent = selected.length.toLocaleString();
                    bar.querySelector('[data-smith-bulk-sell-total]').textContent = `${total.toLocaleString()}G`;
                }

                async function sellOneSelectedEquipment(checkbox, csrfToken) {
                    const formData = new FormData();
                    formData.append('_token', csrfToken);
                    formData.append('return_to_smith', '1');

                    const response = await fetch(checkbox.dataset.sellUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData,
                    });
                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || data.success !== true) {
                        throw new Error(data.message || '売却に失敗しました。');
                    }

                    removeSourceCard(checkbox.dataset.sourceItemId, checkbox.closest('[data-smith-source-card]'));
                    return data;
                }

                document.addEventListener('change', (event) => {
                    if (!event.target.matches('[data-smith-bulk-sell-checkbox]')) return;
                    updateBulkSellBar();
                });

                document.querySelector('[data-smith-bulk-sell-clear]')?.addEventListener('click', () => {
                    selectedBulkSellCheckboxes().forEach((checkbox) => {
                        checkbox.checked = false;
                    });
                    updateBulkSellBar();
                });

                function bulkSellModal() {
                    return document.getElementById('smithBulkSellConfirmModal');
                }

                function closeBulkSellModal() {
                    bulkSellModal()?.classList.add('hidden');
                }

                function openBulkSellModal() {
                    const selected = selectedBulkSellCheckboxes();
                    if (selected.length === 0) return;

                    const total = selected.reduce((sum, checkbox) => sum + Number(checkbox.dataset.sellPrice || 0), 0);
                    const modal = bulkSellModal();
                    if (!modal) return;

                    modal.querySelector('[data-smith-bulk-confirm-count]').textContent = selected.length.toLocaleString();
                    modal.querySelector('[data-smith-bulk-confirm-total]').textContent = `${total.toLocaleString()}G`;
                    modal.classList.remove('hidden');
                }

                async function executeBulkSell(button) {
                    const selected = selectedBulkSellCheckboxes();
                    if (selected.length === 0) {
                        closeBulkSellModal();
                        return;
                    }

                    const originalText = button.textContent;
                    button.disabled = true;
                    button.textContent = '売却中...';

                    let success = 0;
                    let failed = 0;
                    for (const checkbox of selected) {
                        try {
                            checkbox.disabled = true;
                            await sellOneSelectedEquipment(checkbox, button.dataset.csrf);
                            success++;
                        } catch (error) {
                            failed++;
                            checkbox.disabled = false;
                            checkbox.checked = false;
                        }
                    }

                    button.disabled = false;
                    button.textContent = originalText;
                    closeBulkSellModal();
                    updateBulkSellBar();

                    if (success > 0) {
                        showSmithMessage(`${success}件の装備を売却しました。${failed > 0 ? ` ${failed}件は売却できませんでした。` : ''}`, true);
                    } else {
                        showSmithMessage('選択した装備を売却できませんでした。', false);
                    }
                }

                document.querySelector('[data-smith-bulk-sell-submit]')?.addEventListener('click', openBulkSellModal);

                document.querySelectorAll('[data-smith-bulk-modal-close]').forEach((button) => {
                    button.addEventListener('click', closeBulkSellModal);
                });

                document.querySelector('[data-smith-bulk-modal-confirm]')?.addEventListener('click', async (event) => {
                    await executeBulkSell(event.currentTarget);
                });

                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') {
                        closeBulkSellModal();
                    }
                });

                document.addEventListener('submit', async (event) => {
                    const form = event.target.closest('[data-smith-sell-form]');
                    if (!form) return;

                    event.preventDefault();

                    const button = form.querySelector('[data-smith-sell-submit]');
                    const originalText = button?.textContent || '';
                    if (button) {
                        button.disabled = true;
                        button.textContent = '売却中...';
                        button.classList.add('opacity-70');
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
                        const data = await response.json().catch(() => ({}));

                        if (!response.ok || data.success !== true) {
                            throw new Error(data.message || '売却に失敗しました。');
                        }

                        showSmithMessage(data.message || '装備を売却しました。', true);
                        removeSourceCard(form.dataset.sourceItemId, form);
                        updateBulkSellBar();
                    } catch (error) {
                        showSmithMessage(error.message || '売却に失敗しました。', false);
                        if (button) {
                            button.disabled = false;
                            button.textContent = originalText;
                            button.classList.remove('opacity-70');
                        }
                    }
                });
            })();
        </script>

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
                        <input type="hidden" name="source_character_item_id" :value="selected?.sourceCharacterItemId">

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
                                選んだ装備を進化元として使用します。装備中なら進化後の装備へ自動で付け替え、保護中なら保護状態も引き継ぎます。合成後の装備は +0 です。
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
        @include('smith.partials.operation-help-modal', ['helpType' => 'evolution'])
    </div>
</x-layouts.facility>
