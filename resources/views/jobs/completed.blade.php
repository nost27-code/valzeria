<x-layouts.app>
    <div class="max-w-2xl mx-auto p-4 flex flex-col gap-4 text-sm min-h-screen">
        <div class="bg-white rounded-lg shadow-xl border-t-4 border-amber-500 overflow-hidden mt-8">
            <div class="bg-amber-50 p-8 text-center border-b border-amber-100">
                <div class="mb-4 flex justify-center"><img src="{{ asset('images/icon/icon_042.webp') }}" alt="" class="w-16 h-16 object-contain"></div>
                <h1 class="text-2xl font-bold text-amber-900 mb-2 tracking-widest">転職完了</h1>
                <p class="text-amber-700 font-medium text-sm">
                    おめでとうございます！<br>
                    あなたは <strong class="text-amber-900 text-base">「{{ session('newJob') }}」</strong> に生まれ変わりました。
                </p>
            </div>
            
            <div class="p-6 text-slate-700 text-[11px] leading-relaxed bg-slate-50 space-y-4">
                <p>
                    これまでの冒険の記憶と経験の一部が、あなたの魂にしっかりと刻み込まれました。<br>
                    レベルは1に戻りましたが、あなたの基礎能力は以前より確実に高まっています。
                </p>
                <p>
                    これより転職 <strong class="text-amber-600">{{ session('reincarnation_count') }} 回目</strong> の新たな冒険が始まります。<br>
                    まずは宿屋で休み、簡単な迷宮から少しずつ感覚を取り戻していきましょう。
                </p>
                
                @if(session('unlocked_titles') && count(session('unlocked_titles')) > 0)
                    <div class="mt-4 p-4 bg-orange-50 border-l-4 border-orange-400 rounded">
                        <h3 class="font-bold text-orange-800 mb-2 flex items-center gap-1"><img src="{{ asset('images/icon/icon_010.webp') }}" alt="" class="w-4 h-4 object-contain"> 新たな称号を獲得しました！</h3>
                        <ul class="list-disc list-inside text-orange-700">
                            @foreach(session('unlocked_titles') as $title)
                                <li>{{ $title->name }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if(session('unequip_messages') && count(session('unequip_messages')) > 0)
                    <div class="mt-4 p-4 bg-rose-50 border-l-4 border-rose-400 rounded">
                        <h3 class="font-bold text-rose-800 mb-2">装備を一部外しました</h3>
                        <ul class="list-disc list-inside text-rose-700">
                            @foreach(session('unequip_messages') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
            
            <div class="p-6 bg-white border-t border-slate-200 flex justify-center">
                <a href="{{ route('home') }}" class="bg-amber-600 hover:bg-amber-700 text-white font-bold py-3 px-12 rounded-lg shadow-lg transition-transform transform hover:-translate-y-1 text-center">
                    新たな冒険へ出発する
                </a>
            </div>
        </div>
    </div>
</x-layouts.app>
