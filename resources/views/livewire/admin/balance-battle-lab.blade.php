<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">BALANCE LAB</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">仮想バランス検証</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">Lv1基礎値から職業・Lv・BP・装備を仮組みし、敵との平均ダメージと想定ターンを確認します。</p>
        </div>
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-xs font-bold text-amber-800">
            DBへ保存されません
        </div>
    </div>

    <section class="mb-6 rounded-md bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <div class="mb-3 flex items-center justify-between gap-3">
            <h2 class="text-sm font-black text-slate-950">設定スロット</h2>
            <p class="text-xs font-bold text-slate-500">職業・Lv・BP・装備・敵を3枠まで保持します。</p>
        </div>
        <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
            @for($slot = 1; $slot <= 3; $slot++)
                @php $preset = $presets[$slot] ?? null; @endphp
                <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-sm font-black text-slate-950">設定 {{ $slot }}</div>
                            @if($preset)
                                <div class="mt-1 text-xs font-bold text-slate-500">
                                    {{ $preset['jobName'] ?? '職業未設定' }} / Lv{{ $preset['playerLevel'] ?? '-' }}
                                    @if(!empty($preset['enemyName']))
                                        / {{ $preset['enemyName'] }}
                                    @endif
                                </div>
                                <div class="mt-1 text-[11px] font-bold text-slate-400">{{ $preset['saved_at'] ?? '' }}</div>
                            @else
                                <div class="mt-1 text-xs font-bold text-slate-400">未保存</div>
                            @endif
                        </div>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <button type="button" wire:click="savePreset({{ $slot }})" class="flex-1 rounded-md bg-slate-950 px-3 py-2 text-xs font-black text-white hover:bg-slate-800">
                            保存
                        </button>
                        <button type="button" wire:click="loadPreset({{ $slot }})" @disabled(!$preset) class="flex-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-black text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40">
                            読込
                        </button>
                        <button type="button" wire:click="clearPreset({{ $slot }})" @disabled(!$preset) class="rounded-md border border-red-200 bg-white px-3 py-2 text-xs font-black text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-40">
                            削除
                        </button>
                    </div>
                </div>
            @endfor
        </div>
    </section>

    <div class="grid grid-cols-1 gap-6 2xl:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
        <section class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-black text-slate-950">仮想プレイヤー</h2>
                <div class="text-xs font-black text-slate-500">BP {{ number_format($bpSpent) }} / 目安 {{ number_format($bpAvailable) }}</div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <label class="block">
                    <span class="text-xs font-black text-slate-600">職業</span>
                    <select wire:model.live="selectedJobId" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-bold">
                        @foreach($jobs as $job)
                            <option value="{{ $job->id }}">{{ $job->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-black text-slate-600">プレイヤーLv</span>
                    <input type="number" min="1" max="255" wire:model.live="playerLevel" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-bold">
                </label>
                <label class="block">
                    <span class="text-xs font-black text-slate-600">職業ランク</span>
                    <input type="number" min="1" max="50" wire:model.live="jobRank" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-bold">
                </label>
            </div>

            <div class="mt-5 rounded-md border border-amber-200 bg-amber-50 p-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="text-sm font-black text-amber-900">転職シミュレーション</div>
                        <p class="mt-1 text-xs font-bold leading-relaxed text-amber-800">
                            現在の素能力（Lv成長・職業ランク・BP込み、装備抜き）を半分にし、そこから選択職業で再成長させます。
                        </p>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <button type="button" wire:click="captureJobChangeBase" class="rounded-md bg-amber-600 px-3 py-2 text-xs font-black text-white shadow hover:bg-amber-700">
                            現在能力から転職
                        </button>
                        <button type="button" wire:click="resetSimulation" class="rounded-md border border-amber-300 bg-white px-3 py-2 text-xs font-black text-amber-800 hover:bg-amber-100">
                            リセット
                        </button>
                    </div>
                </div>

                @if($jobChangeMode)
                    <div class="mt-4 rounded-md bg-white p-3 ring-1 ring-amber-200">
                        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-xs font-black text-amber-700">転職後ベース適用中</div>
                            <div class="text-xs font-bold text-slate-500">転職元: {{ $jobChangeSourceLabel ?? '-' }}</div>
                        </div>
                        <div class="mt-3 grid grid-cols-4 gap-2 md:grid-cols-8">
                            @foreach([
                                'max_hp' => 'HP',
                                'max_mp' => 'SP',
                                'str' => '攻',
                                'def' => '防',
                                'agi' => '敏',
                                'mag' => '魔',
                                'spr' => '精',
                                'luk' => '運',
                            ] as $key => $label)
                                <div class="rounded bg-amber-50 px-2 py-1 text-center">
                                    <div class="text-[10px] font-black text-amber-700">{{ $label }}</div>
                                    <div class="text-sm font-black text-slate-950">{{ number_format($jobChangeBaseStats[$key] ?? 0) }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="mt-5 rounded-md border border-slate-200 bg-slate-50 p-4">
                <div class="mb-3 text-sm font-black text-slate-800">BP割り振り</div>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    @foreach([
                        'hp' => 'HP',
                        'mp' => 'SP',
                        'str' => '攻撃',
                        'def' => '防御',
                        'agi' => '敏捷',
                        'mag' => '魔力',
                        'spr' => '精神',
                        'luk' => '運',
                    ] as $key => $label)
                        <label class="block">
                            <span class="text-xs font-black text-slate-500">{{ $label }}</span>
                            <input type="number" min="0" max="999" wire:model.live="bp.{{ $key }}" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-bold">
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <label class="block">
                    <span class="text-xs font-black text-slate-600">武器</span>
                    <select wire:model.live="weaponId" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-bold">
                        <option value="">なし</option>
                        @foreach($weapons as $item)
                            <option value="{{ $item->id }}">#{{ $item->id }} {{ $item->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-black text-slate-600">防具</span>
                    <select wire:model.live="armorId" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-bold">
                        <option value="">なし</option>
                        @foreach($armors as $item)
                            <option value="{{ $item->id }}">#{{ $item->id }} {{ $item->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-black text-slate-600">装飾</span>
                    <select wire:model.live="accessoryId" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-bold">
                        <option value="">なし</option>
                        @foreach($accessories as $item)
                            <option value="{{ $item->id }}">#{{ $item->id }} {{ $item->name }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="mt-4 max-w-xs">
                <label class="block">
                    <span class="text-xs font-black text-slate-600">武器強化</span>
                    <select wire:model.live="weaponEnhance" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-bold">
                        <option value="0">+0</option>
                        <option value="1">+1</option>
                        <option value="2">+2</option>
                        <option value="3">+3</option>
                    </select>
                </label>
            </div>

            <div class="mt-5 rounded-md border border-slate-200 bg-white p-4">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h3 class="text-sm font-black text-slate-950">計算後ステータス</h3>
                    @if($selectedJob)
                        <span class="rounded bg-slate-100 px-2 py-1 text-xs font-black text-slate-600">{{ $selectedJob->name }}</span>
                    @endif
                </div>
                <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
                    @foreach([
                        'max_hp' => 'HP',
                        'max_mp' => 'SP',
                        'str' => '攻撃',
                        'def' => '防御',
                        'agi' => '敏捷',
                        'mag' => '魔力',
                        'spr' => '精神',
                        'luk' => '運',
                    ] as $key => $label)
                        <div class="rounded-md bg-slate-50 px-3 py-2">
                            <div class="text-[11px] font-black text-slate-500">{{ $label }}</div>
                            <div class="mt-1 text-lg font-black text-slate-950">{{ number_format($playerStats[$key] ?? 0) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <h2 class="text-lg font-black text-slate-950">敵選択</h2>
                <input type="text" wire:model.live.debounce.300ms="enemySearch" placeholder="敵名・ID・街・ダンジョン" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
            </div>

            <div class="mt-4 max-h-72 space-y-2 overflow-auto rounded-md border border-slate-200 bg-slate-50 p-2">
                @foreach($enemyCandidates as $enemy)
                    @php $active = (int) $selectedEnemyId === (int) $enemy->id; @endphp
                    <button type="button" wire:click="selectEnemy({{ $enemy->id }})" class="w-full rounded-md border px-3 py-2 text-left transition {{ $active ? 'border-amber-400 bg-amber-50' : 'border-slate-200 bg-white hover:bg-slate-50' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-sm font-black text-slate-950">#{{ $enemy->id }} {{ $enemy->name }}</div>
                                <div class="mt-1 text-xs font-bold text-slate-500">{{ $enemy->area?->city?->name ?? '-' }} / {{ $enemy->area?->name ?? '-' }}</div>
                            </div>
                            <div class="shrink-0 text-right text-xs font-black text-slate-600">
                                <div>Lv {{ $enemy->level }}</div>
                                <div>{{ $enemy->is_boss ? 'BOSS' : '通常' }}</div>
                            </div>
                        </div>
                    </button>
                @endforeach
            </div>

            @if($selectedEnemy)
                <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-4">
                    <div class="font-black text-slate-950">{{ $selectedEnemy->name }} / Lv {{ $selectedEnemy->level }}</div>
                    <div class="mt-2 grid grid-cols-2 gap-2 text-xs font-black text-slate-600 md:grid-cols-4">
                        <span>HP {{ number_format($selectedEnemy->max_hp) }}</span>
                        <span>攻 {{ number_format($selectedEnemy->str) }}</span>
                        <span>防 {{ number_format($selectedEnemy->def) }}</span>
                        <span>速 {{ number_format($selectedEnemy->agi) }}</span>
                        <span>魔 {{ number_format($selectedEnemy->mag) }}</span>
                        <span>精 {{ number_format($selectedEnemy->spr ?? $selectedEnemy->def) }}</span>
                        <span>運 {{ number_format($selectedEnemy->luk ?? 10) }}</span>
                        <span>{{ $selectedEnemy->type_name ?? '-' }}</span>
                    </div>
                </div>
            @endif

            <button type="button" wire:click="runSimulation" wire:loading.attr="disabled" class="mt-5 w-full rounded-md bg-slate-950 px-4 py-3 text-sm font-black text-white shadow hover:bg-slate-800 disabled:opacity-50">
                <span wire:loading.remove>疑似対戦する</span>
                <span wire:loading>計算中...</span>
            </button>
            @error('selectedEnemyId') <div class="mt-2 text-xs font-bold text-red-600">{{ $message }}</div> @enderror

            @if($result)
                <div class="mt-5 rounded-md p-4 ring-1 {{ $result['judgement']['class'] }}">
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-sm font-black">判定</div>
                        <div class="text-xl font-black">{{ $result['judgement']['label'] }}</div>
                    </div>
                    <p class="mt-2 text-sm font-bold">{{ $result['judgement']['message'] }}</p>
                </div>

                <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                    @foreach([
                        '撃破ターン' => $result['turns_to_defeat_enemy'],
                        '敗北ターン' => $result['turns_to_defeat_player'],
                        '余裕度' => $result['margin'],
                        '有効攻撃' => $result['player_attack_type'],
                    ] as $label => $value)
                        <div class="rounded-md bg-white p-4 shadow-sm ring-1 ring-slate-200">
                            <div class="text-xs font-black text-slate-500">{{ $label }}</div>
                            <div class="mt-2 text-xl font-black text-slate-950">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <div class="rounded-md border border-slate-200 bg-slate-50 p-4">
                        <h3 class="text-sm font-black text-slate-950">プレイヤー → 敵</h3>
                        <div class="mt-3 space-y-2 text-sm font-bold text-slate-700">
                            <div class="flex justify-between"><span>物理平均</span><span>{{ number_format($result['player_physical_damage']) }}</span></div>
                            <div class="flex justify-between"><span>魔法平均</span><span>{{ number_format($result['player_magical_damage']) }}</span></div>
                            <div class="flex justify-between border-t border-slate-200 pt-2 text-slate-950"><span>採用ダメージ</span><span>{{ number_format($result['player_damage']) }}</span></div>
                        </div>
                    </div>
                    <div class="rounded-md border border-slate-200 bg-slate-50 p-4">
                        <h3 class="text-sm font-black text-slate-950">敵 → プレイヤー</h3>
                        <div class="mt-3 space-y-2 text-sm font-bold text-slate-700">
                            <div class="flex justify-between"><span>物理平均</span><span>{{ number_format($result['enemy_physical_damage']) }}</span></div>
                            <div class="flex justify-between"><span>魔法平均</span><span>{{ number_format($result['enemy_magical_damage']) }}</span></div>
                            <div class="flex justify-between border-t border-slate-200 pt-2 text-slate-950"><span>採用ダメージ</span><span>{{ number_format($result['enemy_damage']) }}</span></div>
                        </div>
                    </div>
                </div>
            @endif
        </section>
    </div>
</div>
