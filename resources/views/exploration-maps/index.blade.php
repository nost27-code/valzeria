<x-layouts.facility title="地図院" headerIcon="🗺️" :showGameHeader="true" :exitUrl="route('home')" exitLabel="街へ戻る">
    @php
        $dungeonTypeLabels = config('exploration_maps.dungeon_type_labels');
    @endphp

    <div class="mx-auto max-w-6xl space-y-5">
        <section class="rounded-xl border border-indigo-200 bg-indigo-50 p-4 shadow-sm">
            <h2 class="font-black text-indigo-950">探索の地図</h2>
            <p class="mt-1 text-sm font-bold text-indigo-900">見つけた地図を調査して公開すると、冒険者たちが同じ地図を探索できる。</p>
            <details class="mt-3 rounded-lg border border-indigo-200 bg-white/80 px-3 py-2 text-sm text-indigo-950">
                <summary class="cursor-pointer font-black">Q. なぜ調査を依頼する地図院を選ぶの？</summary>
                <div class="mt-2 space-y-2 border-t border-indigo-100 pt-2 text-xs font-bold leading-relaxed text-slate-700">
                    <p><span class="text-indigo-800">A.</span> 遠征調査費は地図の等級で決まります（通常 500G／希少 1,500G／英雄 5,000G／伝説 10,000G）。依頼先は、公開する地図院と入場料の積み立て先を選ぶために指定します。</p>
                    <p>ほかの冒険者が支払った入場料は、発見者に70%、選んだ街の地図院に20%、システム分として10%に分かれます。たとえばルミナス地図院へ依頼した地図なら、入場料の20%がルミナス地図院の発展値へ積み立てられます。</p>
                    <p>地図院の発展値は、今後その街で利用できる地図院の設備や機能を充実させるために使われる予定です。現在は積み立てのみで、地図内の敵の強さやドロップ率は、どこへ依頼しても変わりません。</p>
                    <p>迷ったときは、好きな街の地図院を選んでかまいません。</p>
                </div>
            </details>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="font-black text-slate-900">手元の探索地図</h2>
            <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
                @forelse($ownedMaps as $map)
                    @php
                        $dungeonTypeLabel = $dungeonTypeLabels[$map->dungeon_type] ?? $map->dungeon_type;
                        $surveyCost = $surveyCosts[$map->map_grade] ?? $surveyCosts['normal'];
                        $registration = $map->registration;
                        $isEnded = $registration?->isPublished() && !$registration->isOpen();
                        $status = $isEnded
                            ? '終了'
                            : (['uninvestigated'=>'未調査','surveying'=>'調査中','surveyed'=>'調査完了','published'=>'公開中'][$map->status] ?? $map->status);
                    @endphp
                    <div class="relative overflow-hidden rounded-lg border p-3 {{ $isEnded ? 'border-slate-300 bg-slate-100 opacity-75 grayscale' : 'border-slate-200 bg-white' }}">
                        @if($isEnded)
                            <div class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center">
                                <span class="-rotate-12 rounded border-4 border-slate-500 px-4 py-1 text-2xl font-black tracking-[0.3em] text-slate-600">終了</span>
                            </div>
                        @endif
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="font-black">{{ $map->status === 'uninvestigated' ? '未調査の探索地図' : $map->name }}</p>
                                <p class="mt-1 text-xs font-bold text-slate-500">等級：{{ ['normal'=>'通常','rare'=>'希少','hero'=>'英雄','legend'=>'伝説'][$map->map_grade] ?? $map->map_grade }}　状態：{{ $status }}</p>
                            </div>
                            @if($map->registration)
                                <a href="{{ route('exploration-maps.show', $map->registration) }}" class="rounded bg-indigo-700 px-3 py-2 text-xs font-black text-white">詳細へ</a>
                            @endif
                        </div>

                        @if($map->status === 'uninvestigated')
                            <form method="POST" action="{{ route('exploration-maps.survey.start', $map) }}" class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3" x-data="{ townId: '' }">
                                @csrf
                                <label for="town-{{ $map->id }}" class="block text-sm font-black text-slate-900">調査を依頼する地図院</label>
                                <p class="mt-1 text-xs font-bold text-slate-600">推定地形：<span class="text-amber-800">{{ $dungeonTypeLabel }}</span>。遠征調査費：{{ number_format($surveyCost) }}G</p>
                                <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                                    <select id="town-{{ $map->id }}" name="town_id" x-model="townId" required class="min-w-0 flex-1 rounded border-slate-300 text-sm font-bold">
                                        <option value="" disabled>地図院を選択してください</option>
                                        @foreach($towns as $town)
                                            <option value="{{ $town->id }}">{{ $town->name }}地図院</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div x-cloak x-show="townId !== ''" class="mt-3">
                                    <button type="submit" class="w-full rounded bg-amber-600 px-4 py-3 text-sm font-black text-white hover:bg-amber-700">遠征調査を始める</button>
                                </div>
                            </form>
                        @endif

                        @if(in_array($map->status, ['uninvestigated', 'surveyed'], true))
                            <form method="POST" action="{{ route('exploration-maps.discard', $map) }}" class="mt-3" onsubmit="return confirm('この地図を破棄しますか？調査済みの場合、遠征調査費は戻りません。');">
                                @csrf
                                <button type="submit" class="w-full rounded border border-rose-200 bg-white px-4 py-2 text-xs font-black text-rose-700 hover:bg-rose-50">この地図を破棄する</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="py-4 text-sm font-bold text-slate-500">まだ未調査の地図はない。通常探索や討伐で見つけよう。</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-xl border border-indigo-200 bg-indigo-50 p-4 shadow-sm">
            <h2 class="font-black text-indigo-950">公開中の地図</h2>
            <p class="mt-1 text-sm font-bold text-indigo-900">ほかの冒険者が公開した地図は、探索画面から選んで入場できます。</p>
            <a href="{{ route('exploration-maps.published') }}" class="mt-3 inline-flex w-full items-center justify-center rounded-lg bg-indigo-700 px-4 py-3 text-sm font-black text-white hover:bg-indigo-800 sm:w-auto">公開地図を見る</a>
        </section>
    </div>
</x-layouts.facility>
