@php
    $headerIcon = '⚒️';
    $bgImage = 'images/card_bg/shop_blacksmith.webp';
    $title = '銘・特攻を鍛える解説 (' . ($currentCity->name ?? '冒険都市ヴァルゼリア') . ')';
@endphp

<x-layouts.facility :title="$title" :headerIcon="$headerIcon" :bgImage="$bgImage">
    <div class="mx-auto w-full pb-10">
        <div class="rounded-lg border border-[#d4af37]/50 bg-white p-4 shadow-sm sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="flex items-center gap-2 text-xl font-bold text-slate-800"><span class="text-2xl">⚒️</span> 銘・特攻を鍛える解説</h2>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">「銘を鍛える」か「特攻・耐性を鍛える」を選び、<strong>残したい装備（ベース）</strong>と<strong>消えてよい装備（素材）</strong>を選びます。武器は種族特攻、防具は種族耐性を鍛えられます（武器同士・防具同士のみ）。</p>
                    <p class="mt-2 text-sm font-bold text-slate-700">例：剣の場合</p>
                </div>
                <a href="{{ route('blacksmith.traits.index') }}" class="rounded-lg bg-slate-900 px-4 py-3 text-center text-sm font-black text-white shadow-sm transition hover:bg-slate-700">鍛錬画面へ戻る</a>
            </div>

            <section class="mt-6 rounded-lg border border-indigo-200 bg-indigo-50 p-4">
                <h3 class="text-lg font-black text-indigo-950">まずは「ベース」と「素材」を決めます</h3>
                <ul class="mt-3 space-y-2 text-sm leading-relaxed text-slate-700">
                    <li>・<strong>銘を鍛える</strong>、または<strong>特攻を鍛える</strong>を選びます。</li>
                    <li>・<strong>ベース</strong>：残したい<strong>剣</strong>を選びます。完成後も剣が手元に残ります。</li>
                    <li>・<strong>素材</strong>：消えてよい<strong>剣</strong>を選びます。素材にした剣は消滅します。</li>
                    <li>・<strong>銘を鍛える</strong>では銘だけ、<strong>特攻を鍛える</strong>では種族特攻だけを確認します。</li>
                </ul>
            </section>

            @include('smith.partials.trait-effect-help', ['traitEffectHelpMargin' => 'mt-6'])

            <section class="mt-6 grid gap-3 sm:grid-cols-2">
                <article class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                    <h3 class="font-black text-emerald-950">例1：銘を1段階上げる方法</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-700"><strong>剣（力の銘I）</strong>をベース、<strong>剣（力の銘I）</strong>を素材にします。どちらも剣で、銘の名前と段階が同じです。</p>
                    <p class="mt-3 rounded border border-emerald-100 bg-white p-2 text-xs font-bold leading-relaxed text-emerald-950">結果：剣は「力の銘II」になります。素材となった武器は消えます。</p>
                </article>
                <article class="rounded-lg border border-sky-200 bg-sky-50 p-4">
                    <h3 class="font-black text-sky-950">例2：特攻を1段階上げる</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-700"><strong>剣（獣特攻I）</strong>をベース、<strong>剣（獣特攻I）</strong>を素材にします。どちらも剣で、特攻の名前と段階が同じです。</p>
                    <p class="mt-3 rounded border border-sky-100 bg-white p-2 text-xs font-bold leading-relaxed text-sky-950">結果：剣は「獣特攻II」になります。素材となった武器は消えます。</p>
                </article>
            </section>

            <section class="mt-6 rounded-lg border border-sky-200 bg-sky-50 p-4">
                <h3 class="text-lg font-black text-sky-950">例3：素材の銘を剣へ移す</h3>
                <p class="mt-2 text-sm leading-relaxed text-slate-700"><strong>木の剣（力の銘I・獣特攻I）</strong>をベース、<strong>鉄の剣（魔力の銘II）</strong>を素材にします。</p>
                <p class="mt-3 rounded border border-sky-100 bg-white p-2 text-xs font-bold leading-relaxed text-sky-950">結果：木の剣は「魔力の銘II・獣特攻I」になります。木の剣の特攻・品質・+強化値は残り、鉄の剣だけが消えます。</p>
            </section>

            <section class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4">
                <h3 class="text-lg font-black text-amber-950">できない例・両方まとめて鍛える例</h3>
                <p class="mt-2 text-sm leading-relaxed text-slate-700"><strong>木の剣（力の銘II）</strong>に<strong>鉄の剣（力の銘I）</strong>を使っても、段階を下げることはできません。また、同じ銘Iでも剣と斧のように武器種が違う場合は、段階を上げられません。</p>
                <p class="mt-3 text-sm leading-relaxed text-slate-700">段階を上げる費用は完成II / III / IV / Vで20,000G / 80,000G / 250,000G / 750,000Gです。移す費用は段階I〜Vで5,000G / 10,000G / 30,000G / 80,000G / 200,000Gです。</p>
                <p class="mt-3 text-sm leading-relaxed text-slate-700"><strong>木の剣と鉄の剣が両方とも「力の銘I・獣特攻I」</strong>で、どちらも剣なら、木の剣を「力の銘II・獣特攻II」にできます。これは任意操作で、費用は単独2回の合計80%です。素材となった武器は消えます。</p>
            </section>

            <p class="mt-6 text-center text-xs font-bold leading-relaxed text-slate-500">実行前に表示される「変更前」「変更後」「必要Gold」を確認してください。</p>
        </div>
    </div>
</x-layouts.facility>
