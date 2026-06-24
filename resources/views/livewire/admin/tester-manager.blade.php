<div class="w-full px-4 sm:px-6 lg:px-8 py-8 lg:py-10">
    <div class="mb-6">
        <p class="text-xs font-black tracking-[0.24em] text-amber-600">TESTER MANAGER</p>
        <h1 class="mt-2 text-3xl font-black text-slate-950">テストキャラ管理</h1>
    </div>

    @if (session()->has('message'))
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded shadow-sm">
            {{ session('message') }}
        </div>
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-4 gap-6">
        <!-- テストプレイヤー作成フォーム -->
        <div class="bg-white/95 p-6 rounded-md shadow-sm ring-1 ring-slate-200 border-t-4 border-[#d4af37] lg:col-span-1 h-fit">
            <h2 class="text-xl font-black mb-4 border-b border-slate-200 pb-2 text-slate-900">テストキャラ生成</h2>
            
            <form wire:submit.prevent="createTester" class="space-y-4">
                <div>
                    <label class="block text-sm font-bold text-slate-600 mb-1">キャラクター名</label>
                    <input type="text" wire:model="name" class="w-full rounded-md border border-slate-300 bg-slate-50 shadow-inner focus:border-[#d4af37] focus:bg-white focus:ring focus:ring-[#d4af37] focus:ring-opacity-50">
                    @error('name') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-slate-600 mb-1">Lv (レベル)</label>
                        <input type="number" wire:model="level" class="w-full rounded-md border border-slate-300 bg-slate-50 shadow-inner focus:border-[#d4af37] focus:bg-white focus:ring focus:ring-[#d4af37]">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-600 mb-1">最大HP</label>
                        <input type="number" wire:model="hp_base" class="w-full rounded-md border border-slate-300 bg-slate-50 shadow-inner focus:border-[#d4af37] focus:bg-white focus:ring focus:ring-[#d4af37]">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-600 mb-1">最大SP</label>
                        <input type="number" wire:model="mp_base" class="w-full rounded-md border border-slate-300 bg-slate-50 shadow-inner focus:border-[#d4af37] focus:bg-white focus:ring focus:ring-[#d4af37]">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-600 mb-1">STR (腕力)</label>
                        <input type="number" wire:model="attack_base" class="w-full rounded-md border border-slate-300 bg-slate-50 shadow-inner focus:border-[#d4af37] focus:bg-white focus:ring focus:ring-[#d4af37]">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-600 mb-1">DEF (体力)</label>
                        <input type="number" wire:model="defense_base" class="w-full rounded-md border border-slate-300 bg-slate-50 shadow-inner focus:border-[#d4af37] focus:bg-white focus:ring focus:ring-[#d4af37]">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-600 mb-1">AGI (敏捷)</label>
                        <input type="number" wire:model="speed_base" class="w-full rounded-md border border-slate-300 bg-slate-50 shadow-inner focus:border-[#d4af37] focus:bg-white focus:ring focus:ring-[#d4af37]">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-600 mb-1">MAG (魔力)</label>
                        <input type="number" wire:model="magic_base" class="w-full rounded-md border border-slate-300 bg-slate-50 shadow-inner focus:border-[#d4af37] focus:bg-white focus:ring focus:ring-[#d4af37]">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-600 mb-1">LUK (運)</label>
                        <input type="number" wire:model="luck_base" class="w-full rounded-md border border-slate-300 bg-slate-50 shadow-inner focus:border-[#d4af37] focus:bg-white focus:ring focus:ring-[#d4af37]">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-600 mb-1">SPR (精神)</label>
                        <input type="number" wire:model="spirit_base" class="w-full rounded-md border border-slate-300 bg-slate-50 shadow-inner focus:border-[#d4af37] focus:bg-white focus:ring focus:ring-[#d4af37]">
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full rounded-md bg-slate-950 hover:bg-slate-800 text-white font-black py-2.5 px-4 shadow transition-colors" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="createTester">生成する</span>
                        <span wire:loading wire:target="createTester">生成中...</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- テストプレイヤー一覧 -->
        <div class="bg-white/95 p-6 rounded-md shadow-sm ring-1 ring-slate-200 xl:col-span-3">
            <h2 class="text-xl font-black mb-4 border-b border-slate-200 pb-2 text-slate-900">テストキャラクター一覧</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                @forelse ($testers as $tester)
                    @php $character = $tester->characters->first(); @endphp
                    @if($character)
                        <div class="border rounded-lg p-4 shadow-sm relative {{ $editingTesterId === $tester->id ? 'bg-yellow-50 border-yellow-200' : 'bg-white' }}">
                            
                            @if($editingTesterId === $tester->id)
                                <!-- 編集モード -->
                                <div class="mb-3">
                                    <div class="font-bold text-gray-900 text-lg">{{ $character->name }}</div>
                                    <div class="flex items-center gap-2 mt-1">
                                        <span class="text-sm font-bold">Lv:</span>
                                        <input type="number" wire:model="editData.level" class="w-20 p-1 text-sm border rounded">
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-5 gap-2 mb-4 text-sm">
                                    <div><span class="text-xs text-gray-500 block">HP</span><input type="number" wire:model="editData.hp_base" class="w-full p-1 border rounded"></div>
                                    <div><span class="text-xs text-gray-500 block">SP</span><input type="number" wire:model="editData.mp_base" class="w-full p-1 border rounded"></div>
                                    <div><span class="text-xs text-gray-500 block">STR</span><input type="number" wire:model="editData.attack_base" class="w-full p-1 border rounded"></div>
                                    <div><span class="text-xs text-gray-500 block">DEF</span><input type="number" wire:model="editData.defense_base" class="w-full p-1 border rounded"></div>
                                    <div><span class="text-xs text-gray-500 block">AGI</span><input type="number" wire:model="editData.speed_base" class="w-full p-1 border rounded"></div>
                                    <div><span class="text-xs text-gray-500 block">MAG</span><input type="number" wire:model="editData.magic_base" class="w-full p-1 border rounded"></div>
                                    <div><span class="text-xs text-gray-500 block">LUK</span><input type="number" wire:model="editData.luck_base" class="w-full p-1 border rounded"></div>
                                    <div><span class="text-xs text-gray-500 block">SPR</span><input type="number" wire:model="editData.spirit_base" class="w-full p-1 border rounded"></div>
                                </div>
                                <div class="flex gap-2">
                                    <button wire:click="updateTester" class="flex-1 border-2 border-green-500 bg-white hover:bg-green-50 text-green-700 font-bold py-2 px-3 rounded transition shadow-sm">保存する</button>
                                    <button wire:click="cancelEdit" class="flex-1 border-2 border-gray-400 bg-white hover:bg-gray-100 text-gray-700 font-bold py-2 px-3 rounded transition shadow-sm">キャンセル</button>
                                </div>
                            @else
                                <!-- 通常モード -->
                                <div class="flex justify-between items-start mb-3">
                                    <div class="flex gap-3">
                                        <!-- キャラクター画像 -->
                                        @if($character->icon_path)
                                            <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($character->icon_path) }}" alt="{{ $character->name }}" class="w-12 h-12 rounded object-cover border border-gray-300 flex-shrink-0">
                                        @else
                                            <div class="w-12 h-12 bg-gray-200 rounded flex items-center justify-center text-gray-500 text-xs border border-gray-300 flex-shrink-0">No Img</div>
                                        @endif
                                        <div>
                                            <div class="font-bold text-gray-900 text-lg flex items-center gap-2">
                                                {{ $character->name }}
                                                @if($character->jobClass)
                                                    <span class="bg-blue-100 text-blue-800 text-xs px-2 py-0.5 rounded">{{ $character->jobClass->name }}</span>
                                                @endif
                                            </div>
                                            <div class="text-xs text-gray-500">{{ $tester->email }}</div>
                                        </div>
                                    </div>
                                    <div class="bg-gray-100 text-gray-800 text-xs font-bold px-2 py-1 rounded whitespace-nowrap">
                                        Lv {{ $character->level }}
                                    </div>
                                </div>
                                <div class="bg-gray-50 p-2 rounded text-sm text-gray-600 mb-4 grid grid-cols-2 md:grid-cols-4 gap-1">
                                    <div><span class="text-gray-400 text-xs">HP:</span> <span class="font-semibold">{{ $character->hp_base }}</span></div>
                                    <div><span class="text-gray-400 text-xs">SP:</span> <span class="font-semibold">{{ $character->mp_base }}</span></div>
                                    <div><span class="text-gray-400 text-xs">STR:</span> <span class="font-semibold">{{ $character->attack_base }}</span></div>
                                    <div><span class="text-gray-400 text-xs">DEF:</span> <span class="font-semibold">{{ $character->defense_base }}</span></div>
                                    <div><span class="text-gray-400 text-xs">AGI:</span> <span class="font-semibold">{{ $character->speed_base }}</span></div>
                                    <div><span class="text-gray-400 text-xs">MAG:</span> <span class="font-semibold">{{ $character->magic_base }}</span></div>
                                    <div><span class="text-gray-400 text-xs">LUK:</span> <span class="font-semibold">{{ $character->luck_base }}</span></div>
                                    <div><span class="text-gray-400 text-xs">SPR:</span> <span class="font-semibold">{{ $character->spirit_base ?? 0 }}</span></div>
                                </div>
                                <div class="flex gap-2 mt-auto">
                                    <button wire:click="editTester({{ $tester->id }})" class="flex-1 border-2 border-gray-400 bg-white hover:bg-gray-100 text-gray-800 font-bold py-2 px-3 rounded text-sm transition shadow-sm text-center">
                                        編集
                                    </button>
                                    <button wire:click="playAs({{ $tester->id }})" class="flex-1 border-2 border-blue-500 bg-white hover:bg-blue-50 text-blue-700 font-bold py-2 px-3 rounded text-sm transition shadow-sm text-center">
                                        プレイ
                                    </button>
                                </div>
                            @endif
                        </div>
                    @endif
                @empty
                    <div class="col-span-full py-8 text-center text-gray-500 bg-gray-50 rounded-lg">
                        テストキャラクターはまだ作成されていません。
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
