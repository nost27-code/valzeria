<div>
    <div class="p-6">
        <!-- ヘッダー -->
        <div class="flex justify-between items-center mb-6 border-b border-[#d4af37]/50 pb-4">
            <h2 class="text-2xl font-bold text-[#d4af37] tracking-widest flex items-center gap-2">
                <img src="{{ asset('images/icon/icon_242.webp') }}" alt="" class="w-7 h-7 object-contain"> 称号一覧
            </h2>
            <a href="{{ route('home') }}" class="text-sm bg-slate-700 hover:bg-slate-600 text-white px-4 py-2 rounded transition-colors border border-slate-500 flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                街へ戻る
            </a>
        </div>

        <div class="bg-blue-50 border border-blue-200 rounded p-3 mb-6 text-sm text-blue-800">
            💡 称号をタップすると装備が切り替わります。
        </div>

        <!-- コンテンツ領域 -->
        <div class="flex flex-wrap gap-2">
            @forelse($titles as $title)
                @php
                    $isUnlocked = in_array($title['id'], $characterTitles);
                    $displayRarity = $title['rarity'];
                    
                    // レアリティに応じた文字色・背景色（未取得時はグレー固定）
                    $rarityColor = 'text-slate-400';
                    $rarityBg = 'bg-slate-50';
                    $rarityBorder = 'border-slate-200 border-dashed';
                    $equipped = false;
                    
                    if ($isUnlocked) {
                        if ($displayRarity === 'common') { 
                            $rarityColor = 'text-slate-700'; $rarityBg = 'bg-slate-100 hover:bg-slate-200'; $rarityBorder = 'border-slate-300 border-solid cursor-pointer';
                        } elseif ($displayRarity === 'rare') { 
                            $rarityColor = 'text-blue-700'; $rarityBg = 'bg-blue-50 hover:bg-blue-100'; $rarityBorder = 'border-blue-300 border-solid cursor-pointer';
                        } elseif ($displayRarity === 'epic') { 
                            $rarityColor = 'text-purple-700'; $rarityBg = 'bg-purple-50 hover:bg-purple-100'; $rarityBorder = 'border-purple-300 border-solid cursor-pointer';
                        } elseif ($displayRarity === 'legendary') { 
                            $rarityColor = 'text-yellow-700'; $rarityBg = 'bg-yellow-50 hover:bg-yellow-100'; $rarityBorder = 'border-yellow-400 border-solid cursor-pointer';
                        } elseif ($displayRarity === 'mythic') { 
                            $rarityColor = 'text-red-700'; $rarityBg = 'bg-red-50 hover:bg-red-100'; $rarityBorder = 'border-red-400 border-solid cursor-pointer';
                        }

                        $equipped = Auth::user()->currentCharacter()->titles()->where('is_equipped', true)->value('title_id') == $title['id'];
                        
                        // 装備中の場合の上書き
                        if ($equipped) {
                            $rarityBg = 'bg-amber-100 shadow-md transform scale-[1.05]';
                            $rarityBorder = 'border-amber-500 border-2 border-solid cursor-pointer';
                            $rarityColor = 'text-amber-800';
                        }
                    }
                @endphp
                
                <div 
                    class="px-3 py-1.5 rounded-full border transition-all duration-200 flex items-center gap-1 {{ $rarityBorder }} {{ $rarityBg }} {{ $rarityColor }}"
                    @if($isUnlocked && !$equipped) wire:click="equipTitle({{ $title['id'] }})" @endif
                >
                    @if($equipped)
                        <img src="{{ asset('images/icon/icon_009.webp') }}" alt="" class="w-3.5 h-3.5 object-contain pr-0.5">
                    @endif
                    <span class="font-bold text-sm">
                        @if($isUnlocked)
                            {{ $title['name'] }}
                        @else
                            {{ $title['is_hidden'] ? '？？？' : $title['name'] }}
                        @endif
                    </span>
                </div>
            @empty
                <div class="text-slate-500 text-sm">
                    称号がありません。
                </div>
            @endforelse
        </div>
    </div>
</div>
