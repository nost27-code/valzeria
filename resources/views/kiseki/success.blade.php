<x-layouts.facility title="購入完了" headerIconImage="images/icon/icon_047.webp" bgImage="images/bg-castle.webp">

    <div class="text-center py-12">
        <div class="mb-4"><img src="{{ asset('images/icon/kiseki.webp') }}" alt="輝石" class="w-16 h-16 mx-auto object-contain"></div>
        <h2 class="text-2xl font-bold text-slate-800 mb-2">購入ありがとうございます！</h2>
        <p class="text-slate-500 text-sm mb-2">決済が完了しました。</p>
        <p class="text-slate-400 text-xs mb-8">有償輝石は自動的に付与されます。反映に少し時間がかかる場合があります。</p>

        <div class="bg-slate-50 rounded-xl px-6 py-4 inline-block mb-8">
            <div class="text-xs text-slate-400 mb-1">現在の有償輝石</div>
            <div class="flex items-center justify-center gap-2 text-3xl font-bold text-yellow-500">
                <img src="{{ asset('images/icon/kiseki.webp') }}" alt="輝石" class="w-8 h-8 object-contain">
                {{ number_format($character->paid_kiseki ?? 0) }}
            </div>
        </div>

        <div class="flex justify-center gap-4">
            <a href="{{ route('kiseki.shop') }}"
               class="px-6 py-2 rounded-lg bg-blue-600 text-white font-semibold text-sm hover:bg-blue-700 transition">
                輝石ショップに戻る
            </a>
            <a href="{{ route('home') }}"
               class="px-6 py-2 rounded-lg bg-slate-200 text-slate-700 font-semibold text-sm hover:bg-slate-300 transition">
                街に戻る
            </a>
        </div>
    </div>

</x-layouts.facility>
