<div class="min-h-screen bg-slate-100 px-3 py-5 text-slate-900 sm:px-5 lg:px-8">
    <div class="mx-auto max-w-[1680px] space-y-5">
        <header class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div>
                    <p class="text-xs font-black tracking-[0.18em] text-violet-600">NEXT RAID FEEDBACK LOOP</p>
                    <h1 class="mt-2 text-2xl font-black text-slate-950 sm:text-3xl">国家対抗レイド 戦闘分析</h1>
                    <p class="mt-3 max-w-4xl text-sm font-bold leading-7 text-slate-600">
                        1出撃を1件として、20ターンの難度、形態、系譜対策、国家規模、個人報酬の到達状況を次回調整へつなげます。
                        無所属の出撃も個人・全体分析には含め、国家集計資格とは分けて確認します。
                    </p>
                </div>
                <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-bold leading-6 text-amber-900 xl:max-w-lg">
                    現時点では国家対抗レイドの戦闘本体は未実装です。将来の戦闘終了処理から計測サービスを呼ぶと、この画面へ実データが蓄積されます。計測失敗で戦闘・共有HP・報酬を失敗させない設計です。
                </div>
            </div>
        </header>

        <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-black text-slate-950">分析条件</h2>
                    <p class="mt-1 text-xs font-bold text-slate-500">絞り込み後の集計値が、そのままCodex用データへ反映されます。</p>
                </div>
                <button type="button" wire:click="resetFilters" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-black text-slate-700 hover:bg-slate-50">
                    条件をリセット
                </button>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-6">
                <label class="text-xs font-black text-slate-600 xl:col-span-2">
                    開催回
                    <select wire:model.live="eventKey" class="mt-1 w-full rounded-md border-slate-300 text-sm font-bold">
                        @forelse($event_options as $option)
                            <option value="{{ $option['event_key'] }}">{{ $option['event_key'] }}（{{ number_format($option['records']) }}件）</option>
                        @empty
                            <option value="">データなし</option>
                        @endforelse
                    </select>
                </label>
                <label class="text-xs font-black text-slate-600">
                    開催日
                    <select wire:model.live="raidDay" class="mt-1 w-full rounded-md border-slate-300 text-sm font-bold">
                        <option value="0">全日</option>
                        @foreach(range(1, 7) as $day)
                            <option value="{{ $day }}">{{ $day }}日目</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-xs font-black text-slate-600">
                    所属・集計資格
                    <select wire:model.live="affiliation" class="mt-1 w-full rounded-md border-slate-300 text-sm font-bold">
                        <option value="all">すべて</option>
                        <option value="eligible">国家集計対象</option>
                        <option value="unaffiliated">無所属</option>
                        <option value="ineligible">所属済み・国家集計外</option>
                    </select>
                </label>
                <label class="text-xs font-black text-slate-600">
                    開始時形態
                    <select wire:model.live="bossPhase" class="mt-1 w-full rounded-md border-slate-300 text-sm font-bold">
                        <option value="all">すべて</option>
                        @foreach($filter_options['phases'] as $phase)
                            <option value="{{ $phase['key'] }}">{{ $phase['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-xs font-black text-slate-600">
                    対抗対象系譜
                    <select wire:model.live="adaptiveLineage" class="mt-1 w-full rounded-md border-slate-300 text-sm font-bold">
                        <option value="all">すべて</option>
                        <option value="none">対象なし</option>
                        @foreach($filter_options['lineages'] as $lineage)
                            <option value="{{ $lineage['key'] }}">{{ $lineage['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <label class="mt-3 block max-w-xs text-xs font-black text-slate-600">
                終了状態
                <select wire:model.live="resultStatus" class="mt-1 w-full rounded-md border-slate-300 text-sm font-bold">
                    <option value="all">すべて</option>
                    <option value="resolved">確定</option>
                    <option value="aborted">中断</option>
                    <option value="refunded">回数返却</option>
                </select>
            </label>
        </section>

        @if(! $table_available)
            <section class="rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm font-bold leading-7 text-rose-900">
                計測テーブルがまだありません。migration適用後に保存・参照できます。レイド本体からの保存呼び出しは、戦闘実装時に接続します。
            </section>
        @elseif(! $has_records)
            <section class="rounded-lg border border-sky-200 bg-sky-50 p-5 text-sm font-bold leading-7 text-sky-900">
                条件に一致する戦闘データはまだありません。下の「収集設計」とCodex用フォーマットは、データがない状態でも確認・コピーできます。
            </section>
        @else
            <section class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                @php
                    $cards = [
                        ['label' => '確定出撃', 'value' => number_format($summary['resolved_sorties'] ?? 0), 'note' => '全記録 '.number_format($summary['records'] ?? 0).'件'],
                        ['label' => '参加Character', 'value' => number_format($summary['unique_characters'] ?? 0), 'note' => '参照可能な人数'],
                        ['label' => '実適用ダメージ', 'value' => number_format($summary['total_applied_damage'] ?? 0), 'note' => '共有HPに反映'],
                        ['label' => '中央値', 'value' => number_format($summary['median_damage'] ?? 0), 'note' => '平均 '.number_format($summary['average_damage'] ?? 0)],
                        ['label' => 'P90', 'value' => number_format($summary['p90_damage'] ?? 0), 'note' => '上位依存の確認'],
                        ['label' => '1出撃最大', 'value' => number_format($summary['max_sortie_damage'] ?? 0), 'note' => '突出した出撃'],
                        ['label' => '1行動最大', 'value' => number_format($summary['max_action_damage'] ?? 0), 'note' => '単発記録の確認'],
                        ['label' => '20ターン到達率', 'value' => ($summary['turn_twenty_rate'] ?? null) === null ? '-' : $summary['turn_twenty_rate'].'%', 'note' => '平均 '.($summary['average_turns'] ?? 0).'ターン'],
                        ['label' => '戦闘不能率', 'value' => ($summary['defeat_rate'] ?? null) === null ? '-' : $summary['defeat_rate'].'%', 'note' => '難度曲線の入口'],
                        ['label' => '同一HPの個体換算', 'value' => ($summary['estimated_defeats_from_damage'] ?? null) === null ? '-' : number_format($summary['estimated_defeats_from_damage'], 3).'体', 'note' => '個体HPが異なる観測は換算しない'],
                    ];
                @endphp
                @foreach($cards as $card)
                    <article class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="text-[11px] font-black text-slate-500">{{ $card['label'] }}</div>
                        <div class="mt-2 break-words text-xl font-black text-slate-950">{{ $card['value'] }}</div>
                        <div class="mt-1 text-[10px] font-bold text-slate-400">{{ $card['note'] }}</div>
                    </article>
                @endforeach
            </section>
        @endif

        <section class="rounded-lg border border-violet-200 bg-white shadow-sm">
            <div class="border-b border-violet-100 bg-violet-50 px-5 py-4">
                <h2 class="text-lg font-black text-violet-950">収集設計：何を集め、何を改善できるか</h2>
                <p class="mt-1 text-xs font-bold leading-5 text-violet-700">次回変更は、観測値と安全条件をセットで判断します。固定値を画面側で推測しません。</p>
            </div>
            <div class="grid grid-cols-1 divide-y divide-slate-100 xl:grid-cols-2 xl:divide-y-0">
                @foreach($metric_definitions as $definition)
                    <article class="p-5 xl:border-b xl:border-slate-100 xl:odd:border-r">
                        <h3 class="font-black text-slate-950">{{ $definition['category'] }}</h3>
                        <dl class="mt-3 space-y-2 text-xs leading-6">
                            <div><dt class="inline font-black text-sky-700">収集：</dt><dd class="inline font-bold text-slate-600">{{ $definition['collect'] }}</dd></div>
                            <div><dt class="inline font-black text-emerald-700">分かること：</dt><dd class="inline font-bold text-slate-600">{{ $definition['reveals'] }}</dd></div>
                            <div><dt class="inline font-black text-violet-700">次回への反映：</dt><dd class="inline font-bold text-slate-600">{{ $definition['improves'] }}</dd></div>
                            <div><dt class="inline font-black text-amber-700">判断条件：</dt><dd class="inline font-bold text-slate-600">{{ $definition['guardrail'] }}</dd></div>
                        </dl>
                    </article>
                @endforeach
            </div>
        </section>

        @if($has_records)
            <div class="grid grid-cols-1 gap-5 2xl:grid-cols-2">
                <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-4"><h2 class="font-black">日別・形態別</h2></div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead class="bg-slate-50 font-black text-slate-500"><tr><th class="px-4 py-3">区分</th><th class="px-4 py-3">出撃</th><th class="px-4 py-3">合計</th><th class="px-4 py-3">平均</th><th class="px-4 py-3">中央値</th><th class="px-4 py-3">20T到達</th></tr></thead>
                            <tbody class="divide-y divide-slate-100 font-bold">
                                @foreach($daily as $row)
                                    <tr><td class="px-4 py-3">{{ $row['raid_day'] }}日目</td><td class="px-4 py-3">{{ number_format($row['sorties']) }}</td><td class="px-4 py-3">{{ number_format($row['total_damage']) }}</td><td class="px-4 py-3">{{ number_format($row['average_damage']) }}</td><td class="px-4 py-3">{{ number_format($row['median_damage'] ?? 0) }}</td><td class="px-4 py-3">{{ $row['turn_twenty_rate'] ?? '-' }}{{ $row['turn_twenty_rate'] === null ? '' : '%' }}</td></tr>
                                @endforeach
                                @foreach($phases as $row)
                                    <tr class="bg-violet-50/40"><td class="px-4 py-3">{{ $row['label'] }}</td><td class="px-4 py-3">{{ number_format($row['sorties']) }}</td><td class="px-4 py-3">{{ number_format($row['total_damage']) }}</td><td class="px-4 py-3">{{ number_format($row['average_damage']) }}</td><td class="px-4 py-3">{{ number_format($row['median_damage'] ?? 0) }}</td><td class="px-4 py-3">{{ $row['turn_twenty_rate'] ?? '-' }}{{ $row['turn_twenty_rate'] === null ? '' : '%' }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-4"><h2 class="font-black">国家規模・無所属</h2><p class="mt-1 text-xs font-bold text-slate-400">国家名を出さず、規模帯で公平性を比較します。</p></div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead class="bg-slate-50 font-black text-slate-500"><tr><th class="px-4 py-3">規模</th><th class="px-4 py-3">出撃</th><th class="px-4 py-3">人数</th><th class="px-4 py-3">平均</th><th class="px-4 py-3">中央値</th><th class="px-4 py-3">戦闘不能</th></tr></thead>
                            <tbody class="divide-y divide-slate-100 font-bold">
                                @foreach($nation_sizes as $row)
                                    <tr><td class="px-4 py-3">{{ $row['label'] }}</td><td class="px-4 py-3">{{ number_format($row['sorties']) }}</td><td class="px-4 py-3">{{ number_format($row['unique_characters']) }}</td><td class="px-4 py-3">{{ number_format($row['average_damage']) }}</td><td class="px-4 py-3">{{ number_format($row['median_damage'] ?? 0) }}</td><td class="px-4 py-3">{{ $row['defeat_rate'] ?? '-' }}{{ $row['defeat_rate'] === null ? '' : '%' }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
                <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm xl:col-span-2">
                    <div class="border-b border-slate-100 px-5 py-4"><h2 class="font-black">国家別の競争値（匿名）</h2><p class="mt-1 text-xs font-bold text-slate-400">国家集計対象だけを総ダメージ順に並べます。順位・一人あたり・単発突出を見ますが、コピー結果へ国家名やIDは出しません。</p></div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead class="bg-slate-50 font-black text-slate-500"><tr><th class="px-4 py-3">匿名順位</th><th class="px-4 py-3">出撃</th><th class="px-4 py-3">参加/基準人数</th><th class="px-4 py-3">総ダメージ</th><th class="px-4 py-3">全体比</th><th class="px-4 py-3">基準一人あたり</th><th class="px-4 py-3">1出撃最大</th><th class="px-4 py-3">1行動最大</th></tr></thead>
                            <tbody class="divide-y divide-slate-100 font-bold">
                                @forelse($nation_competition as $row)
                                    <tr><td class="px-4 py-3">{{ $row['anonymous_nation'] }}</td><td class="px-4 py-3">{{ number_format($row['sorties']) }}</td><td class="px-4 py-3">{{ number_format($row['participants']) }} / {{ number_format($row['active_count_snapshot']) }}</td><td class="px-4 py-3">{{ number_format($row['total_damage']) }}</td><td class="px-4 py-3">{{ $row['damage_share'] ?? '-' }}{{ $row['damage_share'] === null ? '' : '%' }}</td><td class="px-4 py-3">{{ $row['damage_per_active_member'] !== null ? number_format($row['damage_per_active_member']) : '算出不能' }}</td><td class="px-4 py-3">{{ number_format($row['max_sortie_damage']) }}</td><td class="px-4 py-3">{{ number_format($row['max_action_damage']) }}</td></tr>
                                @empty
                                    <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">国家集計対象の出撃はありません。</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
                <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="font-black">個人貢献の偏り（匿名）</h2>
                    <dl class="mt-4 space-y-3 text-xs font-bold text-slate-600">
                        <div class="flex justify-between gap-3"><dt>分析人数</dt><dd>{{ number_format($participant_distribution['participants'] ?? 0) }}人</dd></div>
                        <div class="flex justify-between gap-3"><dt>累計中央値</dt><dd>{{ number_format($participant_distribution['median_cumulative_damage'] ?? 0) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt>累計P90</dt><dd>{{ number_format($participant_distribution['p90_cumulative_damage'] ?? 0) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt>最大累計</dt><dd>{{ number_format($participant_distribution['max_cumulative_damage'] ?? 0) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt>上位1人の比率</dt><dd>{{ $participant_distribution['top_one_damage_share'] ?? '-' }}{{ ($participant_distribution['top_one_damage_share'] ?? null) === null ? '' : '%' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt>上位10%の比率</dt><dd>{{ $participant_distribution['top_ten_percent_damage_share'] ?? '-' }}{{ ($participant_distribution['top_ten_percent_damage_share'] ?? null) === null ? '' : '%' }}</dd></div>
                    </dl>
                </section>
            </div>

            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-4"><h2 class="font-black">10系譜の採用と対抗対象時の落差</h2><p class="mt-1 text-xs font-bold text-slate-400">複数系譜の出撃は各系譜へ1件ずつ帰属するため、件数合計は出撃数を超える場合があります。</p></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead class="bg-slate-50 font-black text-slate-500"><tr><th class="px-4 py-3">系譜</th><th class="px-4 py-3">採用出撃</th><th class="px-4 py-3">採用率</th><th class="px-4 py-3">平均</th><th class="px-4 py-3">対象時平均</th><th class="px-4 py-3">非対象時平均</th><th class="px-4 py-3">対象/非対象</th><th class="px-4 py-3">20T到達</th></tr></thead>
                        <tbody class="divide-y divide-slate-100 font-bold">
                            @foreach($lineages as $row)
                                <tr><td class="px-4 py-3">{{ $row['label'] }}</td><td class="px-4 py-3">{{ number_format($row['sorties']) }}</td><td class="px-4 py-3">{{ $row['adoption_rate'] ?? '-' }}{{ $row['adoption_rate'] === null ? '' : '%' }}</td><td class="px-4 py-3">{{ number_format($row['average_damage']) }}</td><td class="px-4 py-3">{{ number_format($row['targeted_average_damage']) }}（{{ $row['targeted_sorties'] }}件）</td><td class="px-4 py-3">{{ number_format($row['untargeted_average_damage']) }}（{{ $row['untargeted_sorties'] }}件）</td><td class="px-4 py-3">{{ $row['targeted_vs_untargeted_percent'] ?? '-' }}{{ $row['targeted_vs_untargeted_percent'] === null ? '' : '%' }}</td><td class="px-4 py-3">{{ $row['turn_twenty_rate'] ?? '-' }}{{ $row['turn_twenty_rate'] === null ? '' : '%' }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-4"><h2 class="font-black">20ターン難度曲線</h2><p class="mt-1 text-xs font-bold text-slate-400">到達率が急落するターンと、予告・戦闘不能の重なりを確認します。</p></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead class="bg-slate-50 font-black text-slate-500"><tr><th class="px-4 py-3">T</th><th class="px-4 py-3">到達数</th><th class="px-4 py-3">到達率</th><th class="px-4 py-3">平均与ダメージ</th><th class="px-4 py-3">平均被ダメージ</th><th class="px-4 py-3">戦闘不能</th><th class="px-4 py-3">予告</th><th class="px-4 py-3">上限到達 / 計測攻撃数</th></tr></thead>
                        <tbody class="divide-y divide-slate-100 font-bold">
                            @foreach($turns as $row)
                                <tr class="{{ $row['turn'] >= 17 ? 'bg-rose-50/40' : '' }}"><td class="px-4 py-3">{{ $row['turn'] }}</td><td class="px-4 py-3">{{ number_format($row['samples']) }}</td><td class="px-4 py-3">{{ $row['reach_rate'] ?? '-' }}{{ $row['reach_rate'] === null ? '' : '%' }}</td><td class="px-4 py-3">{{ number_format($row['average_player_damage']) }}</td><td class="px-4 py-3">{{ number_format($row['average_boss_damage']) }}</td><td class="px-4 py-3">{{ $row['deaths'] }}（{{ $row['death_rate'] ?? '-' }}{{ $row['death_rate'] === null ? '' : '%' }}）</td><td class="px-4 py-3">{{ number_format($row['telegraphs']) }}</td><td class="px-4 py-3">{{ $row['cap_hits'] }} / {{ $row['cap_samples'] }}（{{ $row['cap_hit_rate'] === null ? '未計測' : $row['cap_hit_rate'].'%' }}）</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
                <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-4"><h2 class="font-black">ダメージ源</h2></div>
                    <div class="divide-y divide-slate-100">
                        @foreach($damage_sources as $row)
                            <div class="flex items-center justify-between gap-3 px-5 py-3 text-xs font-bold"><span>{{ $row['label'] }}</span><span>{{ number_format($row['damage']) }} / {{ $row['share'] ?? '-' }}{{ $row['share'] === null ? '' : '%' }}</span></div>
                        @endforeach
                    </div>
                </section>
                <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-4"><h2 class="font-black">予告への対策</h2><p class="mt-1 text-xs text-slate-500">大技阻止の経路は重複します。成立回数と選択回数は別集計です。</p></div>
                    <div class="divide-y divide-slate-100">
                        @foreach($counterplay as $row)
                            <div class="flex items-center justify-between gap-3 px-5 py-3 text-xs font-bold"><span>{{ $row['label'] }}</span><span>{{ number_format($row['count']) }}回 @if($row['per_telegraph_rate'] !== null)（予告比 {{ $row['per_telegraph_rate'] }}%）@endif @if($row['per_turn_twenty_rate'] !== null)（20ターン到達出撃比 {{ $row['per_turn_twenty_rate'] }}%）@endif</span></div>
                        @endforeach
                    </div>
                </section>
            </div>

            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="font-black">竜特攻・耐性の持込状況</h2>
                <p class="mt-2 text-xs text-slate-500">出撃単位の分布です。装備効果以外の能力差も含むため、与ダメージ差を特攻だけの効果とは断定できません。</p>
                <p class="mt-3 text-sm">特攻有効 {{ $equipment_effects['matched_sorties'] ?? 0 }}件 / 効果なし {{ $equipment_effects['unmatched_sorties'] ?? 0 }}件 / 未計測 {{ $equipment_effects['unavailable_sorties'] ?? 0 }}件</p>
                <p class="mt-2 text-sm">特攻上限到達 {{ $equipment_effects['cap_reached_sorties'] ?? 0 }}件・耐性有効 {{ $equipment_effects['resistance_matched_sorties'] ?? 0 }} / 計測 {{ $equipment_effects['resistance_observed_sorties'] ?? 0 }}件</p>
                <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs text-slate-600">
                    @foreach($equipment_effects['effective_rate_distribution'] ?? [] as $rate)
                        <span>特攻 +{{ $rate['effective_percent'] }}%：{{ $rate['sorties'] }}件</span>
                    @endforeach
                </div>
            </section>

            <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
                <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="font-black">個人報酬の到達</h2>
                    @if($reward_reach['definition_available'] ?? false)
                        <p class="mt-2 text-xs font-bold text-slate-600">有効参加：{{ $reward_reach['valid_participants'] }} / {{ $reward_reach['linked_participants'] }}人（{{ $reward_reach['valid_participation_rate'] ?? '-' }}%）・必要出撃 {{ $reward_reach['valid_participation_sorties'] }}回</p>
                        <div class="mt-3 space-y-2">
                            @foreach($reward_reach['threshold_reach'] as $row)
                                <div class="rounded-md bg-slate-50 px-3 py-2 text-xs font-bold">累計 {{ number_format($row['damage']) }}：{{ $row['participants'] }}人（有効参加者の {{ $row['rate_among_valid'] ?? '-' }}%）</div>
                            @endforeach
                        </div>
                    @else
                        <p class="mt-2 text-xs font-bold leading-6 text-amber-700">開催時snapshotに有効出撃数とダメージ閾値がないため、到達判定はしていません。</p>
                    @endif
                </section>
                <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="font-black">計測品質</h2>
                    <div class="mt-3 grid grid-cols-2 gap-2 text-xs font-bold text-slate-600">
                        <div>欠損Character：{{ $data_quality['missing_character_rows'] ?? 0 }}</div>
                        <div>turn不一致：{{ $data_quality['turn_detail_mismatch_rows'] ?? 0 }}</div>
                        <div>snapshot欠損：{{ $data_quality['missing_event_snapshot_rows'] ?? 0 }}</div>
                        <div>未適用ダメージ：{{ number_format($data_quality['calculated_but_not_applied_damage'] ?? 0) }}</div>
                        <div>国家人数揺れ：{{ $data_quality['inconsistent_nation_active_count_groups'] ?? 0 }}</div>
                        <div>国家人数欠損：{{ $data_quality['missing_nation_active_count_rows'] ?? 0 }}</div>
                    </div>
                    <ul class="mt-3 space-y-2 text-xs font-bold leading-6 text-amber-800">
                        @forelse($data_quality['warnings'] ?? [] as $warning)
                            <li class="rounded-md bg-amber-50 px-3 py-2">{{ $warning }}</li>
                        @empty
                            <li class="rounded-md bg-emerald-50 px-3 py-2 text-emerald-800">重大な計測警告はありません。</li>
                        @endforelse
                    </ul>
                </section>
            </div>
        @endif

        <section
            class="rounded-lg border border-slate-300 bg-slate-950 p-5 text-slate-100 shadow-sm sm:p-6"
            x-data="{
                copied: false,
                failed: false,
                async copyForCodex() {
                    this.copied = false;
                    this.failed = false;
                    try {
                        if (!navigator.clipboard) throw new Error('clipboard unavailable');
                        await navigator.clipboard.writeText(@js($codex_prompt));
                        this.copied = true;
                        window.setTimeout(() => this.copied = false, 2500);
                    } catch (error) {
                        this.failed = true;
                    }
                }
            }"
        >
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-black">Codex貼り付け用データ</h2>
                    <p class="mt-2 max-w-3xl text-xs font-bold leading-6 text-slate-400">表示中の絞り込み、集計定義、匿名集計値、分析依頼を1つのMarkdownにまとめます。表示名・account・個人/国家の識別子・戦闘tokenは含めません。</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <span x-show="copied" x-cloak class="text-xs font-black text-emerald-300">コピーしました</span>
                    <span x-show="failed" x-cloak class="text-xs font-black text-rose-300">コピーできませんでした</span>
                    <button type="button" x-on:click="copyForCodex" class="rounded-md bg-violet-500 px-4 py-3 text-sm font-black text-white hover:bg-violet-400">
                        Codex貼り付け用にコピー
                    </button>
                </div>
            </div>
            <textarea readonly rows="18" class="mt-5 w-full rounded-md border border-slate-700 bg-slate-900 p-4 font-mono text-[11px] leading-5 text-slate-300">{{ $codex_prompt }}</textarea>
        </section>
    </div>
</div>
