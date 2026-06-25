<div class="flex flex-col gap-4 text-sm font-sans w-full h-full"
     x-data="{ 
         isModalOpen: false, 
         modalMessage: '', 
         modalTitle: 'システム',
         playerInfo: null,
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
         }
     }">


    <!-- 2. 中段：メイン2カラム -->
    <div class="flex flex-col md:flex-row gap-4 flex-grow overflow-hidden relative">


        <!-- 右カラム（旧）：移動メニューとメイン画面 -->
        <div class="w-full flex flex-col gap-0 bg-white border border-[#d4af37] rounded-xl overflow-hidden shadow-sm shrink-0 min-h-[80vh]">

            <!-- タブナビゲーション -->
            <livewire:nav-menu />

            <!-- メインコンテンツ表示枠（施設ハブ） -->
            <div class="p-4 flex-grow flex flex-col relative bg-white"
                 wire:loading.class.add="opacity-40 pointer-events-none"
                 wire:target="changeLocation">
                <div wire:loading wire:target="changeLocation"
                     class="absolute inset-0 z-50 flex items-center justify-center bg-white/60 rounded-b-xl">
                    <svg class="w-8 h-8 text-[#d4af37] animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                </div>

                <!-- 現在地ヘッダー -->
                @if($currentLocation !== 'home')
                    @php
                        $locationHeaderTitle = $currentLocation === 'town'
                            ? '街の施設'
                            : ($locationData['title'] ?? '');
                    @endphp
                        <div class="mb-4 pb-2 border-b-2 border-gray-100 flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="shrink-0 text-[#d4af37] text-2xl">⚜</span>
                                    <h3 class="text-xl min-w-0 truncate font-bold text-[#1e293b]">
                                        {{ $locationHeaderTitle }}
                                    </h3>
                                </div>
                                <p class="mt-2 text-xs leading-relaxed text-gray-600">
                                    {{ $locationData['description'] }}
                                </p>
                            </div>
                            @if(in_array($currentLocation, ['town', 'dungeon', 'guild'], true))
                                <button type="button"
                                        wire:click="$dispatchTo('nav-menu', 'tabSelectedFromOutside', { location: 'move' })"
                                        @click="window.dispatchEvent(new CustomEvent('main-tab-selected', { detail: { location: 'move' } }))"
                                        class="shrink-0 rounded-md border border-[#d4af37] bg-white px-3 py-1.5 text-[12px] font-black text-[#9a6b00] shadow-sm transition hover:bg-amber-50 active:scale-95">
                                    MAPへ
                                </button>
                            @endif
                        </div>
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
                                    <a href="{{ route('equipment.index') }}"
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
                                        <a href="{{ route($currentMission['route']) }}"
                                           class="inline-flex items-center justify-center rounded bg-[#1e40af] px-3 py-2 text-xs font-bold text-white shadow border border-[#1e3a8a] active:scale-95">
                                            {{ $currentMission['action_label'] }}
                                        </a>
                                    @elseif(!empty($currentMission['tab']))
                                        <button type="button"
                                                wire:click="$dispatchTo('nav-menu', 'tabSelectedFromOutside', { location: '{{ $currentMission['tab'] }}' })"
                                                @click="window.dispatchEvent(new CustomEvent('main-tab-selected', { detail: { location: '{{ $currentMission['tab'] }}' } }))"
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
                                        <div class="flex shrink-0 items-center gap-1.5">
                                            <span class="rounded bg-white px-2 py-1 text-[10px] font-black text-indigo-700 shadow-sm">
                                                Lv{{ number_format($subArea->recommended_level_min) }}〜
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
                                            <a href="{{ route($menuItem['route']) }}" class="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-slate-50 active:bg-slate-100 {{ $menuBorder }}">
                                                <div class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-amber-50 p-1">{!! $menuIconHtml !!}</div>
                                                <div class="min-w-0 flex-1">
                                                    <div class="text-sm font-bold leading-tight text-slate-800">{{ $menuItem['name'] }}</div>
                                                    <div class="mt-0.5 truncate text-[11px] text-slate-500">{{ $menuItem['desc'] }}</div>
                                                </div>
                                                <svg class="h-4 w-4 shrink-0 text-slate-300" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>
                                            </a>
                                        @elseif(!$menuIsInactive && isset($menuItem['tab']))
                                            <button type="button"
                                                    wire:click="$dispatchTo('nav-menu', 'tabSelectedFromOutside', { location: '{{ $menuItem['tab'] }}' })"
                                                    @click="window.dispatchEvent(new CustomEvent('main-tab-selected', { detail: { location: '{{ $menuItem['tab'] }}' } }))"
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
                                        @endphp
                                        <div class="overflow-hidden rounded-md border shadow-sm {{ $isInactive ? 'border-slate-200 bg-slate-100 opacity-60 grayscale' : 'border-[#d4af37]/50 bg-white transition active:scale-[0.98] hover:border-[#d4af37]' }}">
                                            @if(!$isInactive && !empty($facility['route']))
                                                @if(!empty($facility['is_post']))
                                                    <form action="{{ route($facility['route'], $facility['params'] ?? []) }}" method="POST" class="h-full" x-data="{ submitting: false }" @submit="submitting = true">
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
                                                    <a href="{{ route($facility['route'], $facility['params'] ?? []) }}" x-data="{ submitting: false }" @click="if (submitting) { $event.preventDefault(); } else { submitting = true; }" x-bind:class="submitting ? 'opacity-60 cursor-wait' : ''" class="flex min-h-[58px] w-full items-center justify-center gap-2 px-2.5 py-2 text-center transition active:scale-[0.98]">
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
                                                        @click="if (submitting) return; submitting = true; window.dispatchEvent(new CustomEvent('main-tab-selected', { detail: { location: '{{ $facility['tab'] }}' } })); $dispatch('tabSelectedFromOutside', { location: '{{ $facility['tab'] }}' }); setTimeout(() => submitting = false, 700);"
                                                        wire:click="$dispatchTo('nav-menu', 'tabSelectedFromOutside', { location: '{{ $facility['tab'] }}' })"
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
                    <div class="mb-4 rounded-xl border-2 border-[#d4af37] overflow-hidden shadow-md relative">
                        <img src="{{ asset('images/map/map.webp') }}" alt="ヴァルゼリア世界地図" class="w-full h-auto object-cover">
                        <div class="absolute top-2 left-2 bg-black/60 text-white px-2 py-1 text-xs font-bold rounded shadow">
                            ヴァルゼリア世界地図
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 overflow-y-auto pr-1 content-start pb-4">
                        @foreach($cities as $city)
                            @php
                                $isUnlocked = $city->sort_order <= $highestCityOrder;
                                $isCurrent = $character && $character->current_city_id == $city->id;
                                $cityBgImg = sprintf('images/cities/city%02d.webp', $city->id);
                                $cityBgExists = file_exists(public_path($cityBgImg));
                            @endphp
                            <div class="border {{ $isCurrent ? 'border-amber-500' : ($isUnlocked ? 'border-gray-200 hover:border-[#d4af37]' : 'border-gray-200 opacity-60') }} rounded-lg overflow-hidden transition-all shadow-sm flex flex-col relative {{ !$isUnlocked ? 'grayscale-[0.5]' : '' }}" style="min-height:200px;">
                                {{-- 全体背景画像 --}}
                                @if($cityBgExists)
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
                                <a href="{{ route($facility['route'], $facility['params'] ?? []) }}" class="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-slate-50 active:bg-slate-100 {{ $sBorder }}">
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
                    @if(in_array($currentLocation, ['town', 'guild'], true))
                        @php $groupedLocFacilities = collect($locationData['facilities'])->groupBy('category'); @endphp
                        <!-- スマホ版（md未満）: カテゴリグループリスト -->
                        <div class="{{ $currentLocation === 'guild' ? 'pb-4 space-y-2.5' : 'md:hidden pb-4 space-y-2.5' }}">
                        @foreach($groupedLocFacilities as $facCategory => $facGroup)
                            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                                @if($facCategory)<div class="border-b border-slate-100 bg-slate-50 px-4 py-1.5"><span class="text-[10px] font-extrabold tracking-widest text-slate-400 uppercase">{{ $facCategory }}</span></div>@endif
                                @foreach($facGroup as $facility)
                                    @php
                                        $facIsInactive = in_array($facility['status'] ?? 'active', ['locked', 'coming_soon']);
                                        $facDetails = $facility['details'] ?? [];
                                        $facHasFree = in_array('無料', $facDetails);
                                        $facSubText = collect($facDetails)->reject(fn($d) => $d === '無料')->implode(' · ');
                                        $facBorder = $loop->last ? '' : 'border-b border-slate-100';
                                        $facIconHtml = isset($facility['symbol_image'])
                                            ? '<img src="' . asset('images/' . $facility['symbol_image']) . '" alt="" class="w-full h-full object-contain">'
                                            : (isset($facility['icon_image'])
                                                ? '<img src="' . asset('images/' . $facility['icon_image']) . '" alt="" class="w-7 h-7 object-contain">'
                                                : '<span class="text-xl leading-none">' . ($facility['icon'] ?? '🏛') . '</span>');
                                    @endphp
                                    @if(!$facIsInactive && isset($facility['route']) && !empty($facility['is_post']))
                                    <form action="{{ route($facility['route'], $facility['params'] ?? []) }}" method="POST" class="{{ $facBorder }}" x-data="{ sub: false }" @submit="sub = true">
                                        @csrf
                                        <button type="submit" x-bind:disabled="sub" class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-slate-50 active:bg-slate-100 disabled:opacity-60">
                                            <div class="w-9 h-9 shrink-0 rounded-lg bg-amber-50 flex items-center justify-center overflow-hidden">{!! $facIconHtml !!}</div>
                                            <div class="flex-1 min-w-0"><div class="text-sm font-bold text-slate-800 leading-tight">{{ $facility['name'] }}</div>@if($facSubText)<div class="text-[11px] text-slate-500 truncate mt-0.5">{{ $facSubText }}</div>@endif</div>
                                            <div class="shrink-0 flex items-center gap-1.5">@if($facHasFree)<span class="text-[10px] font-bold text-green-700 bg-green-50 border border-green-200 px-1.5 py-0.5 rounded">無料</span>@endif<svg class="w-4 h-4 text-slate-300" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg></div>
                                        </button>
                                    </form>
                                    @elseif(!$facIsInactive && isset($facility['route']))
                                    <a href="{{ route($facility['route'], $facility['params'] ?? []) }}" class="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-slate-50 active:bg-slate-100 {{ $facBorder }}">
                                        <div class="w-9 h-9 shrink-0 rounded-lg bg-amber-50 flex items-center justify-center overflow-hidden">{!! $facIconHtml !!}</div>
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
                                    <button wire:click="{{ $facility['method'] }}" class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-slate-50 active:bg-slate-100 {{ $facBorder }}">
                                        <div class="w-9 h-9 shrink-0 rounded-lg bg-amber-50 flex items-center justify-center overflow-hidden">{!! $facIconHtml !!}</div>
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
                        @endphp
                        
                        <div @if(isset($facility['id'])) id="dungeon-area-{{ $facility['id'] }}" @endif
                            class="border border-[#d4af37]/50 rounded-md flex relative overflow-hidden group scroll-mt-24
                            {{ (isset($facility['id']) && (int) ($targetAreaId ?? 0) === (int) $facility['id']) ? 'ring-2 ring-amber-400 ring-offset-2' : '' }}
                            {{ !$isInactive ? 'bg-white hover:border-[#d4af37] shadow hover:shadow-md transition-all' : 'bg-gray-100 border-gray-200 opacity-80 grayscale-[0.6]' }}">

                            @if(isset($facility['bg_image']))
                                <!-- 実際の背景画像 -->
                                <div class="absolute inset-0 z-0 transition-transform duration-700 {{ !$isInactive ? 'group-hover:scale-105' : '' }}"
                                     style="background-image: url('{{ asset('images/' . ltrim($facility['bg_image'], '/')) }}'); background-size: cover; background-position: right center; background-repeat: no-repeat;"></div>
                                @if(($facility['depth_overlay'] ?? 0) > 0)
                                    <div class="absolute inset-0 z-0 pointer-events-none" style="background-color: rgba(15, 23, 42, {{ min(70, (int) $facility['depth_overlay']) / 100 }});"></div>
                                @endif
                                <!-- 文字の可読性を上げるための白グラデーション -->
                                <div class="absolute inset-0 z-0 bg-gradient-to-r from-white via-white/90 to-transparent w-full md:w-3/4 pointer-events-none"></div>
                                <div class="absolute inset-0 z-0 bg-white/40 pointer-events-none"></div>
                            @else
                                <!-- 代替の薄い背景色 -->
                                <div class="absolute inset-0 z-0 bg-gradient-to-r from-amber-50/30 to-white pointer-events-none"></div>
                            @endif

                            <div class="relative z-10 p-3 flex flex-col sm:flex-row w-full sm:items-center sm:justify-between gap-3">
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
                                        <div class="font-bold text-lg leading-tight mb-1 break-words {{ $isInactive ? 'text-gray-600' : 'text-[#1e293b]' }}">
                                            {{ $facility['name'] }}
                                        </div>
                                        <div class="text-sm leading-snug {{ $isInactive ? 'text-gray-500' : 'text-gray-700' }} font-medium">
                                            {{ $facility['desc'] }}
                                        </div>
                                        @if(isset($facility['details']))
                                            <div class="mt-2 flex flex-wrap gap-1.5">
                                                @foreach($facility['details'] as $detail)
                                                    <span class="inline-flex max-w-full rounded bg-white/80 border border-[#d4af37]/30 px-2 py-0.5 text-[11px] font-bold leading-snug text-[#9a6b00] shadow-sm">
                                                        {{ $detail }}
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
                                            @endphp
                                            @if(!empty($facility['depth_entries']))
                                                <div class="rounded border border-amber-200 bg-white/85 p-1.5 shadow-sm">
                                                    <div class="mb-1 text-[10px] font-black text-amber-700">記録済み入口</div>
                                                    <div class="flex flex-col gap-1">
                                                        @foreach($facility['depth_entries'] as $depthEntry)
                                                            <form action="{{ route('battle.explore', ['area' => $facility['id']]) }}" method="POST" class="w-full"
                                                                  x-data="{
                                                                      submitting: false,
                                                                      remaining: {{ $cooldownRemaining }},
                                                                      timer: null,
                                                                      get ready() { return this.remaining <= 0; },
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
                                                                        class="inline-flex w-full items-center justify-between gap-2 rounded border border-amber-300 bg-amber-50 px-2 py-1 text-left text-[11px] font-black text-amber-900 shadow-sm transition active:scale-95 disabled:cursor-not-allowed disabled:opacity-60">
                                                                    <span x-show="!submitting">{{ $depthEntry['label'] }}へ</span>
                                                                    <span x-show="submitting" style="display: none;">探索中...</span>
                                                                    <span class="text-[10px] font-bold text-amber-700">{{ $depthEntry['recommended'] }}</span>
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
                                                <form action="{{ route('battle.explore', ['area' => $facility['id']]) }}" method="POST" class="w-full"
                                                      x-data="{
                                                          submitting: false,
                                                          remaining: {{ $cooldownRemaining }},
                                                          timer: null,
                                                          get ready() { return this.remaining <= 0; },
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
                                                            class="inline-flex w-full items-center justify-center gap-2 border-2 px-4 py-1.5 rounded text-sm font-bold shadow transition-all duration-150 text-center disabled:opacity-80">
                                                        <x-loading-spinner x-show="submitting" style="display: none;" />
                                                        <span x-show="!submitting" x-text="ready ? @js($readyActionText) : `待機中 あと${remaining}秒`">{{ $cooldownRemaining > 0 ? '待機中 あと' . $cooldownRemaining . '秒' : $readyActionText }}</span>
                                                        <span x-show="submitting" style="display: none;">探索中...</span>
                                                    </button>
                                                </form>
                                            @endif
                                            @if(isset($facility['boss_action']))
                                                <form action="{{ route('battle.boss', ['area' => $facility['id']]) }}" method="POST" class="w-full"
                                                      x-data="{
                                                          submitting: false,
                                                          remaining: {{ $cooldownRemaining }},
                                                          timer: null,
                                                          get ready() { return this.remaining <= 0; },
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
                                                      x-show="ready"
                                                      @submit="if (!ready) { $event.preventDefault(); return; } submitting = true">
                                                    @csrf
                                                    <button type="submit" x-bind:disabled="submitting" class="inline-flex w-full cursor-pointer items-center justify-center gap-2 px-4 py-1.5 rounded text-sm font-bold shadow transition-all duration-150 active:scale-95 text-center disabled:cursor-wait disabled:opacity-70" style="background-color: #dc2626; border: 2px solid #991b1b; color: white;">
                                                        <x-loading-spinner x-show="submitting" style="display: none;" />
                                                        <span x-show="!submitting">{{ $facility['boss_action'] }}</span>
                                                        <span x-show="submitting" style="display: none;">準備中...</span>
                                                    </button>
                                                </form>
                                            @endif
                                        @elseif(isset($facility['route']))
                                            @if(isset($facility['is_post']) && $facility['is_post'])
                                                <form action="{{ route($facility['route'], $facility['params'] ?? []) }}" method="POST" class="w-full" x-data="{ submitting: false }" @submit="submitting = true">
                                                    @csrf
                                                    <button type="submit" x-bind:disabled="submitting" class="inline-flex w-full cursor-pointer items-center justify-center gap-2 bg-[#1e40af] text-white hover:bg-[#1e3a8a] border-2 border-[#1e3a8a] px-4 py-1.5 rounded text-sm font-bold shadow transition-all duration-150 active:scale-95 text-center disabled:cursor-wait disabled:opacity-70" style="background-color: #1e40af; border-color: #1e3a8a; color: #ffffff;">
                                                        <x-loading-spinner x-show="submitting" style="display: none;" />
                                                        <span x-show="!submitting">{{ $facility['action'] }}</span>
                                                        <span x-show="submitting" style="display: none;">処理中...</span>
                                                    </button>
                                                </form>
                                            @else
                                                <a href="{{ route($facility['route'], $facility['params'] ?? []) }}" class="inline-flex w-full items-center justify-center bg-[#1e40af] text-white hover:bg-[#1e3a8a] border-2 border-[#1e3a8a] px-4 py-1.5 rounded text-sm font-bold shadow transition-all duration-150 active:scale-95 text-center">
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
                            $nextCityBgImg = sprintf('images/cities/city%02d.webp', $nextCity->id);
                            $nextCityBgExists = file_exists(public_path($nextCityBgImg));
                        @endphp
                        <div class="col-span-1 xl:col-span-2 overflow-hidden rounded-md border border-[#d4af37]/60 bg-white shadow-md">
                            <div class="relative flex min-h-[168px] flex-col justify-end p-4">
                                @if($nextCityBgExists)
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
                            <button wire:click="$dispatchTo('nav-menu', 'tabSelectedFromOutside', { location: 'move' })" @click="window.dispatchEvent(new CustomEvent('main-tab-selected', { detail: { location: 'move' } }))" class="inline-flex cursor-pointer items-center justify-center bg-[#1e40af] hover:bg-[#1e3a8a] border-2 border-[#1e3a8a] text-white font-bold py-2.5 px-8 rounded text-center shadow-md transition-transform hover:-translate-y-1">
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
