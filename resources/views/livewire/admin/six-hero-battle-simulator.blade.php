<section class="mb-10 rounded-lg border border-indigo-200 bg-indigo-50/50 p-4 shadow-sm sm:p-6" data-six-hero-admin-simulator>
    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.2em] text-indigo-700">SIX HEROES BATTLE LAB</p>
            <h2 class="mt-2 text-2xl font-black text-slate-950">六英雄戦シミュレーション</h2>
            <p class="mt-2 max-w-3xl text-sm font-bold leading-relaxed text-slate-600">
                現在のキャラクター能力・装備・PvP戦技セットを使い、選択した間の公式戦と同じルール・ダメージ計算で対戦します。
            </p>
        </div>
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs font-black leading-relaxed text-emerald-800">
            順位・挑戦回数・戦績・HP/SP・戦闘ログDBは更新しません
        </div>
    </div>

    <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
        @foreach($rooms as $room)
            <button type="button"
                    wire:click="selectRoom('{{ $room['key'] }}')"
                    class="min-h-16 rounded-md border px-3 py-3 text-left transition {{ $selectedRoomKey === $room['key'] ? 'border-indigo-500 bg-indigo-600 text-white shadow' : 'border-slate-200 bg-white text-slate-800 hover:border-indigo-300 hover:bg-indigo-50' }}">
                <div class="text-sm font-black">{{ $room['label'] }}</div>
                <div class="mt-1 text-[11px] font-bold leading-snug {{ $selectedRoomKey === $room['key'] ? 'text-indigo-100' : 'text-slate-500' }}">{{ $room['description'] }}</div>
            </button>
        @endforeach
    </div>

    <div class="mt-5 grid gap-4 xl:grid-cols-2">
        <div class="rounded-md border border-indigo-200 bg-white p-4">
            <div class="text-sm font-black text-indigo-900">{{ $selectedRoom->label() }}の現行ルール</div>
            <p class="mt-2 text-sm font-black leading-relaxed text-slate-800">{{ $selectedRoomRule['summary'] }}</p>
            <ul class="mt-3 space-y-2 pl-5 text-xs font-bold leading-relaxed text-slate-600">
                @foreach($selectedRoomRule['points'] as $point)
                    <li class="list-disc">{{ $point }}</li>
                @endforeach
            </ul>
        </div>

        <div class="rounded-md border border-indigo-200 bg-slate-950 p-4 text-slate-100">
            <div class="text-sm font-black text-indigo-200">現在適用されるランク戦ダメージ式</div>
            <div class="mt-3 overflow-x-auto rounded bg-black/30 p-3 font-mono text-xs leading-relaxed text-slate-100">
                基準 = max(1, 攻撃能力×{{ $formula['attack_rate'] }} − 混合防御×{{ $formula['defense_rate'] }} + max(0, 攻撃能力−混合防御)×{{ $formula['pressure_rate'] }})<br>
                通常換算damage = floor(基準 × 会心・相性・防御・軽減 × 乱数{{ $formula['variance_min'] }}〜{{ $formula['variance_max'] }}%)<br>
                威力適用damage = max(1, 通常換算damage × 表示威力÷100)<br>
                最終damage = 戦技・場効果補正 → 部屋の最終補正 → バリア等
            </div>
            <ul class="mt-3 space-y-1 text-xs font-bold leading-relaxed text-slate-300">
                <li>通常攻撃の表示威力は{{ $normalAttackPower }}%。戦技は各hitの表示威力を適用します。</li>
                <li>会心時は基準{{ $formula['critical_multiplier'] }}倍。部屋別の能力置換は基準計算前、最終damage補正は威力適用後に行います。</li>
                <li>六英雄戦ではHP割合の最低保証を使わず、最終最低値1だけを維持します。</li>
            </ul>
        </div>
    </div>

    <div class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
        @foreach([
            ['side' => 'attacker', 'title' => '挑戦側', 'search' => $attackerSearch, 'candidates' => $attackerCandidates, 'selected' => $selectedAttacker, 'stats' => $attackerStats],
            ['side' => 'defender', 'title' => '防衛側', 'search' => $defenderSearch, 'candidates' => $defenderCandidates, 'selected' => $selectedDefender, 'stats' => $defenderStats],
        ] as $combatant)
            @if($combatant['side'] === 'defender')
                <div class="flex items-center justify-center">
                    <button type="button"
                            wire:click="swapCombatants"
                            class="min-h-11 rounded-md border border-indigo-300 bg-white px-4 text-sm font-black text-indigo-800 shadow-sm hover:bg-indigo-50">
                        ⇄ 攻守交代
                    </button>
                </div>
            @endif

            <div class="min-w-0 rounded-md border border-slate-200 bg-white p-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <h3 class="text-lg font-black text-slate-950">{{ $combatant['title'] }}キャラクター</h3>
                    <input type="search"
                           wire:model.live.debounce.300ms="{{ $combatant['side'] }}Search"
                           placeholder="名前・ID・メール"
                           class="min-h-11 w-full rounded-md border border-slate-300 px-3 text-sm sm:w-56">
                </div>

                <div class="mt-3 max-h-64 space-y-2 overflow-y-auto">
                    @foreach($combatant['candidates'] as $character)
                        @php
                            $selectedId = $combatant['side'] === 'attacker' ? $selectedAttackerId : $selectedDefenderId;
                            $isSelected = (int) $selectedId === (int) $character->id;
                            $selectMethod = $combatant['side'] === 'attacker' ? 'selectAttacker' : 'selectDefender';
                        @endphp
                        <button type="button"
                                wire:click="{{ $selectMethod }}({{ $character->id }})"
                                class="w-full rounded-md border px-3 py-3 text-left {{ $isSelected ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200 hover:bg-slate-50' }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-black text-slate-950">#{{ $character->id }} {{ $character->name }}</div>
                                    <div class="mt-1 truncate text-[11px] font-bold text-slate-500">User #{{ $character->user_id }} / {{ $character->user?->email ?? 'N/A' }}</div>
                                </div>
                                <div class="shrink-0 text-right text-xs font-black text-slate-600">
                                    <div>Lv {{ $character->level }}</div>
                                    <div>{{ $character->currentJob?->name ?? '無職' }}</div>
                                </div>
                            </div>
                        </button>
                    @endforeach
                </div>

                @if($combatant['selected'])
                    @php
                        $equipment = $combatant['selected']->characterItems->where('is_equipped', true);
                    @endphp
                    <div class="mt-4 rounded-md bg-slate-950 p-4 text-slate-100" data-six-hero-simulator-combatant="{{ $combatant['side'] }}">
                        <div class="font-black">{{ $combatant['selected']->name }} / Lv {{ $combatant['selected']->level }} / {{ $combatant['selected']->currentJob?->name ?? '無職' }}</div>
                        <div class="mt-3 grid grid-cols-2 gap-2 text-xs font-black sm:grid-cols-4">
                            <span>HP {{ number_format($combatant['stats']['max_hp'] ?? 0) }}</span>
                            <span>SP {{ number_format($combatant['stats']['max_mp'] ?? 0) }}</span>
                            <span>攻撃 {{ number_format($combatant['stats']['str'] ?? 0) }}</span>
                            <span>防御 {{ number_format($combatant['stats']['def'] ?? 0) }}</span>
                            <span>魔力 {{ number_format($combatant['stats']['mag'] ?? 0) }}</span>
                            <span>精神 {{ number_format($combatant['stats']['spr'] ?? 0) }}</span>
                            <span>敏捷 {{ number_format($combatant['stats']['agi'] ?? 0) }}</span>
                            <span>運 {{ number_format($combatant['stats']['luk'] ?? 0) }}</span>
                        </div>
                        <div class="mt-3 border-t border-slate-700 pt-3 text-xs font-bold leading-relaxed text-slate-300">
                            <span class="text-slate-100">装備:</span>
                            {{ $equipment->isEmpty() ? 'なし' : $equipment->map(fn ($item) => $item->displayName())->implode(' / ') }}
                        </div>
                    </div>
                @else
                    <div class="mt-4 rounded-md bg-slate-100 p-4 text-sm font-bold text-slate-500">{{ $combatant['title'] }}を選択してください。</div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-6 flex flex-col gap-4 rounded-md border border-slate-200 bg-white p-4 sm:flex-row sm:items-end">
        <div class="sm:w-44">
            <label class="block text-sm font-black text-slate-700">試行回数</label>
            <input type="number" min="1" max="100" wire:model="simulationCount" class="mt-1 min-h-11 w-full rounded-md border border-slate-300 px-3 text-sm">
        </div>
        <button type="button"
                wire:click="runSimulation"
                wire:loading.attr="disabled"
                wire:target="runSimulation"
                class="min-h-12 flex-1 rounded-md bg-indigo-700 px-5 text-sm font-black text-white shadow hover:bg-indigo-800 disabled:cursor-wait disabled:opacity-60">
            <span wire:loading.remove wire:target="runSimulation">{{ $selectedRoom->label() }}でシミュレーション実行</span>
            <span wire:loading wire:target="runSimulation">実行中…</span>
        </button>
    </div>

    @error('selectedAttackerId') <div class="mt-2 text-xs font-black text-red-700">{{ $message }}</div> @enderror
    @error('selectedDefenderId') <div class="mt-2 text-xs font-black text-red-700">{{ $message }}</div> @enderror
    @error('selectedRoomKey') <div class="mt-2 text-xs font-black text-red-700">{{ $message }}</div> @enderror
    @error('simulationCount') <div class="mt-2 text-xs font-black text-red-700">{{ $message }}</div> @enderror
    @error('simulation') <div class="mt-2 text-xs font-black text-red-700">{{ $message }}</div> @enderror

    @if($summary)
        <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-7" data-six-hero-simulator-summary>
            @foreach([
                '試行回数' => number_format($summary['total']),
                '挑戦側勝率' => number_format($summary['attacker_win_rate'], 1).'%',
                '防衛側勝率' => number_format($summary['defender_win_rate'], 1).'%',
                '挑戦側勝利' => number_format($summary['attacker_wins']),
                '防衛側勝利' => number_format($summary['defender_wins']),
                '平均ターン' => number_format($summary['avg_turns'], 1),
                '平均残HP' => '挑'.number_format($summary['avg_attacker_hp_rate'], 1).'% / 防'.number_format($summary['avg_defender_hp_rate'], 1).'%',
            ] as $label => $value)
                <div class="rounded-md border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="text-[11px] font-black text-slate-500">{{ $label }}</div>
                    <div class="mt-2 text-lg font-black text-slate-950">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        <div class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)]">
            <div class="overflow-hidden rounded-md border border-slate-200 bg-white">
                <div class="border-b border-slate-200 px-4 py-3 text-lg font-black text-slate-950">試行結果</div>
                <div class="max-h-[560px] overflow-auto">
                    <table class="min-w-[620px] w-full text-sm">
                        <thead class="sticky top-0 bg-slate-100 text-xs font-black text-slate-700">
                            <tr><th class="px-3 py-3 text-left">#</th><th class="px-3 py-3 text-left">勝者</th><th class="px-3 py-3 text-right">ターン</th><th class="px-3 py-3 text-right">挑戦側HP</th><th class="px-3 py-3 text-right">防衛側HP</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($runs as $run)
                                <tr>
                                    <td class="px-3 py-3 font-bold text-slate-500">{{ $run['index'] }}</td>
                                    <td class="px-3 py-3 font-black {{ $run['attacker_won'] ? 'text-indigo-700' : 'text-rose-700' }}">{{ $run['winner'] }}</td>
                                    <td class="px-3 py-3 text-right font-bold">{{ number_format($run['turns']) }}</td>
                                    <td class="px-3 py-3 text-right font-bold">{{ number_format($run['attacker_hp']) }} ({{ number_format($run['attacker_hp_rate'], 1) }}%)</td>
                                    <td class="px-3 py-3 text-right font-bold">{{ number_format($run['defender_hp']) }} ({{ number_format($run['defender_hp_rate'], 1) }}%)</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-md bg-slate-950 p-4 text-slate-100">
                <h3 class="text-lg font-black">サンプル1戦の全ログ</h3>
                <p class="mt-1 text-xs font-bold text-slate-400">実際に適用されたダメージ・回復・強化・弱体を生成順で表示します。</p>
                <div class="mt-4 max-h-[560px] overflow-auto rounded bg-black/30 p-4 text-sm leading-relaxed">
                    @foreach($sampleLogs as $line)
                        <div class="mb-2 whitespace-pre-wrap">{{ $line }}</div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</section>
