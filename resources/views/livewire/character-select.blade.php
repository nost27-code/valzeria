<div x-data="{ isDeleteModalOpen: false, deleteCharId: null, deleteCharName: '' }" class="min-h-screen flex items-center justify-center bg-slate-50 bg-opacity-90 bg-cover bg-center" style="background-image: url('{{ asset('images/bg-town.png') }}'); background-blend-mode: overlay;">
    <div class="max-w-4xl w-full mx-auto p-6 bg-white/95 rounded-2xl shadow-2xl border border-slate-200 backdrop-blur-sm">
        
        <h1 class="text-3xl font-bold text-center text-amber-700 mb-8 title-font">キャラクター選択</h1>

        @if($characters->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                @foreach($characters as $char)
                    <div class="bg-white rounded-xl p-5 border-2 border-slate-200 hover:border-amber-400 transition-colors shadow-md cursor-pointer relative" wire:click="selectCharacter({{ $char->id }})">
                        <!-- 削除ボタン -->
                        <button 
                            @click.stop="deleteCharId = {{ $char->id }}; deleteCharName = '{{ addslashes($char->name) }}'; isDeleteModalOpen = true" 
                            class="absolute top-2 right-2 text-slate-400 hover:text-red-500 hover:bg-red-50 p-1.5 rounded-full transition-colors z-10"
                            title="キャラクターを削除"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        </button>
                        <div class="flex items-center space-x-4 mb-4 mt-2">
                            <div class="w-16 h-16 rounded-full border-2 border-slate-300 overflow-hidden flex items-center justify-center bg-slate-50">
                                @if($char->icon_path)
                                    <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($char->icon_path) }}" alt="{{ $char->name }}" class="w-full h-full object-cover">
                                @else
                                    👤
                                @endif
                            </div>
                            <div>
                                <h2 class="text-xl font-bold text-slate-800">{{ $char->name }}</h2>
                                <p class="text-sm text-amber-600 font-bold">Lv. {{ $char->level }} {{ $char->jobClass->name ?? '冒険者' }}</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-2 text-sm text-slate-700">
                            <div class="bg-slate-50 p-2 rounded border border-slate-200">
                                <span class="text-slate-500 text-xs block font-bold">総合スコア</span>
                                {{ number_format($char->total_score) }} Pt
                            </div>
                        </div>
                        <button class="mt-4 w-full py-2 bg-gradient-to-r from-amber-600 to-purple-600 hover:from-amber-500 hover:to-purple-500 text-white font-bold rounded shadow transition-transform active:scale-95">
                            冒険を再開する
                        </button>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center py-10 bg-slate-50 rounded-xl border border-slate-200 mb-8 shadow-sm">
                <p class="text-slate-600 font-bold text-lg mb-2">まだキャラクターが存在しません。</p>
                <p class="text-slate-500 text-sm">新しいキャラクターを作成して冒険を始めましょう！</p>
            </div>
        @endif

        @if($characters->count() === 0)
        <div class="flex justify-center border-t border-slate-200 pt-8">
            <button wire:click="createNewCharacter" class="py-3 px-8 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-bold rounded-xl shadow-lg transform transition duration-200 hover:-translate-y-1 flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                新しいキャラクターを作成する
            </button>
        </div>
        @endif
        
    </div>

    <!-- 削除確認モーダル -->
    <div x-show="isDeleteModalOpen" style="display: none;" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <!-- 背景のオーバーレイ -->
        <div class="absolute inset-0 bg-black/60" x-transition.opacity @click="isDeleteModalOpen = false"></div>
        
        <!-- モーダルコンテンツ -->
        <div class="relative bg-white border-2 border-[#d4af37] rounded-lg shadow-xl w-full max-w-md overflow-hidden" x-transition.scale.origin.center @click.stop>
            <div class="bg-[#1e293b] p-4 border-b border-[#d4af37]/30 text-center relative">
                <h3 class="font-bold text-white text-lg tracking-wide">
                    <img src="{{ asset('images/icon/icon_046.webp') }}" alt="" class="w-5 h-5 object-contain inline-block mr-1">キャラクター削除の確認
                </h3>
            </div>
            
            <div class="p-6 text-center">
                <p class="text-slate-700 font-bold mb-2 text-lg">
                    本当に「<span x-text="deleteCharName" class="text-red-600"></span>」を削除しますか？
                </p>
                <p class="text-slate-500 text-sm">
                    この操作は取り消せません。データは完全に失われます。
                </p>
            </div>
            
            <div class="bg-slate-50 border-t border-slate-200 p-4 flex justify-end gap-3">
                <button @click="isDeleteModalOpen = false" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold rounded-lg transition-colors shadow-sm text-sm">
                    キャンセル
                </button>
                <button @click="$wire.deleteCharacter(deleteCharId); isDeleteModalOpen = false" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-bold rounded-lg shadow-md transition-colors text-sm flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                    削除する
                </button>
            </div>
        </div>
    </div>
</div>
