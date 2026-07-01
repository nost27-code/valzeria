<x-layouts.facility title="宿屋" headerIconImage="images/facilities/facility_inn_300.webp" bgImage="images/facilities/inn.webp">
    <div class="mx-auto w-full max-w-2xl py-8">
        <div class="overflow-hidden rounded-xl border border-amber-300 bg-white shadow-lg">
            <div class="border-b border-amber-200 bg-amber-50 px-5 py-3">
                <div class="text-xs font-black tracking-widest text-amber-700">宿屋のおばちゃん</div>
                <h2 class="mt-1 text-xl font-black text-slate-900">今日は特別に休ませてあげるよ</h2>
            </div>

            <div class="p-5">
                <div class="flex gap-4">
                    <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-amber-200 bg-amber-50">
                        <img src="{{ asset('images/icon/icon_093.webp') }}" alt="" class="h-16 w-16 object-contain">
                    </div>
                    <div class="min-w-0 flex-1 space-y-3">
                        <p class="text-sm font-bold leading-relaxed text-slate-700">
                            「{{ $characterName }}、財布が軽いじゃないかい。宿代は本当なら
                            <span class="font-black text-amber-700">{{ number_format($fee) }}G</span>
                            だけど、今日は見逃してあげるよ。」
                        </p>
                        <p class="text-sm font-bold leading-relaxed text-slate-700">
                            「次に泊まるときは、ちゃんとGoldを持っておいで。銀行に預けているなら、先に引き出してから来るんだよ。」
                        </p>
                        @if($rescueStreak >= 2)
                            <p class="text-sm font-black leading-relaxed text-red-700">
                                「ただし、こう何度も続くなら次は泊められないよ。Goldを用意してから来るんだ。」
                            </p>
                        @endif
                    </div>
                </div>

                <div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-700">
                    HP/SPが全回復しました。
                    @if($paid > 0)
                        所持金 {{ number_format($paid) }}G を支払いました。
                    @else
                        今回の支払いは免除されました。
                    @endif
                </div>

                <div class="mt-6 flex justify-center">
                    <a href="{{ route('home') }}"
                       class="inline-flex min-w-48 items-center justify-center rounded-lg bg-slate-800 px-5 py-3 text-sm font-black text-white shadow transition hover:bg-slate-900 active:scale-95">
                        街へ戻る
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-layouts.facility>
