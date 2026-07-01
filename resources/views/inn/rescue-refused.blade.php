<x-layouts.facility title="宿屋" headerIconImage="images/facilities/facility_inn_300.webp" bgImage="images/facilities/inn.webp">
    <div class="mx-auto w-full max-w-2xl py-8">
        <div class="overflow-hidden rounded-xl border border-red-300 bg-white shadow-lg">
            <div class="border-b border-red-200 bg-red-50 px-5 py-3">
                <div class="text-xs font-black tracking-widest text-red-700">宿屋のおばちゃん</div>
                <h2 class="mt-1 text-xl font-black text-slate-900">今日は泊められないよ</h2>
            </div>

            <div class="p-5">
                <div class="flex gap-4">
                    <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-amber-200 bg-amber-50">
                        <img src="{{ asset('images/icon/icon_093.webp') }}" alt="" class="h-16 w-16 object-contain">
                    </div>
                    <div class="min-w-0 flex-1 space-y-3">
                        <p class="text-sm font-bold leading-relaxed text-slate-700">
                            「{{ $characterName }}、また宿代が足りないのかい。さすがに何度もツケにはできないよ。」
                        </p>
                        <p class="text-sm font-bold leading-relaxed text-slate-700">
                            「素材を売っておいで。市場や素材の売却で少しでもGoldを作るんだ。」
                        </p>
                        <p class="text-sm font-bold leading-relaxed text-slate-700">
                            「補給所の回復薬も使っちまったのかい？ それなら無理せず、持ち物を整理して出直しておいで。」
                        </p>
                    </div>
                </div>

                <div class="mt-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
                    宿泊には {{ number_format($fee) }}G が必要です。Goldを用意してから来てください。
                </div>

                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    <a href="{{ route('inventory.index') }}"
                       class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-black text-amber-800 shadow-sm transition hover:bg-amber-100 active:scale-95">
                        <img src="{{ asset('images/icon/icon_025.webp') }}" alt="" class="h-5 w-5 object-contain">
                        倉庫を見る
                    </a>
                    <a href="{{ route('shop.items') }}"
                       class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-sky-300 bg-sky-50 px-4 py-3 text-sm font-black text-sky-800 shadow-sm transition hover:bg-sky-100 active:scale-95">
                        <img src="{{ asset('images/facilities/facility_supply_300.webp') }}" alt="" class="h-5 w-5 object-contain">
                        補給所へ
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-layouts.facility>
