<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">SKILL EFFECT LAB</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">技効果検証</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">必殺技・継承奥義の平均ダメージ、通常攻撃比、追加効果の反映を6ターンで確認します。</p>
        </div>
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-xs font-bold text-amber-800">
            DBへ保存されません
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 2xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
        <section class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-black text-slate-950">検証条件</h2>
                @if($selectedJob)
                    <span class="rounded bg-slate-100 px-2 py-1 text-xs font-black text-slate-600">{{ \App\Support\JobRankCatalog::label($selectedJob->rank) }} / {{ $selectedJob->name }}</span>
                @endif
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <label class="block">
                    <span class="text-xs font-black text-slate-600">職業</span>
                    <select wire:model.live="selectedJobId" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-bold">
                        @foreach($jobs as $job)
                            <option value="{{ $job->id }}">{{ \App\Support\JobRankCatalog::label($job->rank) }} / {{ $job->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-black text-slate-600">街</span>
                    <select wire:model.live="selectedCityId" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-bold">
                        @foreach($cities as $city)
                            <option value="{{ $city->id }}">{{ $city->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-black text-slate-600">敵</span>
                    <select wire:model.live="selectedEnemyId" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-bold">
                        @foreach($enemyOptions as $enemy)
                            <option value="{{ $enemy->id }}">
                                {{ $enemy->area?->name ?? '-' }} / #{{ $enemy->id }} {{ $enemy->name }} Lv{{ $enemy->level }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="mt-5 rounded-md border border-slate-200 bg-slate-50 p-4">
                <div class="mb-3 text-sm font-black text-slate-800">自分のパラメータ</div>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    @foreach([
                        'max_hp' => 'HP',
                        'max_mp' => 'SP',
                        'str' => 'ATK',
                        'def' => 'DEF',
                        'agi' => 'SPD',
                        'mag' => 'MAG',
                        'spr' => 'SPR',
                        'luk' => 'LUK',
                    ] as $key => $label)
                        <label class="block">
                            <span class="text-xs font-black text-slate-500">{{ $label }}</span>
                            <input type="number" min="0" wire:model.live="stats.{{ $key }}" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-bold">
                        </label>
                    @endforeach
                </div>
                <div class="mt-3 text-xs font-bold leading-relaxed text-slate-500">
                    通常攻撃タイプは選択職業の設定を使います。回復・反動・吸収があるためHP/SPも入力対象にしています。
                </div>
            </div>

            <div class="mt-5 rounded-md border border-slate-200 bg-white p-4">
                <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <h3 class="text-sm font-black text-slate-950">検証する技</h3>
                    <p class="text-xs font-bold text-slate-500">最大3つ。2/4/6ターン目に順番に実行します。</p>
                </div>

                <div class="space-y-3">
                    @for($slot = 0; $slot < 3; $slot++)
                        <label class="block">
                            <span class="text-xs font-black text-slate-600">{{ $slot + 1 }}つ目</span>
                            <select wire:model.live="selectedSkillIds.{{ $slot }}" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-bold">
                                <option value="">未選択</option>
                                @foreach($skillOptions as $skill)
                                    @php
                                        $skillKindLabel = $skill->skill_type === 'special'
                                            ? '必殺技'
                                            : ((int) $skill->job_id === (int) $selectedJobId ? '職業奥義' : '継承奥義');
                                        $skillJobLabel = $skill->jobClass
                                            ? \App\Support\JobRankCatalog::label($skill->jobClass->rank) . ' / ' . $skill->jobClass->name
                                            : '-';
                                    @endphp
                                    <option value="{{ $skill->id }}">
                                        {{ $skillKindLabel }} / {{ $skillJobLabel }} / {{ $skill->name }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    @endfor
                </div>

                @if($selectedSkillDetails->isNotEmpty())
                    <div class="mt-5 space-y-3">
                        <div>
                            <h4 class="text-sm font-black text-slate-950">選択中の技解説</h4>
                            <p class="mt-1 text-xs font-bold text-slate-500">説明文と、実際に参照される構造化効果を並べて確認できます。</p>
                        </div>
                        @foreach($selectedSkillDetails as $index => $detail)
                            @php $skill = $detail['skill']; @endphp
                            <div class="rounded-md border border-slate-200 bg-slate-50 p-4">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <div class="text-xs font-black text-amber-700">
                                            {{ $index + 1 }}つ目 / {{ $detail['kindLabel'] }}
                                            @if($skill->jobClass)
                                                / {{ \App\Support\JobRankCatalog::label($skill->jobClass->rank) }} / {{ $skill->jobClass->name }}
                                            @endif
                                        </div>
                                        <div class="mt-1 text-lg font-black text-slate-950">{{ $skill->name }}</div>
                                    </div>
                                    <div class="rounded bg-white px-2 py-1 text-xs font-black text-slate-600 ring-1 ring-slate-200">
                                        {{ $skill->damage_type ?? 'type未設定' }}
                                    </div>
                                </div>

                                <div class="mt-3 rounded-md border border-indigo-100 bg-indigo-50 p-3">
                                    <div class="text-[11px] font-black text-indigo-700">転職画面の紹介</div>
                                    <div class="mt-1 text-sm font-bold leading-relaxed text-indigo-950">
                                        {{ $detail['jobChangeIntro']['body'] }}
                                    </div>
                                    @if($detail['jobChangeIntro']['phrase'] || $detail['jobChangeIntro']['description'])
                                        <div class="mt-2 rounded bg-white/80 px-3 py-2 text-xs font-bold leading-relaxed text-indigo-900 ring-1 ring-indigo-100">
                                            @if($detail['jobChangeIntro']['phrase'])
                                                <div class="font-black">{{ $detail['jobChangeIntro']['phrase'] }}</div>
                                            @endif
                                            @if($detail['jobChangeIntro']['description'])
                                                <div class="text-indigo-700">{{ $detail['jobChangeIntro']['description'] }}</div>
                                            @endif
                                        </div>
                                    @endif
                                </div>

                                <div class="mt-3 grid grid-cols-1 gap-3 xl:grid-cols-2">
                                    <div class="rounded-md bg-white p-3 ring-1 ring-slate-200">
                                        <div class="text-[11px] font-black text-slate-500">説明文</div>
                                        <div class="mt-1 text-sm font-bold leading-relaxed text-slate-800">
                                            {{ $skill->description ?: '説明文なし' }}
                                        </div>
                                        @if($skill->memo)
                                            <div class="mt-2 text-[11px] font-bold leading-relaxed text-slate-500">memo: {{ $skill->memo }}</div>
                                        @endif
                                        @if($skill->activation_description)
                                            <div class="mt-2 rounded bg-amber-50 px-3 py-2 text-xs font-bold leading-relaxed text-amber-900">
                                                {{ $skill->activation_description }}
                                            </div>
                                        @endif
                                    </div>

                                    <div class="rounded-md bg-white p-3 ring-1 ring-slate-200">
                                        <div class="text-[11px] font-black text-slate-500">実効果フィールド</div>
                                        @if(!empty($detail['effectRows']))
                                            <div class="mt-2 flex flex-wrap gap-1.5">
                                                @foreach($detail['effectRows'] as $row)
                                                    <span class="rounded bg-slate-100 px-2 py-1 text-[11px] font-black text-slate-700 ring-1 ring-slate-200">
                                                        {{ $row['label'] }}: {{ $row['value'] }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @else
                                            <div class="mt-2 text-xs font-bold text-slate-400">効果フィールドなし</div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @error('selectedSkillIds') <div class="mt-3 text-xs font-bold text-red-600">{{ $message }}</div> @enderror
                @foreach(['selectedJobId', 'selectedCityId', 'selectedEnemyId'] as $field)
                    @error($field) <div class="mt-2 text-xs font-bold text-red-600">{{ $message }}</div> @enderror
                @endforeach

                <button type="button" wire:click="runPreview" wire:loading.attr="disabled" class="mt-5 w-full rounded-md bg-slate-950 px-4 py-3 text-sm font-black text-white shadow hover:bg-slate-800 disabled:opacity-50">
                    <span wire:loading.remove>検証する</span>
                    <span wire:loading>計算中...</span>
                </button>
            </div>
        </section>

        <section class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-black text-slate-950">対象敵</h2>
                    @if($selectedEnemy)
                        <p class="mt-1 text-xs font-bold text-slate-500">
                            {{ $selectedEnemy->area?->city?->name ?? '-' }} / {{ $selectedEnemy->area?->name ?? '-' }} / #{{ $selectedEnemy->id }} {{ $selectedEnemy->name }}
                        </p>
                    @endif
                </div>
                @if($selectedEnemy)
                    <span class="rounded-md bg-slate-100 px-3 py-1 text-xs font-black text-slate-600">Lv {{ $selectedEnemy->level }}</span>
                @endif
            </div>

            @if($selectedEnemy)
                <div class="mt-4 grid grid-cols-2 gap-2 md:grid-cols-4">
                    @foreach([
                        'max_hp' => 'HP',
                        'str' => 'ATK',
                        'def' => 'DEF',
                        'agi' => 'SPD',
                        'mag' => 'MAG',
                        'spr' => 'SPR',
                        'luk' => 'LUK',
                    ] as $key => $label)
                        <div class="rounded-md bg-slate-50 px-3 py-2">
                            <div class="text-[11px] font-black text-slate-500">{{ $label }}</div>
                            <div class="mt-1 text-lg font-black text-slate-950">{{ number_format((int) ($selectedEnemy->{$key} ?? ($key === 'spr' ? $selectedEnemy->def : 0))) }}</div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="mt-5 rounded-md border border-slate-200 bg-slate-50 p-4">
                <div class="text-sm font-black text-slate-800">表示の前提</div>
                <ul class="mt-2 space-y-1 text-xs font-bold leading-relaxed text-slate-500">
                    <li>・乱数、命中、会心、敵の反撃は含めません。</li>
                    <li>・敵DEF/SPR低下や自己バフは、以後の通常攻撃にも反映します。</li>
                    <li>・継承奥義はマスタ時継承として、各奥義の継承倍率を掛けます。</li>
                </ul>
            </div>
        </section>
    </div>

    @if($result)
        <section class="mt-6 rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-black text-slate-950">6ターンプレビュー</h2>
                    <p class="mt-1 text-xs font-bold text-slate-500">1・3・5ターン目は通常攻撃、2・4・6ターン目は選択技です。</p>
                </div>
                <div class="rounded-md bg-slate-100 px-3 py-2 text-xs font-black text-slate-700">
                    1T通常攻撃 {{ number_format($result['baseline_damage'] ?? 0) }}
                </div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-xs font-black text-slate-700">
                        <tr>
                            <th class="px-4 py-3 text-left">ターン</th>
                            <th class="px-4 py-3 text-left">行動</th>
                            <th class="px-4 py-3 text-left">種別</th>
                            <th class="px-4 py-3 text-right">ダメージ</th>
                            <th class="px-4 py-3 text-right">直前通常比</th>
                            <th class="px-4 py-3 text-right">1T通常比</th>
                            <th class="px-4 py-3 text-left">追加効果</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($result['turns'] as $turn)
                            <tr class="align-top hover:bg-slate-50">
                                <td class="px-4 py-3 font-black text-slate-700">{{ $turn['turn'] }}T</td>
                                <td class="px-4 py-3">
                                    <div class="font-black text-slate-950">{{ $turn['label'] }}</div>
                                    @if(!empty($turn['description']))
                                        <div class="mt-1 max-w-md text-xs font-bold leading-relaxed text-slate-500">{{ $turn['description'] }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 font-bold text-slate-600">{{ $turn['damage_type_label'] }}</td>
                                <td class="px-4 py-3 text-right font-black text-slate-950">{{ number_format($turn['damage']) }}</td>
                                <td class="px-4 py-3 text-right font-black {{ ($turn['ratio_to_previous_normal'] ?? 0) >= 1 ? 'text-emerald-700' : 'text-slate-600' }}">
                                    {{ number_format($turn['ratio_to_previous_normal'] ?? 0, 2) }}倍
                                </td>
                                <td class="px-4 py-3 text-right font-bold text-slate-600">{{ number_format($turn['ratio_to_first_normal'] ?? 0, 2) }}倍</td>
                                <td class="px-4 py-3">
                                    @if(!empty($turn['effects']))
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach($turn['effects'] as $effect)
                                                <span class="rounded bg-amber-50 px-2 py-1 text-[11px] font-black text-amber-800 ring-1 ring-amber-200">{{ $effect }}</span>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-xs font-bold text-slate-400">なし</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @if(!empty($result['skill_summaries']))
            <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                @foreach($result['skill_summaries'] as $summary)
                    <div class="rounded-md bg-white p-4 shadow-sm ring-1 ring-slate-200">
                        <div class="text-xs font-black text-slate-500">{{ $summary['kind_label'] ?? ($summary['kind'] === 'job_art' ? '奥義' : '必殺技') }}</div>
                        <div class="mt-1 text-lg font-black text-slate-950">{{ $summary['label'] }}</div>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <div class="rounded bg-slate-50 px-3 py-2">
                                <div class="text-[11px] font-black text-slate-500">ダメージ</div>
                                <div class="mt-1 text-xl font-black text-slate-950">{{ number_format($summary['damage']) }}</div>
                            </div>
                            <div class="rounded bg-emerald-50 px-3 py-2">
                                <div class="text-[11px] font-black text-emerald-700">直前通常比</div>
                                <div class="mt-1 text-xl font-black text-emerald-800">{{ number_format($summary['ratio_to_previous_normal'], 2) }}倍</div>
                            </div>
                            <div class="rounded bg-slate-50 px-3 py-2">
                                <div class="text-[11px] font-black text-slate-500">発動率</div>
                                <div class="mt-1 text-lg font-black text-slate-950">{{ number_format($summary['activation_rate'] ?? 0) }}%</div>
                            </div>
                            <div class="rounded bg-slate-50 px-3 py-2">
                                <div class="text-[11px] font-black text-slate-500">SP消費</div>
                                <div class="mt-1 text-lg font-black text-slate-950">{{ number_format($summary['sp_cost'] ?? 0) }}</div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
