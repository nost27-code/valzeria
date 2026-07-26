<x-layouts.valmon-ranch>
@php
    $equipmentFeedExpById = $equipment->mapWithKeys(fn ($row) => [(string) $row->id => (int) $row->feed_exp])->all();
    $equipmentRankOf = function ($row): string {
        return strtoupper((string) (
            $row->item?->weapon_rank
            ?? $row->item?->armor_rank
            ?? $row->item?->accessory_rank
            ?? $row->item?->rarity
            ?? ''
        ));
    };
    $equipmentFeedRankById = $equipment->mapWithKeys(fn ($row) => [(string) $row->id => $equipmentRankOf($row)])->all();
    $equipmentFeedRankCounts = $equipment
        ->map(fn ($row) => $equipmentRankOf($row))
        ->filter()
        ->countBy()
        ->sortBy(fn ($count, $rank) => array_search($rank, ['G', 'F', 'E', 'D', 'C', 'B', 'A'], true) === false ? 999 : array_search($rank, ['G', 'F', 'E', 'D', 'C', 'B', 'A'], true))
        ->all();
    $initialTab = in_array(session('valmon_active_tab'), ['farm', 'feed', 'background', 'dex'], true)
        ? session('valmon_active_tab')
        : 'farm';
    $initialFeedKind = in_array(session('valmon_feed_kind'), ['material', 'equipment'], true)
        ? session('valmon_feed_kind')
        : 'material';
    $fieldSlots = [
        // [left%, bottom%, size%, z-index]  size%は画像幅に対する割合
        [50, 5,  14, 40],  // 0: センター前列
        [24, 4,  12, 32],  // 1: 左前列
        [76, 4,  12, 36],  // 2: 右前列
        [38, 13, 10, 28],  // 3: 左中列
        [63, 12, 10, 30],  // 4: 右中列
        [ 9, 3,  10, 22],  // 5: 左端前
        [90, 3,  10, 24],  // 6: 右端前
        [28, 20,  9, 18],  // 7: 左後列（フェンス際）
        [55, 19,  9, 20],  // 8: 中後列（フェンス際）
        [76, 21,  8, 14],  // 9: 右後列（フェンス際）
    ];
    $pasturePageSize = count($fieldSlots);
    $pastureValmons = $valmons->sortByDesc('is_partner')->values();
    $pasturePages = $pastureValmons->chunk($pasturePageSize)->values();
    $pasturePageCount = max(1, $pasturePages->count());
@endphp
<div class="w-full" x-data="{
    tab: @js($initialTab),
    feedKind: @js($initialFeedKind),
    pasturePage: 1,
    pasturePageCount: @js($pasturePageCount),
    materialFeedQuery: '',
    materialFeedCategory: 'all',
    materialFeedRarity: 'all',
    materialFeedSort: 'feed_exp_desc',
    selectedBackground: @js(old('profile_ranch_background', $selectedRanchBackground)),
    selectedEquipmentIds: [],
    equipmentFeedExpById: @js($equipmentFeedExpById),
    equipmentFeedRankById: @js($equipmentFeedRankById),
    feedConfirmOpen: false,
    feedConfirmName: '',
    feedConfirmExp: 0,
    feedConfirmFormId: '',
    feedConfirmHighRank: false,
    feedConfirmSubmitting: false,
    feedSubmitting: false,
    highRanks: ['S', 'SS', 'SSS', 'EPIC'],
    normalizeMaterialFeedText(value) {
        return String(value || '').toLocaleLowerCase('ja-JP').replace(/[\s　]+/g, '');
    },
    matchesMaterialFeed(element) {
        const query = this.normalizeMaterialFeedText(this.materialFeedQuery);
        return (!query || this.normalizeMaterialFeedText(element.dataset.materialFeedName).includes(query))
            && (this.materialFeedCategory === 'all' || element.dataset.materialFeedCategory === this.materialFeedCategory)
            && (this.materialFeedRarity === 'all' || element.dataset.materialFeedRarity === this.materialFeedRarity);
    },
    matchingMaterialFeedCount() {
        if (!this.$refs.materialFeedGrid) return 0;
        return Array.from(this.$refs.materialFeedGrid.querySelectorAll('[data-material-feed-card]'))
            .filter((element) => this.matchesMaterialFeed(element)).length;
    },
    sortMaterialFeedCards() {
        const grid = this.$refs.materialFeedGrid;
        if (!grid) return;
        const compareText = (a, b) => String(a).localeCompare(String(b), 'ja');
        const cards = Array.from(grid.querySelectorAll('[data-material-feed-card]'));
        cards.sort((a, b) => {
            if (this.materialFeedSort === 'name_asc') return compareText(a.dataset.materialFeedName, b.dataset.materialFeedName);
            if (this.materialFeedSort === 'quantity_desc') return Number(b.dataset.materialFeedQuantity) - Number(a.dataset.materialFeedQuantity) || compareText(a.dataset.materialFeedName, b.dataset.materialFeedName);
            if (this.materialFeedSort === 'category_asc') return compareText(a.dataset.materialFeedCategoryLabel, b.dataset.materialFeedCategoryLabel) || Number(b.dataset.materialFeedRarityRank) - Number(a.dataset.materialFeedRarityRank) || compareText(a.dataset.materialFeedName, b.dataset.materialFeedName);
            if (this.materialFeedSort === 'rarity_desc') return Number(b.dataset.materialFeedRarityRank) - Number(a.dataset.materialFeedRarityRank) || compareText(a.dataset.materialFeedName, b.dataset.materialFeedName);
            return Number(b.dataset.materialFeedExp) - Number(a.dataset.materialFeedExp) || compareText(a.dataset.materialFeedName, b.dataset.materialFeedName);
        });
        cards.forEach((card) => grid.appendChild(card));
    },
    init() {
        this.$nextTick(() => this.sortMaterialFeedCards());
        this.$watch('materialFeedSort', () => this.$nextTick(() => this.sortMaterialFeedCards()));
    },
    openFeedConfirm(name, exp, formId, highRank = false) {
        if (this.feedSubmitting) return;
        this.feedConfirmName = name;
        this.feedConfirmExp = exp;
        this.feedConfirmFormId = formId;
        this.feedConfirmHighRank = highRank;
        this.feedConfirmSubmitting = false;
        this.feedConfirmOpen = true;
    },
    selectedEquipmentCount() {
        return this.selectedEquipmentIds.length;
    },
    selectedEquipmentExp() {
        return this.selectedEquipmentIds.reduce((total, id) => total + Number(this.equipmentFeedExpById[id] || 0), 0);
    },
    equipmentIdsByRank(rank) {
        return Object.entries(this.equipmentFeedRankById)
            .filter(([, itemRank]) => itemRank === rank)
            .map(([id]) => id);
    },
    selectedEquipmentCountByRank(rank) {
        const targets = this.equipmentIdsByRank(rank);
        return targets.filter((id) => this.selectedEquipmentIds.includes(id)).length;
    },
    selectEquipmentRank(rank) {
        const merged = new Set(this.selectedEquipmentIds.map((id) => String(id)));
        this.equipmentIdsByRank(rank).forEach((id) => merged.add(String(id)));
        this.selectedEquipmentIds = Array.from(merged);
    },
    clearEquipmentRank(rank) {
        const targets = new Set(this.equipmentIdsByRank(rank).map((id) => String(id)));
        this.selectedEquipmentIds = this.selectedEquipmentIds.filter((id) => !targets.has(String(id)));
    },
    openBulkFeedConfirm() {
        if (this.selectedEquipmentIds.length <= 0) return;
        const highRank = this.selectedEquipmentIds.some((id) => this.highRanks.includes(this.equipmentFeedRankById[id]));
        this.openFeedConfirm('選択した装備 ' + this.selectedEquipmentIds.length + '個', this.selectedEquipmentExp(), 'valmonEquipmentBulkFeedForm', highRank);
    },
    closeFeedConfirm() {
        if (this.feedConfirmSubmitting) return;
        this.feedConfirmOpen = false;
    },
    submitFeedConfirm() {
        if (this.feedConfirmSubmitting || this.feedSubmitting) return;
        const form = document.getElementById(this.feedConfirmFormId);
        if (!form) return;
        this.feedConfirmSubmitting = true;
        this.feedSubmitting = true;
        form.submit();
    }
}">
    {{-- ========== ヴァルモンシーン（16:9 全幅） ========== --}}
    <div class="relative w-full overflow-hidden md:rounded-2xl md:shadow-xl md:mb-5"
         style="aspect-ratio: 16/9;">

        {{-- 背景画像（モバイル・PC共通） --}}
        @foreach($ranchBackgrounds as $background)
            <div x-show="selectedBackground === @js($background['path'])"
                 class="absolute inset-0 bg-cover bg-center"
                 style="background-image: url('{{ asset($background['path']) }}');"></div>
        @endforeach

        {{-- 上部バー（戻る + カウント） --}}
        <div class="absolute inset-x-0 top-0 flex items-start justify-between px-3 pt-2 z-50 md:px-4">
            <a href="{{ route('home') }}"
               class="md:hidden bg-black/45 backdrop-blur-sm text-white text-xs font-black px-3 py-1.5 rounded-xl flex items-center gap-1.5 shadow-lg active:scale-95 transition-transform">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                街へ戻る
            </a>
            <div class="ml-auto bg-black/45 backdrop-blur-sm text-white text-xs font-black px-3 py-1.5 rounded-xl flex items-center gap-1.5 shadow-lg">
                <img src="{{ asset('images/icon/icon_037.webp') }}" alt="" class="w-4 h-4 object-contain"> 仲間 {{ $valmons->count() }}体
                @if($activeEgg)<img src="{{ asset('images/icon/icon_038.webp') }}" alt="" class="w-4 h-4 object-contain">@endif
            </div>
        </div>

        @if($pasturePageCount > 1)
            <div class="absolute left-1/2 top-2 z-[60] flex -translate-x-1/2 items-center gap-1 rounded-xl bg-black/45 p-1 text-white shadow-lg backdrop-blur-sm"
                 data-pasture-pagination
                 data-pasture-page-count="{{ $pasturePageCount }}">
                <button type="button"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-sm font-black transition hover:bg-white/20 disabled:cursor-not-allowed disabled:opacity-40"
                        aria-label="前の{{ $pasturePageSize }}体を表示"
                        :disabled="pasturePage <= 1"
                        @click="if (pasturePage > 1) pasturePage--">
                    ←
                </button>
                <span class="min-w-8 text-center text-[11px] font-black" aria-live="polite">
                    <span x-text="pasturePage">1</span> / {{ $pasturePageCount }}
                </span>
                <button type="button"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-sm font-black transition hover:bg-white/20 disabled:cursor-not-allowed disabled:opacity-40"
                        aria-label="次の{{ $pasturePageSize }}体を表示"
                        :disabled="pasturePage >= pasturePageCount"
                        @click="if (pasturePage < pasturePageCount) pasturePage++">
                    →
                </button>
            </div>
        @endif

        {{-- ヴァルモン配置（16:9基準 / 草地エリア: bottom 3〜22%） --}}
        @foreach($pasturePages as $pageIndex => $pageValmons)
            @php $pageNumber = $pageIndex + 1; @endphp
            <div class="absolute inset-0 pointer-events-none"
                 x-show="pasturePage === {{ $pageNumber }}"
                 @if($pageNumber > 1) style="display: none;" @endif
                 data-pasture-page="{{ $pageNumber }}">
                @foreach($pageValmons as $valmon)
                    @if($valmon->master?->image_path)
                        @php
                            [$lft, $btm, $sz, $zi] = $fieldSlots[$loop->index];
                        @endphp
                        <div class="absolute"
                             style="left:{{ $lft }}%;bottom:{{ $btm }}%;transform:translateX(-50%);z-index:{{ $zi }};width:{{ $sz }}%;"
                             data-pasture-entry
                             data-pasture-entry-page="{{ $pageNumber }}">
                            <div class="{{ $valmon->is_partner ? 'animate-bounce' : '' }} relative" style="animation-duration:2.2s;">
                                <img src="{{ $valmon->master->imageUrl() }}"
                                     alt="{{ $valmon->displayName() }}"
                                     class="w-full h-auto object-contain drop-shadow-lg">
                                @if($valmon->is_partner)
                                    <div class="absolute whitespace-nowrap text-white text-[9px] font-black px-1.5 py-0.5 rounded-full shadow-md"
                                         style="top:-16px;left:50%;transform:translateX(-50%);background:#16a34a;">
                                        {{ $valmon->nickname ?: '★ 相棒' }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        @endforeach

        {{-- 下端フェード --}}
        <div class="absolute inset-x-0 bottom-0 h-[15%] pointer-events-none"
             style="background:linear-gradient(to top, rgba(255,255,255,0.95) 0%, transparent 100%); z-index:55;"></div>
    </div>

    {{-- ========== コンテンツパネル ========== --}}
    <div class="relative bg-white md:bg-transparent pb-safe md:pb-0"
         style="z-index: 20;">

        <div class="px-4 md:px-0 pt-4 md:pt-0 space-y-4">

            {{-- フラッシュメッセージ --}}
            @if(session('error'))
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">{{ session('error') }}</div>
            @endif

            {{-- 相棒ステータス --}}
            @if($partner)
                <div class="flex items-center gap-3 bg-white rounded-xl border border-emerald-200 shadow-sm px-4 py-3">
                    @if($partner->master?->image_path)
                        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl border border-emerald-100 bg-emerald-50 p-1">
                            <img src="{{ $partner->master->imageUrl() }}" alt="{{ $partner->displayName() }}" class="h-full w-full object-contain">
                        </div>
                    @endif
                    <div class="flex-1 min-w-0">
                        <div class="text-[10px] font-black text-emerald-600 uppercase tracking-wider">現在の相棒</div>
                        <div class="text-lg font-black text-slate-900 flex flex-wrap items-center gap-2 leading-tight">
                            {{ $partner->displayName() }}
                            <span class="text-xs font-black bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full">Lv{{ $partner->level }}</span>
                        </div>
                        <div class="text-xs text-slate-500 font-bold mt-0.5">
                            種族: {{ $partner->master?->name ?? 'ヴァルモン' }} /
                            {{ $partner->master?->silhouette_type }} /
                            {{ $partner->is_max_level ? '最大Lv' : '次のLvまであと' . number_format($partner->next_level_remaining ?? 0) }}
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-black text-emerald-700">{{ $partner->role_label ?? '標準型' }}</span>
                            @foreach(($partner->effect_summary ?? []) as $effect)
                                <span class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[11px] font-black text-slate-600">{{ $effect }}</span>
                            @endforeach
                            @if(($partner->bond_art_settings['available'] ?? false))
                                <span class="rounded-full border border-violet-200 bg-violet-50 px-2 py-0.5 text-[11px] font-black text-violet-700">掛け声 {{ $partner->bond_art_settings['phrase_style_label'] }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            @else
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm px-4 py-3 text-center text-sm font-bold text-slate-500">
                    相棒が設定されていません
                </div>
            @endif

            {{-- タブ --}}
            <div class="grid grid-cols-4 gap-2 rounded-xl border border-slate-200 bg-slate-100 p-1">
                <button type="button" @click="tab = 'farm'"
                    class="rounded-lg px-2 py-2.5 text-sm font-black transition"
                    :class="tab === 'farm' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-800'">牧場</button>
                <button type="button" @click="tab = 'feed'"
                    class="rounded-lg px-2 py-2.5 text-sm font-black transition"
                    :class="tab === 'feed' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-800'">餌</button>
                <button type="button" @click="tab = 'background'"
                    class="rounded-lg px-2 py-2.5 text-sm font-black transition"
                    :class="tab === 'background' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-800'">背景</button>
                <button type="button" @click="tab = 'dex'"
                    class="rounded-lg px-2 py-2.5 text-sm font-black transition"
                    :class="tab === 'dex' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-800'">図鑑</button>
            </div>

            {{-- 牧場タブ --}}
            <div x-show="tab === 'farm'" x-transition class="space-y-3">
                <div class="grid gap-3 md:grid-cols-2">
                @forelse($valmons as $valmon)
                    @php
                        $renameErrorBag = 'renameValmon' . $valmon->id;
                        $bondErrorBag = 'bondSettings' . $valmon->id;
                        $bondSettings = $valmon->bond_art_settings ?? [];
                        $bondHasErrors = $errors->getBag($bondErrorBag)->any();
                        $bondPanelOpen = $bondHasErrors || (int) session('valmon_settings_id') === (int) $valmon->id;
                        $selectedBondStyle = $bondHasErrors
                            ? old('bond_style', $bondSettings['style_key'] ?? 'balanced')
                            : ($bondSettings['style_key'] ?? 'balanced');
                        $selectedBondPhraseStyle = $bondHasErrors
                            ? old('bond_phrase_style', $bondSettings['phrase_style_key'] ?? 'trust')
                            : ($bondSettings['phrase_style_key'] ?? 'trust');
                        $speciesName = $valmon->master?->name ?? 'ヴァルモン';
                    @endphp
                    <div class="rounded-xl border {{ $valmon->is_partner ? 'border-emerald-300 bg-emerald-50' : 'border-slate-200 bg-white' }} p-4 shadow-sm"
                         x-data="{
                             renameOpen: @js($errors->getBag($renameErrorBag)->has('nickname')),
                             bondOpen: @js($bondPanelOpen),
                             selectedBondStyle: @js($selectedBondStyle),
                             selectedBondPhraseStyle: @js($selectedBondPhraseStyle)
                         }">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                                @if($valmon->master?->image_path)
                                    <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white p-1 shadow-inner">
                                        <img src="{{ $valmon->master->imageUrl() }}" alt="{{ $valmon->displayName() }}" class="h-full w-full object-contain">
                                    </div>
                                @endif
                                <div class="min-w-0">
                                    <div class="text-xs font-black text-slate-400">{{ $valmon->master?->silhouette_type }} / {{ strtoupper($valmon->master?->rarity ?? 'normal') }}</div>
                                    <h3 class="mt-1 flex flex-wrap items-end gap-2 text-xl font-black text-slate-950">
                                        <span>{{ $valmon->displayName() }}</span>
                                        <span class="mb-0.5 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-black leading-none text-slate-600">Lv{{ $valmon->level }}</span>
                                    </h3>
                                    <div class="mt-1 text-xs font-black text-slate-500">
                                        種族: <span class="text-slate-700">{{ $speciesName }}</span>
                                    </div>
                                    <div class="mt-1 text-sm font-bold text-slate-600">
                                        {{ $valmon->is_max_level ? '最大Lv' : '次のLvまであと' . number_format($valmon->next_level_remaining ?? 0) }}
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-black text-emerald-700">{{ $valmon->role_label ?? '標準型' }}</span>
                                        @foreach(($valmon->effect_summary ?? []) as $effect)
                                            <span class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[11px] font-black text-slate-600">{{ $effect }}</span>
                                        @endforeach
                                        @if(($bondSettings['available'] ?? false))
                                            <span class="rounded-full border border-violet-200 bg-violet-50 px-2 py-0.5 text-[11px] font-black text-violet-700">掛け声 {{ $bondSettings['phrase_style_label'] }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            @if($valmon->is_partner)
                                <span class="rounded bg-emerald-600 px-2 py-1 text-xs font-black text-white shrink-0">相棒</span>
                            @endif
                        </div>
                        <p class="mt-3 text-sm font-bold leading-relaxed text-slate-500">{{ $valmon->master?->description }}</p>
                        @if(($bondSettings['available'] ?? false))
                            <button type="button"
                                    @click="bondOpen = !bondOpen"
                                    class="mt-4 flex min-h-10 w-full items-center justify-between gap-3 rounded-lg border border-violet-200 bg-violet-50 px-3 text-left text-xs font-black text-violet-800 shadow-sm hover:bg-violet-100">
                                <span>絆技の設定</span>
                                <span class="text-[11px] text-violet-600">{{ $bondSettings['style_label'] }}／{{ $bondSettings['phrase_style_label'] }}</span>
                            </button>
                            <div x-show="bondOpen" x-transition class="mt-2 rounded-xl border border-violet-200 bg-white/90 p-3">
                                <form method="POST"
                                      action="{{ route('valmons.bond-art-settings', $valmon) }}"
                                      data-submit-lock
                                      data-loading-text="設定中...">
                                    @csrf
                                    <div>
                                        <div class="text-sm font-black text-slate-950">発動スタイル</div>
                                        <div class="mt-0.5 text-[11px] font-bold text-slate-500">1戦につき1回抽選。3種類とも「発動率×威力」の基礎値は同じです。</div>
                                        <div class="mt-0.5 text-[11px] font-bold text-slate-500">表示威力を基準に、追撃ダメージは{{ $bondSettings['damage_variance_min_percent'] }}〜{{ $bondSettings['damage_variance_max_percent'] }}%で変動します。</div>
                                    </div>
                                    <div class="mt-2 grid gap-2 sm:grid-cols-3">
                                        @foreach(($bondSettings['styles'] ?? []) as $style)
                                            @php
                                                $styleRate = rtrim(rtrim(number_format((float) $style['rate'], 1), '0'), '.');
                                                $stylePower = (int) round((float) $style['power_rate'] * 100);
                                            @endphp
                                            <label class="relative rounded-lg border p-2.5 transition {{ $style['unlocked'] ? 'cursor-pointer' : 'cursor-not-allowed opacity-55' }}"
                                                   :class="selectedBondStyle === @js($style['key'])
                                                       ? 'border-violet-500 bg-violet-50 ring-2 ring-violet-500/20'
                                                       : 'border-slate-200 bg-white'">
                                                <input type="radio"
                                                       name="bond_style"
                                                       value="{{ $style['key'] }}"
                                                       class="sr-only"
                                                       x-model="selectedBondStyle"
                                                       @checked($selectedBondStyle === $style['key'])
                                                       @disabled(!$style['unlocked'])>
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="text-sm font-black text-slate-950">{{ $style['label'] }}</span>
                                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-black text-slate-600">Lv{{ $style['unlock_level'] }}</span>
                                                </div>
                                                <div class="mt-1 text-[11px] font-black text-violet-700">発動 {{ $styleRate }}% ／ 威力 {{ $stylePower }}%</div>
                                                <div class="mt-1 text-[11px] font-bold leading-relaxed text-slate-500">{{ $style['description'] }}</div>
                                                <div class="mt-2 border-t border-slate-100 pt-1.5 text-[11px] font-black text-slate-700">技名：{{ $style['technique_name'] }}</div>
                                            </label>
                                        @endforeach
                                    </div>
                                    @error('bond_style', $bondErrorBag)
                                        <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>
                                    @enderror

                                    <div class="mt-4">
                                        <div class="text-sm font-black text-slate-950">掛け声</div>
                                        <div class="mt-0.5 text-[11px] font-bold text-slate-500">性能は変わりません。好みの雰囲気を選べます。</div>
                                    </div>
                                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                        @foreach(($bondSettings['phrase_styles'] ?? []) as $phraseStyle)
                                            <label class="cursor-pointer rounded-lg border p-2.5 transition"
                                                   :class="selectedBondPhraseStyle === @js($phraseStyle['key'])
                                                       ? 'border-violet-500 bg-violet-50 ring-2 ring-violet-500/20'
                                                       : 'border-slate-200 bg-white'">
                                                <input type="radio"
                                                       name="bond_phrase_style"
                                                       value="{{ $phraseStyle['key'] }}"
                                                       class="sr-only"
                                                       x-model="selectedBondPhraseStyle"
                                                       @checked($selectedBondPhraseStyle === $phraseStyle['key'])>
                                                <div class="text-xs font-black text-violet-700">{{ $phraseStyle['label'] }}</div>
                                                <div class="mt-1 text-xs font-bold leading-relaxed text-slate-700">{{ $phraseStyle['preview'] }}</div>
                                            </label>
                                        @endforeach
                                    </div>
                                    @error('bond_phrase_style', $bondErrorBag)
                                        <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>
                                    @enderror

                                    <div class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-[11px] font-bold leading-relaxed text-slate-600">
                                        選んだ内容はこのヴァルモンに保存され、相棒にしている間のモンスター戦で使われます。対人戦では発動しません。
                                    </div>
                                    <button type="submit" class="mt-3 w-full rounded-lg bg-violet-700 px-4 py-2.5 text-sm font-black text-white hover:bg-violet-800">
                                        絆技を設定
                                    </button>
                                </form>
                            </div>
                        @else
                            <div class="mt-4 rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-2.5 text-xs font-bold text-slate-500">
                                Lv40で絆技と掛け声の設定が解放されます。
                            </div>
                        @endif
                        <button type="button"
                                @click="renameOpen = !renameOpen"
                                class="mt-4 inline-flex min-h-9 items-center justify-center rounded-md border border-emerald-200 bg-white px-3 text-xs font-black text-emerald-700 shadow-sm hover:bg-emerald-50">
                            名前を変更する
                        </button>
                        <form x-show="renameOpen"
                              x-transition
                              method="POST"
                              action="{{ route('valmons.nickname', $valmon) }}"
                              data-submit-lock
                              data-loading-text="保存中..."
                              class="mt-2 rounded-lg border border-slate-200 bg-white/70 p-2">
                            @csrf
                            <label class="block text-[11px] font-black text-slate-500">ニックネーム (1〜8文字)</label>
                            <div class="mt-1 flex gap-2">
                                <input type="text"
                                       name="nickname"
                                       value="{{ $valmon->nickname ?? '' }}"
                                       maxlength="8"
                                       placeholder="{{ $speciesName }}"
                                       class="min-w-0 flex-1 rounded-md border-slate-300 px-3 py-2 text-sm font-bold text-slate-900 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                <button type="submit" class="shrink-0 rounded-md bg-emerald-700 px-3 py-2 text-xs font-black text-white hover:bg-emerald-800">
                                    保存
                                </button>
                            </div>
                            <div class="mt-1 text-[11px] font-bold text-slate-500">種族名「{{ $speciesName }}」はそのまま残ります。</div>
                            @error('nickname', $renameErrorBag)
                                <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>
                            @enderror
                        </form>
                        @unless($valmon->is_partner)
                            <form method="POST" action="{{ route('valmons.partner', $valmon) }}" class="mt-4" data-submit-lock data-loading-text="変更中...">
                                @csrf
                                <button type="submit" class="w-full rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-black text-white hover:bg-slate-700">相棒にする</button>
                            </form>
                        @endunless
                    </div>
                @empty
                    <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm font-bold text-slate-500">ヴァルモンがいません。</div>
                @endforelse
                </div>
            </div>

            {{-- 背景タブ --}}
            <div x-show="tab === 'background'" x-transition class="space-y-3">
                <form method="POST" action="{{ route('valmons.background') }}" class="rounded-xl border border-emerald-200 bg-white p-4 shadow-sm" data-submit-lock data-loading-text="変更中...">
                    @csrf
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="text-sm font-black text-slate-950">牧場背景</div>
                            <div class="mt-0.5 text-xs font-bold text-slate-500">所持している背景だけ変更できます。</div>
                        </div>
                        <button type="submit"
                                class="inline-flex min-h-10 items-center justify-center rounded-lg bg-emerald-700 px-4 text-xs font-black text-white shadow-sm hover:bg-emerald-800">
                            背景を変更
                        </button>
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
                        @foreach($ranchBackgrounds as $background)
                            <label class="cursor-pointer overflow-hidden rounded-lg border bg-white shadow-sm transition"
                                   :class="selectedBackground === @js($background['path']) ? 'border-emerald-500 ring-2 ring-emerald-500/25' : 'border-slate-200 hover:border-slate-300'">
                                <input type="radio"
                                       name="profile_ranch_background"
                                       value="{{ $background['path'] }}"
                                       class="sr-only"
                                       x-model="selectedBackground">
                                <div class="relative w-full bg-slate-100" style="aspect-ratio: 16/9;">
                                    <img src="{{ asset($background['path']) }}" alt="{{ $background['label'] }}" class="h-full w-full object-cover">
                                    <div x-show="selectedBackground === @js($background['path'])"
                                         class="absolute right-1.5 top-1.5 rounded-full bg-emerald-600 px-2 py-0.5 text-[10px] font-black text-white shadow">
                                        選択中
                                    </div>
                                </div>
                                <div class="truncate px-2 py-1.5 text-xs font-black text-slate-700">{{ $background['label'] }}</div>
                            </label>
                        @endforeach
                    </div>
                    @error('profile_ranch_background')
                        <div class="mt-2 text-xs font-bold text-red-600">{{ $message }}</div>
                    @enderror
                </form>
            </div>

            {{-- 餌タブ --}}
            <div x-show="tab === 'feed'" x-transition class="space-y-4">
                @if(!$partner)
                    <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm font-bold text-slate-500">相棒を設定すると餌を与えられます。</div>
                @else
                    <div class="grid grid-cols-2 gap-2 rounded-xl border border-slate-200 bg-slate-100 p-1">
                        <button type="button" @click="feedKind = 'material'"
                            class="rounded-lg px-3 py-3 text-sm font-black transition"
                            :class="feedKind === 'material' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-800'">素材</button>
                        <button type="button" @click="feedKind = 'equipment'"
                            class="rounded-lg px-3 py-3 text-sm font-black transition"
                            :class="feedKind === 'equipment' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-800'">装備</button>
                    </div>
                    <div x-show="feedKind === 'material'" class="space-y-3">
                        @if($materials->isNotEmpty())
                            <div class="rounded-xl border border-emerald-100 bg-emerald-50/70 p-3">
                                <label>
                                    <span class="mb-1 block text-[11px] font-black text-emerald-800">素材を探す</span>
                                    <input type="search" x-model.debounce.150ms="materialFeedQuery" placeholder="素材名を入力" class="w-full rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder:text-slate-400 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                                </label>
                                <div class="mt-2 grid grid-cols-2 gap-2">
                                    <label>
                                        <span class="mb-1 block text-[11px] font-black text-emerald-800">分類</span>
                                        <select x-model="materialFeedCategory" class="w-full rounded-lg border border-emerald-200 bg-white px-2.5 py-2 text-xs font-bold text-slate-700 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                                            <option value="all">すべて</option>
                                            @foreach($materialFeedCategoryFilters as $filter)
                                                <option value="{{ $filter['key'] }}">{{ $filter['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>
                                        <span class="mb-1 block text-[11px] font-black text-emerald-800">レアリティ</span>
                                        <select x-model="materialFeedRarity" class="w-full rounded-lg border border-emerald-200 bg-white px-2.5 py-2 text-xs font-bold text-slate-700 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                                            <option value="all">すべて</option>
                                            @foreach($materialFeedRarityFilters as $filter)
                                                <option value="{{ $filter['key'] }}">{{ $filter['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                </div>
                                <label class="mt-2 block">
                                    <span class="mb-1 block text-[11px] font-black text-emerald-800">並び替え</span>
                                    <select x-model="materialFeedSort" class="w-full rounded-lg border border-emerald-200 bg-white px-2.5 py-2 text-xs font-bold text-slate-700 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                                        <option value="feed_exp_desc">獲得EXPが多い順</option>
                                        <option value="name_asc">名前順</option>
                                        <option value="quantity_desc">所持数が多い順</option>
                                        <option value="category_asc">分類順</option>
                                        <option value="rarity_desc">レアリティが高い順</option>
                                    </select>
                                </label>
                            </div>
                        @endif
                        <div x-ref="materialFeedGrid" class="grid gap-3 md:grid-cols-2">
                        @forelse($materials as $row)
                            @php
                                $materialFeedMeta = $materialFeedMetadata[$row->id];
                            @endphp
                            <div
                                class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
                                data-material-feed-card
                                data-material-feed-name="{{ $row->material?->displayName() }}"
                                data-material-feed-category="{{ $materialFeedMeta['category_key'] }}"
                                data-material-feed-category-label="{{ $materialFeedMeta['category'] }}"
                                data-material-feed-rarity="{{ $materialFeedMeta['rarity'] }}"
                                data-material-feed-rarity-rank="{{ $materialFeedMeta['rarity_rank'] }}"
                                data-material-feed-exp="{{ $row->feed_exp }}"
                                data-material-feed-quantity="{{ $row->quantity }}"
                                x-show="matchesMaterialFeed($el)"
                                x-transition
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 class="text-base font-black text-slate-950">{{ $row->material?->displayName() }}</h3>
                                        <div class="mt-1.5 flex flex-wrap gap-1">
                                            <span class="rounded bg-slate-800 px-1.5 py-0.5 text-[10px] font-black leading-none text-white">{{ $materialFeedMeta['category'] }}</span>
                                            <span class="rounded border border-[#d4af37]/50 bg-[#fdf8ec] px-1.5 py-0.5 text-[10px] font-black leading-none text-[#9c7a19]">{{ $materialFeedMeta['rarity'] }}</span>
                                        </div>
                                        <div class="mt-1 text-xs font-bold text-slate-500">所持 {{ number_format($row->quantity) }} / EXP {{ $row->feed_exp }}ずつ</div>
                                    </div>
                                    <form method="POST" action="{{ route('valmons.feed.material', [$partner, $row]) }}"
                                          class="flex flex-col items-end gap-2 sm:flex-row sm:items-center"
                                          x-data="{ qty: 1, max: {{ (int) $row->quantity }} }"
                                          @submit="if (feedSubmitting) { $event.preventDefault(); return; } feedSubmitting = true">
                                        @csrf
                                        <div class="flex items-center overflow-hidden rounded-lg border border-slate-300 bg-white shadow-sm">
                                            <button type="button" class="flex h-11 w-11 items-center justify-center border-r border-slate-200 text-lg font-black text-slate-700 hover:bg-slate-50 disabled:text-slate-300"
                                                @click="qty = Math.max(1, qty - 1)" :disabled="qty <= 1">-</button>
                                            <input type="number" name="quantity" x-model.number="qty" min="1" max="{{ $row->quantity }}"
                                                class="h-11 w-14 border-0 p-0 text-center text-sm font-black text-slate-900 focus:ring-0"
                                                @input="qty = Math.min(max, Math.max(1, Number(qty) || 1))">
                                            <button type="button" class="flex h-11 w-11 items-center justify-center border-l border-slate-200 text-lg font-black text-slate-700 hover:bg-slate-50 disabled:text-slate-300"
                                                @click="qty = Math.min(max, qty + 1)" :disabled="qty >= max">+</button>
                                        </div>
                                        <button type="submit"
                                                :disabled="feedSubmitting"
                                                class="h-11 rounded bg-emerald-700 px-4 text-xs font-black text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-slate-300">
                                            <span x-show="!feedSubmitting">与える</span>
                                            <span x-show="feedSubmitting">処理中...</span>
                                        </button>
                                    </form>
                                </div>
                                @error('quantity', 'feedMaterial' . $row->id)
                                    <div class="mt-2 text-xs font-bold text-red-600">{{ $message }}</div>
                                @enderror
                            </div>
                        @empty
                            <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm font-bold text-slate-500">餌にできる素材がありません。</div>
                        @endforelse
                        </div>
                        @if($materials->isNotEmpty())
                            <div x-show="matchingMaterialFeedCount() === 0" class="rounded-xl border border-dashed border-slate-200 bg-white p-6 text-center text-sm font-bold text-slate-500">
                                条件に合う素材はありません。
                            </div>
                        @endif
                    </div>
                    <div x-show="feedKind === 'equipment'" class="space-y-3">
                        <form id="valmonEquipmentBulkFeedForm" method="POST" action="{{ route('valmons.feed.equipment.bulk', $partner) }}">
                            @csrf
                        </form>

                        @if($equipment->isNotEmpty())
                            <div class="sticky top-2 z-20 rounded-xl border border-red-100 bg-white/95 p-3 shadow-sm backdrop-blur">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="text-[11px] font-black tracking-widest text-red-600">まとめて餌</div>
                                        <div class="mt-0.5 text-xs font-bold text-slate-500">
                                            選択中 <span class="font-black text-slate-950" x-text="selectedEquipmentCount()"></span> 個 /
                                            EXP <span class="font-black text-slate-950" x-text="selectedEquipmentExp()"></span>
                                        </div>
                                        @if(!empty($equipmentFeedRankCounts))
                                            <div class="mt-2 flex flex-wrap gap-1.5">
                                                @foreach($equipmentFeedRankCounts as $rank => $count)
                                                    <button type="button"
                                                            @click="selectedEquipmentCountByRank(@js($rank)) >= {{ (int) $count }} ? clearEquipmentRank(@js($rank)) : selectEquipmentRank(@js($rank))"
                                                            class="rounded-full border border-red-200 bg-red-50 px-2.5 py-1 text-[11px] font-black text-red-700 transition hover:bg-red-100">
                                                        <span x-text="selectedEquipmentCountByRank(@js($rank)) >= {{ (int) $count }} ? '{{ $rank }}解除' : '{{ $rank }}全部'"></span>
                                                        <span class="text-red-500">({{ number_format((int) $count) }})</span>
                                                    </button>
                                                @endforeach
                                                <button type="button"
                                                        @click="selectedEquipmentIds = []"
                                                        x-show="selectedEquipmentIds.length > 0"
                                                        class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-black text-slate-500 transition hover:bg-slate-100">
                                                    解除
                                                </button>
                                            </div>
                                        @endif
                                    </div>
                                    <button type="button"
                                            @click="openBulkFeedConfirm()"
                                            :disabled="selectedEquipmentIds.length === 0 || feedSubmitting"
                                            class="min-h-10 rounded-lg bg-red-700 px-4 text-xs font-black text-white shadow-sm hover:bg-red-800 disabled:cursor-not-allowed disabled:bg-slate-300">
                                        まとめて餌にする
                                    </button>
                                </div>
                            </div>
                        @endif

                        <div class="grid gap-3 md:grid-cols-2">
                            @forelse($equipment as $row)
                                @php
                                    $rowRank = $equipmentRankOf($row);
                                    $equipmentIcon = $row->item?->iconImagePath();
                                @endphp
                                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-feed-rank="{{ $rowRank }}">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="flex min-w-0 items-start gap-3">
                                            <input type="checkbox"
                                                   form="valmonEquipmentBulkFeedForm"
                                                   name="character_item_ids[]"
                                                   value="{{ $row->id }}"
                                                   x-model="selectedEquipmentIds"
                                                   aria-label="{{ $row->displayName() }}をまとめて餌にする"
                                                   class="mt-1 h-5 w-5 shrink-0 rounded border-slate-300 text-red-700 focus:ring-red-600">
                                            <div class="min-w-0">
                                                <h3 class="flex min-w-0 items-center gap-2 text-base font-black text-slate-950">
                                                    @if($equipmentIcon)
                                                        <img src="{{ asset($equipmentIcon) }}" alt="" class="h-6 w-6 shrink-0 object-contain">
                                                    @endif
                                                    @include('equipment.partials.rank-label', ['item' => $row->item])
                                                    <span class="min-w-0 break-words">{{ $row->displayName(false) }}</span>
                                                </h3>
                                                <div class="mt-1 text-xs font-bold text-slate-500">EXP {{ $row->feed_exp }} / 餌にすると消滅します</div>
                                            </div>
                                        </div>
                                        <form id="valmonEquipmentFeedForm_{{ $row->id }}" method="POST" action="{{ route('valmons.feed.equipment', [$partner, $row]) }}">
                                            @csrf
                                            <button type="button"
                                                    @click="openFeedConfirm(@js($row->displayName()), {{ (int) $row->feed_exp }}, 'valmonEquipmentFeedForm_{{ $row->id }}', {{ in_array($rowRank, ['S', 'SS', 'SSS', 'EPIC'], true) ? 'true' : 'false' }})"
                                                    :disabled="feedSubmitting"
                                                    class="rounded bg-red-700 px-3 py-2 text-xs font-black text-white hover:bg-red-800 disabled:cursor-not-allowed disabled:bg-slate-300">与える</button>
                                        </form>
                                    </div>
                                </div>
                            @empty
                                <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm font-bold text-slate-500">餌にできる装備がありません。</div>
                            @endforelse
                        </div>
                    </div>
                @endif
            </div>

            {{-- 図鑑タブ --}}
            <div x-show="tab === 'dex'" x-transition class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach($dex as $entry)
                    @php $master = $entry['master']; @endphp
                    <div class="rounded-xl border {{ $entry['owned'] ? 'border-sky-200 bg-white' : 'border-slate-200 bg-slate-50' }} p-4 shadow-sm">
                        <div class="flex items-start gap-3">
                            <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white p-1 shadow-inner">
                                @if($entry['owned'] && $master->image_path)
                                    <img src="{{ $master->imageUrl() }}" alt="{{ $master->name }}" class="h-full w-full object-contain">
                                @else
                                    <span class="text-2xl font-black text-slate-300">?</span>
                                @endif
                            </div>
                            <div class="min-w-0">
                                <div class="text-xs font-black text-slate-400">No.{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }} / {{ strtoupper($master->rarity) }}</div>
                                <h3 class="mt-1 text-lg font-black {{ $entry['owned'] ? 'text-slate-950' : 'text-slate-400' }}">{{ $entry['owned'] ? $master->name : '？？？' }}</h3>
                            </div>
                        </div>
                        <p class="mt-2 text-sm font-bold leading-relaxed text-slate-500">
                            {{ $entry['owned'] ? $master->description : 'ヒント：' . $master->base_find_material_category . 'に縁がある地域で見つかることがあります。' }}
                        </p>
                    </div>
                @endforeach
            </div>

            {{-- モバイル: 退出ボタン --}}
            <div class="md:hidden pt-2">
                <a href="{{ route('home') }}"
                   x-data="{ loading: false }"
                   @click="if (!$event.defaultPrevented && !$event.metaKey && !$event.ctrlKey && !$event.shiftKey && $event.button === 0) loading = true"
                   :class="loading ? 'pointer-events-none opacity-80' : ''"
                   class="flex items-center justify-center gap-2 w-full bg-slate-800 hover:bg-slate-700 text-white font-black rounded-xl py-4 shadow-lg active:scale-95 transition-transform text-sm">
                    <span x-show="!loading">🚪</span>
                    <svg x-show="loading" style="display: none;" class="h-4 w-4 animate-spin text-white" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                        <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
                    </svg>
                    <span>ヴァルモン牧場から出る</span>
                </a>
            </div>

        </div>
    </div>

    <div x-show="feedConfirmOpen"
         style="display: none;"
         x-transition.opacity
         @keydown.escape.window="closeFeedConfirm()"
         class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-950/55 px-4 py-6"
         role="dialog"
         aria-modal="true"
         aria-labelledby="valmon-feed-confirm-title">
        <button type="button"
                class="absolute inset-0 h-full w-full cursor-default"
                aria-label="閉じる"
                @click="closeFeedConfirm()"></button>
        <div x-show="feedConfirmOpen"
             x-transition
             class="relative w-full max-w-sm overflow-hidden rounded-2xl border border-red-100 bg-white shadow-2xl">
            <div class="border-b border-slate-100 px-5 py-4">
                <div class="text-[11px] font-black tracking-widest text-red-600">ヴァルモンの餌</div>
                <h2 id="valmon-feed-confirm-title" class="mt-1 text-lg font-black text-slate-950">装備を与えますか？</h2>
            </div>
            <div class="space-y-4 px-5 py-4">
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="text-[11px] font-bold text-slate-500">消費する装備</div>
                    <div class="mt-1 text-base font-black text-slate-950" x-text="feedConfirmName"></div>
                    <div class="mt-1 text-xs font-bold text-slate-500">獲得EXP <span x-text="feedConfirmExp"></span></div>
                </div>
                <p class="text-sm font-bold leading-relaxed text-red-700">
                    餌にした装備は消滅し、元に戻せません。
                </p>
                <p x-show="feedConfirmHighRank" x-cloak
                   class="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm font-black leading-relaxed text-red-800">
                    ⚠ 高ランク・レア装備が含まれています。よろしいですか？
                </p>
            </div>
            <div class="flex gap-3 border-t border-slate-100 bg-slate-50 px-5 py-4">
                <button type="button"
                        @click="closeFeedConfirm()"
                        :disabled="feedConfirmSubmitting"
                        class="min-h-11 flex-1 rounded-lg border border-slate-300 bg-white px-4 text-sm font-black text-slate-700 shadow-sm hover:bg-slate-100 disabled:opacity-60">
                    キャンセル
                </button>
                <button type="button"
                        @click="submitFeedConfirm()"
                        :disabled="feedConfirmSubmitting || feedSubmitting"
                        class="min-h-11 flex-1 rounded-lg bg-red-700 px-4 text-sm font-black text-white shadow-sm hover:bg-red-800 disabled:opacity-60">
                    <span x-show="!feedConfirmSubmitting">餌にする</span>
                    <span x-show="feedConfirmSubmitting">処理中...</span>
                </button>
            </div>
        </div>
    </div>

</div>
</x-layouts.valmon-ranch>
