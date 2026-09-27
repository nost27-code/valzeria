@if(!empty($description))
    <details class="mt-3 rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-xs text-violet-950">
        <summary class="cursor-pointer py-1 font-bold">装備の兆しの効果</summary>
        <p class="mt-2 leading-relaxed">勝利時の通常装備の入手確率が少し上がります。</p>
        <p class="mt-1 flex flex-wrap gap-x-3 gap-y-1 font-bold leading-relaxed">
            @foreach(explode(' ／ ', $description) as $bonus)
                <span class="whitespace-nowrap">{{ $bonus }}</span>
            @endforeach
        </p>
        <p class="mt-2 leading-relaxed">「＋0.10ポイント」は、入手確率が1%なら1.10%になる上乗せです。毎回の入手や、高ランク装備の入手を保証する効果ではありません。</p>
    </details>
@endif
