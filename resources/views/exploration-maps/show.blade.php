<x-layouts.facility :title="$registration->map->status === 'uninvestigated' ? '未調査の探索地図' : $registration->map->name" headerIcon="🗺️" :showGameHeader="true" :exitUrl="route('exploration-maps.index')" exitLabel="地図院一覧へ戻る">
    @php
        $map = $registration->map;
        $owner = $map->owner_character_id === $character->id;
    @endphp

    <div class="mx-auto max-w-2xl space-y-4">
        <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="grid grid-cols-2 gap-3 text-sm font-bold text-slate-700">
                <div>発見者：{{ $map->owner->name }}</div>
                <div>公開地図院：{{ $registration->town->name }}</div>
                <div>地図等級：{{ ['normal' => '通常', 'rare' => '希少', 'hero' => '英雄', 'legend' => '伝説'][$map->map_grade] ?? $map->map_grade }}</div>
                <div>状態：{{ match ($registration->status) {
                    'surveying' => '遠征調査中',
                    'surveyed' => '調査完了（公開待ち）',
                    default => $registration->isOpen() ? '公開中' : '終了',
                } }}</div>
            </div>
        </section>

        @if($registration->status === 'surveying')
            <section class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="font-black text-amber-900">調査隊は地図の奥を確かめている。</p>
                <p class="mt-1 text-sm font-bold text-amber-800">完了予定：{{ $registration->survey_completed_at->format('n月j日 H:i') }}</p>
                @if($owner && $registration->survey_completed_at->isPast())
                    <form method="POST" action="{{ route('exploration-maps.survey.complete', $registration) }}" class="mt-3">
                        @csrf
                        <button class="rounded bg-amber-700 px-4 py-2 text-sm font-black text-white">調査結果を受け取る</button>
                    </form>
                @endif
            </section>
        @else
            <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <dl class="grid grid-cols-2 gap-3 text-sm font-bold text-slate-700">
                    <div><dt class="text-xs text-slate-500">地図Lv</dt><dd class="mt-1 text-slate-900">{{ $map->map_level }}</dd></div>
                    <div><dt class="text-xs text-slate-500">探索地</dt><dd class="mt-1 text-slate-900">{{ $mapDetails['dungeon_type'] }}</dd></div>
                    <div><dt class="text-xs text-slate-500">出現敵Lv</dt><dd class="mt-1 text-slate-900">{{ $mapDetails['enemy_level_range'] }}</dd></div>
                    <div><dt class="text-xs text-slate-500">危険度</dt><dd class="mt-1 text-slate-900">{{ $mapDetails['threat_tier'] }}</dd></div>
                    <div><dt class="text-xs text-slate-500">探索可能回数</dt><dd class="mt-1 text-slate-900">{{ number_format($registration->remaining_explorations) }} / {{ number_format($registration->exploration_limit) }}</dd></div>
                    @if($registration->expires_at)
                        <div><dt class="text-xs text-slate-500">公開終了</dt><dd class="mt-1 text-slate-900">{{ $registration->expires_at->format('n月j日 H:i') }}</dd></div>
                    @endif
                </dl>

                <h2 class="mt-4 font-black">主な出現モンスター</h2>
                <ul class="mt-2 space-y-1 text-sm font-bold text-slate-700">
                    @foreach($map->normal_monster_variants_json as $variant)
                        <li>・{{ $variant['display_name'] }}</li>
                    @endforeach
                </ul>

                <h2 class="mt-4 font-black">地図に記された特徴</h2>
                <dl class="mt-2 space-y-2 text-sm font-bold text-slate-700">
                    @if($mapDetails['reward'])
                        <div><dt class="text-xs text-slate-500">報酬</dt><dd class="mt-1 text-slate-900">{{ $mapDetails['reward'] }}</dd></div>
                    @endif
                    @if($mapDetails['environment'])
                        <div><dt class="text-xs text-slate-500">周辺の様子</dt><dd class="mt-1 text-slate-900">{{ implode('、', $mapDetails['environment']) }}</dd></div>
                    @endif
                </dl>
            </section>

            @if($owner && $registration->status === 'surveyed')
                <section class="rounded-xl border border-indigo-200 bg-indigo-50 p-4">
                    <form method="POST" action="{{ route('exploration-maps.publish', $registration) }}" x-data="{ fee: {{ $recommendedFee }} }">
                        @csrf
                        <input type="hidden" name="entry_fee" :value="fee">
                        <fieldset>
                            <legend class="font-black text-indigo-950">入場料を設定して公開する</legend>
                            <p class="mt-1 text-xs font-bold text-indigo-800">街から地図へ入るときの入場料を選んでください。入場中は×10探索を何度繰り返しても追加ではかかりません。街へ戻って入り直すと、もう一度入場料がかかります。公開後は変更できません。</p>
                            <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                @foreach($feeOptions as $option)
                                    <button type="button" @click="fee = {{ $option['fee'] }}" :class="fee === {{ $option['fee'] }} ? 'border-indigo-700 bg-indigo-700 text-white' : 'border-indigo-200 bg-white text-indigo-950'" class="rounded-lg border px-3 py-3 text-center text-sm font-black">
                                        {{ $option['label'] }}
                                        <span class="mt-1 block text-xs font-bold">{{ number_format($option['fee']) }}G</span>
                                    </button>
                                @endforeach
                            </div>
                        <p class="mt-3 text-sm font-black text-indigo-950">設定中：<span x-text="Number(fee).toLocaleString()"></span>G / 1入場</p>
                        </fieldset>
                        <button class="mt-4 w-full rounded-lg bg-indigo-700 px-4 py-3 text-sm font-black text-white hover:bg-indigo-800">この入場料で公開する</button>
                    </form>
                </section>
            @endif

            @if($registration->isOpen())
                <section class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                    <p class="font-black text-emerald-950">{{ $owner ? '発見者は無料（他の冒険者：' . number_format($registration->entry_fee_per_exploration) . 'G）' : '入場料：1入場 ' . number_format($registration->entry_fee_per_exploration) . 'G' }}</p>
                    <p class="mt-1 text-xs font-bold text-emerald-800">薬草・回復薬・魔力水は、所持分から各10個まで持ち込めます。</p>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        @foreach(array_unique([1, min(10, $registration->remaining_explorations)]) as $count)
                            <form method="POST" action="{{ route('exploration-maps.explore', $registration) }}">
                                @csrf
                                <input type="hidden" name="count" value="{{ $count }}">
                                <input type="hidden" name="request_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                                <button class="w-full rounded bg-emerald-700 px-3 py-3 text-sm font-black text-white">{{ $count === 1 ? '探索する ×1' : '残り' . $count . '回をまとめて探索' }}　{{ $owner ? '発見者は無料' : number_format($registration->entry_fee_per_exploration) . 'G' }}</button>
                            </form>
                        @endforeach
                    </div>
                </section>
            @endif
        @endif
    </div>
</x-layouts.facility>
