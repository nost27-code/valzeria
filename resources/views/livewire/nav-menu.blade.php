<div x-data="{ pending: null }">
    @php
        $bottomNavs = [
            'town'      => ['label' => '街',      'icon' => '🏰', 'image' => 'tabs/tab_town.webp'],
            'dungeon'   => ['label' => '探索',    'icon' => '🧭', 'image' => 'tabs/tab_dungeon.webp'],
            'home'      => ['label' => '冒険者',  'icon' => '👤', 'image' => 'tabs/tab_home.webp'],
            'guild'     => ['label' => '市場・依頼', 'icon' => '⚖️', 'image' => 'tabs/tab_guild.webp'],
            'colosseum' => ['label' => '闘技場',  'icon' => '🛡️', 'image' => 'tabs/tab_colosseum.webp'],
        ];
    @endphp

    <div class="fixed inset-x-0 bottom-0 z-40 border-t border-[#d4af37]/40 bg-white shadow-[0_-4px_20px_rgba(0,0,0,0.08)]"
         style="padding-bottom: env(safe-area-inset-bottom)">
        <div class="grid grid-cols-5">
            @foreach($bottomNavs as $key => $nav)
                <button type="button"
                        wire:click="$dispatch('changeTab', { newLocation: '{{ $key }}' })"
                        @click="pending = '{{ $key }}'; window.dispatchEvent(new CustomEvent('main-tab-selected', { detail: { location: '{{ $key }}' } }))"
                        class="relative flex flex-col items-center justify-center gap-0.5 py-1 transition-all active:scale-90">

                    {{-- アクティブインジケーター（上部ライン） --}}
                    <span class="absolute inset-x-4 top-0 h-[2px] rounded-b-full transition-all duration-200"
                          :class="(pending ?? $wire.currentLocation) === '{{ $key }}' ? 'bg-[#d4af37]' : 'bg-transparent'"></span>

                    {{-- アイコン --}}
                    @if(!empty($nav['image']) && file_exists(public_path('images/' . $nav['image'])))
                        <span class="relative">
                            <img src="{{ asset('images/' . $nav['image']) }}" alt=""
                                 class="h-12 w-12 object-contain transition-all duration-200"
                                 :class="(pending ?? $wire.currentLocation) === '{{ $key }}'
                                     ? 'scale-110 opacity-100'
                                     : 'scale-100 opacity-35 grayscale'">
                            @if($key === 'guild' && (int) ($marketActionCount ?? 0) > 0)
                                <span class="absolute right-0 top-0 flex min-h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[9px] font-black leading-none text-white ring-2 ring-white">
                                    {{ (int) $marketActionCount > 9 ? '9+' : (int) $marketActionCount }}
                                </span>
                            @endif
                        </span>
                    @else
                        <span class="relative text-2xl leading-none transition-all duration-200"
                              :class="(pending ?? $wire.currentLocation) === '{{ $key }}' ? 'opacity-100' : 'opacity-35'">
                            {{ $nav['icon'] }}
                            @if($key === 'guild' && (int) ($marketActionCount ?? 0) > 0)
                                <span class="absolute -right-2 -top-1 flex min-h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[9px] font-black leading-none text-white ring-2 ring-white">
                                    {{ (int) $marketActionCount > 9 ? '9+' : (int) $marketActionCount }}
                                </span>
                            @endif
                        </span>
                    @endif

                    {{-- ラベル --}}
                    <span class="text-[7px] font-bold leading-none tracking-wide transition-colors duration-200"
                          :class="(pending ?? $wire.currentLocation) === '{{ $key }}' ? 'text-[#d4af37]' : 'text-slate-500'">{{ $nav['label'] }}</span>
                </button>
            @endforeach
        </div>
    </div>
</div>
