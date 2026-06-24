<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">BATTLE SIMULATOR</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">戦闘シミュレーション</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">実プレイヤーのキャラデータを使って、敵との勝率や残HPを確認します。</p>
        </div>
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-xs font-bold text-amber-800">
            実行結果はDBへ保存されません
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <section class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-lg font-black text-slate-950">キャラクター選択</h2>
                <input type="text" wire:model.live.debounce.300ms="characterSearch" placeholder="名前・User ID・メール" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div class="mt-4 max-h-96 space-y-2 overflow-auto">
                @foreach($characterCandidates as $character)
                    @php $active = (int) $selectedCharacterId === (int) $character->id; @endphp
                    <button type="button" wire:click="selectCharacter({{ $character->id }})" class="w-full rounded-md border px-4 py-3 text-left transition {{ $active ? 'border-amber-400 bg-amber-50' : 'border-slate-200 bg-white hover:bg-slate-50' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-black text-slate-950">#{{ $character->id }} {{ $character->name }}</div>
                                <div class="mt-1 text-xs font-bold text-slate-500">User #{{ $character->user_id }} / {{ optional($character->user)->email ?? 'N/A' }}</div>
                            </div>
                            <div class="text-right text-xs font-black text-slate-600">
                                <div>Lv {{ $character->level }}</div>
                                <div>{{ $character->currentJob?->name ?? '-' }}</div>
                            </div>
                        </div>
                    </button>
                @endforeach
            </div>
        </section>

        <section class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-lg font-black text-slate-950">敵選択</h2>
                <input type="text" wire:model.live.debounce.300ms="enemySearch" placeholder="敵名・ID・ダンジョン" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div class="mt-4 max-h-96 space-y-2 overflow-auto">
                @foreach($enemyCandidates as $enemy)
                    @php $active = (int) $selectedEnemyId === (int) $enemy->id; @endphp
                    <button type="button" wire:click="selectEnemy({{ $enemy->id }})" class="w-full rounded-md border px-4 py-3 text-left transition {{ $active ? 'border-amber-400 bg-amber-50' : 'border-slate-200 bg-white hover:bg-slate-50' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-black text-slate-950">#{{ $enemy->id }} {{ $enemy->name }}</div>
                                <div class="mt-1 text-xs font-bold text-slate-500">{{ $enemy->area?->city?->name ?? '-' }} / {{ $enemy->area?->name ?? '-' }}</div>
                            </div>
                            <div class="text-right text-xs font-black text-slate-600">
                                <div>Lv {{ $enemy->level }}</div>
                                <div>{{ $enemy->is_boss ? 'BOSS' : '通常' }}</div>
                            </div>
                        </div>
                    </button>
                @endforeach
            </div>
        </section>
    </div>

    <section class="mt-6 rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_260px]">
            <div>
                <h2 class="text-lg font-black text-slate-950">選択中キャラ</h2>
                @if($selectedCharacter)
                    <div class="mt-3 rounded bg-slate-50 p-4 text-sm">
                        <div class="font-black text-slate-950">{{ $selectedCharacter->name }} / Lv {{ $selectedCharacter->level }} / {{ $selectedCharacter->currentJob?->name ?? '-' }}</div>
                        <div class="mt-2 grid grid-cols-4 gap-2 text-xs font-black text-slate-600">
                            <span>HP {{ $selectedCharacterStats['max_hp'] ?? '-' }}</span>
                            <span>ATK {{ $selectedCharacterStats['str'] ?? '-' }}</span>
                            <span>DEF {{ $selectedCharacterStats['def'] ?? '-' }}</span>
                            <span>SPD {{ $selectedCharacterStats['agi'] ?? '-' }}</span>
                            <span>MAG {{ $selectedCharacterStats['mag'] ?? '-' }}</span>
                            <span>SPR {{ $selectedCharacterStats['spr'] ?? '-' }}</span>
                            <span>LUK {{ $selectedCharacterStats['luk'] ?? '-' }}</span>
                            <span>MP {{ $selectedCharacterStats['max_mp'] ?? '-' }}</span>
                        </div>
                    </div>
                @else
                    <div class="mt-3 rounded bg-slate-50 p-4 text-sm font-bold text-slate-500">キャラクターを選択してください。</div>
                @endif
            </div>
            <div>
                <h2 class="text-lg font-black text-slate-950">選択中の敵</h2>
                @if($selectedEnemy)
                    <div class="mt-3 rounded bg-slate-50 p-4 text-sm">
                        <div class="font-black text-slate-950">{{ $selectedEnemy->name }} / Lv {{ $selectedEnemy->level }}</div>
                        <div class="mt-2 grid grid-cols-4 gap-2 text-xs font-black text-slate-600">
                            <span>HP {{ $selectedEnemy->max_hp }}</span>
                            <span>ATK {{ $selectedEnemy->str }}</span>
                            <span>DEF {{ $selectedEnemy->def }}</span>
                            <span>SPD {{ $selectedEnemy->agi }}</span>
                            <span>MAG {{ $selectedEnemy->mag }}</span>
                            <span>SPR {{ $selectedEnemy->spr ?? $selectedEnemy->def }}</span>
                            <span>LUK {{ $selectedEnemy->luk ?? 10 }}</span>
                            <span>{{ $selectedEnemy->type_name ?? '-' }}</span>
                        </div>
                    </div>
                @else
                    <div class="mt-3 rounded bg-slate-50 p-4 text-sm font-bold text-slate-500">敵を選択してください。</div>
                @endif
            </div>
            <div>
                <label class="block text-sm font-black text-slate-700">試行回数</label>
                <input type="number" min="1" max="100" wire:model="simulationCount" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                <label class="mt-3 flex items-center gap-2 text-sm font-bold text-slate-600">
                    <input type="checkbox" wire:model="startWithFullHp" class="rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                    毎回HP/SP全快で開始
                </label>
                <button type="button" wire:click="runSimulation" wire:loading.attr="disabled" class="mt-4 w-full rounded-md bg-slate-950 px-4 py-3 text-sm font-black text-white shadow hover:bg-slate-800 disabled:opacity-50">
                    <span wire:loading.remove>シミュレーション実行</span>
                    <span wire:loading>実行中...</span>
                </button>
                @error('selectedCharacterId') <div class="mt-2 text-xs font-bold text-red-600">{{ $message }}</div> @enderror
                @error('selectedEnemyId') <div class="mt-2 text-xs font-bold text-red-600">{{ $message }}</div> @enderror
                @error('simulationCount') <div class="mt-2 text-xs font-bold text-red-600">{{ $message }}</div> @enderror
            </div>
        </div>
    </section>

    @if($summary)
        <div class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4 xl:grid-cols-8">
            @foreach([
                '試行回数' => number_format($summary['total']),
                '勝率' => number_format($summary['win_rate'], 1) . '%',
                '敗北率' => number_format($summary['defeat_rate'], 1) . '%',
                '勝利' => number_format($summary['wins']),
                '敗北' => number_format($summary['defeats']),
                '平均ターン' => number_format($summary['avg_turns'], 1),
                '平均残HP' => number_format($summary['avg_hp_after'], 1),
                '平均J-EXP' => number_format($summary['avg_job_exp'], 1),
            ] as $label => $value)
                <div class="rounded-md bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <div class="text-xs font-black text-slate-500">{{ $label }}</div>
                    <div class="mt-2 text-xl font-black text-slate-950">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
            <section class="overflow-hidden rounded-md bg-white shadow-sm ring-1 ring-slate-200">
                <div class="border-b border-slate-200 px-4 py-3">
                    <h2 class="text-lg font-black text-slate-950">試行結果</h2>
                </div>
                <div class="max-h-[520px] overflow-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-xs font-black text-slate-700">
                            <tr>
                                <th class="px-4 py-3 text-left">#</th>
                                <th class="px-4 py-3 text-left">結果</th>
                                <th class="px-4 py-3 text-right">残HP</th>
                                <th class="px-4 py-3 text-right">残SP</th>
                                <th class="px-4 py-3 text-right">ターン</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($runs as $run)
                                <tr class="hover:bg-slate-50">
                                    <td class="px-4 py-3 font-bold text-slate-500">{{ $run['index'] }}</td>
                                    <td class="px-4 py-3 font-black {{ $run['result'] === 'victory' ? 'text-emerald-700' : ($run['result'] === 'defeat' ? 'text-red-700' : 'text-slate-700') }}">{{ $run['result'] }}</td>
                                    <td class="px-4 py-3 text-right font-bold">{{ number_format($run['player_hp_after']) }}</td>
                                    <td class="px-4 py-3 text-right font-bold">{{ number_format($run['player_mp_after']) }}</td>
                                    <td class="px-4 py-3 text-right font-bold">{{ number_format($run['turns']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-md bg-slate-950 p-5 shadow-sm ring-1 ring-slate-800">
                <h2 class="text-lg font-black text-white">サンプル戦闘ログ</h2>
                <div class="mt-4 max-h-[520px] overflow-auto rounded bg-black/30 p-4 text-sm leading-relaxed text-slate-100">
                    @foreach($sampleLogs as $line)
                        <div class="mb-2 whitespace-pre-wrap">{{ $line }}</div>
                    @endforeach
                </div>
            </section>
        </div>
    @endif
</div>
