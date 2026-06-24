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

            <!-- 世界地図 -->
            <div class="mb-6 rounded-xl border-2 border-[#d4af37] overflow-hidden shadow-md relative">
                <img src="{{ asset('images/map/map.webp') }}" alt="ヴァルゼリア世界地図" class="w-full h-auto object-cover">
                <div class="absolute top-2 left-2 bg-black/60 text-white px-2 py-1 text-xs font-bold rounded shadow">
                    ヴァルゼリア世界地図
                </div>
            </div>

            <p class="text-gray-600 mb-6">
                現在地: <span class="font-bold text-[#1e293b]">{{ $character->currentCity ? $character->currentCity->name : '不明' }}</span>
                <br>
                行きたい街を選択してください。未解放の街へは移動できません。
            </p>

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
                            <div class="text-xs text-gray-400 mb-4 font-medium">推奨Lv: {{ $city->recommended_level_min }} 〜 {{ $city->recommended_level_max }}</div>
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
