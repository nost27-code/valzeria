@php
    $helpModal = match ($helpType) {
        'enhance' => ['icon' => '🔨', 'title' => '装備強化の解説', 'accent' => 'amber'],
        'traits' => ['icon' => '⚒️', 'title' => '銘・特攻を鍛える解説', 'accent' => 'indigo'],
        'evolution' => ['icon' => '⬆', 'title' => '進化合成の解説', 'accent' => 'violet'],
    };
@endphp

<div x-show="helpOpen" style="display: none;" @keydown.escape.window="helpOpen = false" class="fixed inset-0 z-[70] overflow-y-auto" aria-labelledby="operation-help-title" role="dialog" aria-modal="true">
    <div class="flex min-h-screen items-center justify-center p-4 text-center">
        <div x-show="helpOpen" x-transition.opacity class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" @click="helpOpen = false" aria-hidden="true"></div>

        <section x-show="helpOpen" x-transition @click.stop class="relative z-10 w-full max-w-2xl overflow-hidden rounded-xl bg-white text-left shadow-2xl">
            <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3 sm:px-5">
                <h3 id="operation-help-title" class="flex items-center gap-2 text-base font-black text-slate-900 sm:text-lg">
                    <span class="text-xl">{{ $helpModal['icon'] }}</span>{{ $helpModal['title'] }}
                </h3>
                <button type="button" @click="helpOpen = false" class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-lg font-black text-slate-500 transition hover:bg-slate-200" aria-label="解説を閉じる">×</button>
            </header>

            <div class="max-h-[calc(100vh-10rem)] overflow-y-auto p-4 sm:p-5">
                @if($helpType === 'enhance')
                    <p class="text-sm leading-relaxed text-slate-600">武器・防具・装飾品を、装備ランクごとの上限まで強化して性能を伸ばす操作です。G〜Eは+10、D〜Bは+15、Aは+20、Sは+25、SS〜EPICは+30まで強化できます。</p>
                    <section class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3">
                        <h4 class="font-black text-amber-950">必要なもの</h4>
                        <p class="mt-1.5 text-sm leading-relaxed text-slate-700">武器は次に到達する+値で素材とGoldが決まり、武器ランク・現在地・出身街では変わりません。+nへの必要Goldは n×n×300Gです。都市素材は強化値帯ごとに固定され、防具・装飾品は従来のレシピです。</p>
                    </section>
                    <section class="mt-3 rounded-lg border border-amber-200 bg-white p-3">
                        <h4 class="font-black text-amber-950">具体例</h4>
                        <p class="mt-1.5 text-sm leading-relaxed text-slate-700">基礎攻撃が100の武器を<strong>+2</strong>にすると、基礎攻撃は106になります。強化したい装備自体は消えず、素材とGoldだけを消費します。</p>
                    </section>
                    @include('smith.partials.enhancement-performance-table')
                    <p class="mt-3 text-xs font-bold text-slate-500">市場出品中の装備は、出品を取り消すまで強化できません。</p>
                @elseif($helpType === 'traits')
                    <p class="text-sm leading-relaxed text-slate-600">「銘を鍛える」か「特攻を鍛える」を選び、<strong>残したい武器（ベース）</strong>と<strong>消えてよい武器（素材）</strong>を選びます。</p>
                    <p class="mt-2 text-sm font-bold text-slate-700">例：剣の場合</p>
                    <section class="mt-4 rounded-lg border border-indigo-200 bg-indigo-50 p-3">
                        <h4 class="font-black text-indigo-950">まずは「ベース」と「素材」を決めます</h4>
                        <ul class="mt-1.5 space-y-1.5 text-sm leading-relaxed text-slate-700">
                            <li>・<strong>ベース</strong>：残したい<strong>剣</strong>を選びます。完成後も剣が手元に残ります。</li>
                            <li>・<strong>素材</strong>：消えてよい<strong>剣</strong>を選びます。素材にした剣は消滅します。</li>
                            <li>・<strong>銘を鍛える</strong>では銘だけ、<strong>特攻を鍛える</strong>では種族特攻だけを確認します。</li>
                        </ul>
                    </section>
                    @include('smith.partials.trait-effect-help')
                    <section class="mt-3 grid gap-2 sm:grid-cols-2">
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm leading-relaxed text-slate-700"><strong class="text-emerald-950">例1：銘を1段階上げる方法</strong><br><span class="font-bold">剣（力の銘I）</span>をベース、<span class="font-bold">剣（力の銘I）</span>を素材にします。どちらも剣で、銘の名前と段階が同じなので、剣は<strong>力の銘II</strong>になります。素材となった武器は消えます。</div>
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm leading-relaxed text-slate-700"><strong class="text-emerald-950">例2：特攻を1段階上げる</strong><br><span class="font-bold">剣（獣特攻I）</span>をベース、<span class="font-bold">剣（獣特攻I）</span>を素材にします。どちらも剣で、特攻の名前と段階が同じなので、剣は<strong>獣特攻II</strong>になります。素材となった武器は消えます。</div>
                    </section>
                    <section class="mt-3 rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm leading-relaxed text-slate-700">
                        <h4 class="font-black text-sky-950">例3：素材の銘を剣へ移す</h4>
                        <p class="mt-1.5"><span class="font-bold">木の剣（力の銘I・獣特攻I）</span>をベース、<span class="font-bold">鉄の剣（魔力の銘II）</span>を素材にすると、木の剣は<strong>魔力の銘II・獣特攻I</strong>になります。木の剣の特攻・品質・+強化値はそのままで、鉄の剣だけが消えます。</p>
                    </section>
                    <section class="mt-3 rounded-lg border border-slate-200 bg-white p-3 text-sm leading-relaxed text-slate-700">
                        <h4 class="font-black text-slate-900">できない例</h4>
                        <p class="mt-1.5"><span class="font-bold">木の剣（力の銘II）</span>に、<span class="font-bold">鉄の剣（力の銘I）</span>を使っても、段階を下げることはできません。また、同じ銘Iでも「剣」と「斧」のように武器種が違う場合は、段階を上げられません。</p>
                    </section>
                    <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm leading-relaxed text-slate-700"><strong>例4：両方まとめて鍛える</strong><br>木の剣と鉄の剣の両方が「力の銘I・獣特攻I」で、どちらも剣なら、木の剣を<strong>力の銘II・獣特攻II</strong>にできます。これは任意操作で、単独2回より20%お得です。素材となった武器は消えます。</p>
                @else
                    <p class="text-sm leading-relaxed text-slate-600">所持している装備と指定素材を使い、より上位の装備へ進化させる操作です。</p>
                    <section class="mt-4 rounded-lg border border-violet-200 bg-violet-50 p-3">
                        <h4 class="font-black text-violet-950">必要なもの</h4>
                        <p class="mt-1.5 text-sm leading-relaxed text-slate-700">進化元になる装備、レシピに表示された素材、Goldを使います。同じ名前の装備を複数持っている時は、候補カードから使う個体を選びます。</p>
                    </section>
                    <section class="mt-3 rounded-lg border border-violet-200 bg-white p-3">
                        <h4 class="font-black text-violet-950">進化するとどうなる？</h4>
                        <ul class="mt-1.5 space-y-1.5 text-sm leading-relaxed text-slate-700">
                            <li>・進化元は進化後の上位装備へ置き換わります。</li>
                            <li>・<strong>+強化値は進化先の上限まで引き継がれます。</strong></li>
                            <li>・銘、銘の段階、種族特攻、種族特攻の段階、品質は引き継がれます。</li>
                        </ul>
                    </section>
                    <p class="mt-3 rounded-lg border border-violet-200 bg-violet-50 p-3 text-sm leading-relaxed text-slate-700">例：銘II・獣特攻Iの<strong>+3 Aランク剣</strong>を上位武器へ進化すると、進化後も<strong>+3</strong>のままです。銘IIと獣特攻Iもそのまま引き継がれます。</p>
                @endif
            </div>

            <footer class="border-t border-slate-200 bg-slate-50 px-4 py-3 text-right sm:px-5">
                <button type="button" @click="helpOpen = false" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100">閉じる</button>
            </footer>
        </section>
    </div>
</div>
