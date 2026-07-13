@php
    $headerIcon = '🔨';
    $bgImage = 'images/card_bg/shop_blacksmith.webp';
    $title = '装備強化の解説 (' . ($currentCity->name ?? '冒険都市ヴァルゼリア') . ')';
@endphp

<x-layouts.facility :title="$title" :headerIcon="$headerIcon" :bgImage="$bgImage">
    <div class="mx-auto w-full pb-10">
        <div class="rounded-lg border border-[#d4af37]/50 bg-white p-4 shadow-sm sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="flex items-center gap-2 text-xl font-bold text-slate-800"><span class="text-2xl">🔨</span> 装備強化の解説</h2>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">武器・防具・装飾品を、装備ランクごとの上限まで強化して性能を伸ばす操作です。G〜Eは+10、D〜Bは+15、Aは+20、Sは+25、SS〜EPICは+30まで強化できます。</p>
                </div>
                <a href="{{ route('blacksmith.index') }}" class="rounded-lg bg-slate-900 px-4 py-3 text-center text-sm font-black text-white shadow-sm transition hover:bg-slate-700">装備強化へ戻る</a>
            </div>

            <section class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4">
                <h3 class="text-lg font-black text-amber-950">必要なもの</h3>
                <p class="mt-2 text-sm leading-relaxed text-slate-700">強化段階に応じて、欠片・強化石系素材・共通素材・Goldを使います。必要数は装備ごとのカードに表示されます。輝石は使いません。</p>
                <p class="mt-3 text-sm leading-relaxed text-slate-700">強化したい装備そのものは消えません。素材とGoldだけを消費して、+値が1段階ずつ上がります。</p>
            </section>

            <section class="mt-6 rounded-lg border border-amber-200 bg-white p-4">
                <h3 class="text-lg font-black text-amber-950">具体例</h3>
                <p class="mt-2 text-sm leading-relaxed text-slate-700">基礎攻撃が100の武器を<strong>+2</strong>にすると、基礎攻撃は106になります。+5までは1段階ごとに約3%伸び、以降は伸び幅を少しずつ抑えながら、+30で合計約47.5%上がります。</p>
            </section>

            <section class="mt-6 rounded-lg border border-slate-200 bg-slate-50 p-4">
                <h3 class="text-lg font-black text-slate-900">使い方</h3>
                <ol class="mt-3 space-y-2 text-sm leading-relaxed text-slate-700">
                    <li><strong>1.</strong> 武器・防具・装飾品のタブから、強化したい装備を探します。</li>
                    <li><strong>2.</strong> カードの「必要素材」「必要Gold」と、強化後の性能を確認します。</li>
                    <li><strong>3.</strong> 「+○へ強化」を押すと、1段階だけ強化されます。</li>
                </ol>
                <p class="mt-3 text-xs font-bold text-slate-500">市場へ出品中の装備は、出品を取り消すまで強化できません。</p>
            </section>
        </div>
    </div>
</x-layouts.facility>
