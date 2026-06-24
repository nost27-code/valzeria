@props(['currentLocation' => 'town'])

@php
    $navs = [
        'town' => ['label' => '街', 'icon' => '🏛️', 'route' => route('home')],
        'dungeon' => ['label' => '探索', 'icon' => '⛩️', 'route' => route('home')],
        'colosseum' => ['label' => '闘技場', 'icon' => '⚔️', 'route' => route('home')],
        'guild' => ['label' => 'ギルド', 'icon' => '🏛️', 'route' => route('home')],
        'message' => ['label' => '手紙', 'icon' => '✉️', 'route' => route('home')],
        'settings' => ['label' => '設定', 'icon' => '⚙️', 'route' => route('home')],
    ];
@endphp

<div class="bg-gray-50 border border-[#d4af37] rounded-t-lg px-2 pt-2 flex flex-wrap gap-1 justify-center shadow-inner mb-[-1px] relative z-10 w-full overflow-hidden shrink-0">
    @foreach($navs as $key => $nav)
        <a href="{{ $nav['route'] }}"
            class="px-3 py-2 text-[14px] font-bold flex items-center gap-1 transition-colors border-t border-l border-r rounded-t-md mb-[-1px]
            {{ $currentLocation === $key 
                ? 'bg-white text-[#1e293b] border-[#d4af37] border-b-white z-20 shadow-[0_-2px_4px_rgba(0,0,0,0.05)]' 
                : 'bg-gray-100 text-gray-500 border-gray-200 border-b-[#d4af37] hover:bg-gray-50' 
            }}">
            <span class="text-sm opacity-80">{{ $nav['icon'] }}</span>
            {{ $nav['label'] }}
        </a>
    @endforeach
</div>
