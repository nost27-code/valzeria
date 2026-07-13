@php
    $headerIconImage = 'images/icon/icon_034.webp';
    $bgImage = 'images/card_bg/shop_blacksmith.webp';
    $title = '進化合成の解説 (' . ($currentCity->name ?? '冒険都市ヴァルゼリア') . ')';
@endphp

<x-layouts.facility :title="$title" :headerIconImage="$headerIconImage" :bgImage="$bgImage">
    <div class="mx-auto w-full pb-10">
        <div class="rounded-lg border border-[#d4af37]/50 bg-white p-4 shadow-sm sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="flex items-center gap-2 text-xl font-bold text-slate-800"><img src="{{ asset('images/icon/icon_034.webp') }}" alt="" class="h-7 w-7 object-contain"> 進化合成の解説</h2>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">所持している装備と指定素材を使い、より上位の装備へ進化させる操作です。</p>
                </div>
                <a href="{{ route('smith.index') }}" class="rounded-lg bg-slate-900 px-4 py-3 text-center text-sm font-black text-white shadow-sm transition hover:bg-slate-700">進化合成へ戻る</a>
            </div>

            <section class="mt-6 rounded-lg border border-violet-200 bg-violet-50 p-4">
                <h3 class="text-lg font-black text-violet-950">必要なもの</h3>
                <p class="mt-2 text-sm leading-relaxed text-slate-700">進化元になる装備、レシピに表示された素材、Goldを使います。進化できる装備は、進化合成の候補カードに表示されます。</p>
                <p class="mt-3 text-sm leading-relaxed text-slate-700">同じ名前の装備を複数持っている時は、候補カードから使う個体を選びます。装備中の個体も選べます。</p>
            </section>

            <section class="mt-6 rounded-lg border border-violet-200 bg-white p-4">
                <h3 class="text-lg font-black text-violet-950">進化するとどうなる？</h3>
                <ul class="mt-3 space-y-2 text-sm leading-relaxed text-slate-700">
                    <li>・進化元の装備は、進化後の上位装備へ置き換わります。</li>
                    <li>・<strong>+強化値は+0に戻ります。</strong></li>
                    <li>・銘、銘の段階、種族特攻、種族特攻の段階、品質は引き継がれます。</li>
                </ul>
            </section>

            <section class="mt-6 rounded-lg border border-violet-200 bg-white p-4">
                <h3 class="text-lg font-black text-violet-950">具体例</h3>
                <p class="mt-2 text-sm leading-relaxed text-slate-700">銘II・獣特攻Iが付いた<strong>+3のAランク剣</strong>を、表示されたレシピで上位武器へ進化すると、進化後の武器は<strong>+0</strong>になります。一方で、銘IIと獣特攻Iはそのまま引き継がれます。</p>
            </section>

            <p class="mt-6 text-center text-xs font-bold leading-relaxed text-slate-500">進化前に、使う装備個体・必要素材・進化後プレビューを確認してください。</p>
        </div>
    </div>
</x-layouts.facility>
