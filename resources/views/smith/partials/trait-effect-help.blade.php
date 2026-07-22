@php
    $traitEffectHelpMargin = $traitEffectHelpMargin ?? 'mt-3';
    $traitKillerTargets = [
        ['name' => '獣牙', 'species' => '獣'],
        ['name' => '屍祓', 'species' => '不死'],
        ['name' => '竜断', 'species' => '竜'],
        ['name' => '魔祓', 'species' => '悪魔'],
        ['name' => '水断', 'species' => '水棲'],
        ['name' => '翼落', 'species' => '飛行'],
        ['name' => '蟲砕', 'species' => '虫'],
        ['name' => '機砕', 'species' => '機械'],
        ['name' => '粘断', 'species' => 'スライム'],
        ['name' => '兵崩', 'species' => '人型'],
        ['name' => '術封', 'species' => '魔法型'],
        ['name' => '霊祓', 'species' => '精霊'],
    ];
    $traitResistTargets = [
        ['name' => '獣避', 'species' => '獣'],
        ['name' => '屍除', 'species' => '不死'],
        ['name' => '竜鱗', 'species' => '竜'],
        ['name' => '魔除', 'species' => '悪魔'],
        ['name' => '水護', 'species' => '水棲'],
        ['name' => '翼避', 'species' => '飛行'],
        ['name' => '蟲除', 'species' => '虫'],
        ['name' => '機護', 'species' => '機械'],
        ['name' => '粘避', 'species' => 'スライム'],
        ['name' => '兵護', 'species' => '人型'],
        ['name' => '術避', 'species' => '魔法型'],
        ['name' => '霊護', 'species' => '精霊'],
    ];
@endphp

<section x-data="{ traitHelpTab: 'effects' }" class="{{ $traitEffectHelpMargin }} rounded-lg border border-violet-200 bg-violet-50 p-3">
    <h4 class="font-black text-violet-950">銘・特攻・耐性の効果</h4>
    <div class="mt-3 grid grid-cols-2 gap-2 rounded-lg border border-violet-100 bg-white p-1" role="tablist" aria-label="銘・特攻・耐性の解説">
        <button type="button" role="tab" @click="traitHelpTab = 'effects'" :aria-selected="traitHelpTab === 'effects'" :class="traitHelpTab === 'effects' ? 'bg-violet-700 text-white shadow-sm' : 'text-slate-600 hover:bg-violet-50'" class="rounded-md px-3 py-2 text-sm font-black transition">効果</button>
        <button type="button" role="tab" @click="traitHelpTab = 'targets'" :aria-selected="traitHelpTab === 'targets'" :class="traitHelpTab === 'targets' ? 'bg-sky-700 text-white shadow-sm' : 'text-slate-600 hover:bg-sky-50'" class="rounded-md px-3 py-2 text-sm font-black transition">特攻・耐性一覧</button>
    </div>

    <div x-show="traitHelpTab === 'effects'" class="mt-3">
        <div class="grid gap-2 sm:grid-cols-2">
            <article class="rounded-lg border border-violet-100 bg-white p-3 text-sm leading-relaxed text-slate-700">
                <h5 class="font-black text-violet-950">銘：能力値を上げます</h5>
                <p class="mt-1.5">強化後の武器で<strong>最も高い基礎能力値</strong>を基準に、対象の能力値へ補正を加えます。武器を強化すると銘の補正も伸びます。通常品の補正率は次のとおりです。</p>
                <p class="mt-2 rounded border border-violet-100 bg-violet-50 px-2 py-1.5 text-xs font-black text-violet-900">I：8%　II：16%　III：24%　IV：32%　V：40%</p>
                <p class="mt-2 text-xs font-bold text-slate-600">良品は<strong>1.15倍</strong>、逸品は<strong>1.35倍</strong>。端数は切り上げます。生命の銘などHPを上げる銘は、計算した値の<strong>3倍</strong>がHPに加わります。全能力を上げる調律の銘は、各能力が単能力銘の<strong>55%</strong>で、防具ではHPが<strong>6倍</strong>になります。</p>
                <p class="mt-2 text-xs font-bold text-violet-800">例：基礎攻撃100の通常品に力の銘IIIなら、攻撃+18です。</p>
            </article>
            <article class="rounded-lg border border-sky-100 bg-white p-3 text-sm leading-relaxed text-slate-700">
                <h5 class="font-black text-sky-950">特攻：特定種族への与ダメージを上げます</h5>
                <p class="mt-1.5">武器に書かれた特攻と、敵の種族が一致した時だけ与ダメージが増えます。通常品の補正率は次のとおりです。</p>
                <p class="mt-2 rounded border border-sky-100 bg-sky-50 px-2 py-1.5 text-xs font-black text-sky-900">I：+6%　II：+12%　III：+18%　IV：+24%　V：+30%</p>
                <p class="mt-2 text-xs font-bold text-slate-600">良品は<strong>1.15倍</strong>、逸品は<strong>1.35倍</strong>。対象の種族は「特攻一覧」で確認できます。</p>
                <p class="mt-2 text-xs font-bold text-sky-800">特攻は<strong>通常探索・ボス戦</strong>で有効です。闘技場（PvP）・チャンプ戦には効きません。</p>
            </article>
            <article class="rounded-lg border border-emerald-100 bg-white p-3 text-sm leading-relaxed text-slate-700">
                <h5 class="font-black text-emerald-950">耐性：特定種族からの被ダメージを減らします（防具）</h5>
                <p class="mt-1.5">防具に書かれた耐性と、敵の種族が一致した時だけ被ダメージが減ります。通常品の軽減率は次のとおりです。</p>
                <p class="mt-2 rounded border border-emerald-100 bg-emerald-50 px-2 py-1.5 text-xs font-black text-emerald-900">I：-5%　II：-10%　III：-15%　IV：-20%　V：-25%</p>
                <p class="mt-2 text-xs font-bold text-slate-600">良品は<strong>1.15倍</strong>、逸品は<strong>1.35倍</strong>。対象の種族は「特攻・耐性一覧」で確認できます。</p>
                <p class="mt-2 text-xs font-bold text-emerald-800">耐性は<strong>通常探索・ボス戦</strong>で有効です。闘技場（PvP）・チャンプ戦には効きません。</p>
            </article>
        </div>
        <p class="mt-2 text-xs font-bold leading-relaxed text-slate-600">段階上限：G〜BはIIまで、AはIIIまで、SはIVまで、SS以上はVまでです。</p>
    </div>

    <div x-show="traitHelpTab === 'targets'" class="mt-3">
        <p class="text-sm font-bold leading-relaxed text-slate-700">特攻名と敵の種族が一致した時だけ、与ダメージが増加します。</p>
        <div class="mt-2 grid gap-1.5 sm:grid-cols-2">
            @foreach ($traitKillerTargets as $traitKillerTarget)
                <p class="rounded-md border border-sky-100 bg-white px-2.5 py-2 text-sm leading-relaxed text-slate-700"><strong class="text-sky-900">{{ $traitKillerTarget['name'] }}</strong>は種族が<strong>{{ $traitKillerTarget['species'] }}</strong>の敵に与ダメージが増加</p>
            @endforeach
        </div>
        <p class="mt-4 text-sm font-bold leading-relaxed text-slate-700">耐性名と敵の種族が一致した時だけ、被ダメージが減少します（防具）。</p>
        <div class="mt-2 grid gap-1.5 sm:grid-cols-2">
            @foreach ($traitResistTargets as $traitResistTarget)
                <p class="rounded-md border border-emerald-100 bg-white px-2.5 py-2 text-sm leading-relaxed text-slate-700"><strong class="text-emerald-900">{{ $traitResistTarget['name'] }}</strong>は種族が<strong>{{ $traitResistTarget['species'] }}</strong>の敵からの被ダメージが減少</p>
            @endforeach
        </div>
        <p class="mt-2 text-xs font-bold text-sky-800">特攻・耐性は通常探索・ボス戦で有効です。闘技場（PvP）・チャンプ戦には効きません。</p>
    </div>
</section>
