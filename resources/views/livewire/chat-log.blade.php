@php
    $drawerMode = (bool) $drawer;
    $resolvedDrawerAppearance = \App\Support\ChatDrawerAppearance::normalize($drawerAppearance);
    $drawerOwnTextColor = \App\Support\ChatDrawerAppearance::contrastTextColor($resolvedDrawerAppearance['own_bubble_color']);
    $drawerOtherTextColor = \App\Support\ChatDrawerAppearance::contrastTextColor($resolvedDrawerAppearance['other_bubble_color']);
    $drawerMetaTextColor = \App\Support\ChatDrawerAppearance::contrastTextColor($resolvedDrawerAppearance['background_color']);
    $drawerFontSizeOptions = \App\Support\ChatDrawerAppearance::fontSizeOptions();
    $drawerAppearancePresets = \App\Support\ChatDrawerAppearance::presets();
@endphp

<div
    wire:poll.60s.keep-alive="pollForUpdates"
    x-data="{
        drawerMode: @js($drawerMode),
        drawerOpen: false,
        settingsOpen: false,
        settingsModalOpen: false,
        stickToBottom: true,
        previousBodyOverflow: '',
        touchStartX: null,
        touchStartY: null,
        touchStartAtDrawerEdge: false,
        drawerTabStorageKey: 'valzeria.chat.drawer.active-tab',
        drawerTabRestoredFromSession: @js($drawerTabRestoredFromSession),
        drawerHintStorageKey: @js($battleDrawer ? 'valzeria.chat.drawer.battle-swipe-hint-v1-seen' : 'valzeria.chat.drawer.swipe-hint-seen'),
        showDrawerSwipeHint: false,
        availableDrawerTabs: @js(array_values(array_filter([
            'all',
            'system',
            'chat',
            $nationChatEnabled ? 'nation' : null,
            'private',
            'drop',
            'info',
        ]))),
        init() {
            if (!this.drawerMode) {
                this.scrollToBottom(false);
                return;
            }

            this.restoreDrawerTab();
            this.restoreDrawerSwipeHint();
        },
        restoreDrawerTab() {
            if (this.drawerTabRestoredFromSession) return;
            let storedTab = null;
            try {
                storedTab = window.localStorage.getItem(this.drawerTabStorageKey);
            } catch (error) {
                return;
            }

            if (!storedTab) return;
            if (!this.availableDrawerTabs.includes(storedTab)) {
                storedTab = 'all';
                this.rememberDrawerTab(storedTab);
            }

            if (storedTab !== @js($activeTab)) {
                this.$wire.setTab(storedTab);
            }
        },
        rememberDrawerTab(tab) {
            if (!this.drawerMode || !this.availableDrawerTabs.includes(tab)) return;
            try {
                window.localStorage.setItem(this.drawerTabStorageKey, tab);
            } catch (error) {
                // ブラウザが保存を拒否した場合は、現在の表示だけを維持する。
            }
        },
        restoreDrawerSwipeHint() {
            try {
                this.showDrawerSwipeHint = window.localStorage.getItem(this.drawerHintStorageKey) !== '1';
            } catch (error) {
                this.showDrawerSwipeHint = true;
            }
        },
        dismissDrawerSwipeHint() {
            if (!this.drawerMode || !this.showDrawerSwipeHint) return;
            this.showDrawerSwipeHint = false;
            try {
                window.localStorage.setItem(this.drawerHintStorageKey, '1');
            } catch (error) {
                // 保存不可でも、この画面では案内を停止する。
            }
        },
        scrollToBottom(smooth = false) {
            this.stickToBottom = true;
            this.$nextTick(() => {
                window.setTimeout(() => {
                    const scroller = this.$refs.logScroller;
                    if (!scroller) return;
                    scroller.scrollTo({
                        top: scroller.scrollHeight,
                        behavior: smooth ? 'smooth' : 'auto',
                    });
                }, 30);
            });
        },
        updateStickiness() {
            const scroller = this.$refs.logScroller;
            if (!scroller) return;
            this.stickToBottom = scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 72;
        },
        openDrawer() {
            if (!this.drawerMode || this.drawerOpen) return;
            this.dismissDrawerSwipeHint();
            this.previousBodyOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            this.drawerOpen = true;
            this.scrollToBottom(false);
        },
        closeDrawer() {
            if (!this.drawerMode || !this.drawerOpen) return;
            this.drawerOpen = false;
            this.settingsOpen = false;
            this.settingsModalOpen = false;
            document.body.style.overflow = this.previousBodyOverflow;
        },
        resetTouchGesture() {
            this.touchStartX = null;
            this.touchStartY = null;
            this.touchStartAtDrawerEdge = false;
        },
        handleTouchStart(event, fromEdgeHandle = false) {
            if (!this.drawerMode || !event.touches.length) return;
            const x = event.touches[0].clientX;
            const y = event.touches[0].clientY;
            this.touchStartX = x;
            this.touchStartY = y;
            if (!this.drawerOpen) {
                this.touchStartAtDrawerEdge = fromEdgeHandle || x >= window.innerWidth - 48;
                return;
            }
            const drawerLeft = this.$refs.drawerPanel?.getBoundingClientRect().left ?? window.innerWidth;
            this.touchStartAtDrawerEdge = x <= drawerLeft + 40;
        },
        handleTouchEnd(event) {
            if (!this.drawerMode || this.touchStartX === null || this.touchStartY === null || !event.changedTouches.length) return;
            const deltaX = event.changedTouches[0].clientX - this.touchStartX;
            const deltaY = event.changedTouches[0].clientY - this.touchStartY;
            const isHorizontalSwipe = Math.abs(deltaX) > Math.abs(deltaY) * 1.2;
            if (!this.drawerOpen && this.touchStartAtDrawerEdge && isHorizontalSwipe && deltaX < -48) {
                this.openDrawer();
            } else if (this.drawerOpen && this.touchStartAtDrawerEdge && isHorizontalSwipe && deltaX > 48) {
                this.closeDrawer();
            }
            this.resetTouchGesture();
        }
    }"
    x-init="init()"
    @visibilitychange.window="if (!document.hidden) { $wire.pollForUpdates() }"
    @open-chat-drawer.window="openDrawer()"
    @chat-drawer-tab-changed.window="rememberDrawerTab($event.detail.tab)"
    @chat-scroll-bottom.window="scrollToBottom(false)"
    @chat-logs-refreshed.window="if (stickToBottom) scrollToBottom(false)"
    @open-chat-settings-modal.window="settingsModalOpen = true"
    @keydown.escape.window="if (settingsModalOpen) { settingsModalOpen = false } else if (drawerOpen) { closeDrawer() }"
    @touchstart.window.passive="handleTouchStart($event)"
    @touchend.window.passive="handleTouchEnd($event)"
    @touchcancel.window.passive="resetTouchGesture()"
    @class([
        'relative w-full bg-white rounded-xl shadow-[0_8px_22px_rgba(126,96,28,0.18)] border border-[#d4af37] flex flex-col shrink-0 overflow-hidden font-sans' => ! $drawerMode,
        'h-[330px] md:h-[380px]' => ! $drawerMode && $isExpanded,
        'h-[250px] md:h-[280px]' => ! $drawerMode && ! $isExpanded,
    ])
    data-chat-log-mode="{{ $drawerMode ? 'drawer' : 'inline' }}"
>
    @if($nationUnreadPollingEnabled)
        <span class="sr-only" aria-hidden="true" wire:poll.15s="pollNationUnread"></span>
    @endif

    @if($drawerMode)
        <style>
            @keyframes chat-drawer-swipe-hint {
                0%, 100% { transform: translateX(0); opacity: 0.55; }
                50% { transform: translateX(-0.5rem); opacity: 1; }
            }

            .chat-drawer-swipe-hint-arrow {
                animation: chat-drawer-swipe-hint 1.6s ease-in-out infinite;
            }

            @media (prefers-reduced-motion: reduce) {
                .chat-drawer-swipe-hint-arrow { animation: none; }
            }
        </style>

        <div
            data-chat-drawer-edge-swipe
            x-show="!drawerOpen"
            class="fixed inset-y-0 right-0 z-[80] w-6 sm:hidden"
            style="display: none; touch-action: pan-y;"
            @touchstart.stop="handleTouchStart($event, true)"
            @touchmove.stop
            @touchend.stop="handleTouchEnd($event)"
            @touchcancel.stop="resetTouchGesture()"
        >
            <div
                x-show="showDrawerSwipeHint"
                x-transition.opacity.duration.200ms
                data-chat-drawer-swipe-hint
                class="pointer-events-none absolute right-4 top-1/2 flex -translate-y-1/2 items-center gap-1 rounded-l-full border border-blue-200 bg-white/95 py-1.5 pl-2.5 pr-3 text-[10px] font-black whitespace-nowrap text-blue-900 shadow-lg"
                style="display: none;"
                aria-hidden="true"
            >
                <svg class="chat-drawer-swipe-hint-arrow h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" />
                </svg>
                <span>右端から中央へスワイプ</span>
            </div>

            <button
                type="button"
                data-chat-drawer-edge-handle
                @click.stop="openDrawer()"
                class="absolute right-0 top-1/2 flex h-16 w-5 -translate-y-1/2 flex-col items-center justify-center gap-0.5 rounded-l-xl border border-r-0 border-blue-300 bg-blue-700 text-white shadow-lg transition active:bg-blue-800"
                aria-label="チャットを開く。右端から中央へスワイプしても開けます"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 3v-3a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" />
                </svg>
                <span class="text-sm font-black leading-none" aria-hidden="true">‹</span>
            </button>
        </div>

        <button
            type="button"
            x-show="drawerOpen"
            x-transition.opacity.duration.200ms
            @click="closeDrawer()"
            class="fixed inset-0 z-[90] bg-slate-950/45"
            style="display: none;"
            aria-label="チャットを閉じる"
        ></button>

        <aside
            x-ref="drawerPanel"
            x-show="drawerOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="fixed inset-y-0 right-0 z-[100] flex h-dvh w-[80vw] max-w-[28rem] flex-col overflow-hidden border-l border-[#d4af37] bg-white font-sans shadow-2xl"
            style="display: none; padding-top: env(safe-area-inset-top); padding-bottom: env(safe-area-inset-bottom);"
            role="dialog"
            aria-modal="true"
            aria-label="チャット"
        >
            <div class="flex shrink-0 items-center justify-between gap-3 border-b border-amber-200 bg-[#0a1628] px-3 py-2.5 text-white">
                <div class="flex min-w-0 items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#1e40af]" aria-hidden="true">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 3v-3a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm3 5a1 1 0 1 0 0 2 1 1 0 0 0 0-2Zm5 0a1 1 0 1 0 0 2 1 1 0 0 0 0-2Zm5 0a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z"/>
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <div class="text-sm font-black tracking-wide">チャット</div>
                        <div class="text-[10px] font-bold text-slate-300">最新の発言が一番下に表示されます</div>
                    </div>
                </div>
                <button
                    type="button"
                    @click="closeDrawer()"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-white/20 bg-white/10 text-xl font-black transition hover:bg-white/20 active:scale-95"
                    aria-label="チャットを閉じる"
                >×</button>
            </div>
    @endif

    <!-- タブ -->
    <div class="relative flex shrink-0 items-stretch border-b border-gray-200 bg-gray-50 text-[11px] font-sans font-bold text-gray-500">
        <div class="flex min-w-0 flex-1 overflow-x-auto">
            <button @click="rememberDrawerTab('all')" wire:click="setTab('all')" class="px-4 py-2 whitespace-nowrap {{ $activeTab === 'all' ? 'bg-white text-[#1e40af] border-t-2 border-[#1e40af]' : 'hover:bg-white border-t-2 border-transparent' }}">全体</button>
            <button @click="rememberDrawerTab('system')" wire:click="setTab('system')" class="px-4 py-2 whitespace-nowrap {{ $activeTab === 'system' ? 'bg-white text-[#1e40af] border-t-2 border-[#1e40af]' : 'hover:bg-white border-t-2 border-transparent' }}">システム</button>
            <button @click="rememberDrawerTab('chat')" wire:click="setTab('chat')" class="px-4 py-2 whitespace-nowrap {{ $activeTab === 'chat' ? 'bg-white text-[#1e40af] border-t-2 border-[#1e40af]' : 'hover:bg-white border-t-2 border-transparent' }}">チャット</button>
            @if($nationChatEnabled)
                <button data-chat-nation-tab @click="rememberDrawerTab('nation')" wire:click="setTab('nation')" class="px-4 py-2 whitespace-nowrap {{ $activeTab === 'nation' ? 'bg-white text-[#1e40af] border-t-2 border-[#1e40af]' : 'hover:bg-white border-t-2 border-transparent' }}">国家</button>
            @endif
            <button @click="rememberDrawerTab('private')" wire:click="setTab('private')" class="px-4 py-2 whitespace-nowrap {{ $activeTab === 'private' ? 'bg-white text-[#1e40af] border-t-2 border-[#1e40af]' : 'hover:bg-white border-t-2 border-transparent' }}">個人(手紙)</button>
            <button @click="rememberDrawerTab('drop')" wire:click="setTab('drop')" class="px-4 py-2 whitespace-nowrap {{ $activeTab === 'drop' ? 'bg-white text-[#1e40af] border-t-2 border-[#1e40af]' : 'hover:bg-white border-t-2 border-transparent text-gray-400' }}">レアドロップ</button>
            <button @click="rememberDrawerTab('info')" wire:click="setTab('info')" class="px-4 py-2 whitespace-nowrap {{ $activeTab === 'info' ? 'bg-white text-[#1e40af] border-t-2 border-[#1e40af]' : 'hover:bg-white border-t-2 border-transparent text-gray-400' }}">お知らせ</button>
        </div>
        <button
            type="button"
            @click="settingsOpen = !settingsOpen"
            class="shrink-0 border-l border-gray-200 bg-white px-3 py-2 text-sm font-black leading-none text-gray-500 hover:bg-blue-50 hover:text-[#1e40af]"
            aria-label="{{ $drawerMode ? '右側チャット設定' : '全体チャット表示設定' }}"
            title="{{ $drawerMode ? '右側チャット設定' : '全体チャット表示設定' }}"
        >⚙</button>
        @unless($drawerMode)
            <button
                wire:click="toggleExpanded"
                type="button"
                class="shrink-0 border-l border-gray-200 bg-white px-3 py-2 text-sm font-black leading-none text-[#1e40af] hover:bg-blue-50"
                aria-label="{{ $isExpanded ? 'チャット欄を短くする' : 'チャットを15行多く表示する' }}"
                title="{{ $isExpanded ? 'チャット欄を短くする' : 'チャットを15行多く表示する' }}"
            >{{ $isExpanded ? '▲' : '▼' }}</button>
        @endunless

        <div
            x-show="settingsOpen"
            x-transition
            @click.outside="settingsOpen = false"
            class="absolute right-2 top-10 z-30 max-h-[calc(100dvh-7rem)] w-[calc(100%-1rem)] max-w-[21rem] overflow-y-auto rounded-lg border border-gray-200 bg-white p-3 shadow-xl"
            style="display: none;"
        >
            <div class="mb-2 flex items-center justify-between gap-2 border-b border-gray-100 pb-2">
                <span class="text-[12px] font-black text-slate-700">{{ $drawerMode ? '右側チャット設定' : '全体チャット' }}</span>
                <button type="button" @click="settingsOpen = false" class="rounded px-2 py-1 text-[12px] font-black text-gray-400 hover:bg-gray-50 hover:text-gray-700" aria-label="閉じる">×</button>
            </div>
            @if($drawerMode)
                <section data-chat-drawer-appearance-settings class="mb-3 rounded-lg border border-blue-100 bg-blue-50/60 p-3">
                    <div class="text-[12px] font-black text-slate-800">見た目</div>
                    <div class="mt-0.5 text-[10px] font-bold text-slate-500">この冒険者の右側チャットだけに反映・自動保存されます</div>

                    <div data-chat-drawer-presets class="mt-3">
                        <div class="mb-1.5 text-[11px] font-black text-slate-700">プリセット</div>
                        <div class="grid grid-cols-1 gap-1.5 min-[360px]:grid-cols-2">
                            @foreach($drawerAppearancePresets as $preset)
                                @php($presetSelected = \App\Support\ChatDrawerAppearance::matchesPreset($resolvedDrawerAppearance, $preset['key']))
                                <button
                                    type="button"
                                    wire:key="chat-drawer-preset-{{ $preset['key'] }}"
                                    wire:click="applyDrawerAppearancePreset('{{ $preset['key'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="applyDrawerAppearancePreset"
                                    class="rounded-md border px-2 py-2 text-left transition disabled:opacity-60 {{ $presetSelected ? 'border-blue-700 bg-blue-50 ring-1 ring-blue-700' : 'border-slate-200 bg-white hover:border-blue-300' }}"
                                    aria-pressed="{{ $presetSelected ? 'true' : 'false' }}"
                                    title="{{ $preset['description'] }}"
                                >
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="text-[10px] font-black text-slate-700">{{ $preset['label'] }}</span>
                                        <span class="flex overflow-hidden rounded-full border border-slate-300" aria-hidden="true">
                                            <span class="h-3 w-3" style="background-color: {{ $preset['colors']['background_color'] }};"></span>
                                            <span class="h-3 w-3" style="background-color: {{ $preset['colors']['other_bubble_color'] }};"></span>
                                            <span class="h-3 w-3" style="background-color: {{ $preset['colors']['own_bubble_color'] }};"></span>
                                        </span>
                                    </span>
                                    <span class="mt-0.5 block text-[9px] font-bold text-slate-500">{{ $preset['description'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="mt-3 grid gap-2">
                        @foreach([
                            'own_bubble_color' => '自分の吹き出し',
                            'other_bubble_color' => '他プレイヤーの吹き出し',
                            'background_color' => '背景',
                        ] as $colorKey => $colorLabel)
                            <label class="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-md border border-white bg-white/80 px-2.5 py-2">
                                <span class="text-[11px] font-black text-slate-700">{{ $colorLabel }}</span>
                                <span class="flex items-center gap-2">
                                    <span class="font-mono text-[10px] font-bold text-slate-500">{{ $resolvedDrawerAppearance[$colorKey] }}</span>
                                    <input
                                        type="color"
                                        value="{{ $resolvedDrawerAppearance[$colorKey] }}"
                                        wire:key="chat-drawer-color-{{ $colorKey }}-{{ $resolvedDrawerAppearance[$colorKey] }}"
                                        wire:change="setDrawerAppearanceColor('{{ $colorKey }}', $event.target.value)"
                                        class="h-7 w-10 cursor-pointer rounded border border-slate-200 bg-white p-0.5"
                                        aria-label="{{ $colorLabel }}の色"
                                    >
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div class="mt-3">
                        <div class="mb-1.5 text-[11px] font-black text-slate-700">文字の大きさ</div>
                        <div class="grid grid-cols-4 gap-1">
                            @foreach($drawerFontSizeOptions as $fontSizeOption)
                                <button
                                    type="button"
                                    wire:click="setDrawerAppearanceFontSize('{{ $fontSizeOption['key'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="setDrawerAppearanceFontSize"
                                    class="rounded-md border px-1 py-1.5 text-[10px] font-black transition disabled:opacity-60 {{ $resolvedDrawerAppearance['font_size'] === $fontSizeOption['key'] ? 'border-blue-700 bg-blue-700 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-blue-300' }}"
                                    aria-pressed="{{ $resolvedDrawerAppearance['font_size'] === $fontSizeOption['key'] ? 'true' : 'false' }}"
                                >{{ $fontSizeOption['label'] }}</button>
                            @endforeach
                        </div>
                    </div>

                    <button
                        type="button"
                        wire:click="resetDrawerAppearance"
                        wire:loading.attr="disabled"
                        wire:target="resetDrawerAppearance"
                        class="mt-3 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-[11px] font-black text-slate-600 hover:border-slate-300 hover:bg-slate-50 disabled:opacity-60"
                    >デフォルトに戻す</button>
                </section>
                <div class="mb-2 text-[11px] font-black text-slate-700">全体タブの表示項目</div>
            @endif
            <div class="grid gap-2">
                @foreach($allTabFilterOptions as $option)
                    <div wire:key="all-tab-filter-{{ $option['key'] }}" class="flex items-center justify-between gap-3 rounded-md border border-gray-100 bg-gray-50 px-3 py-2">
                        <div class="min-w-0 truncate text-[12px] font-black text-slate-700">{{ $option['label'] }}</div>
                        <button
                            type="button"
                            wire:click="setAllTabVisibility('{{ $option['key'] }}', {{ $option['enabled'] ? 'false' : 'true' }})"
                            wire:loading.attr="disabled"
                            wire:target="setAllTabVisibility"
                            class="relative h-6 w-11 shrink-0 rounded-full transition disabled:pointer-events-none {{ $option['enabled'] ? 'bg-[#1e40af]' : 'bg-gray-300' }}"
                            aria-label="{{ $option['label'] }}を{{ $option['enabled'] ? '非表示' : '表示' }}"
                        >
                            <span class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition {{ $option['enabled'] ? 'left-5' : 'left-0.5' }}"></span>
                        </button>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div
        x-show="settingsModalOpen"
        x-transition.opacity
        class="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/45 px-4 py-6"
        style="display: none;"
        role="dialog"
        aria-modal="true"
        aria-label="チャット表示項目"
    >
        <div @click.outside="settingsModalOpen = false" class="w-full max-w-sm overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl">
            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
                <div>
                    <div class="text-sm font-black text-slate-800">チャット表示項目</div>
                    <div class="mt-0.5 text-[11px] font-bold text-slate-500">全体チャットに表示する項目</div>
                </div>
                <button type="button" @click="settingsModalOpen = false" class="flex h-8 w-8 items-center justify-center rounded-full text-lg font-black text-gray-400 hover:bg-gray-50 hover:text-gray-700" aria-label="閉じる">×</button>
            </div>
            <div class="max-h-[min(70vh,28rem)] overflow-y-auto p-3">
                <div class="grid gap-2">
                    @foreach($allTabFilterOptions as $option)
                        <div wire:key="all-tab-modal-filter-{{ $option['key'] }}" class="flex items-center justify-between gap-3 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2.5">
                            <div class="min-w-0 truncate text-[13px] font-black text-slate-700">{{ $option['label'] }}</div>
                            <button
                                type="button"
                                wire:click="setAllTabVisibility('{{ $option['key'] }}', {{ $option['enabled'] ? 'false' : 'true' }})"
                                wire:loading.attr="disabled"
                                wire:target="setAllTabVisibility"
                                class="relative h-6 w-11 shrink-0 rounded-full transition disabled:pointer-events-none {{ $option['enabled'] ? 'bg-[#1e40af]' : 'bg-gray-300' }}"
                                aria-label="{{ $option['label'] }}を{{ $option['enabled'] ? '非表示' : '表示' }}"
                            >
                                <span class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition {{ $option['enabled'] ? 'left-5' : 'left-0.5' }}"></span>
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    @if($drawerMode)
    <!-- 古い発言を上、新しい発言を下に並べる -->
    <div
        x-ref="logScroller"
        @scroll.passive="updateStickiness()"
        class="flex-grow overflow-y-auto p-3 font-sans leading-relaxed sm:p-4"
        style="--chat-drawer-background: {{ $resolvedDrawerAppearance['background_color'] }}; --chat-own-bubble-background: {{ $resolvedDrawerAppearance['own_bubble_color'] }}; --chat-own-bubble-text: {{ $drawerOwnTextColor }}; --chat-other-bubble-background: {{ $resolvedDrawerAppearance['other_bubble_color'] }}; --chat-other-bubble-text: {{ $drawerOtherTextColor }}; --chat-drawer-meta-color: {{ $drawerMetaTextColor }}; --chat-drawer-font-size: {{ \App\Support\ChatDrawerAppearance::fontSizePixels($resolvedDrawerAppearance['font_size']) }}px; background-color: var(--chat-drawer-background); font-size: var(--chat-drawer-font-size);"
        data-chat-log-scroller
        data-chat-line-presentation
        data-chat-drawer-customized
    >
        @if($activeTab !== 'nation' && $logLimit < \App\Livewire\ChatLog::LOG_MAX)
            <div class="pb-3 text-center">
                <button wire:click="loadMore" class="rounded-full border border-slate-200 bg-white/90 px-3 py-1 text-[10px] font-black text-[#1e40af] shadow-sm hover:bg-white">
                    過去の発言をもっとよむ（現在 {{ $logLimit }} 件）
                </button>
            </div>
        @endif

        @if($activeTab === 'nation' && ! $nationChatAvailable)
            <div data-chat-nation-unavailable class="rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 font-bold text-blue-800">
                国家へ所属すると、自国の国民だけで会話できます。
            </div>
        @elseif($activeTab === 'nation' && $systemLogs === [])
            <div data-chat-nation-empty class="rounded-lg border border-gray-100 bg-white/80 px-3 py-2 text-center font-bold text-gray-500">
                まだ国家チャットの発言はありません。
            </div>
        @endif

        <div class="space-y-2.5">
            @foreach($systemLogs as $log)
                <div wire:key="chat-log-{{ $log['id'] }}" class="flex items-end gap-1.5 {{ $log['is_sender'] ? 'justify-end' : 'justify-start' }}">
                    @unless($log['is_sender'])
                        <img src="{{ $log['avatar_url'] }}" alt="" loading="lazy" class="h-9 w-9 shrink-0 rounded-full border border-white/90 bg-white object-contain shadow-sm">
                    @endunless

                    <div class="flex max-w-[76%] flex-col {{ $log['is_sender'] ? 'items-end' : 'items-start' }}">
                        @if($log['reply_id'])
                            <button type="button" wire:click="setReplyTarget({{ $log['reply_id'] }})" @click="window.setTimeout(() => document.getElementById('chat-message-input')?.focus(), 100)" class="mb-0.5 px-1 text-[10px] font-black hover:underline" style="color: var(--chat-drawer-meta-color);" title="タップして返信">{{ $log['author_name'] }}</button>
                        @else
                            <span class="mb-0.5 px-1 text-[10px] font-black" style="color: var(--chat-drawer-meta-color);">{{ $log['author_name'] }}</span>
                        @endif

                        <div class="flex items-end gap-1 {{ $log['is_sender'] ? 'flex-row-reverse' : '' }}">
                            <div
                                class="min-w-0 rounded-2xl px-3 py-2 shadow-sm {{ $log['is_sender'] ? 'rounded-br-sm' : 'rounded-bl-sm border border-white/90' }}"
                                style="background-color: var(--chat-{{ $log['is_sender'] ? 'own' : 'other' }}-bubble-background); color: var(--chat-{{ $log['is_sender'] ? 'own' : 'other' }}-bubble-text);"
                                data-chat-bubble-owner="{{ $log['is_sender'] ? 'own' : 'other' }}"
                            >
                                @if($editingLogId === $log['id'])
                                    <form wire:submit="updateMessage" class="flex min-w-0 flex-col gap-1.5">
                                        <input type="text" wire:model="editingMessage" maxlength="100" class="h-8 min-w-0 rounded border-gray-300 bg-white px-2 py-1 text-[11px] text-slate-800 focus:border-[#1e40af] focus:ring-[#1e40af]">
                                        <div class="flex justify-end gap-1">
                                            <button type="button" wire:click="cancelEdit" class="rounded bg-white/80 px-2 py-1 text-[10px] font-black text-gray-500">やめる</button>
                                            <button type="submit" wire:loading.attr="disabled" wire:target="updateMessage" class="rounded bg-[#1e40af] px-2 py-1 text-[10px] font-black text-white disabled:opacity-60">
                                                <span wire:loading.remove wire:target="updateMessage">保存</span>
                                                <span wire:loading wire:target="updateMessage">保存中...</span>
                                            </button>
                                        </div>
                                    </form>
                                @else
                                    <div class="whitespace-pre-wrap break-words
                                        @if(str_contains($log['message'] ?? '', '【星樹の塔】') && str_contains($log['message'] ?? '', '100階を踏破しました')) text-pink-600 font-black
                                        @elseif($log['type'] == 'system' || $log['type'] == 'newcomer') text-orange-600 font-bold
                                        @elseif($log['type'] == 'drop') text-fuchsia-700 font-bold
                                        @elseif($log['type'] == 'job') text-purple-600 font-bold
                                        @elseif($log['type'] == 'arena') text-amber-700 font-bold
                                        @elseif($log['type'] == 'duel') text-red-600 font-bold
                                        @elseif($log['type'] == 'admin') text-[#1e40af] font-black
                                        @elseif($log['type'] == 'notice') text-cyan-700 font-black
                                        @elseif($log['type'] == 'valmon') text-teal-600 font-bold
                                        @elseif($log['type'] == 'sub_area') text-cyan-600 font-bold
                                        @endif
                                    ">{{ $log['message'] }}</div>
                                @endif
                            </div>
                            <span class="shrink-0 pb-0.5 text-[9px] font-bold" style="color: var(--chat-drawer-meta-color);">{{ $log['time'] }}</span>
                        </div>

                        @if($editingLogId !== $log['id'] && ($log['is_edited'] || $log['can_edit']))
                            <div class="mt-0.5 flex items-center gap-1 px-1 text-[9px] font-bold" style="color: var(--chat-drawer-meta-color);">
                                @if($log['is_edited'])<span>修正済み</span>@endif
                                @if($log['can_edit'])<button type="button" wire:click="startEdit({{ $log['id'] }})" class="hover:underline">修正</button>@endif
                            </div>
                        @endif
                    </div>

                    @if($log['is_sender'])
                        <img src="{{ $log['avatar_url'] }}" alt="" loading="lazy" class="h-9 w-9 shrink-0 rounded-full border border-white/90 bg-white object-contain shadow-sm">
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    @else
        <!-- 従来の画面下部チャット表示 -->
        <div class="p-3 flex-grow overflow-y-auto space-y-1 text-[11px] bg-white font-sans leading-relaxed" data-chat-inline-presentation>
            @if($activeTab === 'nation' && ! $nationChatAvailable)
                <div data-chat-nation-unavailable class="rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 font-bold text-blue-800">
                    国家へ所属すると、自国の国民だけで会話できます。
                </div>
            @elseif($activeTab === 'nation' && $systemLogs === [])
                <div data-chat-nation-empty class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 font-bold text-gray-500">
                    まだ国家チャットの発言はありません。
                </div>
            @endif

            @foreach($systemLogs as $log)
                <div class="flex" wire:key="chat-log-{{ $log['id'] }}">
                    <span class="text-gray-400 w-10 shrink-0">{{ $log['time'] }}</span>
                    <span class="
                        @if(str_contains($log['message'] ?? '', '【星樹の塔】') && str_contains($log['message'] ?? '', '100階を踏破しました')) text-pink-600 font-black
                        @elseif($log['type'] == 'system' || $log['type'] == 'newcomer') text-orange-600 font-bold
                        @elseif($log['type'] == 'chat') text-green-700 font-bold
                        @elseif($log['type'] == 'nation') text-blue-700 font-bold
                        @elseif($log['type'] == 'private')
                            @if(isset($log['is_sender']) && $log['is_sender']) text-slate-900 font-bold
                            @else text-pink-600 font-bold
                            @endif
                        @elseif($log['type'] == 'drop')
                            @if(str_contains($log['message'] ?? '', 'SSSランク') || str_contains($log['message'] ?? '', 'EPICランク')) text-fuchsia-600 font-bold
                            @else text-yellow-600 font-bold
                            @endif
                        @elseif($log['type'] == 'job') text-purple-600 font-bold
                        @elseif($log['type'] == 'arena') text-amber-700 font-bold
                        @elseif($log['type'] == 'duel') text-red-600 font-bold
                        @elseif($log['type'] == 'admin') text-[#1e40af] font-black
                        @elseif($log['type'] == 'notice') text-cyan-700 font-black
                        @elseif($log['type'] == 'guild') text-blue-600 font-bold
                        @elseif($log['type'] == 'valmon') text-teal-600 font-bold
                        @elseif($log['type'] == 'sub_area') text-cyan-600 font-bold
                        @elseif($log['type'] == 'growth') text-gray-700 font-medium
                        @else text-gray-700 font-medium
                        @endif
                    ">
                        @if($editingLogId === $log['id'])
                            <form wire:submit="updateMessage" class="inline-flex max-w-full flex-wrap items-center gap-1">
                                @if(isset($log['reply_prefix']) && $log['reply_prefix'])
                                    <span>{{ $log['reply_prefix'] }}</span>
                                @endif
                                <input type="text" wire:model="editingMessage" maxlength="100" class="h-7 min-w-[12rem] max-w-full rounded border-gray-300 px-2 py-1 text-[11px] text-slate-800 focus:border-[#1e40af] focus:ring-[#1e40af]">
                                <button type="submit" wire:loading.attr="disabled" wire:target="updateMessage" class="rounded bg-[#1e40af] px-2 py-1 text-[10px] font-black text-white hover:bg-[#1e3a8a] disabled:cursor-wait disabled:opacity-60">
                                    <span wire:loading.remove wire:target="updateMessage">保存</span>
                                    <span wire:loading wire:target="updateMessage">保存中...</span>
                                </button>
                                <button type="button" wire:click="cancelEdit" class="rounded border border-gray-200 bg-white px-2 py-1 text-[10px] font-black text-gray-500 hover:bg-gray-50">やめる</button>
                            </form>
                        @else
                            @if(isset($log['reply_prefix']) && $log['reply_prefix'])
                                @if(isset($log['reply_id']) && $log['reply_id'])
                                    <span wire:click="setReplyTarget({{ $log['reply_id'] }})" onclick="setTimeout(() => { document.getElementById('chat-message-input').focus(); }, 100);" class="cursor-pointer hover:underline" title="タップして返信">{{ $log['reply_prefix'] }}</span>
                                @else
                                    <span>{{ $log['reply_prefix'] }}</span>
                                @endif
                            @endif
                            {{ $log['message'] }}
                            @if($log['is_edited'])
                                <span class="ml-1 text-[10px] font-bold text-gray-400">修正済み</span>
                            @endif
                            @if($log['can_edit'])
                                <button type="button" wire:click="startEdit({{ $log['id'] }})" class="ml-1 rounded border border-gray-200 bg-white px-1.5 py-0.5 text-[10px] font-black text-gray-500 hover:bg-gray-50">修正</button>
                            @endif
                        @endif
                    </span>
                </div>
            @endforeach

            @if($activeTab !== 'nation' && $logLimit < \App\Livewire\ChatLog::LOG_MAX)
                <div class="pt-1">
                    <button wire:click="loadMore" class="w-full py-0.5 text-center text-[10px] font-bold text-[#1e40af] hover:underline">
                        もっとよむ（現在 {{ $logLimit }} 件 / 最大{{ \App\Livewire\ChatLog::LOG_MAX }}件）
                    </button>
                </div>
            @endif
        </div>
    @endif

    <!-- チャット入力欄 -->
    @if($activeTab !== 'nation' || $nationChatAvailable)
        <form wire:submit="sendMessage" class="flex min-w-0 shrink-0 items-center gap-1.5 border-t border-gray-200 bg-gray-50 p-2">
            @if($activeTab === 'nation')
                <span data-chat-nation-target class="w-[4.75rem] shrink-0 rounded border border-blue-200 bg-blue-50 px-2 py-1.5 text-center text-[11px] font-black text-blue-800">国家</span>
            @else
                <select wire:model.live="chatTarget" class="w-[4.75rem] shrink-0 rounded border-gray-300 bg-white py-1.5 pl-2 pr-6 font-sans text-[11px] text-gray-700 focus:ring-[#1e40af]">
                    <option value="all">全体</option>
                    <option value="private">個人</option>
                </select>

                @if($chatTarget === 'private')
                    <select wire:model="receiverId" class="w-[7rem] min-w-0 shrink-0 truncate rounded border-gray-300 bg-white py-1.5 pl-2 pr-6 font-sans text-[11px] text-gray-700 focus:ring-[#1e40af] sm:w-[8.25rem]">
                        @foreach($availableReceivers as $receiver)
                            <option value="{{ $receiver->id }}">{{ $receiver->name }}</option>
                        @endforeach
                    </select>
                @endif
            @endif

            <input
                type="text"
                id="chat-message-input"
                wire:model="message"
                placeholder="{{ $activeTab === 'nation' ? '国家へメッセージ' : 'メッセージ' }}"
                required
                maxlength="100"
                class="min-w-0 basis-0 flex-1 rounded border-gray-300 px-3 py-1.5 text-[11px] focus:border-[#1e40af] focus:ring-[#1e40af]"
            >

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="sendMessage"
                aria-label="送信"
                title="送信"
                class="flex h-9 w-10 shrink-0 items-center justify-center rounded-lg bg-[#1e40af] text-lg font-bold text-white shadow hover:bg-[#1e3a8a] disabled:cursor-wait disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="sendMessage" aria-hidden="true">➤</span>
                <span wire:loading wire:target="sendMessage" class="submit-lock-spinner" aria-hidden="true"></span>
            </button>
        </form>
        @error('message')
            <div class="shrink-0 border-t border-red-100 bg-red-50 px-3 py-1 text-[10px] font-bold text-red-700">{{ $message }}</div>
        @enderror
    @else
        <div data-chat-nation-disabled class="shrink-0 border-t border-gray-200 bg-gray-50 px-3 py-2 text-center text-[11px] font-bold text-gray-500">
            国家へ所属すると送信できます。
        </div>
    @endif

    @if($drawerMode)
        </aside>
    @endif
</div>
