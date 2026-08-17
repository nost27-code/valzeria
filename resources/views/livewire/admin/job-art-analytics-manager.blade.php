<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-violet-600">JOB ART META</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">戦技メタ分析</h1>
            <p class="mt-2 max-w-3xl text-sm font-bold leading-6 text-slate-500">
                現在の戦技セットを、採用率・セット順・組み合わせ・プレイヤー単位で確認します。
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-md border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-500 shadow-sm">
                集計 {{ $generatedAt->format('Y/m/d H:i') }}
            </span>
            <button
                type="button"
                wire:click="downloadCsv"
                class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-black text-white shadow-sm transition hover:bg-emerald-800"
            >
                プレイヤー別CSV
            </button>
        </div>
    </div>

    <section class="rounded-md border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <label class="text-xs font-black text-slate-600">
                対象セット
                <select wire:model.live="battleContext" class="mt-1 w-full rounded-md border-slate-300 bg-white text-sm font-bold shadow-sm focus:border-violet-500 focus:ring-violet-500">
                    <option value="normal">通常戦</option>
                    <option value="boss">ボス戦</option>
                    <option value="pvp">PvP</option>
                </select>
            </label>
            <label class="text-xs font-black text-slate-600">
                最終活動
                <select wire:model.live="activityWindow" class="mt-1 w-full rounded-md border-slate-300 bg-white text-sm font-bold shadow-sm focus:border-violet-500 focus:ring-violet-500">
                    <option value="7">7日以内</option>
                    <option value="30">30日以内</option>
                    <option value="90">90日以内</option>
                    <option value="all">全期間</option>
                </select>
            </label>
            <label class="text-xs font-black text-slate-600">
                現在職
                <select wire:model.live="currentJobId" class="mt-1 w-full rounded-md border-slate-300 bg-white text-sm font-bold shadow-sm focus:border-violet-500 focus:ring-violet-500">
                    <option value="0">すべて</option>
                    @foreach($jobOptions as $job)
                        <option value="{{ $job->id }}">{{ $job->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-xs font-black text-slate-600">
                プレイヤーLv
                <select wire:model.live="levelBand" class="mt-1 w-full rounded-md border-slate-300 bg-white text-sm font-bold shadow-sm focus:border-violet-500 focus:ring-violet-500">
                    <option value="all">すべて</option>
                    <option value="1-49">Lv1〜49</option>
                    <option value="50-99">Lv50〜99</option>
                    <option value="100-149">Lv100〜149</option>
                    <option value="150-199">Lv150〜199</option>
                    <option value="200-255">Lv200〜255</option>
                </select>
            </label>
            <div class="flex items-end">
                <button type="button" wire:click="resetFilters" class="w-full rounded-md border border-slate-300 bg-slate-50 px-4 py-2 text-sm font-black text-slate-700 transition hover:bg-slate-100">
                    絞り込みを戻す
                </button>
            </div>
        </div>
    </section>

    @unless($ready)
        <div class="mt-6 rounded-md border border-amber-300 bg-amber-50 px-5 py-4 text-sm font-bold leading-6 text-amber-900">
            戦技分析に必要なテーブルが揃っていません。未準備: {{ collect($tablesReady)->filter(fn ($ready) => !$ready)->keys()->implode(' / ') }}
        </div>
    @else
        <div class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-5">
            <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-black text-slate-500">対象プレイヤー</div>
                <div class="mt-2 text-2xl font-black text-slate-950">{{ number_format($cards['cohort_players']) }}</div>
                <div class="mt-1 text-xs font-bold text-slate-400">管理者・テスター除外</div>
            </div>
            <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-black text-slate-500">{{ $contextLabel }}セット済み</div>
                <div class="mt-2 text-2xl font-black text-violet-700">{{ number_format($cards['configured_players']) }}</div>
                <div class="mt-1 text-xs font-bold text-slate-400">対象の {{ $cards['configured_rate'] }}%</div>
            </div>
            <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-black text-slate-500">5枠完成</div>
                <div class="mt-2 text-2xl font-black text-emerald-700">{{ number_format($cards['complete_sets']) }}</div>
                <div class="mt-1 text-xs font-bold text-slate-400">順番を含む現在構成</div>
            </div>
            <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-black text-slate-500">構成パターン</div>
                <div class="mt-2 text-2xl font-black text-sky-700">{{ number_format($cards['unique_loadouts']) }}</div>
                <div class="mt-1 text-xs font-bold text-slate-400">同じ技でも順番違いは別</div>
            </div>
            <div class="rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-black text-slate-500">参照文脈</div>
                <div class="mt-2 text-2xl font-black text-slate-950">{{ $contextLabel }}</div>
                <div class="mt-1 text-xs font-bold text-slate-400">現在保存中のスナップショット</div>
            </div>
        </div>

        <div class="mt-6 rounded-md border border-violet-200 bg-violet-50 px-5 py-4 text-sm font-bold leading-6 text-violet-950">
            <p class="font-black">判断時の注意</p>
            <p class="mt-1">
                「利用可能者採用率」は、その戦技を現在職で習得済み、またはマスター職から継承可能な対象者を分母にしています。
                ただし、保存されるのは現在のセットだけで、過去の変更履歴や実戦での発動回数は未計測です。採用率だけで強化・弱体を確定せず、現在職・Lv帯・セット文脈を切り替えて偏りを確認してください。
            </p>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
            <div class="rounded-md border border-rose-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-black text-rose-700">弱体化を検討する入口</div>
                <p class="mt-2 text-xs font-bold leading-5 text-slate-600">高採用が複数の現在職・Lv帯・戦闘文脈でも続くかを確認し、実戦結果を別途照合します。</p>
            </div>
            <div class="rounded-md border border-sky-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-black text-sky-700">強化を検討する入口</div>
                <p class="mt-2 text-xs font-bold leading-5 text-slate-600">「低い順」に切り替え、利用可能者が十分いるのに選ばれない戦技と、そのセット位置を確認します。</p>
            </div>
            <div class="rounded-md border border-amber-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-black text-amber-700">対策戦技を考える入口</div>
                <p class="mt-2 text-xs font-bold leading-5 text-slate-600">併用上位と流行セットの順番を見て、繰り返し現れる組み合わせへ対抗手段があるか確認します。</p>
            </div>
        </div>

        @if($invalidSlotCount > 0)
            <div class="mt-4 rounded-md border border-rose-300 bg-rose-50 px-5 py-4 text-sm font-bold text-rose-900">
                現行の戦技マスタ・対象文脈・習得状態と一致せず、実戦で有効にならない保存枠が {{ number_format($invalidSlotCount) }} 件あります。集計順位には含めず、プレイヤー別一覧で「無効保存」と表示します。
            </div>
        @endif

        <section class="mt-6 overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="text-lg font-black text-slate-950">戦技別の採用状況</h2>
                        <p class="mt-1 text-xs font-bold leading-5 text-slate-400">配置数は、各戦技が何枠目に置かれているかを示します。</p>
                    </div>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <label class="text-xs font-black text-slate-600">
                            並び順
                            <select wire:model.live="artSort" class="mt-1 w-full rounded-md border-slate-300 bg-white text-sm font-bold">
                                <option value="popular">利用可能者採用率が高い順</option>
                                <option value="low">利用可能者採用率が低い順</option>
                                <option value="name">職業・段階順</option>
                            </select>
                        </label>
                        <label class="text-xs font-black text-slate-600">
                            戦技検索
                            <input wire:model.live.debounce.300ms="artSearch" type="search" placeholder="戦技名・元職業" class="mt-1 w-full rounded-md border-slate-300 bg-white text-sm font-bold">
                        </label>
                    </div>
                </div>
            </div>

            <div class="max-h-[720px] overflow-auto">
                <table class="min-w-[980px] divide-y divide-slate-200 text-sm">
                    <thead class="sticky top-0 z-10 bg-slate-50 text-slate-700 shadow-sm">
                        <tr>
                            <th class="px-4 py-3 text-left font-black">順位</th>
                            <th class="px-4 py-3 text-left font-black">戦技</th>
                            <th class="px-4 py-3 text-right font-black">利用可能者採用率</th>
                            <th class="px-4 py-3 text-right font-black">セット者内シェア</th>
                            <th class="px-4 py-3 text-left font-black">セット位置</th>
                            <th class="px-4 py-3 text-left font-black">最終セット更新</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse($artRows as $index => $row)
                            <tr class="align-top hover:bg-slate-50">
                                <td class="px-4 py-3 font-black text-slate-400">{{ $index + 1 }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-black text-slate-950">
                                        <span
                                            data-job-art-effect-tooltip="{{ $row['skill_id'] }}"
                                            class="cursor-help border-b border-dotted border-slate-400"
                                            title="効果：{{ $row['effect_description'] }}"
                                        >{{ $row['name'] }}</span>
                                    </div>
                                    <div class="mt-1 flex flex-wrap gap-1 text-[11px] font-bold text-slate-500">
                                        <span class="rounded bg-slate-100 px-2 py-0.5">{{ $row['source_job_name'] }}</span>
                                        <span class="rounded bg-violet-100 px-2 py-0.5 text-violet-800">{{ $row['stage_label'] }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="text-lg font-black text-violet-700">{{ $row['eligible_adoption_rate'] }}%</div>
                                    <div class="text-[11px] font-bold text-slate-400">{{ number_format($row['selected_count']) }} / {{ number_format($row['eligible_count']) }}人</div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="font-black text-slate-800">{{ $row['configured_share'] }}%</div>
                                    <div class="text-[11px] font-bold text-slate-400">全セット者が分母</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($row['slot_counts'] as $slotNo => $count)
                                            <span class="rounded border px-2 py-1 text-[11px] font-black {{ $count > 0 ? 'border-sky-200 bg-sky-50 text-sky-800' : 'border-slate-100 bg-slate-50 text-slate-300' }}">
                                                {{ $slotNo }}枠 {{ number_format($count) }}
                                            </span>
                                        @endforeach
                                    </div>
                                    <div class="mt-1 text-[11px] font-bold text-slate-400">平均 {{ $row['average_slot'] ?? '-' }}枠目</div>
                                </td>
                                <td class="px-4 py-3 text-xs font-bold text-slate-500">
                                    {{ $row['latest_set_at'] ? \Illuminate\Support\Carbon::parse($row['latest_set_at'])->format('Y/m/d H:i') : '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-10 text-center font-bold text-slate-500">条件に一致する戦技がありません。</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="mt-6 grid grid-cols-1 gap-6 2xl:grid-cols-3">
            <section class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm 2xl:col-span-2">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="text-lg font-black text-slate-950">流行セット（順番込み）</h2>
                    <p class="mt-1 text-xs font-bold text-slate-400">1枠目からの並びが完全一致した現在構成を集計します。</p>
                </div>
                <div class="divide-y divide-slate-100">
                    @forelse($loadoutRows as $index => $loadout)
                        <article class="p-4 sm:p-5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="font-black text-slate-950">#{{ $index + 1 }} 構成</div>
                                <div class="text-sm font-black text-violet-700">{{ number_format($loadout['count']) }}人・{{ $loadout['share'] }}%</div>
                            </div>
                            <ol class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-5">
                                @foreach($loadout['slots'] as $slot)
                                    <li class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <div class="text-[10px] font-black text-slate-400">{{ $slot['slot_no'] }}枠目</div>
                                        <div class="mt-1 text-sm font-black text-slate-900">{{ $slot['name'] }}</div>
                                        <div class="mt-1 text-[10px] font-bold text-slate-500">{{ $slot['stage_label'] }} / {{ $slot['source_job_name'] }}</div>
                                    </li>
                                @endforeach
                            </ol>
                            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[11px] font-bold text-slate-500">
                                <span>主な現在職:
                                    @foreach($loadout['jobs'] as $job)
                                        {{ $job['name'] }}×{{ $job['count'] }}@if(!$loop->last)、@endif
                                    @endforeach
                                </span>
                                <span>例: {{ implode('、', $loadout['players']) }}</span>
                                <span>最終更新 {{ $loadout['latest_set_at'] ? \Illuminate\Support\Carbon::parse($loadout['latest_set_at'])->format('Y/m/d H:i') : '-' }}</span>
                            </div>
                        </article>
                    @empty
                        <div class="px-6 py-10 text-center text-sm font-bold text-slate-500">対象セットはありません。</div>
                    @endforelse
                </div>
            </section>

            <section class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="text-lg font-black text-slate-950">併用される戦技</h2>
                    <p class="mt-1 text-xs font-bold leading-5 text-slate-400">よく一緒に使われる2枚。対策戦技を考える入口に使います。</p>
                </div>
                <div class="max-h-[760px] divide-y divide-slate-100 overflow-y-auto">
                    @forelse($pairRows as $index => $pair)
                        <div class="px-4 py-3">
                            <div class="text-[10px] font-black text-slate-400">#{{ $index + 1 }}</div>
                            <div class="mt-1 text-sm font-black text-slate-900">{{ $pair['first_name'] }}</div>
                            <div class="text-xs font-bold text-slate-400">＋</div>
                            <div class="text-sm font-black text-slate-900">{{ $pair['second_name'] }}</div>
                            <div class="mt-2 text-xs font-black text-violet-700">{{ number_format($pair['count']) }}人・{{ $pair['share'] }}%</div>
                        </div>
                    @empty
                        <div class="px-6 py-10 text-center text-sm font-bold text-slate-500">2枚以上のセットがありません。</div>
                    @endforelse
                </div>
            </section>
        </div>

        <section class="mt-6 overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="text-lg font-black text-slate-950">プレイヤー別セット</h2>
                        <p class="mt-1 text-xs font-bold leading-5 text-slate-400">累計戦績は現在セットだけの成績ではありません。個別構成を読むための参考情報です。</p>
                    </div>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <label class="text-xs font-black text-slate-600">
                            プレイヤー・職業・戦技検索
                            <input wire:model.live.debounce.300ms="playerSearch" type="search" placeholder="名前または戦技" class="mt-1 w-full rounded-md border-slate-300 bg-white text-sm font-bold">
                        </label>
                        <label class="text-xs font-black text-slate-600">
                            1ページ
                            <select wire:model.live="perPage" class="mt-1 w-full rounded-md border-slate-300 bg-white text-sm font-bold">
                                <option value="10">10人</option>
                                <option value="25">25人</option>
                                <option value="50">50人</option>
                                <option value="100">100人</option>
                            </select>
                        </label>
                    </div>
                </div>
            </div>

            <div class="divide-y divide-slate-100">
                @forelse($playerRows as $player)
                    <article class="p-4 sm:p-5">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-lg font-black text-slate-950">{{ $player['name'] }}</h3>
                                    <span class="rounded bg-slate-100 px-2 py-1 text-xs font-black text-slate-700">Lv{{ $player['level'] }}</span>
                                    <span class="rounded bg-violet-100 px-2 py-1 text-xs font-black text-violet-800">{{ $player['current_job_name'] }}</span>
                                    <span class="rounded bg-emerald-100 px-2 py-1 text-xs font-black text-emerald-800">SP {{ $player['sp_policy_label'] }}</span>
                                </div>
                                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] font-bold text-slate-500">
                                    <span>最終活動 {{ $player['last_seen_at'] ? \Illuminate\Support\Carbon::parse($player['last_seen_at'])->format('Y/m/d H:i') : '-' }}</span>
                                    <span>セット更新 {{ $player['set_updated_at'] ? \Illuminate\Support\Carbon::parse($player['set_updated_at'])->format('Y/m/d H:i') : '-' }}</span>
                                </div>
                            </div>
                            <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-600">
                                累計 {{ number_format($player['wins']) }}勝 {{ number_format($player['losses']) }}敗
                                <span class="ml-1 font-black text-slate-900">{{ $player['win_rate'] === null ? '-' : $player['win_rate'].'%' }}</span>
                            </div>
                        </div>

                        <ol class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-5">
                            @foreach(range(1, 5) as $slotNo)
                                @php $slot = collect($player['slots'])->firstWhere('slot_no', $slotNo); @endphp
                                <li class="min-h-20 rounded-md border {{ $slot ? ($slot['is_active'] ? 'border-slate-200 bg-white' : 'border-rose-200 bg-rose-50') : 'border-dashed border-slate-200 bg-slate-50' }} p-3">
                                    <div class="text-[10px] font-black text-slate-400">{{ $slotNo }}枠目</div>
                                    @if($slot)
                                        <div class="mt-1 text-sm font-black text-slate-950">{{ $slot['name'] }}</div>
                                        <div class="mt-1 text-[10px] font-bold text-slate-500">{{ $slot['stage_label'] }} / {{ $slot['source_job_name'] }}</div>
                                        @unless($slot['is_active'])
                                            <div class="mt-2 text-[10px] font-black text-rose-700">無効保存</div>
                                        @endunless
                                    @else
                                        <div class="mt-2 text-xs font-bold text-slate-300">未設定</div>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </article>
                @empty
                    <div class="px-6 py-12 text-center text-sm font-bold text-slate-500">条件に一致するセット済みプレイヤーはいません。</div>
                @endforelse
            </div>

            <div class="flex flex-col gap-3 border-t border-slate-100 bg-slate-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="text-xs font-bold text-slate-500">
                    {{ number_format($playerPagination['total']) }}人中 {{ number_format($playerPagination['from']) }}〜{{ number_format($playerPagination['to']) }}人
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        wire:click="previousPlayerPage"
                        @disabled($playerPagination['page'] <= 1)
                        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-black text-slate-700 disabled:cursor-not-allowed disabled:opacity-40"
                    >前へ</button>
                    <span class="px-2 text-xs font-black text-slate-600">{{ $playerPagination['page'] }} / {{ $playerPagination['last_page'] }}</span>
                    <button
                        type="button"
                        wire:click="nextPlayerPage({{ $playerPagination['last_page'] }})"
                        @disabled($playerPagination['page'] >= $playerPagination['last_page'])
                        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-black text-slate-700 disabled:cursor-not-allowed disabled:opacity-40"
                    >次へ</button>
                </div>
            </div>
        </section>
    @endunless
</div>
