@php
    $explorationSupportEnabled = app(\App\Services\ExplorationSupportService::class)->isEnabled();
@endphp
<div class="flex flex-col gap-4 text-sm font-sans w-full h-full"
     x-data="{
         isModalOpen: false,
         modalMessage: '',
         modalTitle: 'システム',
         playerInfo: null,
         belongingsModalOpen: false,
         belongingsLoading: false,
         belongingsHtml: '',
         openModal(title, message) {
             this.modalTitle = title;
             this.modalMessage = message;
             this.playerInfo = null;
             this.isModalOpen = true;
         },
         openPlayerModal(player) {
             this.playerInfo = player;
             this.modalTitle = player.name;
             this.isModalOpen = true;
         },
         async openBelongingsModal() {
             this.belongingsModalOpen = true;
             this.belongingsLoading = true;
             try {
                 const response = await fetch(@js(route('apothecary.belongings')), {
                     headers: { 'X-Requested-With': 'XMLHttpRequest' },
                 });
                 this.belongingsHtml = await response.text();
                 this.$nextTick(() => {
                     this.$refs.belongingsBody?.querySelectorAll('script').forEach((oldScript) => {
                         const newScript = document.createElement('script');
                         newScript.textContent = oldScript.textContent;
                         oldScript.replaceWith(newScript);
                     });
                 });
             } catch (error) {
                 this.belongingsHtml = '<p class=\'text-sm text-red-600 font-bold\'>読み込みに失敗しました。</p>';
             } finally {
                 this.belongingsLoading = false;
             }
         }
     }">


    <!-- 2. 中段：メイン2カラム -->
    <div class="flex flex-col md:flex-row gap-4 flex-grow overflow-hidden relative">


        <!-- 右カラム（旧）：移動メニューとメイン画面 -->
        <div class="w-full flex flex-col gap-0 rounded-xl overflow-hidden shadow-sm shrink-0 min-h-[80vh] {{ (!empty($isFerdiaRegion) || !empty($isFerdiaSimpleBase)) ? 'border border-emerald-300/70 bg-white' : 'bg-white border border-[#d4af37]' }}">

            <!-- タブナビゲーション -->
            <livewire:nav-menu />

            <!-- メインコンテンツ表示枠（施設ハブ） -->
            <div class="p-4 flex-grow flex flex-col relative {{ (!empty($isFerdiaRegion) || !empty($isFerdiaSimpleBase)) ? 'bg-emerald-50/15' : 'bg-white' }}"
                 wire:loading.class.add="opacity-40 pointer-events-none"
                 wire:target="changeLocation">
                <div wire:loading wire:target="changeLocation"
                     class="absolute inset-0 z-50 flex items-center justify-center rounded-b-xl {{ (!empty($isFerdiaRegion) || !empty($isFerdiaSimpleBase)) ? 'bg-white/70' : 'bg-white/60' }}">
                    <svg class="w-8 h-8 animate-spin {{ (!empty($isFerdiaRegion) || !empty($isFerdiaSimpleBase)) ? 'text-emerald-600' : 'text-[#d4af37]' }}" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                </div>

                <!-- 現在地ヘッダー -->
                @if($currentLocation !== 'home')
                    @php
                        $locationHeaderTitle = $currentLocation === 'town'
                            ? (!empty($isFerdiaSimpleBase) ? 'フェルディア簡易拠点' : '街の施設')
                            : ($locationData['title'] ?? '');
                    @endphp
                        <div class="mb-4 pb-2 border-b-2 flex items-start justify-between gap-3 {{ (!empty($isFerdiaRegion) || !empty($isFerdiaSimpleBase)) ? 'border-emerald-100' : 'border-gray-100' }}">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="shrink-0 text-2xl {{ (!empty($isFerdiaRegion) || !empty($isFerdiaSimpleBase)) ? 'text-emerald-600' : 'text-[#d4af37]' }}">⚜</span>
                                    <h3 class="text-xl min-w-0 truncate font-bold {{ (!empty($isFerdiaRegion) || !empty($isFerdiaSimpleBase)) ? 'text-slate-900' : 'text-[#1e293b]' }}">
                                        {{ $locationHeaderTitle }}
                                    </h3>
                                </div>
                                @if(!empty($locationData['description']))
                                    <p class="mt-2 text-xs leading-relaxed text-gray-600">
                                        {{ $locationData['description'] }}
                                    </p>
                                @endif
                            </div>
                            @if(in_array($currentLocation, ['town', 'dungeon', 'guild'], true))
                                <div class="shrink-0 flex items-center gap-2">
                                    @if($explorationSupportEnabled)
                                        <button type="button"
                                                @click="openBelongingsModal()"
                                                class="shrink-0 rounded-md border bg-white px-3 py-1.5 text-[12px] font-black shadow-sm transition active:scale-95 {{ (!empty($isFerdiaRegion) || !empty($isFerdiaSimpleBase)) ? 'border-emerald-200 text-emerald-700 hover:bg-emerald-50/50' : 'border-[#d4af37] text-[#9a6b00] hover:bg-amber-50' }}">
                                            もちもの
                                        </button>
                                    @endif
                                    <button type="button"
                                            @click="window.dispatchEvent(new CustomEvent('main-tab-selected', { detail: { location: 'move' } })); $dispatch('changeTab', { newLocation: 'move' })"
                                            class="shrink-0 rounded-md border bg-white px-3 py-1.5 text-[12px] font-black shadow-sm transition active:scale-95 {{ (!empty($isFerdiaRegion) || !empty($isFerdiaSimpleBase)) ? 'border-emerald-200 text-emerald-700 hover:bg-emerald-50/50' : 'border-[#d4af37] text-[#9a6b00] hover:bg-amber-50' }}">
                                        MAPへ
                                    </button>
                                </div>
                            @endif
                        </div>
                @endif

                @if($currentLocation === 'dungeon' && !empty($hasActiveValmonEgg))
                    <section class="mb-4 rounded-xl border-2 border-rose-300 bg-rose-50 px-4 py-3 shadow-sm" role="status">
                        <div class="flex items-start gap-3">
                            <img src="{{ asset('images/icon/icon_038.webp') }}" alt="" class="h-9 w-9 shrink-0 object-contain">
                            <div class="min-w-0">
                                <h2 class="text-sm font-black text-rose-950">ヴァルモンの卵を預かっている</h2>
                                <p class="mt-1 text-xs font-bold leading-relaxed text-rose-800">探索中に敗北すると卵を失います。探索を終えて街へ戻ると孵化します。</p>
                            </div>
                        </div>
                    </section>
                @endif

                @if(!empty($beginnerMissions['reward_granted']))
                    <section class="mb-4 rounded-lg border border-emerald-300 bg-emerald-50/90 shadow-sm overflow-hidden">
                        <div class="p-3 bg-white/70">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 text-[12px] font-extrabold text-emerald-700">
                                        <span>{{ $beginnerMissions['reward_title'] ?? (($beginnerMissions['label'] ?? 'ミッション') . '達成') }}</span>
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] text-emerald-800">
                                            {{ $beginnerMissions['completed'] }} / {{ $beginnerMissions['total'] }}
                                        </span>
                                    </div>
                                    <div class="mt-1 text-base font-extrabold text-slate-900 leading-tight">
                                        {{ $beginnerMissions['reward_name'] }}を獲得しました
                                    </div>
                                    <div class="mt-0.5 text-xs font-semibold text-slate-600 leading-snug">
                                        {{ $beginnerMissions['reward_message'] ?? '報酬は装備変更から確認できます。' }}
                                    </div>
                                </div>
                                <div class="shrink-0">
                                    <a href="{{ route('equipment.index') }}" wire:navigate
                                       class="inline-flex items-center justify-center rounded bg-[#1e40af] px-3 py-2 text-xs font-bold text-white shadow border border-[#1e3a8a] active:scale-95">
                                        装備変更へ
                                    </a>
                                </div>
                            </div>
                        </div>
                    </section>
                @elseif(!empty($beginnerMissions['should_show']) && !empty($beginnerMissions['current']))
                    @php
                        $currentMission = $beginnerMissions['current'];
                    @endphp
                    <section class="mb-4 rounded-lg border border-amber-300 bg-amber-50/90 shadow-sm overflow-hidden">
                        <div class="p-3 border-b border-amber-200 bg-white/70">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 text-[12px] font-extrabold text-amber-700">
                                        <span>{{ $beginnerMissions['label'] ?? 'ミッション' }}</span>
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] text-amber-800">
                                            {{ $beginnerMissions['completed'] }} / {{ $beginnerMissions['total'] }}
                                        </span>
                                    </div>
                                    <div class="mt-1 text-base font-extrabold text-slate-900 leading-tight">
                                        {{ $currentMission['title'] }}
                                    </div>
                                    <div class="mt-0.5 text-xs font-semibold text-slate-600 leading-snug">
                                        {{ $currentMission['desc'] }}
                                    </div>
                                </div>
                                <div class="shrink-0">
                                    @if(!empty($currentMission['route']))
                                        <a href="{{ route($currentMission['route']) }}" wire:navigate
                                           class="inline-flex items-center justify-center rounded bg-[#1e40af] px-3 py-2 text-xs font-bold text-white shadow border border-[#1e3a8a] active:scale-95">
                                            {{ $currentMission['action_label'] }}
                                        </a>
                                    @elseif(!empty($currentMission['tab']))
                                        <button type="button"
                                                @click="window.dispatchEvent(new CustomEvent('main-tab-selected', { detail: { location: '{{ $currentMission['tab'] }}' } })); $dispatch('changeTab', { newLocation: '{{ $currentMission['tab'] }}' })"
                                                class="inline-flex items-center justify-center rounded bg-[#1e40af] px-3 py-2 text-xs font-bold text-white shadow border border-[#1e3a8a] active:scale-95">
                                            {{ $currentMission['action_label'] }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-amber-100">
                                <div class="h-full rounded-full bg-amber-500" style="width: {{ $beginnerMissions['percent'] }}%;"></div>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 p-2">
                            @foreach($beginnerMissions['missions'] as $mission)
                                <div class="flex items-center gap-2 rounded border px-2 py-1.5 text-[11px] font-bold
                                    {{ $mission['completed'] ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : ($mission['key'] === $currentMission['key'] ? 'border-amber-300 bg-white text-slate-800' : 'border-slate-200 bg-white/70 text-slate-500') }}">
                                    <span class="shrink-0">{{ $mission['completed'] ? '✓' : ($mission['key'] === $currentMission['key'] ? '▶' : '・') }}</span>
                                    <span class="min-w-0 truncate">{{ $mission['title'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif


                @if($currentLocation === 'dungeon' && !empty($storageIsFull))
                    <section class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-bold leading-relaxed text-amber-900 shadow-sm">
                        {!! $storageFullMessage !!}
                    </section>
                @endif

                @if($currentLocation === 'dungeon' && isset($subAreaDiscoveries) && $subAreaDiscoveries->isNotEmpty())
                    <section class="mb-4 overflow-hidden rounded-xl border border-indigo-200 bg-indigo-50/80 shadow-sm">
                        <div class="flex items-center justify-between border-b border-indigo-100 bg-white/70 px-4 py-2">
                            <h4 class="text-sm font-black text-slate-900">地図に記録した入口</h4>
                            <span class="text-[11px] font-bold text-indigo-700">{{ number_format($subAreaDiscoveries->count()) }}件</span>
                        </div>
                        <div class="divide-y divide-indigo-100">
                            @foreach($subAreaDiscoveries->take(4) as $discovery)
                                @php
                                    $route = $discovery->route;
                                    $subArea = $route?->subArea;
                                    $sourceArea = $route?->sourceArea;
                                @endphp
                                <div class="flex items-center gap-3 px-4 py-2.5">
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white shadow-sm overflow-hidden"><img src="{{ asset('images/icon/icon_003.webp') }}" alt="" class="w-6 h-6 object-contain"></div>
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-sm font-black leading-tight text-slate-900">{{ $subArea?->name ?? '未知の場所' }}</div>
                                        <div class="mt-0.5 truncate text-[11px] font-bold text-slate-500">
                                            {{ $sourceArea?->name ?? '不明な入口' }} / {{ $route?->route_name ?? '入口' }}
                                        </div>
                                    </div>
                                    @if($subArea)
                                        @php
                                            $subAreaPowerRange = app(\App\Services\CharacterPowerService::class)->recommendedRangeForLevels(
                                                (int) ($subArea->recommended_level_min ?? 1),
                                                (int) ($subArea->recommended_level_max ?? $subArea->recommended_level_min ?? 1)
                                            );
                                        @endphp
                                        <div class="flex shrink-0 items-center gap-1.5">
                                            <span class="rounded bg-white px-2 py-1 text-[10px] font-black text-indigo-700 shadow-sm">
                                                戦力{{ app(\App\Services\CharacterPowerService::class)->formatRange($subAreaPowerRange) }}
                                            </span>
                                            <a href="{{ route('battle.sub_area.confirm', ['discovery' => $discovery]) }}"
                                               class="rounded bg-indigo-700 px-2.5 py-1 text-[10px] font-black text-white shadow-sm transition hover:bg-indigo-800 active:scale-95">
                                                入る
                                            </a>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
                
                @if($currentLocation === 'home')

                    <hr class="border-gray-200 my-2">

                    @php
                        $groupedHomeMenuItems = collect($homeMenuItems ?? [])->groupBy('group');
                    @endphp
                    <section class="pb-4">
                        <div class="mb-2 flex items-center justify-between">
                            <h4 class="text-sm font-black text-slate-900">冒険者メニュー</h4>
                            <span class="text-[11px] font-bold text-slate-400">詳細・管理</span>
                        </div>

                        <div class="space-y-2.5">
                            @foreach($groupedHomeMenuItems as $menuCategory => $menuGroup)
                                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                                    <div class="border-b border-slate-100 bg-slate-50 px-4 py-1.5">
                                        <span class="text-[10px] font-extrabold tracking-widest text-slate-400 uppercase">{{ $menuCategory }}</span>
                                    </div>
                                    @foreach($menuGroup as $menuItem)
                                        @php
                                            $menuIsInactive = in_array($menuItem['status'] ?? 'active', ['locked', 'coming_soon']);
                                            $menuBorder = $loop->last ? '' : 'border-b border-slate-100';
                                            $menuIconHtml = isset($menuItem['icon_image'])
                                                ? '<img src="' . asset('images/' . $menuItem['icon_image']) . '" alt="" class="h-full w-full object-contain">'
                                                : '<span class="text-xl leading-none">' . ($menuItem['icon'] ?? '•') . '</span>';
                                        @endphp

                                        @if(!$menuIsInactive && isset($menuItem['route']))
                                            <a href="{{ route($menuItem['route']) }}" wire:navigate class="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-slate-50 active:bg-slate-100 {{ $menuBorder }}">
                                                <div class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-amber-50 p-1">{!! $menuIconHtml !!}</div>
                                                <div class="min-w-0 flex-1">
                                                    <div class="text-sm font-bold leading-tight text-slate-800">{{ $menuItem['name'] }}</div>
                                                    <div class="mt-0.5 truncate text-[11px] text-slate-500">{{ $menuItem['desc'] }}</div>
                                                </div>
                                                <svg class="h-4 w-4 shrink-0 text-slate-300" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>
                                            </a>
                                        @elseif(!$menuIsInactive && isset($menuItem['tab']))
                                            <button type="button"
                                                    @click="window.dispatchEvent(new CustomEvent('main-tab-selected', { detail: { location: '{{ $menuItem['tab'] }}' } })); $dispatch('changeTab', { newLocation: '{{ $menuItem['tab'] }}' })"
                                                    class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-slate-50 active:bg-slate-100 {{ $menuBorder }}">
                                                <div class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-amber-50 p-1">{!! $menuIconHtml !!}</div>
                                                <div class="min-w-0 flex-1">
                                                    <div class="text-sm font-bold leading-tight text-slate-800">{{ $menuItem['name'] }}</div>
                                                    <div class="mt-0.5 truncate text-[11px] text-slate-500">{{ $menuItem['desc'] }}</div>
                                                </div>
                                                <svg class="h-4 w-4 shrink-0 text-slate-300" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>
                                            </button>
                                        @else
                                            <div class="flex items-center gap-3 px-4 py-2.5 opacity-40 {{ $menuBorder }}">
                                                <div class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-100 p-1">{!! $menuIconHtml !!}</div>
                                                <div class="min-w-0 flex-1">
                                                    <div class="text-sm font-bold leading-tight text-slate-500">{{ $menuItem['name'] }}</div>
                                                    <div class="mt-0.5 truncate text-[11px] text-slate-400">{{ $menuItem['desc'] }}</div>
                                                </div>
                                                <span class="shrink-0 rounded bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-400">準備中</span>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </section>

                @elseif($currentLocation === 'town' && ($homeDisplayMode ?? 'normal') === 'simple')
                    @php
                        $groupedSimpleFacilities = collect($simpleFacilities ?? [])->groupBy('group');
                    @endphp
                    <div class="mb-3 rounded-lg border border-[#d4af37]/40 bg-amber-50/70 px-3 py-2">
                        <div class="text-sm font-extrabold text-slate-900">簡易モード</div>
                        <div class="text-xs font-semibold text-slate-600 mt-0.5">よく使う場所をまとめて表示しています。</div>
                    </div>
                    <div class="space-y-4 pb-4">
                        @foreach($groupedSimpleFacilities as $groupName => $facilities)
                            <section>
                                <div class="mb-2 flex items-center gap-2 text-[12px] font-extrabold text-slate-600">
                                    <span class="h-px flex-1 bg-slate-200"></span>
                                    <span>{{ $groupName }}</span>
                                    <span class="h-px flex-1 bg-slate-200"></span>
                                </div>
                                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                                    @foreach($facilities as $facility)
                                        @php
                                            $isLocked = ($facility['status'] ?? 'active') === 'locked';
                                            $isComingSoon = ($facility['status'] ?? 'active') === 'coming_soon';
                                            $isInactive = $isLocked || $isComingSoon;
                                            $simpleIcons = [
                                                '探索する' => '🗡️',
                                                '街を移動' => '🌍',
                                                '宿屋' => '🛏️',
                                                '補給所' => '🧪',
                                                '倉庫' => '📦',
                                                '装備変更' => '🗡️',
                                                '装備屋' => '🛡️',
                                                '能力割振り' => '✦',
                                                '神殿' => '⛪',
                                                '印図鑑' => '📖',
                                                '鍛冶屋' => '⚒️',
                                                '合成屋' => '🧩',
                                                '素材交換所' => '💎',
                                                '冒険者市場' => '⚖️',
                                                '闘技場' => '⚔️',
                                                '酒場' => '🍺',
                                                '手紙' => '✉️',
                                                '案内所' => '📘',
                                                '設定' => '⚙️',
                                            ];
                                            $icon = $simpleIcons[$facility['name'] ?? ''] ?? '🏛️';
                                            $iconImage = $facility['icon_image'] ?? null;
                                            $restBlocked = (bool) ($facility['rest_blocked'] ?? false);
                                            $restBlockMessage = (string) ($facility['rest_block_message'] ?? 'HP/SPが満タンです。宿屋で休む必要はありません。');
                                        @endphp
                                        <div class="overflow-hidden rounded-md border shadow-sm {{ $isInactive ? 'border-slate-200 bg-slate-100 opacity-60 grayscale' : 'border-[#d4af37]/50 bg-white transition active:scale-[0.98] hover:border-[#d4af37]' }}">
                                            @if(!$isInactive && !empty($facility['route']))
                                                @if(!empty($facility['is_post']))
                                                    <form action="{{ route($facility['route'], $facility['params'] ?? []) }}" method="POST" class="h-full" x-data="{ submitting: false }" @submit="if (@js($restBlocked)) { $event.preventDefault(); openModal('宿屋', @js($restBlockMessage)); return; } submitting = true">
                                                        @csrf
                                                        <button type="submit" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-wait' : ''" class="flex min-h-[58px] w-full items-center justify-center gap-2 px-2.5 py-2 text-center transition active:scale-[0.98]">
                                                            <x-loading-spinner x-show="submitting" style="display: none;" size="h-4 w-4" />
                                                            @if($iconImage)
                                                                <img x-show="!submitting" src="{{ asset('images/' . $iconImage) }}" alt="" class="h-7 w-7 object-contain">
                                                            @else
                                                                <span x-show="!submitting" class="text-xl leading-none">{{ $icon }}</span>
                                                            @endif
                                                            <span class="min-w-0 truncate text-sm font-extrabold text-slate-900" x-text="submitting ? '処理中...' : @js($facility['name'])">{{ $facility['name'] }}</span>
                                                        </button>
                                                    </form>
                                                @else
                                                    <a href="{{ route($facility['route'], $facility['params'] ?? []) }}" wire:navigate class="flex min-h-[58px] w-full items-center justify-center gap-2 px-2.5 py-2 text-center transition active:scale-[0.98]">
                                                        @if($iconImage)
                                                            <img src="{{ asset('images/' . $iconImage) }}" alt="" class="h-7 w-7 object-contain">
                                                        @else
                                                            <span class="text-xl leading-none">{{ $icon }}</span>
                                                        @endif
                                                        <span class="min-w-0 truncate text-sm font-extrabold text-slate-900">{{ $facility['name'] }}</span>
                                                    </a>
                                                @endif
                                            @elseif(!$isInactive && !empty($facility['tab']))
                                                <button type="button"
                                                        x-data="{ submitting: false }"
                                                        @click="if (submitting) return; submitting = true; window.dispatchEvent(new CustomEvent('main-tab-selected', { detail: { location: '{{ $facility['tab'] }}' } })); $dispatch('changeTab', { newLocation: '{{ $facility['tab'] }}' }); setTimeout(() => submitting = false, 700);"
                                                        x-bind:class="submitting ? 'opacity-60 cursor-wait' : ''"
                                                        class="flex min-h-[58px] w-full items-center justify-center gap-2 px-2.5 py-2 text-center transition active:scale-[0.98]">
                                                    @if($iconImage)
                                                        <img src="{{ asset('images/' . $iconImage) }}" alt="" class="h-7 w-7 object-contain">
                                                    @else
                                                        <span class="text-xl leading-none">{{ $icon }}</span>
                                                    @endif
                                                    <span class="min-w-0 truncate text-sm font-extrabold text-slate-900">{{ $facility['name'] }}</span>
                                                </button>
                                            @else
                                                <button type="button" disabled class="flex min-h-[58px] w-full items-center justify-center gap-2 px-2.5 py-2 text-center">
                                                    @if($iconImage)
                                                        <img src="{{ asset('images/' . $iconImage) }}" alt="" class="h-7 w-7 object-contain">
                                                    @else
                                                        <span class="text-xl leading-none">{{ $icon }}</span>
                                                    @endif
                                                    <span class="min-w-0 truncate text-sm font-extrabold text-slate-500">{{ $facility['name'] }}</span>
                                                </button>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>

                @elseif($currentLocation === 'move' && isset($cities))
                    @php
                        $worldMapPath = config('valzeria_world_map.image_path', 'images/map/map01.webp');
                        $mapCitiesByName = collect(config('valzeria_world_map.cities', []))->keyBy('city_name');
                        $cityPopulationCounts = collect($cityPopulationCounts ?? []);
                        $cityIconSamples = collect($cityIconSamples ?? []);
                        $worldZoomIconItems = collect($cities)
                            ->flatMap(function ($city) use ($mapCitiesByName, $cityIconSamples) {
                                $mapCity = $mapCitiesByName->get($city->name);
                                $iconSamples = collect($cityIconSamples[$city->id] ?? []);
                                $iconSampleCount = $iconSamples->count();

                                if (!$mapCity || $iconSampleCount === 0) {
                                    return [];
                                }

                                return $iconSamples->map(function (array $sample, int $index) use ($mapCity, $iconSampleCount): array {
                                    $x = (float) ($sample['x'] ?? ($mapCity['x_percent'] ?? 50));
                                    $y = (float) ($sample['y'] ?? ($mapCity['y_percent'] ?? 50));
                                    $angle = deg2rad(($index * 137.508) + ($iconSampleCount * 11));
                                    $radius = $iconSampleCount === 1 ? 0 : 3.5 + (sqrt($index + 1) * 4.8);

                                    return [
                                        'icon' => \App\Support\CharacterIconCatalog::versionedAsset($sample['icon'] ?? null),
                                        'name' => $sample['name'] ?? '冒険者',
                                        'comment' => $sample['comment'] ?? 'よろしくお願いします',
                                        'map_x' => max(3, min(97, $x + ((cos($angle) * $radius) / 3))),
                                        'map_y' => max(3, min(97, $y + ((sin($angle) * $radius * 0.7) / 3))),
                                        'location_name' => $sample['location_name'] ?? '街',
                                    ];
                                });
                            })
                            ->values()
                            ->all();
                        $worldZoomIconCount = count($worldZoomIconItems);
                        $hasFerdiaMap = !empty($ferdiaMap);
                        $initialMapRegion = $hasFerdiaMap ? ($initialMapRegion ?? 'valzeria') : 'valzeria';
                    @endphp
                    <div x-data="{ activeRegion: @js($initialMapRegion) }">
                    @if($hasFerdiaMap)
                        <div class="mb-4 grid grid-cols-2 gap-2 rounded-lg border border-slate-200 bg-slate-50 p-1">
                            <button type="button"
                                    class="rounded-md px-3 py-2 text-sm font-black transition"
                                    x-bind:class="activeRegion === 'valzeria' ? 'bg-white text-[#1e40af] shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:bg-white/70'"
                                    @click="activeRegion = 'valzeria'">
                                ヴァルゼリア大陸
                            </button>
                            <button type="button"
                                    class="rounded-md px-3 py-2 text-sm font-black transition"
                                    x-bind:class="activeRegion === 'ferdia' ? 'bg-white text-emerald-700 shadow-sm ring-1 ring-emerald-200' : 'text-slate-500 hover:bg-white/70'"
                                    @click="activeRegion = 'ferdia'">
                                フェルディア大陸
                            </button>
                        </div>
                    @endif
                    <div x-show="activeRegion === 'valzeria'" style="{{ $initialMapRegion === 'valzeria' ? '' : 'display: none;' }}">
                    <div class="mb-4 overflow-hidden rounded-xl border-2 border-[#d4af37] bg-[#f8f1df] shadow-md">
                        <div class="relative" x-data="{ zoomOpen: false, zoomName: '', zoomX: 50, zoomY: 50, zoomPopulation: 0, zoomIcons: [], selectedPlayer: null, panX: 0, panY: 0, isPanning: false, panStartX: 0, panStartY: 0, panOriginX: 0, panOriginY: 0 }">
                            <img src="{{ asset($worldMapPath) }}" alt="ヴァルゼリア大陸MAP" class="block h-auto w-full">
                            <div class="absolute left-2 top-2 rounded bg-black/60 px-2 py-1 text-xs font-bold text-white shadow">
                            ヴァルゼリア大陸MAP
                            </div>

                            <div class="absolute right-2 top-2 hidden rounded border border-amber-900/25 bg-[#fff8e7]/90 px-2.5 py-1.5 text-[10px] font-bold text-slate-800 shadow sm:block">
                                <div class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-amber-500"></span>現在地</div>
                                <div class="mt-1 flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-blue-700"></span>移動可能</div>
                                <div class="mt-1 flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-slate-400"></span>未解放</div>
                            </div>

                            @foreach($cities as $city)
                                @php
                                    $mapCity = $mapCitiesByName->get($city->name);
                                    $isUnlocked = $city->sort_order <= $highestCityOrder;
                                    $isCurrent = $character && $character->current_city_id == $city->id;
                                    $populationCount = (int) ($cityPopulationCounts[$city->id] ?? 0);
                                    $iconSamples = collect($cityIconSamples[$city->id] ?? []);
                                    $iconSampleCount = $iconSamples->count();
                                    $mapIconSamples = $iconSamples->take(6);
                                    $hiddenIconCount = max(0, $populationCount - $mapIconSamples->count());
                                    $markerTone = $isCurrent
                                        ? 'border-amber-200 bg-amber-500 text-slate-950'
                                        : ($isUnlocked ? 'border-blue-200 bg-blue-700 text-white' : 'border-slate-200 bg-slate-500 text-white');
                                @endphp
                                @if($mapCity)
                                    <div class="absolute z-10 -translate-x-1/2 -translate-y-1/2"
                                         style="left: {{ (float) ($mapCity['x_percent'] ?? 50) }}%; top: {{ (float) ($mapCity['y_percent'] ?? 50) }}%;">
                                        @if($isCurrent)
                                            <div class="flex flex-col items-center">
                                                <div class="flex items-center gap-1 whitespace-nowrap rounded-sm border border-[#6f5124] bg-[#fff3d1]/95 px-1.5 py-0.5 text-[8px] font-black text-slate-950 shadow-md sm:px-2 sm:text-[11px]">
                                                    <span class="hidden whitespace-nowrap sm:inline">{{ $mapCity['label'] ?? $city->name }}</span>
                                                    <span class="whitespace-nowrap sm:hidden">{{ $mapCity['short_label'] ?? $city->name }}</span>
                                                    <span class="shrink-0 whitespace-nowrap rounded-sm bg-amber-500 px-1 text-[8px] sm:text-[10px]">現在</span>
                                                </div>
                                                <span class="mt-0.5 h-3 w-3 rounded-full border-2 shadow {{ $markerTone }}"></span>
                                                <div class="mt-0.5 flex items-center gap-0.5">
                                                    <span class="rounded-full border border-white/80 bg-slate-900/75 px-1.5 py-0.5 text-[8px] font-black leading-none text-white shadow sm:text-[10px]">滞在{{ $populationCount }}人</span>
                                                    <button type="button" class="flex h-4 w-4 items-center justify-center rounded-full border border-white/90 bg-white/90 text-[10px] font-black leading-none text-slate-900 shadow sm:h-5 sm:w-5 sm:text-xs"
                                                        @click.stop="zoomOpen = true; selectedPlayer = null; panX = 0; panY = 0; isPanning = false; zoomName = @js($mapCity['label'] ?? $city->name); zoomX = {{ (float) ($mapCity['x_percent'] ?? 50) }}; zoomY = {{ (float) ($mapCity['y_percent'] ?? 50) }}; zoomPopulation = {{ $worldZoomIconCount }}; zoomIcons = @js($worldZoomIconItems);"
                                                        aria-label="{{ $mapCity['label'] ?? $city->name }}周辺を拡大">+</button>
                                                </div>
                                                @if($iconSamples->isNotEmpty())
                                                    <div class="mt-0.5 flex max-w-20 flex-wrap justify-center gap-0.5 sm:max-w-24" aria-label="滞在中の冒険者アイコン">
                                                        @foreach($mapIconSamples as $iconSample)
                                                            <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($iconSample['icon'] ?? null) }}" alt="" class="h-4 w-4 object-contain drop-shadow sm:h-5 sm:w-5">
                                                        @endforeach
                                                        @if($hiddenIconCount > 0)
                                                            <span class="flex h-3.5 min-w-3.5 items-center justify-center rounded-full border border-white/90 bg-slate-900/80 px-0.5 text-[7px] font-black leading-none text-white shadow sm:h-4 sm:min-w-4 sm:text-[8px]">+{{ $hiddenIconCount }}</span>
                                                        @endif
                                                    </div>
                                                @endif
                                            </div>
                                        @elseif($isUnlocked)
                                            <form action="{{ route('city.travel', $city) }}" method="POST" class="flex flex-col items-center" x-data="{ submitting: false }" @submit="submitting = true">
                                                @csrf
                                                <button type="submit" x-bind:disabled="submitting" class="flex items-center gap-1 whitespace-nowrap rounded-sm border border-[#6f5124] bg-[#fff3d1]/95 px-1.5 py-0.5 text-[8px] font-black text-slate-950 shadow-md transition hover:scale-105 hover:bg-white focus:outline-none focus:ring-2 focus:ring-white/90 disabled:cursor-wait disabled:opacity-70 sm:px-2 sm:text-[11px]">
                                                    <span class="hidden whitespace-nowrap sm:inline">{{ $mapCity['label'] ?? $city->name }}</span>
                                                    <span class="whitespace-nowrap sm:hidden">{{ $mapCity['short_label'] ?? $city->name }}</span>
                                                    <span class="shrink-0 whitespace-nowrap rounded-sm bg-blue-900 px-1 text-[8px] text-white sm:text-[10px]">移動</span>
                                                </button>
                                                <span class="mt-0.5 h-3 w-3 rounded-full border-2 shadow {{ $markerTone }}"></span>
                                                <div class="mt-0.5 flex items-center gap-0.5">
                                                    <span class="rounded-full border border-white/80 bg-slate-900/75 px-1.5 py-0.5 text-[8px] font-black leading-none text-white shadow sm:text-[10px]">滞在{{ $populationCount }}人</span>
                                                    <button type="button" class="flex h-4 w-4 items-center justify-center rounded-full border border-white/90 bg-white/90 text-[10px] font-black leading-none text-slate-900 shadow sm:h-5 sm:w-5 sm:text-xs"
                                                        @click.stop.prevent="zoomOpen = true; selectedPlayer = null; panX = 0; panY = 0; isPanning = false; zoomName = @js($mapCity['label'] ?? $city->name); zoomX = {{ (float) ($mapCity['x_percent'] ?? 50) }}; zoomY = {{ (float) ($mapCity['y_percent'] ?? 50) }}; zoomPopulation = {{ $worldZoomIconCount }}; zoomIcons = @js($worldZoomIconItems);"
                                                        aria-label="{{ $mapCity['label'] ?? $city->name }}周辺を拡大">+</button>
                                                </div>
                                                @if($iconSamples->isNotEmpty())
                                                    <div class="mt-0.5 flex max-w-20 flex-wrap justify-center gap-0.5 sm:max-w-24" aria-label="滞在中の冒険者アイコン">
                                                        @foreach($mapIconSamples as $iconSample)
                                                            <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($iconSample['icon'] ?? null) }}" alt="" class="h-4 w-4 object-contain drop-shadow sm:h-5 sm:w-5">
                                                        @endforeach
                                                        @if($hiddenIconCount > 0)
                                                            <span class="flex h-3.5 min-w-3.5 items-center justify-center rounded-full border border-white/90 bg-slate-900/80 px-0.5 text-[7px] font-black leading-none text-white shadow sm:h-4 sm:min-w-4 sm:text-[8px]">+{{ $hiddenIconCount }}</span>
                                                        @endif
                                                    </div>
                                                @endif
                                            </form>
                                        @else
                                            <div class="flex flex-col items-center opacity-80">
                                                <div class="flex items-center gap-1 whitespace-nowrap rounded-sm border border-slate-500 bg-slate-100/90 px-1.5 py-0.5 text-[8px] font-black text-slate-500 shadow-md sm:px-2 sm:text-[11px]">
                                                    <span class="hidden whitespace-nowrap sm:inline">{{ $mapCity['label'] ?? $city->name }}</span>
                                                    <span class="whitespace-nowrap sm:hidden">{{ $mapCity['short_label'] ?? $city->name }}</span>
                                                    <span class="shrink-0 whitespace-nowrap rounded-sm bg-slate-500 px-1 text-[8px] text-white sm:text-[10px]">未</span>
                                                </div>
                                                <span class="mt-0.5 h-3 w-3 rounded-full border-2 shadow {{ $markerTone }}"></span>
                                                <div class="mt-0.5 flex items-center gap-0.5">
                                                    <span class="rounded-full border border-white/80 bg-slate-900/75 px-1.5 py-0.5 text-[8px] font-black leading-none text-white shadow sm:text-[10px]">滞在{{ $populationCount }}人</span>
                                                    <button type="button" class="flex h-4 w-4 items-center justify-center rounded-full border border-white/90 bg-white/90 text-[10px] font-black leading-none text-slate-900 shadow sm:h-5 sm:w-5 sm:text-xs"
                                                        @click.stop="zoomOpen = true; selectedPlayer = null; panX = 0; panY = 0; isPanning = false; zoomName = @js($mapCity['label'] ?? $city->name); zoomX = {{ (float) ($mapCity['x_percent'] ?? 50) }}; zoomY = {{ (float) ($mapCity['y_percent'] ?? 50) }}; zoomPopulation = {{ $worldZoomIconCount }}; zoomIcons = @js($worldZoomIconItems);"
                                                        aria-label="{{ $mapCity['label'] ?? $city->name }}周辺を拡大">+</button>
                                                </div>
                                                @if($iconSamples->isNotEmpty())
                                                    <div class="mt-0.5 flex max-w-20 flex-wrap justify-center gap-0.5 sm:max-w-24" aria-label="滞在中の冒険者アイコン">
                                                        @foreach($mapIconSamples as $iconSample)
                                                            <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($iconSample['icon'] ?? null) }}" alt="" class="h-4 w-4 object-contain drop-shadow sm:h-5 sm:w-5">
                                                        @endforeach
                                                        @if($hiddenIconCount > 0)
                                                            <span class="flex h-3.5 min-w-3.5 items-center justify-center rounded-full border border-white/90 bg-slate-900/80 px-0.5 text-[7px] font-black leading-none text-white shadow sm:h-4 sm:min-w-4 sm:text-[8px]">+{{ $hiddenIconCount }}</span>
                                                        @endif
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            @endforeach

                            <div x-show="zoomOpen" x-transition class="absolute inset-x-2 bottom-2 z-40 rounded-lg border-2 border-[#6f5124] bg-[#fff8e7]/95 p-2 shadow-2xl sm:inset-x-4 sm:bottom-4" style="display: none;">
                                <div class="mb-1.5 flex items-center justify-between gap-2">
                                    <div class="truncate text-xs font-black text-slate-900" x-text="zoomName + ' 周辺'"></div>
                                    <button type="button" class="flex h-6 w-6 items-center justify-center rounded border border-slate-300 bg-white text-sm font-black text-slate-700 shadow-sm" @click="zoomOpen = false" aria-label="拡大表示を閉じる">×</button>
                                </div>
                                <div class="relative touch-none cursor-grab overflow-hidden rounded-md border border-amber-900/20 bg-white shadow-inner active:cursor-grabbing"
                                     style="aspect-ratio: 300 / 401;"
                                     @pointerdown.self="isPanning = true; panStartX = $event.clientX; panStartY = $event.clientY; panOriginX = panX; panOriginY = panY; $event.currentTarget.setPointerCapture && $event.currentTarget.setPointerCapture($event.pointerId)"
                                     @pointermove.self="if (isPanning) { const w = $event.currentTarget.clientWidth; const h = $event.currentTarget.clientHeight; const minX = ((zoomX * 3 / 100) - 2.5) * w; const maxX = ((zoomX * 3 / 100) - 0.5) * w; const minY = ((zoomY * 3 / 100) - 2.5) * h; const maxY = ((zoomY * 3 / 100) - 0.5) * h; panX = Math.max(minX, Math.min(maxX, panOriginX + $event.clientX - panStartX)); panY = Math.max(minY, Math.min(maxY, panOriginY + $event.clientY - panStartY)); }"
                                     @pointerup.self="isPanning = false"
                                     @pointercancel.self="isPanning = false"
                                     @pointerleave.self="isPanning = false">
                                    <img src="{{ asset($worldMapPath) }}" alt="" class="pointer-events-none absolute max-w-none select-none"
                                         style="width: 300%; height: auto;"
                                         x-bind:style="{
                                             left: 'calc(50% - ' + (zoomX * 3) + '% + ' + panX + 'px)',
                                             top: 'calc(50% - ' + (zoomY * 3) + '% + ' + panY + 'px)'
                                         }">
                                    <div class="absolute left-2 top-2 rounded-md border border-white/80 bg-slate-900/75 px-2 py-1 text-[10px] font-black text-white shadow">
                                        <span>表示中の冒険者</span>
                                        <span x-text="' ' + zoomPopulation + '人'"></span>
                                    </div>
                                    <template x-for="(player, index) in zoomIcons" :key="player.icon + '-' + index">
                                        <button type="button" class="absolute -translate-x-1/2 -translate-y-1/2"
                                             @click.stop="selectedPlayer = Object.assign({}, player, { index: index, screenX: 50 + ((player.map_x - zoomX) * 3), screenY: 50 + ((player.map_y - zoomY) * 3) })"
                                             x-bind:style="{
                                                 left: 'calc(' + (50 + ((player.map_x - zoomX) * 3)) + '% + ' + panX + 'px)',
                                                 top: 'calc(' + (50 + ((player.map_y - zoomY) * 3)) + '% + ' + panY + 'px)'
                                             }">
                                            <img :src="player.icon" alt="" class="h-10 w-10 object-contain drop-shadow-lg sm:h-12 sm:w-12">
                                        </button>
                                    </template>
                                    <template x-if="zoomPopulation > zoomIcons.length">
                                        <span class="absolute flex h-8 min-w-8 items-center justify-center rounded-full border-2 border-white bg-slate-900/85 px-1 text-[10px] font-black text-white shadow-lg sm:h-9 sm:min-w-9 sm:text-xs"
                                              x-bind:style="{
                                                  left: 'calc(78% + ' + panX + 'px)',
                                                  top: 'calc(70% + ' + panY + 'px)',
                                                  transform: 'translate(-50%, -50%)'
                                              }"
                                              x-text="'+' + (zoomPopulation - zoomIcons.length)"></span>
                                    </template>
                                    <div class="absolute inset-x-2 bottom-2 rounded-md border border-white/70 bg-white/80 px-2 py-1 text-[10px] font-bold text-slate-500 shadow" x-show="zoomIcons.length === 0">
                                        滞在アイコンなし
                                    </div>
                                    <template x-if="selectedPlayer">
                                        <div>
                                            <div class="absolute z-30 max-w-32 whitespace-nowrap rounded bg-slate-900/85 px-2 py-1 text-[10px] font-black text-white shadow"
                                                 x-bind:style="{
                                                     left: 'calc(' + selectedPlayer.screenX + '% + ' + panX + 'px)',
                                                     top: 'calc(' + selectedPlayer.screenY + '% + ' + panY + 'px)',
                                                     transform: 'translate(-50%, calc(-100% - 22px))'
                                                 }"
                                                 x-text="selectedPlayer.location_name || '街'"></div>
                                            <div class="absolute z-30 w-44 rounded-xl border border-amber-900/20 bg-white/95 px-3 py-2 text-slate-800 shadow-xl"
                                                 x-bind:style="{
                                                     left: 'calc(' + selectedPlayer.screenX + '% + ' + panX + 'px)',
                                                     top: 'calc(' + selectedPlayer.screenY + '% + ' + panY + 'px)',
                                                     transform: selectedPlayer.screenX > 60 ? 'translate(calc(-100% - 18px), 8px)' : 'translate(18px, 8px)'
                                                 }">
                                                <div x-show="!(selectedPlayer.screenX > 60)" class="absolute" style="left: -8px; top: 14px; width: 0; height: 0; border-top: 7px solid transparent; border-bottom: 7px solid transparent; border-right: 8px solid rgba(255, 255, 255, 0.95);"></div>
                                                <div x-show="selectedPlayer.screenX > 60" class="absolute" style="right: -8px; top: 14px; width: 0; height: 0; border-top: 7px solid transparent; border-bottom: 7px solid transparent; border-left: 8px solid rgba(255, 255, 255, 0.95);"></div>
                                                <div class="flex items-start justify-between gap-2">
                                                    <div class="min-w-0">
                                                        <div class="truncate text-xs font-black text-slate-950" x-text="selectedPlayer.name"></div>
                                                        <div class="mt-1 text-[11px] font-bold leading-snug text-slate-600" x-text="selectedPlayer.comment"></div>
                                                    </div>
                                                    <button type="button" class="shrink-0 text-xs font-black text-slate-400" @click.stop="selectedPlayer = null" aria-label="冒険者情報を閉じる">×</button>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 overflow-y-auto pr-1 content-start pb-4">
                        @foreach($cities as $city)
                            @php
                                $isUnlocked = $city->sort_order <= $highestCityOrder;
                                $isCurrent = $character && $character->current_city_id == $city->id;
                                $cityBgPath = \App\Support\CityVisualCatalog::cardBackground((int) $city->id);
                                $cityBgImg = $cityBgPath ? 'images/' . $cityBgPath : null;
                            @endphp
                            <div class="border {{ $isCurrent ? 'border-amber-500' : ($isUnlocked ? 'border-gray-200 hover:border-[#d4af37]' : 'border-gray-200 opacity-60') }} rounded-lg overflow-hidden transition-all shadow-sm flex flex-col relative {{ !$isUnlocked ? 'grayscale-[0.5]' : '' }}" style="min-height:200px;">
                                {{-- 全体背景画像 --}}
                                @if($cityBgImg)
                                    <img src="{{ asset($cityBgImg) }}" alt=""
                                         class="absolute inset-0 w-full h-full object-cover object-center pointer-events-none"
                                         style="filter: contrast(1.08) saturate(1.1);">
                                @else
                                    <div class="absolute inset-0" style="background:{{ app(\App\Services\CityThemeService::class)->backgroundColorForCityId($city->id) }}"></div>
                                @endif
                                {{-- 下部グラデーションオーバーレイ --}}
                                <div class="absolute inset-0 pointer-events-none" style="background: linear-gradient(to bottom, rgba(255,255,255,0) 30%, rgba(255,255,255,0.88) 58%, rgba(255,255,255,0.97) 100%);"></div>
                                {{-- バッジ --}}
                                @if($isCurrent)
                                    <span class="absolute top-2 right-2 text-[10px] font-bold text-white bg-amber-500 px-1.5 py-0.5 rounded shadow z-10">現在地</span>
                                @elseif(!$isUnlocked)
                                    <span class="absolute top-2 right-2 text-[10px] font-bold text-gray-600 bg-white/80 px-1.5 py-0.5 rounded border border-gray-300 shadow z-10">未解放</span>
                                @endif
                                {{-- テキスト・ボタン（下部に固定） --}}
                                <div class="relative z-10 mt-auto p-3">
                                    <h3 class="font-bold text-[14px] tracking-tight {{ $isUnlocked ? 'text-[#1e293b]' : 'text-gray-500' }} mb-1">
                                        {{ $city->name }}
                                    </h3>
                                    <div class="text-xs {{ $isUnlocked ? 'text-gray-600' : 'text-gray-400' }} mb-3 leading-relaxed">
                                        {{ $city->description }}
                                    </div>
                                    @if(!$isCurrent && $isUnlocked)
                                        <form action="{{ route('city.travel', $city) }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true">
                                            @csrf
                                            <button type="submit" x-bind:disabled="submitting" class="inline-flex w-full cursor-pointer items-center justify-center gap-2 bg-[#1e40af] hover:bg-[#1e3a8a] text-white font-bold py-1.5 px-3 rounded text-sm text-center shadow-sm border border-[#1e3a8a] transition-all duration-150 active:scale-95 disabled:cursor-wait disabled:opacity-70">
                                                <x-loading-spinner x-show="submitting" style="display: none;" />
                                                <span x-show="!submitting">移動する</span>
                                                <span x-show="submitting" style="display: none;">移動中...</span>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                    </div>
                    @if($hasFerdiaMap)
                        <div x-show="activeRegion === 'ferdia'" style="{{ $initialMapRegion === 'ferdia' ? '' : 'display: none;' }}">
                            <x-ferdia-map :map="$ferdiaMap" :character="$character" />
                        </div>
                    @endif
                    </div>
                @elseif($currentLocation === 'message')
                    <!-- 個人チャットコンポーネントを直接埋め込む -->
                    <div class="w-full">
                        <livewire:message-box />
                    </div>
                @elseif($currentLocation === 'colosseum')
                    <!-- 闘技場コンポーネントを直接埋め込む -->
                    <div class="w-full">
                        <livewire:colosseum-screen />
                    </div>
                @elseif($currentLocation === 'settings')
                    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm pb-4">
                        @foreach($locationData['facilities'] as $facility)
                            @php
                                $sIsInactive = in_array($facility['status'] ?? 'active', ['locked', 'coming_soon']);
                                $sBorder = $loop->last ? '' : 'border-b border-slate-100';
                                $sDetail = collect($facility['details'] ?? [])->implode(' · ');
                                $sIconHtml = isset($facility['icon_image'])
                                    ? '<img src="' . asset('images/' . ltrim($facility['icon_image'], '/')) . '" alt="" class="h-7 w-7 object-contain">'
                                    : '<span class="text-xl leading-none">' . ($facility['icon'] ?? '⚙️') . '</span>';
                            @endphp
                            @if(!$sIsInactive && isset($facility['method']))
                                <button wire:click="{{ $facility['method'] }}" class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-slate-50 active:bg-slate-100 {{ $sBorder }}">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-50">{!! $sIconHtml !!}</div>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-bold leading-tight text-slate-900">{{ $facility['name'] }}</div>
                                        <div class="mt-0.5 text-[11px] text-slate-500">{{ $facility['desc'] }}</div>
                                        @if($sDetail)<div class="mt-1 inline-block rounded bg-amber-50 border border-amber-200 px-2 py-0.5 text-[10px] font-bold text-amber-700">{{ $sDetail }}</div>@endif
                                    </div>
                                    <svg class="h-4 w-4 shrink-0 text-slate-300" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>
                                </button>
                            @elseif(!$sIsInactive && isset($facility['route']))
                                @php
                                    $sIsDangerRoute = ($facility['route'] ?? null) === 'account.delete';
                                    $sIconBg = $sIsDangerRoute ? 'bg-rose-50' : 'bg-amber-50';
                                    $sNameClass = $sIsDangerRoute ? 'text-rose-700' : 'text-slate-900';
                                    $sDetailClass = $sIsDangerRoute
                                        ? 'bg-rose-50 border-rose-200 text-rose-600'
                                        : 'bg-amber-50 border-amber-200 text-amber-700';
                                @endphp
                                <a href="{{ route($facility['route'], $facility['params'] ?? []) }}" wire:navigate class="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-slate-50 active:bg-slate-100 {{ $sBorder }}">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl {{ $sIconBg }}">{!! $sIconHtml !!}</div>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-bold leading-tight {{ $sNameClass }}">{{ $facility['name'] }}</div>
                                        <div class="mt-0.5 text-[11px] text-slate-500">{{ $facility['desc'] }}</div>
                                        @if($sDetail)<div class="mt-1 inline-block rounded border px-2 py-0.5 text-[10px] font-bold {{ $sDetailClass }}">{{ $sDetail }}</div>@endif
                                    </div>
                                    <svg class="h-4 w-4 shrink-0 text-slate-300" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>
                                </a>
                            @else
                                <div class="flex items-center gap-3 px-4 py-3 opacity-50 {{ $sBorder }}">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-100">{!! $sIconHtml !!}</div>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-bold leading-tight text-slate-500">{{ $facility['name'] }}</div>
                                        <div class="mt-0.5 text-[11px] text-slate-400">{{ $facility['desc'] }}</div>
                                    </div>
                                    <span class="shrink-0 rounded bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-400">準備中</span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @else
                    <!-- 施設カードグリッド -->
                    @php
                        $usesFerdiaFacilityTheme = !empty($isFerdiaRegion) || !empty($isFerdiaSimpleBase);
                    @endphp
                    @if(in_array($currentLocation, ['town', 'guild'], true))
                        @php
                            $groupedLocFacilities = collect($locationData['facilities'])->groupBy('category');
                            $rankingSpotlightUntil = \Illuminate\Support\Carbon::parse('2026-07-14 23:59:59', config('app.timezone'));
                            $showRankingSpotlight = $currentLocation === 'town' && now()->lte($rankingSpotlightUntil);
                            $rankingSpotlightImageUrl = null;
                            if (!empty($rankingSpotlightLeader)) {
                                $rankingSpotlightPath = (string) ($rankingSpotlightLeader['icon_path'] ?? '/images/chara/chara_001.webp');
                                if (($rankingSpotlightLeader['image_type'] ?? 'character') === 'asset') {
                                    $rankingSpotlightNormalized = '/' . ltrim($rankingSpotlightPath, '/');
                                    $rankingSpotlightAbsolutePath = public_path(ltrim($rankingSpotlightNormalized, '/'));
                                    $rankingSpotlightVersion = is_file($rankingSpotlightAbsolutePath) ? (string) filemtime($rankingSpotlightAbsolutePath) : '1';
                                    $rankingSpotlightImageUrl = asset($rankingSpotlightNormalized) . '?v=' . $rankingSpotlightVersion;
                                } else {
                                    $rankingSpotlightImageUrl = \App\Support\CharacterIconCatalog::versionedAsset($rankingSpotlightPath);
                                }
                            }
                        @endphp
                        @if($showRankingSpotlight)
                            <a href="{{ route('ranking.index', !empty($rankingSpotlightLeader['board_key'] ?? null) ? ['board' => $rankingSpotlightLeader['board_key']] : []) }}" wire:navigate class="mb-4 block overflow-hidden rounded-xl border-2 border-amber-300 bg-white shadow-md transition hover:border-amber-400 hover:shadow-lg active:scale-[0.99]">
                                <div class="relative min-h-[104px] px-4 py-3 sm:px-5">
                                    <div class="absolute inset-0 bg-cover bg-right-center opacity-20" style="background-image: url('{{ asset('images/bg-castle.webp') }}');"></div>
                                    <div class="absolute inset-0 bg-gradient-to-r from-white via-white/95 to-white/60"></div>
                                    <div class="relative z-10 flex items-center gap-3">
                                        <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl bg-amber-50 shadow-sm ring-1 ring-amber-200">
                                            <img src="{{ asset('images/icon/icon_223.webp') }}" alt="" class="h-12 w-12 object-contain">
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="mb-1 flex flex-wrap items-center gap-1.5">
                                                <span class="rounded bg-[#003366] px-2 py-0.5 text-[10px] font-black text-white">期間限定表示</span>
                                                <span class="rounded bg-amber-100 px-2 py-0.5 text-[10px] font-black text-amber-800">7/14まで</span>
                                            </div>
                                            <div class="text-lg font-black leading-tight text-slate-950 sm:text-xl">番付掲示板を公開中</div>
                                            <div class="mt-1 text-xs font-bold leading-relaxed text-slate-600">
                                                勝利数・ヴァルモン・素材・市場売上など、冒険者たちの記録を確認できます。
                                            </div>
                                            @if(!empty($rankingSpotlightLeader))
                                                <div class="mt-2 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50/90 px-2.5 py-2">
                                                    <div class="flex h-12 w-12 shrink-0 items-center justify-center">
                                                        <img
                                                            src="{{ $rankingSpotlightImageUrl }}"
                                                            alt=""
                                                            class="h-full w-full object-contain drop-shadow-sm"
                                                        >
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="truncate text-[11px] font-black text-amber-700">{{ $rankingSpotlightLeader['board_title'] }}1位</div>
                                                        <div class="truncate text-sm font-black text-slate-950">
                                                            {{ $rankingSpotlightLeader['name'] }}
                                                            <span class="text-xs tabular-nums text-[#003366]">{{ number_format($rankingSpotlightLeader['score']) }}{{ $rankingSpotlightLeader['unit'] }}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                        <div class="hidden shrink-0 rounded-md border border-[#d4af37] bg-white px-3 py-2 text-xs font-black text-[#9a6b00] shadow-sm sm:block">
                                            見に行く
                                        </div>
                                    </div>
                                </div>
                            </a>
                        @endif
                        <!-- スマホ版（md未満）: カテゴリグループリスト -->
                        <div class="{{ $currentLocation === 'guild' ? 'pb-4 space-y-2.5' : 'md:hidden pb-4 space-y-2.5' }}">
                        @foreach($groupedLocFacilities as $facCategory => $facGroup)
                            <div class="overflow-hidden rounded-xl border shadow-sm {{ $usesFerdiaFacilityTheme ? 'border-emerald-100 bg-white shadow-emerald-950/5' : 'border-slate-200 bg-white' }}">
                                @if($facCategory)<div class="border-b px-4 py-1.5 {{ $usesFerdiaFacilityTheme ? 'border-emerald-100 bg-emerald-50/45' : 'border-slate-100 bg-slate-50' }}"><span class="text-[10px] font-extrabold tracking-widest uppercase {{ $usesFerdiaFacilityTheme ? 'text-emerald-700' : 'text-slate-400' }}">{{ $facCategory }}</span></div>@endif
                                @foreach($facGroup as $facility)
                                    @php
                                        $facIsInactive = in_array($facility['status'] ?? 'active', ['locked', 'coming_soon']);
                                        $facDetails = $facility['details'] ?? [];
                                        $facHasFree = in_array('無料', $facDetails);
                                        $facSubText = collect($facDetails)->reject(fn($d) => $d === '無料')->implode(' · ');
                                        $facRestBlocked = (bool) ($facility['rest_blocked'] ?? false);
                                        $facRestBlockMessage = (string) ($facility['rest_block_message'] ?? 'HP/SPが満タンです。宿屋で休む必要はありません。');
                                        $facIconHtml = isset($facility['symbol_image'])
                                            ? '<img src="' . asset('images/' . $facility['symbol_image']) . '" alt="" class="w-full h-full object-contain">'
                                            : (isset($facility['icon_image'])
                                                ? '<img src="' . asset('images/' . $facility['icon_image']) . '" alt="" class="w-7 h-7 object-contain">'
                                                : '<span class="text-xl leading-none">' . ($facility['icon'] ?? '🏛') . '</span>');
                                        $facRowBorder = $usesFerdiaFacilityTheme ? 'border-b border-emerald-50' : 'border-b border-slate-100';
                                        $facBorder = $loop->last ? '' : $facRowBorder;
                                        $facHoverClass = $usesFerdiaFacilityTheme ? 'hover:bg-emerald-50/40 active:bg-emerald-50' : 'hover:bg-slate-50 active:bg-slate-100';
                                        $facIconBgClass = $usesFerdiaFacilityTheme ? 'bg-emerald-50/45 ring-1 ring-emerald-50' : 'bg-amber-50';
                                    @endphp
                                    @if(!$facIsInactive && isset($facility['route']) && !empty($facility['is_post']))
                                    <form action="{{ route($facility['route'], $facility['params'] ?? []) }}" method="POST" class="{{ $facBorder }}" x-data="{ sub: false }" @submit="if (@js($facRestBlocked)) { $event.preventDefault(); openModal('宿屋', @js($facRestBlockMessage)); return; } sub = true">
                                        @csrf
                                        <button type="submit" x-bind:disabled="sub" class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors disabled:opacity-60 {{ $facHoverClass }}">
                                            <div class="w-9 h-9 shrink-0 rounded-lg flex items-center justify-center overflow-hidden {{ $facIconBgClass }}">{!! $facIconHtml !!}</div>
                                            <div class="flex-1 min-w-0"><div class="text-sm font-bold text-slate-800 leading-tight">{{ $facility['name'] }}</div>@if($facSubText)<div class="text-[11px] text-slate-500 truncate mt-0.5">{{ $facSubText }}</div>@endif</div>
                                            <div class="shrink-0 flex items-center gap-1.5">@if(!empty($facility['badge']))<span class="text-[10px] font-bold text-amber-700 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded">{{ $facility['badge'] }}</span>@elseif($facHasFree)<span class="text-[10px] font-bold text-green-700 bg-green-50 border border-green-200 px-1.5 py-0.5 rounded">無料</span>@endif<svg class="w-4 h-4 text-slate-300" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg></div>
                                        </button>
                                    </form>
                                    @elseif(!$facIsInactive && isset($facility['route']))
                                    <a href="{{ route($facility['route'], $facility['params'] ?? []) }}" wire:navigate class="flex items-center gap-3 px-4 py-2.5 transition-colors {{ $facHoverClass }} {{ $facBorder }}">
                                        <div class="w-9 h-9 shrink-0 rounded-lg flex items-center justify-center overflow-hidden {{ $facIconBgClass }}">{!! $facIconHtml !!}</div>
                                        <div class="flex-1 min-w-0"><div class="text-sm font-bold text-slate-800 leading-tight">{{ $facility['name'] }}</div>@if($facSubText)<div class="text-[11px] text-slate-500 truncate mt-0.5">{{ $facSubText }}</div>@endif</div>
                                        <div class="shrink-0 flex items-center gap-1.5">
                                            @if(!empty($facility['badge_count']))
                                                <span class="flex min-h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1.5 text-[10px] font-black leading-none text-white shadow-sm">
                                                    {{ $facility['badge_label'] ?? $facility['badge_count'] }}
                                                </span>
                                            @endif
                                            @if($facHasFree)<span class="text-[10px] font-bold text-green-700 bg-green-50 border border-green-200 px-1.5 py-0.5 rounded">無料</span>@endif<svg class="w-4 h-4 text-slate-300" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>
                                        </div>
                                    </a>
                                    @elseif(!$facIsInactive && isset($facility['method']))
                                    <button wire:click="{{ $facility['method'] }}" class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors {{ $facHoverClass }} {{ $facBorder }}">
                                        <div class="w-9 h-9 shrink-0 rounded-lg flex items-center justify-center overflow-hidden {{ $facIconBgClass }}">{!! $facIconHtml !!}</div>
                                        <div class="flex-1 min-w-0"><div class="text-sm font-bold text-slate-800 leading-tight">{{ $facility['name'] }}</div>@if($facSubText)<div class="text-[11px] text-slate-500 truncate mt-0.5">{{ $facSubText }}</div>@endif</div>
                                        <svg class="w-4 h-4 text-slate-300 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>
                                    </button>
                                    @else
                                    <div class="flex items-center gap-3 px-4 py-2.5 opacity-40 {{ $facBorder }}">
                                        <div class="w-9 h-9 shrink-0 rounded-lg bg-slate-100 flex items-center justify-center overflow-hidden">{!! $facIconHtml !!}</div>
                                        <div class="flex-1 min-w-0"><div class="text-sm font-bold text-slate-500 leading-tight">{{ $facility['name'] }}</div>@if($facSubText)<div class="text-[11px] text-slate-400 truncate mt-0.5">{{ $facSubText }}</div>@endif</div>
                                        <span class="text-[10px] font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded shrink-0">準備中</span>
                                    </div>
                                    @endif
                                @endforeach
                            </div>
                        @endforeach
                        </div>
                        <!-- PC版（md以上）: カードグリッド -->
                        <div class="{{ $currentLocation === 'guild' ? 'hidden' : 'hidden md:grid grid-cols-2 gap-4 overflow-y-auto pr-1 content-start pb-4' }}">
                    @else
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 overflow-y-auto pr-1 content-start pb-4">
                    @endif
                    @foreach($locationData['facilities'] as $facility)
                        @php
                            $isLocked = $facility['status'] === 'locked';
                            $isComingSoon = $facility['status'] === 'coming_soon';
                            $isInactive = $isLocked || $isComingSoon;
                            $isTargetArea = isset($facility['id']) && (int) ($targetAreaId ?? 0) === (int) $facility['id'];
                            $targetAreaBadgeLabel = match ((string) ($targetAreaPurpose ?? 'focus')) {
                                'material_source' => '素材の入手場所',
                                'next_action' => 'おすすめ探索先',
                                default => '選択中',
                            };
                            $isStarTreeCard = ($facility['card_variant'] ?? null) === 'star_tree';
                            $isFerdiaTownFacilityCard = $usesFerdiaFacilityTheme && $currentLocation === 'town' && !$isStarTreeCard;
                        @endphp
                        
                        <div @if(isset($facility['id'])) id="dungeon-area-{{ $facility['id'] }}" @endif
                            class="border rounded-md flex relative overflow-hidden group scroll-mt-24
                            {{ $isTargetArea ? 'ring-4 ring-orange-400 ring-offset-2 border-orange-500 shadow-[0_0_0_4px_rgba(251,146,60,0.18),0_14px_28px_rgba(194,65,12,0.18)] animate-pulse' : '' }}
                            {{ $isStarTreeCard && !$isInactive ? 'border-teal-400/70 bg-slate-950 shadow-[0_10px_24px_rgba(15,76,92,0.20)] transition-all hover:border-cyan-300 hover:shadow-[0_14px_30px_rgba(15,76,92,0.28)]' : '' }}
                            {{ !$isStarTreeCard && !$isInactive ? ($isFerdiaTownFacilityCard ? 'border-emerald-100 bg-white hover:border-emerald-200 shadow hover:shadow-md hover:shadow-emerald-950/5 transition-all' : 'border-[#d4af37]/50 bg-white hover:border-[#d4af37] shadow hover:shadow-md transition-all') : '' }}
                            {{ $isInactive ? 'bg-gray-100 border-gray-200 opacity-80 grayscale-[0.6]' : '' }}">

                            @if($isTargetArea)
                                <div class="absolute inset-y-0 left-0 z-20 w-1.5 bg-orange-500"></div>
                                <div class="absolute right-2 top-2 z-20 rounded-full border border-orange-300 bg-orange-50 px-2.5 py-1 text-[11px] font-black text-orange-700 shadow-sm">
                                    {{ $targetAreaBadgeLabel }}
                                </div>
                            @endif

                            @if(isset($facility['bg_image']))
                                <!-- 実際の背景画像 -->
                                <div class="absolute inset-0 z-0 transition-transform duration-700 {{ !$isInactive ? 'group-hover:scale-105' : '' }}"
                                     style="background-image: url('{{ asset('images/' . ltrim($facility['bg_image'], '/')) }}'); background-size: cover; background-position: right center; background-repeat: no-repeat;"></div>
                                @if(($facility['depth_overlay'] ?? 0) > 0)
                                    <div class="absolute inset-0 z-0 pointer-events-none" style="background-color: rgba(15, 23, 42, {{ min(70, (int) $facility['depth_overlay']) / 100 }});"></div>
                                @endif
                                <!-- 文字の可読性を上げるための白グラデーション -->
                                @if($isStarTreeCard)
                                    <div class="absolute inset-0 z-0 bg-gradient-to-r from-slate-950/92 via-teal-950/74 to-slate-950/20 pointer-events-none"></div>
                                    <div class="absolute inset-y-0 left-0 z-0 w-1 bg-cyan-300/70 pointer-events-none"></div>
                                    <div class="absolute inset-0 z-0 bg-[radial-gradient(circle_at_18%_35%,rgba(45,212,191,0.22),transparent_36%)] pointer-events-none"></div>
                                @else
                                    <div class="absolute inset-0 z-0 bg-gradient-to-r {{ $isFerdiaTownFacilityCard ? 'from-white via-white/94 to-transparent' : 'from-white via-white/90 to-transparent' }} w-full md:w-3/4 pointer-events-none"></div>
                                    <div class="absolute inset-0 z-0 {{ $isFerdiaTownFacilityCard ? 'bg-emerald-50/10' : 'bg-white/40' }} pointer-events-none"></div>
                                @endif
                            @else
                                <!-- 代替の薄い背景色 -->
                                <div class="absolute inset-0 z-0 bg-gradient-to-r {{ $isFerdiaTownFacilityCard ? 'from-emerald-50/25 to-white' : 'from-amber-50/30 to-white' }} pointer-events-none"></div>
                            @endif

                            <div class="relative z-10 p-3 {{ $isTargetArea ? 'pt-9 sm:pt-3' : '' }} flex flex-col sm:flex-row w-full sm:items-center sm:justify-between gap-3">
                                <!-- アイコンとテキストのコンテナ -->
                                <div class="flex flex-row items-start sm:items-center flex-grow min-w-0 gap-3">
                                    <!-- アイコン（左側） -->
                                    <div class="w-16 h-16 shrink-0 flex items-center justify-center text-5xl drop-shadow-sm">
                                        @if(isset($facility['symbol_image']))
                                            <img src="{{ asset('images/' . $facility['symbol_image']) }}" alt="{{ $facility['name'] }}" class="w-full h-full object-contain">
                                        @elseif(isset($facility['icon_image']))
                                            <img src="{{ asset('images/' . $facility['icon_image']) }}" alt="{{ $facility['name'] }}" class="w-12 h-12 object-contain">
                                        @else
                                            {!! $facility['icon'] ?? '🏛' !!}
                                        @endif
                                    </div>
                                    
                                    <!-- テキスト情報（中央） -->
                                    <div class="flex min-w-0 flex-1 flex-col justify-center">
                                        <div class="font-bold text-lg leading-tight mb-1 break-words {{ $isInactive ? 'text-gray-600' : ($isStarTreeCard ? 'text-white drop-shadow' : 'text-[#1e293b]') }}">
                                            {{ $facility['name'] }}
                                        </div>
                                        <div class="text-sm leading-snug {{ $isInactive ? 'text-gray-500' : ($isStarTreeCard ? 'text-cyan-50/90' : 'text-gray-700') }} font-medium">
                                            {{ $facility['desc'] }}
                                        </div>
                                        @if(isset($facility['details']))
                                            <div class="mt-2 flex flex-wrap gap-1.5">
                                                @foreach($facility['details'] as $detail)
                                                    <span class="inline-flex max-w-full rounded px-2 py-0.5 text-[11px] font-bold leading-snug shadow-sm {{ $isStarTreeCard ? 'border border-cyan-200/25 bg-white/10 text-cyan-50' : 'bg-white/80 border border-[#d4af37]/30 text-[#9a6b00]' }}">
                                                        @if(is_array($detail))
                                                            @if(!empty($detail['icon_image']))
                                                                <img src="{{ asset($detail['icon_image']) }}" alt="" class="mr-1 h-3.5 w-3.5 object-contain">
                                                            @endif
                                                            {{ $detail['text'] ?? '' }}
                                                        @else
                                                            {{ $detail }}
                                                        @endif
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                <!-- アクションボタン（右側、狭い場合は下へ） -->
                                <div class="shrink-0 flex w-full flex-col items-stretch justify-end gap-2 sm:ml-auto sm:w-auto sm:min-w-[112px] sm:items-end">
                                    @if(!$isInactive)
                                        @if($currentLocation === 'dungeon' && isset($facility['id']))
                                            @php
                                                $cooldownRemaining = (int) ($facility['cooldown_remaining_seconds'] ?? 0);
                                                $stamina = $facility['stamina'] ?? null;
                                                $staminaCurrent = (int) ($stamina['current'] ?? 0);
                                                $staminaCost = (int) ($stamina['cost'] ?? 1);
                                                $usesStamina = (bool) ($stamina['enabled'] ?? false);
                                                $staminaCostHtml = $usesStamina
                                                    ? '<span class="inline-flex items-center gap-0.5"><span>（</span><img src="' . asset('images/icon/icon_082.webp') . '" alt="" class="h-4 w-4 object-contain"><span>-' . number_format($staminaCost) . '）</span></span>'
                                                    : '';
                                                $batchExploreCount = 10;
                                                $batchStaminaCost = $staminaCost * $batchExploreCount;
                                                $batchStaminaCostHtml = $usesStamina
                                                    ? '<span class="inline-flex items-center gap-0.5"><span>（</span><img src="' . asset('images/icon/icon_082.webp') . '" alt="" class="h-4 w-4 object-contain"><span>-' . number_format($batchStaminaCost) . '）</span></span>'
                                                    : '';
                                            @endphp
                                            @if(!empty($facility['depth_entries']))
                                                <div class="rounded border border-amber-200 bg-white/85 p-1.5 shadow-sm">
                                                    <div class="mb-1 text-[10px] font-black text-amber-700">記録済み入口</div>
                                                    <div class="flex flex-col gap-1">
                                                        @foreach($facility['depth_entries'] as $depthEntry)
                                                            @php
                                                                $isOtherworldDepthEntry = ($depthEntry['key'] ?? '') === 'otherworld';
                                                            @endphp
                                                            <form action="{{ route('battle.explore', ['area' => $facility['id']]) }}" method="POST" class="w-full"
                                                                  x-data="{
                                                                      submitting: false,
                                                                      remaining: {{ $cooldownRemaining }},
                                                                      staminaCurrent: {{ $staminaCurrent }},
                                                                      staminaCost: {{ $staminaCost }},
                                                                      usesStamina: @js($usesStamina),
                                                                      timer: null,
                                                                      get enoughStamina() { return !this.usesStamina || this.staminaCurrent >= this.staminaCost; },
                                                                      get ready() { return this.remaining <= 0 && this.enoughStamina; },
                                                                      start() {
                                                                          if (this.remaining <= 0) return;
                                                                          this.timer = setInterval(() => {
                                                                              this.remaining = Math.max(0, this.remaining - 1);
                                                                              if (this.remaining <= 0 && this.timer) {
                                                                                  clearInterval(this.timer);
                                                                                  this.timer = null;
                                                                              }
                                                                          }, 1000);
                                                                      }
                                                                  }"
                                                                  x-init="start()"
                                                                  @submit="
                                                                      if (!ready) { $event.preventDefault(); return; }
                                                                      submitting = true
                                                                  ">
                                                                @csrf
                                                                <input type="hidden" name="depth_target" value="{{ $depthEntry['key'] }}">
                                                                <button type="submit"
                                                                        x-bind:disabled="submitting || !ready"
                                                                        class="inline-flex w-full items-center justify-between gap-2 rounded border px-2 py-1 text-left text-[11px] font-black shadow-sm transition active:scale-95 disabled:cursor-not-allowed disabled:opacity-60 {{ $isOtherworldDepthEntry ? 'border-red-900 bg-black text-red-500' : 'border-amber-300 bg-amber-50 text-amber-900' }}">
                                                                    <span x-show="!submitting" class="inline-flex items-center gap-1">{{ $depthEntry['label'] }}へ {!! $staminaCostHtml !!}</span>
                                                                    <span x-show="submitting" style="display: none;">探索中...</span>
                                                                    <span class="text-[10px] font-bold {{ $isOtherworldDepthEntry ? 'text-red-300' : 'text-amber-700' }}">{{ $depthEntry['recommended'] }}</span>
                                                                </button>
                                                            </form>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif
                                            @if(empty($facility['hide_explore']))
                                                @php
                                                    $readyActionText = $facility['action'] ?? '探索する';
                                                @endphp
                                                <div class="flex w-full items-stretch gap-2">
                                                    <form action="{{ route('battle.explore', ['area' => $facility['id']]) }}" method="POST" class="min-w-0 flex-1"
                                                          x-data="{
                                                              submitting: false,
                                                              remaining: {{ $cooldownRemaining }},
                                                              staminaCurrent: {{ $staminaCurrent }},
                                                              staminaCost: {{ $staminaCost }},
                                                              usesStamina: @js($usesStamina),
                                                              timer: null,
                                                              get enoughStamina() { return !this.usesStamina || this.staminaCurrent >= this.staminaCost; },
                                                              get ready() { return this.remaining <= 0 && this.enoughStamina; },
                                                              start() {
                                                                  if (this.remaining <= 0) return;
                                                                  this.timer = setInterval(() => {
                                                                      this.remaining = Math.max(0, this.remaining - 1);
                                                                      if (this.remaining <= 0 && this.timer) {
                                                                          clearInterval(this.timer);
                                                                          this.timer = null;
                                                                      }
                                                                  }, 1000);
                                                              }
                                                          }"
                                                          x-init="start()"
                                                          @submit="
                                                              if (!ready) { $event.preventDefault(); return; }
                                                              submitting = true
                                                          ">
                                                        @csrf
                                                        <button type="submit"
                                                                x-bind:disabled="submitting || !ready"
                                                                x-bind:class="ready ? 'bg-[#1e40af] text-white hover:bg-[#1e3a8a] border-[#1e3a8a] active:scale-95 cursor-pointer disabled:cursor-wait' : 'bg-gray-300 text-gray-600 border-gray-400 cursor-not-allowed'"
                                                                class="inline-flex h-full w-full items-center justify-center gap-2 border-2 px-4 py-1.5 rounded text-sm font-bold shadow transition-all duration-150 text-center disabled:opacity-80">
                                                            <x-loading-spinner x-show="submitting" style="display: none;" />
                                                            <span x-show="!submitting && ready" class="inline-flex items-center gap-1">{{ $readyActionText }} {!! $staminaCostHtml !!}</span>
                                                            <span x-show="!submitting && !ready" x-text="enoughStamina ? `待機中 あと${remaining}秒` : '探索力不足'">{{ $usesStamina && $staminaCurrent < $staminaCost ? '探索力不足' : ($cooldownRemaining > 0 ? '待機中 あと' . $cooldownRemaining . '秒' : $readyActionText) }}</span>
                                                            <span x-show="submitting" style="display: none;">探索中...</span>
                                                        </button>
                                                    </form>
                                                    @if($usesStamina)
                                                        <form action="{{ route('battle.explore', ['area' => $facility['id']]) }}" method="POST" class="shrink-0"
                                                              x-data="{
                                                                  submitting: false,
                                                                  staminaCurrent: {{ $staminaCurrent }},
                                                                  staminaCost: {{ $staminaCost }},
                                                                  get ready() { return this.staminaCurrent >= this.staminaCost; }
                                                              }"
                                                              @submit="
                                                                  if (!ready) { $event.preventDefault(); return; }
                                                                  submitting = true
                                                              ">
                                                            @csrf
                                                            <input type="hidden" name="batch_count" value="{{ $batchExploreCount }}">
                                                            <button type="submit"
                                                                    title="探索力を1回ごとに消費して最大10回探索"
                                                                    x-bind:disabled="submitting || !ready"
                                                                    x-bind:class="ready ? 'bg-sky-700 text-white hover:bg-sky-800 border-sky-800 active:scale-95 cursor-pointer disabled:cursor-wait' : 'bg-gray-300 text-gray-600 border-gray-400 cursor-not-allowed'"
                                                                    class="inline-flex h-full items-center justify-center gap-1.5 border-2 px-3 rounded text-xs font-bold shadow transition-all duration-150 text-center disabled:opacity-80">
                                                                <x-loading-spinner x-show="submitting" style="display: none;" />
                                                                <span x-show="!submitting">×10 探索</span>
                                                                <span x-show="submitting" style="display: none;">探索中...</span>
                                                            </button>
                                                        </form>
                                                    @endif
                                                </div>
                                            @endif
                                            @if(isset($facility['boss_action']))
                                                <form action="{{ route('battle.boss', ['area' => $facility['id']]) }}" method="POST" class="w-full"
                                                      x-data="{
                                                          submitting: false,
                                                          remaining: {{ $cooldownRemaining }},
                                                          staminaCurrent: {{ $staminaCurrent }},
                                                          staminaCost: {{ $staminaCost }},
                                                          usesStamina: @js($usesStamina),
                                                          timer: null,
                                                          get enoughStamina() { return !this.usesStamina || this.staminaCurrent >= this.staminaCost; },
                                                          get ready() { return this.remaining <= 0 && this.enoughStamina; },
                                                          start() {
                                                              if (this.remaining <= 0) return;
                                                              this.timer = setInterval(() => {
                                                                  this.remaining = Math.max(0, this.remaining - 1);
                                                                  if (this.remaining <= 0 && this.timer) {
                                                                      clearInterval(this.timer);
                                                                      this.timer = null;
                                                                  }
                                                              }, 1000);
                                                          }
                                                      }"
                                                      x-init="start()"
                                                      @submit="if (!ready) { $event.preventDefault(); return; } submitting = true">
                                                    @csrf
                                                    <button type="submit"
                                                            x-bind:disabled="submitting || !ready"
                                                            x-bind:class="ready ? 'cursor-pointer active:scale-95' : 'cursor-not-allowed opacity-70'"
                                                            class="inline-flex w-full items-center justify-center gap-2 px-4 py-1.5 rounded text-sm font-bold shadow transition-all duration-150 text-center disabled:cursor-not-allowed disabled:opacity-70" style="background-color: #dc2626; border: 2px solid #991b1b; color: white;">
                                                        <x-loading-spinner x-show="submitting" style="display: none;" />
                                                        <span x-show="!submitting && ready" class="inline-flex items-center gap-1">{{ $facility['boss_action'] }} {!! $staminaCostHtml !!}</span>
                                                        <span x-show="!submitting && !ready" x-text="enoughStamina ? `待機中 あと${remaining}秒` : '探索力不足'">{{ $usesStamina && $staminaCurrent < $staminaCost ? '探索力不足' : '待機中' }}</span>
                                                        <span x-show="submitting" style="display: none;">準備中...</span>
                                                    </button>
                                                </form>
                                            @endif
                                        @elseif(isset($facility['route']))
                                            @if(isset($facility['is_post']) && $facility['is_post'])
                                                <form action="{{ route($facility['route'], $facility['params'] ?? []) }}" method="POST" class="w-full" x-data="{ submitting: false }" @submit="if (@js((bool) ($facility['rest_blocked'] ?? false))) { $event.preventDefault(); openModal('宿屋', @js((string) ($facility['rest_block_message'] ?? 'HP/SPが満タンです。宿屋で休む必要はありません。'))); return; } submitting = true">
                                                    @csrf
                                                    <button type="submit" x-bind:disabled="submitting" class="inline-flex w-full cursor-pointer items-center justify-center gap-2 bg-[#1e40af] text-white hover:bg-[#1e3a8a] border-2 border-[#1e3a8a] px-4 py-1.5 rounded text-sm font-bold shadow transition-all duration-150 active:scale-95 text-center disabled:cursor-wait disabled:opacity-70" style="background-color: #1e40af; border-color: #1e3a8a; color: #ffffff;">
                                                        <x-loading-spinner x-show="submitting" style="display: none;" />
                                                        <span x-show="!submitting">{{ $facility['action'] }}</span>
                                                        <span x-show="submitting" style="display: none;">処理中...</span>
                                                    </button>
                                                </form>
                                            @else
                                                <a href="{{ route($facility['route'], $facility['params'] ?? []) }}" class="inline-flex w-full items-center justify-center px-4 py-1.5 rounded text-sm font-bold shadow transition-all duration-150 active:scale-95 text-center {{ $isStarTreeCard ? 'border-2 border-cyan-200/70 bg-cyan-100 text-slate-950 hover:bg-white' : 'bg-[#1e40af] text-white hover:bg-[#1e3a8a] border-2 border-[#1e3a8a]' }}">
                                                    {{ $facility['action'] }}
                                                </a>
                                            @endif
                                        @elseif(isset($facility['method']))
                                            <button wire:click="{{ $facility['method'] }}" class="inline-flex w-full cursor-pointer items-center justify-center bg-[#1e40af] text-white hover:bg-[#1e3a8a] border-2 border-[#1e3a8a] px-4 py-1.5 rounded text-sm font-bold shadow transition-all duration-150 active:scale-95 text-center">
                                                {{ $facility['action'] }}
                                            </button>
                                        @else
                                            <button class="inline-flex w-full cursor-pointer items-center justify-center bg-[#1e40af] text-white hover:bg-[#1e3a8a] border-2 border-[#1e3a8a] px-4 py-1.5 rounded text-sm font-bold shadow transition-all duration-150 active:scale-95 text-center" 
                                                    @click="openModal('{{ $facility['icon'] ?? '' }} {{ $facility['name'] }}', 'この施設へ入場しますか？（未実装モック）')">
                                                {{ $facility['action'] }}
                                            </button>
                                        @endif
                                    @else
                                        <button class="bg-gray-300 text-gray-500 cursor-not-allowed border-2 border-gray-400 px-4 py-1.5 rounded text-sm font-bold" disabled>
                                            {{ $facility['action'] }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                    @if($currentLocation === 'dungeon' && !empty($locationData['rumors'] ?? []))
                        <div class="col-span-1 md:col-span-2 rounded-md border border-sky-200 bg-sky-50/80 p-3 shadow-sm">
                            <div class="mb-2 text-sm font-black text-sky-900">旅の噂</div>
                            <div class="space-y-1.5">
                                @foreach($locationData['rumors'] as $rumor)
                                    <div class="rounded border border-sky-100 bg-white/80 px-3 py-2 text-xs font-bold text-slate-700">
                                        <div class="flex items-center justify-between gap-2">
                                            <span>@if(!empty($rumor['hint']))<span class="font-normal text-slate-400">{{ $rumor['hint'] }}</span>@endif{{ $rumor['text'] }}</span>
                                            @if(!empty($rumor['required']))
                                                <span class="shrink-0 text-[11px] font-black text-sky-700">{{ $rumor['required'] }}</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    @if($currentLocation === 'dungeon' && !empty($locationData['next_city_travel'] ?? null))
                        @php
                            $nextCity = $locationData['next_city_travel'];
                            $nextCityBgPath = \App\Support\CityVisualCatalog::cardBackground((int) $nextCity->id);
                            $nextCityBgImg = $nextCityBgPath ? 'images/' . $nextCityBgPath : null;
                        @endphp
                        <div class="col-span-1 xl:col-span-2 overflow-hidden rounded-md border border-[#d4af37]/60 bg-white shadow-md">
                            <div class="relative flex min-h-[168px] flex-col justify-end p-4">
                                @if($nextCityBgImg)
                                    <img src="{{ asset($nextCityBgImg) }}" alt="" class="absolute inset-0 h-full w-full object-cover object-center">
                                @else
                                    <div class="absolute inset-0" style="background:{{ app(\App\Services\CityThemeService::class)->backgroundColorForCityId($nextCity->id) }}"></div>
                                @endif
                                <div class="absolute inset-0 bg-gradient-to-r from-white via-white/90 to-white/25"></div>
                                <div class="relative z-10 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="mb-1 inline-flex rounded bg-amber-100/90 px-2 py-0.5 text-[11px] font-black text-amber-800 shadow-sm">新しい街が解放されました</div>
                                        <h3 class="text-lg font-black leading-tight text-slate-900">{{ $nextCity->name }}</h3>
                                        <p class="mt-1 text-sm font-semibold leading-relaxed text-slate-700">{{ $nextCity->description }}</p>
                                    </div>
                                    <form action="{{ route('city.travel', $nextCity) }}" method="POST" class="shrink-0" x-data="{ submitting: false }" @submit="submitting = true">
                                        @csrf
                                        <button type="submit" x-bind:disabled="submitting" class="inline-flex w-full min-w-[140px] cursor-pointer items-center justify-center gap-2 rounded border-2 border-[#1e3a8a] bg-[#1e40af] px-5 py-2 text-sm font-bold text-white shadow transition-all duration-150 hover:bg-[#1e3a8a] active:scale-95 disabled:cursor-wait disabled:opacity-70">
                                            <x-loading-spinner x-show="submitting" style="display: none;" />
                                            <span x-show="!submitting">{{ $nextCity->name }}へ</span>
                                            <span x-show="submitting" style="display: none;">移動中...</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @elseif($currentLocation === 'dungeon' && isset($locationData['all_cleared']) && $locationData['all_cleared'])
                        <div class="col-span-1 xl:col-span-2 border-2 border-[#d4af37] bg-amber-50 rounded-lg p-5 mt-4 flex flex-col items-center justify-center text-center shadow-md">
                            <h3 class="text-lg font-bold text-[#b8860b] mb-2 flex items-center justify-center gap-2">この街の探索を全て完了しました。</h3>
                            <p class="text-gray-700 mb-4 font-medium">新しい街へ旅立つ準備が整いました。次の冒険の舞台へ向かいましょう。</p>
                            <button @click="window.dispatchEvent(new CustomEvent('main-tab-selected', { detail: { location: 'move' } })); $dispatch('changeTab', { newLocation: 'move' })" class="inline-flex cursor-pointer items-center justify-center bg-[#1e40af] hover:bg-[#1e3a8a] border-2 border-[#1e3a8a] text-white font-bold py-2.5 px-8 rounded text-center shadow-md transition-transform hover:-translate-y-1">
                                <img src="{{ asset('images/icon/icon_003.webp') }}" alt="" class="w-4 h-4 object-contain inline-block mr-1"> 街を移動する
                            </button>
                        </div>
                    @endif
                    </div>
                @endif
            </div>
        </div>
    </div>



    <!-- カスタムモーダル (Alpine.js) -->
    <template x-teleport="body">
        <div x-show="isModalOpen" style="display: none;">
            <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 9998; background-color: rgba(0,0,0,0.5);" @click="isModalOpen = false"></div>
            <div style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 9999; width: 90%; max-width: 400px;" class="bg-white border-2 border-[#d4af37] rounded shadow-xl p-6 text-gray-800">
                <!-- 閉じるボタン -->
                <button @click="isModalOpen = false" class="text-gray-400 hover:text-gray-600" style="position: absolute; top: 12px; right: 12px;">
                    <svg style="width: 24px; height: 24px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>



                <!-- 汎用メッセージ用 -->
                <template x-if="!playerInfo">
                    <div>
                        <h3 class="font-bold text-[#1e40af] border-b border-gray-200" style="font-size: 16px; margin-bottom: 12px; padding-bottom: 8px;">
                            <span x-text="modalTitle"></span>
                        </h3>
                        <p class="text-gray-600 font-medium" style="font-size: 13px; margin-bottom: 24px; white-space: pre-wrap; line-height: 1.6;" x-text="modalMessage"></p>
                    </div>
                </template>

                <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 16px;">
                    <button @click="isModalOpen = false" class="bg-gray-100 hover:bg-gray-200 text-gray-700 border border-gray-300 rounded" style="padding: 8px 16px; font-size: 12px; font-weight: bold; cursor: pointer;">閉じる</button>
                </div>
            </div>
        </div>
    </template>

    @if($explorationSupportEnabled)
    <!-- もちものモーダル -->
    <template x-teleport="body">
        <div x-show="belongingsModalOpen" style="display: none;" class="fixed inset-0 z-[9999] overflow-y-auto" aria-labelledby="belongings-modal-title" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-center justify-center p-4 text-center">
                <div
                    x-show="belongingsModalOpen"
                    x-transition.opacity
                    class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm"
                    @click="belongingsModalOpen = false"
                    aria-hidden="true"
                ></div>

                <div
                    x-show="belongingsModalOpen"
                    x-transition
                    class="relative z-10 inline-block w-full max-w-lg transform overflow-hidden rounded-lg bg-white text-left align-middle shadow-xl transition-all"
                >
                    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                        <h3 class="text-lg font-extrabold text-slate-900" id="belongings-modal-title">もちもの</h3>
                        <button type="button" @click="belongingsModalOpen = false" class="shrink-0 rounded-full border border-slate-200 bg-white px-2.5 py-1 text-sm font-black text-slate-500 hover:bg-slate-50" aria-label="閉じる">×</button>
                    </div>
                    <div class="max-h-[70vh] overflow-y-auto px-5 py-4" x-ref="belongingsBody">
                        <template x-if="belongingsLoading">
                            <p class="text-sm font-bold text-slate-400">読み込み中...</p>
                        </template>
                        <div x-show="!belongingsLoading" x-html="belongingsHtml"></div>
                    </div>
                </div>
            </div>
        </div>
    </template>
    @endif
    <!-- キャラアイコン変更モーダル -->
    @if($isIconModalOpen)
    <template x-teleport="body">
        <div class="fixed inset-0 bg-black/60 flex items-center justify-center p-4" style="z-index: 2147483647 !important;">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl overflow-hidden border-2 border-[#d4af37]">
                <div class="p-4 border-b border-[#d4af37]/30 bg-cover bg-center relative"
                     style="background-image: url('{{ asset('images/bg-castle.webp') }}');">
                    <div class="absolute inset-0 bg-white/80"></div>
                    <h3 class="relative font-bold text-[#1e293b] text-xl z-10 drop-shadow-md text-center">キャラクターアイコン変更</h3>
                </div>
                <div class="p-6 max-h-[70vh] overflow-y-auto">
                    <div class="flex flex-wrap gap-3 justify-center">
                        @foreach($characterIconPaths as $iconPath)
                            <div class="relative cursor-pointer group" wire:click="updateIcon('{{ $iconPath }}')">
                                <div class="w-24 h-24 md:w-32 md:h-32 rounded-lg border-2 border-transparent group-hover:border-[#d4af37] overflow-hidden shadow-sm group-hover:shadow-md transition-all p-2 bg-gray-50 flex items-center justify-center">
                                    <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($iconPath) }}" alt="キャラクターアイコン" class="max-w-full max-h-full object-contain group-hover:scale-105 transition-transform duration-300">
                                </div>
                                @if($character && $character->icon_path === $iconPath)
                                    <div class="absolute -top-2 -right-2 bg-green-500 text-white text-xs font-bold px-2 py-0.5 rounded-full shadow-md border border-white">
                                        選択中
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="p-3 border-t border-gray-100 bg-gray-50 flex justify-end">
                    <button wire:click="closeIconModal" class="px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 font-bold text-sm shadow-sm transition-colors">キャンセル</button>
                </div>
            </div>
        </div>
    </template>
    @endif

    <!-- 名前変更モーダル -->
    @if($isNameModalOpen)
    <template x-teleport="body">
        <div class="fixed inset-0 bg-black/60 flex items-center justify-center p-4" style="z-index: 2147483647 !important;">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-md overflow-hidden border-2 border-[#d4af37]">
                <div class="p-4 border-b border-[#d4af37]/30 bg-slate-100">
                    <h3 class="font-bold text-[#1e293b] text-xl text-center">名前変更</h3>
                </div>
                <div class="p-6">
                    <form wire:submit.prevent="updateName">
                        <div class="mb-4">
                            <label class="block text-gray-700 text-sm font-bold mb-2">新しい名前</label>
                            <input type="text" wire:model="newName" class="w-full px-3 py-2 border rounded-md focus:outline-none focus:border-[#d4af37]" maxlength="20" required>
                            @error('newName') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                        </div>
                        <div class="flex justify-end gap-3 mt-6">
                            <button type="button" wire:click="closeNameModal" class="px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 font-bold text-sm shadow-sm transition-colors">キャンセル</button>
                            <button type="submit" class="inline-flex cursor-pointer items-center justify-center px-4 py-2 bg-[#1e40af] text-white hover:bg-[#1e3a8a] rounded text-center font-bold text-sm shadow-sm transition-colors">変更する</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>
    @endif
</div>
