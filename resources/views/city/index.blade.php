<x-layouts.app>
    <div class="max-w-7xl mx-auto p-4 flex flex-col gap-4 text-sm font-sans text-[#1e293b]">

        @if (session()->has('message'))
            <div class="bg-[#f0f9ff] border border-[#bae6fd] text-[#0369a1] px-4 py-3 rounded relative shadow-sm font-medium" role="alert">
                <span class="block sm:inline">{{ session('message') }}</span>
            </div>
        @endif
        @if (session()->has('success'))
            <div class="bg-[#f0fdf4] border border-[#bbf7d0] text-[#15803d] px-4 py-3 rounded relative shadow-sm font-medium" role="alert">
                <span class="block sm:inline">{{ session('success') }}</span>
            </div>
        @endif
        @if (session()->has('error'))
            <div class="bg-[#fef2f2] border border-[#fecaca] text-[#b91c1c] px-4 py-3 rounded relative shadow-sm font-medium" role="alert">
                <span class="block sm:inline">{{ session('error') }}</span>
            </div>
        @endif

        <div class="bg-white border border-[#d4af37] rounded-xl p-5 shadow-md">
            <div class="flex justify-between items-center mb-4 border-b border-gray-100 pb-2">
                <h2 class="text-xl font-bold text-[#1e293b] flex items-center gap-2">
                    <img src="{{ asset('images/icon/icon_003.webp') }}" alt="" class="w-7 h-7 object-contain"> 街の移動
                </h2>
                <a href="{{ route('home') }}" class="text-[#1e40af] hover:text-[#1e3a8a] text-sm font-bold flex items-center gap-1">
                    ◀ 戻る
                </a>
            </div>

            <p class="text-gray-600 mb-6">
                現在地: <span class="font-bold text-[#1e293b]">{{ $character->currentCity ? $character->currentCity->name : '不明' }}</span>
                <br>
                行きたい街を選択してください。未解放の街へは移動できません。
            </p>

            <!-- 世界地図 -->
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
            @endphp
            <div class="mb-6 overflow-hidden rounded-xl border-2 border-[#d4af37] bg-[#f8f1df] shadow-md">
                <div class="relative" x-data="{ zoomOpen: false, zoomName: '', zoomX: 50, zoomY: 50, zoomPopulation: 0, zoomIcons: [], selectedPlayer: null, panX: 0, panY: 0, isPanning: false, panStartX: 0, panStartY: 0, panOriginX: 0, panOriginY: 0 }">
                    <img src="{{ asset($worldMapPath) }}" alt="ヴァルゼリア世界地図" class="block h-auto w-full">
                    <div class="absolute left-2 top-2 rounded bg-black/60 px-2 py-1 text-xs font-bold text-white shadow">
                        ヴァルゼリア世界地図
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
                            $isCurrent = $character->current_city_id == $city->id;
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
                                    <div class="group flex flex-col items-center">
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
                                    <form action="{{ route('city.travel', $city->id) }}" method="POST" class="group flex flex-col items-center">
                                        @csrf
                                        <button type="submit" class="flex items-center gap-1 whitespace-nowrap rounded-sm border border-[#6f5124] bg-[#fff3d1]/95 px-1.5 py-0.5 text-[8px] font-black text-slate-950 shadow-md transition hover:scale-105 hover:bg-white focus:outline-none focus:ring-2 focus:ring-white/90 sm:px-2 sm:text-[11px]">
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
                                    <div class="group flex flex-col items-center opacity-80">
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

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($cities as $city)
                    @php
                        $isUnlocked = $city->sort_order <= $highestCityOrder;
                        $isCurrent = $character->current_city_id == $city->id;
                        $cityImgPath = sprintf('images/cities/city%02d.webp', $city->id);
                        $cityImgExists = file_exists(public_path($cityImgPath));
                    @endphp
                    <div class="border {{ $isCurrent ? 'border-amber-500' : ($isUnlocked ? 'border-gray-200 hover:border-[#d4af37]' : 'border-gray-200 opacity-60') }} rounded-lg overflow-hidden transition-all shadow-sm {{ !$isUnlocked ? 'grayscale-[0.6]' : '' }}">
                        {{-- 都市サムネイル --}}
                        <div class="relative h-28 bg-gray-100 overflow-hidden">
                            @if($cityImgExists)
                                <img src="{{ asset($cityImgPath) }}" alt="{{ $city->name }}"
                                     class="w-full h-full object-cover object-top {{ !$isUnlocked ? 'opacity-50' : '' }}">
                            @else
                                <div class="w-full h-full flex items-center justify-center text-4xl"
                                     style="background:{{ app(\App\Services\CityThemeService::class)->backgroundColorForCityId($city->id) }}">
                                    <img src="{{ asset('images/icon/icon_001.webp') }}" alt="" class="w-12 h-12 object-contain opacity-60">
                                </div>
                            @endif
                            {{-- バッジ --}}
                            @if($isCurrent)
                                <span class="absolute top-2 right-2 text-xs font-bold text-white bg-amber-500 px-2 py-0.5 rounded shadow">現在地</span>
                            @elseif(!$isUnlocked)
                                <span class="absolute top-2 right-2 text-xs font-bold text-gray-600 bg-gray-200 px-2 py-0.5 rounded border border-gray-300 shadow">未解放</span>
                            @endif
                        </div>
                        {{-- カード本体 --}}
                        <div class="p-4 {{ $isCurrent ? 'bg-amber-50' : ($isUnlocked ? 'bg-white' : 'bg-gray-100') }}">
                            <h3 class="font-bold text-lg {{ $isUnlocked ? 'text-[#1e293b]' : 'text-gray-500' }} mb-1">
                                {{ $city->name }}
                            </h3>
                            <p class="text-xs text-gray-500 h-8 mb-2 leading-relaxed">{{ $city->description }}</p>
                            @php
                                $cityPowerRange = app(\App\Services\CharacterPowerService::class)->openingRecommendedRangeForCity($city);
                            @endphp
                            <div class="text-xs text-gray-400 mb-4 font-medium">開拓目安: {{ app(\App\Services\CharacterPowerService::class)->formatRange($cityPowerRange) }}</div>
                            <div class="flex justify-end">
                                @if($isCurrent)
                                    <button disabled class="bg-gray-300 text-white font-bold py-1.5 px-4 rounded text-sm cursor-not-allowed">
                                        滞在中
                                    </button>
                                @elseif($isUnlocked)
                                    <form action="{{ route('city.travel', $city->id) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="bg-[#1e40af] hover:bg-[#1e3a8a] text-white font-bold py-1.5 px-4 rounded text-sm shadow transition-colors">
                                            移動する
                                        </button>
                                    </form>
                                @else
                                    <button disabled class="bg-gray-400 text-white font-bold py-1.5 px-4 rounded text-sm cursor-not-allowed">
                                        移動不可
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-layouts.app>
