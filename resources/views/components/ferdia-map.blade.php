@props(['map', 'character'])

@if(!empty($map))
    @php
        $imagePath = !empty($map['image_exists']) ? $map['map_image'] : ($map['placeholder_image'] ?? $map['map_image']);
        $nodes = collect($map['nodes'] ?? []);
        $routes = collect($map['routes'] ?? []);
        $iconFor = [
            'landing' => '⚓',
            'road' => '◆',
            'river' => '≈',
            'ruin' => '◇',
            'city' => '■',
            'port' => '⚑',
            'forest' => '♣',
            'castle' => '✦',
            'mountain' => '▲',
            'branch' => '◇',
        ];
        $cityNodesById = $nodes
            ->filter(fn (array $node): bool => !empty($node['city_id']))
            ->keyBy(fn (array $node): int => (int) $node['city_id']);
        $cityCards = collect(config('ferdia_world_map.cities', []))
            ->map(function (array $city) use ($cityNodesById, $character): array {
                $cityId = (int) ($city['id'] ?? 0);
                $node = $cityNodesById->get($cityId);
                $state = (string) ($node['state'] ?? 'hidden');
                $isCurrent = $character && (int) ($character->current_city_id ?? 0) === $cityId;
                $isDiscovered = $state === 'completed';
                $canTravel = !$isCurrent && $state === 'completed';
                $imagePath = \App\Support\CityVisualCatalog::cardBackground($cityId);

                return [
                    'id' => $cityId,
                    'name' => $isDiscovered ? (string) ($city['name'] ?? ($node['name'] ?? '未知の街')) : '未発見',
                    'description' => $isDiscovered ? (string) ($city['description'] ?? ($node['description'] ?? '')) : '未発見の街を調査中です。',
                    'state' => $state,
                    'is_current' => $isCurrent,
                    'can_travel' => $canTravel,
                    'image_path' => $imagePath ? 'images/' . $imagePath : null,
                ];
            })
            ->values();
    @endphp

    <section class="mb-6 overflow-hidden rounded-xl border-2 border-emerald-700/60 bg-emerald-50 shadow-md"
             x-data="{ ferdiaZoom: 1, ferdiaPinchDistance: 0, ferdiaPinchZoom: 1, ferdiaSelectedNode: '', ferdiaFocusOpen: false, focusName: '', focusX: 50, focusY: 50, focusPanX: 0, focusPanY: 0, focusDragging: false, focusStartX: 0, focusStartY: 0, focusOriginX: 0, focusOriginY: 0 }">
        <div class="border-b border-emerald-100 bg-white/85 px-4 py-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="text-[11px] font-black text-emerald-700">{{ $map['subtitle'] ?? '緑豊かなる連邦の大地' }}</div>
                    <h3 class="text-lg font-black leading-tight text-slate-950">{{ $map['name'] ?? 'フェルディア大陸' }}</h3>
                </div>
                <div class="text-right text-[11px] font-bold leading-5 text-slate-600">
                    <div>現在: <span class="font-black text-slate-900">{{ $map['current_node']['name'] ?? 'フェルディア南岸' }}</span></div>
                    @if(!empty($map['next_node']))
                        <div>遠景: <span class="font-black text-emerald-700">{{ $map['next_node']['name'] }}</span></div>
                    @endif
                </div>
            </div>
        </div>

        @if(!empty($map['setup_missing']))
            <div class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-[11px] font-bold leading-relaxed text-amber-800">
                フェルディア地方のマスタが未投入です。ローカルでは FerdiaRegionSeeder を実行してください。
                @if(!empty($map['setup_missing_area_ids']))
                    <span class="font-black">未投入Area: {{ implode(', ', $map['setup_missing_area_ids']) }}</span>
                @endif
            </div>
        @endif

        <div class="flex items-center justify-end gap-1 border-b border-emerald-100 bg-white/85 px-3 py-2">
            <div class="mr-auto text-[10px] font-bold text-slate-600">地点をタップ／ピンチで拡大縮小</div>
            <button type="button" @click="ferdiaZoom = 1; $nextTick(() => $refs.ferdiaMapScroll.scrollLeft = 0)" class="h-8 min-w-12 rounded border px-2 text-xs font-black shadow-sm" :class="ferdiaZoom === 1 ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-slate-300 bg-white text-slate-700'">等倍</button>
            <button type="button" @click="ferdiaZoom = 2" class="h-8 min-w-12 rounded border px-2 text-xs font-black shadow-sm" :class="ferdiaZoom === 2 ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-slate-300 bg-white text-slate-700'">2倍</button>
            <button type="button" @click="ferdiaZoom = 3" class="h-8 min-w-12 rounded border px-2 text-xs font-black shadow-sm" :class="ferdiaZoom === 3 ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-slate-300 bg-white text-slate-700'">3倍</button>
        </div>

        <div x-ref="ferdiaMapScroll"
             class="relative overflow-x-auto bg-[#e8f4df] overscroll-x-contain touch-pan-x [-webkit-overflow-scrolling:touch]"
             @touchstart="if ($event.touches.length === 2) { const dx = $event.touches[0].clientX - $event.touches[1].clientX; const dy = $event.touches[0].clientY - $event.touches[1].clientY; ferdiaPinchDistance = Math.hypot(dx, dy); ferdiaPinchZoom = ferdiaZoom; }"
             @touchmove="if ($event.touches.length === 2 && ferdiaPinchDistance > 0) { $event.preventDefault(); const dx = $event.touches[0].clientX - $event.touches[1].clientX; const dy = $event.touches[0].clientY - $event.touches[1].clientY; const nextZoom = ferdiaPinchZoom * (Math.hypot(dx, dy) / ferdiaPinchDistance); ferdiaZoom = Math.round(Math.max(1, Math.min(6, nextZoom)) * 100) / 100; }"
             @touchend="if ($event.touches.length < 2) ferdiaPinchDistance = 0"
             @touchcancel="ferdiaPinchDistance = 0">
            <div class="relative bg-[#e8f4df] transition-[width] duration-200 ease-out" :style="{ width: (ferdiaZoom * 100) + '%' }">
            <img src="{{ asset($imagePath) }}" alt="フェルディア大陸MAP" class="block h-auto w-full">
            @if(empty($map['image_exists']))
                <div class="absolute inset-x-3 top-3 rounded border border-amber-200 bg-white/90 px-3 py-2 text-[11px] font-bold text-amber-800 shadow">
                    map02.webp が未配置のため仮背景で表示しています。
                </div>
            @endif
            <div class="absolute left-2 top-2 rounded bg-emerald-950/70 px-2 py-1 text-xs font-bold text-white shadow">
                フェルディア大陸MAP
            </div>

            <svg class="pointer-events-none absolute inset-0 h-full w-full" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                @foreach($routes as $route)
                    @php
                        $routeGroup = (string) ($route['group'] ?? 'main');
                        $routeStroke = match ($routeGroup) {
                            'story' => '#ea580c',
                            'story_final' => '#7e22ce',
                            'branch' => '#047857',
                            default => '#166534',
                        };
                    @endphp
                    <line
                        x1="{{ (float) $route['from_x'] }}"
                        y1="{{ (float) $route['from_y'] }}"
                        x2="{{ (float) $route['to_x'] }}"
                        y2="{{ (float) $route['to_y'] }}"
                        stroke="{{ $routeStroke }}"
                        stroke-width="{{ ($route['state'] ?? '') === 'hinted' ? '0.24' : '0.38' }}"
                        stroke-dasharray="{{ in_array($routeGroup, ['branch', 'story_final'], true) ? '0.9 1.1' : (($route['state'] ?? '') === 'hinted' ? '1.4 1.2' : '0') }}"
                        opacity="{{ ($route['state'] ?? '') === 'hinted' ? '0.28' : '0.44' }}"
                        stroke-linecap="round"
                    />
                @endforeach
            </svg>

            @foreach($nodes as $node)
                @php
                    $state = (string) ($node['state'] ?? 'hidden');
                    $isClickable = !empty($node['is_clickable']);
                    $isCurrentCity = !empty($node['city_id']) && $character && (int) $character->current_city_id === (int) $node['city_id'];
                    $tone = match ($state) {
                        'completed' => 'border-emerald-800 bg-emerald-700 text-white',
                        'unlocked' => 'border-blue-900 bg-blue-800 text-white',
                        'hinted' => 'border-slate-400 bg-white/80 text-slate-500',
                        default => 'border-slate-300 bg-slate-100 text-slate-400',
                    };
                    $badge = $isCurrentCity ? '現在地' : match ($state) {
                        'completed' => '踏破',
                        'unlocked' => !empty($node['city_id']) ? '街へ' : '探索',
                        'hinted' => '遠景',
                        default => '',
                    };
                    $nodeIcon = $iconFor[$node['node_type'] ?? 'road'] ?? '◆';
                @endphp
                <div class="absolute z-10 -translate-x-1/2 -translate-y-1/2"
                     style="left: {{ (float) ($node['x_percent'] ?? 50) }}%; top: {{ (float) ($node['y_percent'] ?? 50) }}%;">
                    <div class="flex items-center justify-center gap-0.5">
                        <button type="button"
                                class="flex h-6 w-6 items-center justify-center rounded-full border-2 border-white text-[11px] font-black shadow transition active:scale-95 {{ $tone }}"
                                :class="ferdiaSelectedNode === @js($node['key']) ? 'ring-2 ring-amber-300 ring-offset-1' : ''"
                                @click.stop="ferdiaSelectedNode = @js($node['key'])"
                                aria-label="{{ $node['name'] }}を確認">
                            {{ $nodeIcon }}
                        </button>
                        <button type="button"
                                class="flex h-4 w-4 items-center justify-center rounded-full border border-white/90 bg-white/95 text-[10px] font-black leading-none text-slate-900 shadow sm:h-5 sm:w-5 sm:text-xs"
                                @click.stop="ferdiaFocusOpen = true; focusName = @js($node['name']); focusX = {{ (float) ($node['x_percent'] ?? 50) }}; focusY = {{ (float) ($node['y_percent'] ?? 50) }}; focusPanX = 0; focusPanY = 0; focusDragging = false"
                                aria-label="{{ $node['name'] }}周辺を拡大">+</button>
                    </div>
                </div>
            @endforeach
            </div>

            @foreach($nodes as $node)
                @php
                    $state = (string) ($node['state'] ?? 'hidden');
                    $isClickable = !empty($node['is_clickable']);
                    $isCurrentCity = !empty($node['city_id']) && $character && (int) $character->current_city_id === (int) $node['city_id'];
                    $badge = $isCurrentCity ? '現在地' : match ($state) {
                        'completed' => '踏破済み',
                        'unlocked' => !empty($node['city_id']) ? '街へ移動可能' : '探索可能',
                        'hinted' => '遠景',
                        default => '未発見',
                    };
                @endphp
                <div x-show="ferdiaSelectedNode === @js($node['key'])" x-transition
                     class="absolute inset-x-3 bottom-3 z-[70] mx-auto max-w-md rounded-lg border-2 border-emerald-800 bg-white/95 p-3 shadow-2xl"
                     style="display: none;">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-[10px] font-black text-emerald-700">{{ $badge }}</div>
                            <div class="text-base font-black text-slate-950">{{ $node['name'] }}</div>
                        </div>
                        <button type="button" class="flex h-7 w-7 items-center justify-center rounded border border-slate-300 bg-white text-sm font-black text-slate-700 shadow-sm" @click="ferdiaSelectedNode = ''" aria-label="地点名の表示を閉じる">×</button>
                    </div>
                    @if($isClickable && !empty($node['area_id']))
                        <form action="{{ route('city.ferdia.area.open', ['area' => (int) $node['area_id']]) }}" method="POST" class="mt-3" x-data="{ submitting: false }" @submit="submitting = true">
                            @csrf
                            <button type="submit" x-bind:disabled="submitting" class="w-full rounded bg-blue-800 px-3 py-2 text-sm font-black text-white shadow transition hover:bg-blue-700 disabled:opacity-70">探索へ進む</button>
                        </form>
                    @elseif($isClickable && !empty($node['city_id']) && !$isCurrentCity)
                        <form action="{{ route('city.travel', ['city' => (int) $node['city_id']]) }}" method="POST" class="mt-3" x-data="{ submitting: false }" @submit="submitting = true">
                            @csrf
                            <button type="submit" x-bind:disabled="submitting" class="w-full rounded bg-amber-700 px-3 py-2 text-sm font-black text-white shadow transition hover:bg-amber-600 disabled:opacity-70">街へ移動する</button>
                        </form>
                    @elseif($isCurrentCity)
                        <div class="mt-3 rounded bg-emerald-50 px-3 py-2 text-center text-sm font-black text-emerald-800">現在この街に滞在しています</div>
                    @endif
                </div>
            @endforeach

            <div x-show="ferdiaFocusOpen" x-transition class="fixed inset-x-3 bottom-3 z-[70] mx-auto max-w-md rounded-lg border-2 border-emerald-800 bg-white/95 p-2 shadow-2xl" style="display: none;">
                <div class="mb-1.5 flex items-center justify-between gap-2">
                    <div class="truncate text-xs font-black text-slate-900" x-text="focusName + ' 周辺'"></div>
                    <button type="button" class="flex h-6 w-6 items-center justify-center rounded border border-slate-300 bg-white text-sm font-black text-slate-700 shadow-sm" @click="ferdiaFocusOpen = false" aria-label="拡大表示を閉じる">×</button>
                </div>
                <div class="relative touch-none cursor-grab overflow-hidden rounded-md border border-emerald-900/20 bg-[#e8f4df] shadow-inner active:cursor-grabbing"
                     style="height: min(62vh, 520px);"
                     @pointerdown="focusDragging = true; focusStartX = $event.clientX; focusStartY = $event.clientY; focusOriginX = focusPanX; focusOriginY = focusPanY; $event.currentTarget.setPointerCapture && $event.currentTarget.setPointerCapture($event.pointerId)"
                     @pointermove="if (focusDragging) { const w = $event.currentTarget.clientWidth; const h = $event.currentTarget.clientHeight; focusPanX = Math.max(-w, Math.min(w, focusOriginX + $event.clientX - focusStartX)); focusPanY = Math.max(-h, Math.min(h, focusOriginY + $event.clientY - focusStartY)); }"
                     @pointerup="focusDragging = false"
                     @pointercancel="focusDragging = false"
                     @pointerleave="focusDragging = false">
                    <div class="pointer-events-none absolute max-w-none select-none"
                         style="width: 600%;"
                         x-bind:style="{
                             left: 'calc(50% - ' + (focusX * 6) + '% + ' + focusPanX + 'px)',
                             top: 'calc(50% - ' + (focusY * 6) + '% + ' + focusPanY + 'px)'
                         }">
                        <img src="{{ asset($imagePath) }}" alt="" class="block h-auto w-full">

                        <svg class="absolute inset-0 h-full w-full" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                            @foreach($routes as $route)
                                @php
                                    $routeGroup = (string) ($route['group'] ?? 'main');
                                    $routeStroke = match ($routeGroup) {
                                        'story' => '#ea580c',
                                        'story_final' => '#7e22ce',
                                        'branch' => '#047857',
                                        default => '#166534',
                                    };
                                @endphp
                                <line
                                    x1="{{ (float) $route['from_x'] }}"
                                    y1="{{ (float) $route['from_y'] }}"
                                    x2="{{ (float) $route['to_x'] }}"
                                    y2="{{ (float) $route['to_y'] }}"
                                    stroke="{{ $routeStroke }}"
                                    stroke-width="{{ ($route['state'] ?? '') === 'hinted' ? '0.24' : '0.38' }}"
                                    stroke-dasharray="{{ in_array($routeGroup, ['branch', 'story_final'], true) ? '0.9 1.1' : (($route['state'] ?? '') === 'hinted' ? '1.4 1.2' : '0') }}"
                                    opacity="{{ ($route['state'] ?? '') === 'hinted' ? '0.28' : '0.44' }}"
                                    stroke-linecap="round"
                                />
                            @endforeach
                        </svg>

                        @foreach($nodes as $node)
                            @php
                                $state = (string) ($node['state'] ?? 'hidden');
                                $isCurrentCity = !empty($node['city_id']) && $character && (int) $character->current_city_id === (int) $node['city_id'];
                                $tone = match ($state) {
                                    'completed' => 'border-emerald-800 bg-emerald-700 text-white',
                                    'unlocked' => 'border-blue-900 bg-blue-800 text-white',
                                    'hinted' => 'border-slate-400 bg-white/80 text-slate-500',
                                    default => 'border-slate-300 bg-slate-100 text-slate-400',
                                };
                                $badge = $isCurrentCity ? '現在地' : match ($state) {
                                    'completed' => '踏破',
                                    'unlocked' => !empty($node['city_id']) ? '街へ' : '探索',
                                    'hinted' => '遠景',
                                    default => '',
                                };
                                $nodeIcon = $iconFor[$node['node_type'] ?? 'road'] ?? '◆';
                            @endphp
                            <div class="absolute z-10 -translate-x-1/2 -translate-y-1/2"
                                 style="left: {{ (float) ($node['x_percent'] ?? 50) }}%; top: {{ (float) ($node['y_percent'] ?? 50) }}%;">
                                <div class="flex max-w-[116px] items-center gap-1 rounded-sm border bg-white/95 px-1.5 py-0.5 text-[9px] font-black leading-tight text-slate-950 shadow">
                                    <span class="shrink-0">{{ $nodeIcon }}</span>
                                    <span class="truncate">{{ $node['name'] }}</span>
                                    @if($badge !== '')
                                        <span class="shrink-0 rounded bg-blue-900 px-1 text-[8px] text-white">{{ $badge }}</span>
                                    @endif
                                </div>
                                <div class="mx-auto mt-0.5 h-2.5 w-2.5 rounded-full border-2 border-white shadow {{ $tone }}"></div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-2 border-t border-emerald-100 bg-white/85 px-4 py-3 text-[11px] font-bold text-slate-600 sm:grid-cols-3">
            <div><span class="font-black text-emerald-700">遠景</span> は道筋が見え始めた地点です。</div>
            <div><span class="font-black text-blue-800">探索</span> から街道を進んで開拓度を上げます。</div>
            <div><span class="font-black text-slate-900">街</span> は到達後に既存施設を利用できます。</div>
        </div>

        @if($cityCards->isNotEmpty())
            <div class="border-t border-emerald-100 bg-emerald-50/80 px-3 py-3">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <div class="text-sm font-black text-slate-950">フェルディアの街</div>
                    <div class="text-[10px] font-bold text-emerald-700">到達後に移動できます</div>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach($cityCards as $city)
                        @php
                            $isLocked = $city['state'] !== 'completed';
                            $badgeText = $city['state'] === 'completed' ? '移動可能' : '未発見';
                            $badgeClass = $city['state'] === 'completed'
                                ? 'bg-blue-800 text-white'
                                : 'bg-slate-200 text-slate-600';
                        @endphp
                        <div class="relative min-h-[164px] overflow-hidden rounded-lg border border-emerald-200 bg-white shadow-sm {{ $isLocked ? 'grayscale-[0.35] opacity-75' : '' }}">
                            @if($city['image_path'])
                                <img src="{{ asset($city['image_path']) }}" alt="" class="absolute inset-0 h-full w-full object-cover object-center">
                            @else
                                <div class="absolute inset-0 bg-emerald-100"></div>
                            @endif
                            <div class="absolute inset-0 bg-gradient-to-b from-white/15 via-white/58 to-white/96"></div>
                            <div class="relative z-10 flex min-h-[164px] flex-col justify-end p-3">
                                <div class="mb-1">
                                    <span class="inline-flex rounded px-2 py-0.5 text-[10px] font-black shadow-sm {{ $badgeClass }}">{{ $badgeText }}</span>
                                </div>
                                <div class="text-sm font-black leading-tight text-slate-950">{{ $city['name'] }}</div>
                                <p class="mt-1 line-clamp-2 text-[11px] font-semibold leading-relaxed text-slate-700">{{ $city['description'] }}</p>
                                <div class="mt-3">
                                    @if($city['is_current'] && $city['state'] === 'completed')
                                        <div class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-center text-xs font-black text-emerald-800">滞在中</div>
                                    @elseif($city['can_travel'])
                                        <form action="{{ route('city.travel', ['city' => (int) $city['id']]) }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true">
                                            @csrf
                                            <button type="submit" x-bind:disabled="submitting" class="inline-flex w-full items-center justify-center gap-2 rounded border border-blue-900 bg-blue-800 px-3 py-2 text-xs font-black text-white shadow transition hover:bg-blue-700 active:scale-95 disabled:cursor-wait disabled:opacity-70">
                                                <x-loading-spinner x-show="submitting" style="display: none;" />
                                                <span x-show="!submitting">{{ $city['name'] }}へ</span>
                                                <span x-show="submitting" style="display: none;">移動中...</span>
                                            </button>
                                        </form>
                                    @else
                                        <div class="rounded border border-slate-200 bg-white/80 px-3 py-2 text-center text-xs font-black text-slate-500">まだ向かえません</div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </section>
@endif
