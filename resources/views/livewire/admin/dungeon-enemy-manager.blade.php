<div class="w-full px-4 py-8 sm:px-6 lg:px-8">
    @php
        $fieldClass = 'w-full rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-900 shadow-inner focus:border-amber-400 focus:bg-white focus:ring-2 focus:ring-amber-200';
        $compactFieldClass = 'w-full rounded-md border border-slate-300 bg-slate-50 px-2 py-1.5 text-sm font-semibold text-slate-900 shadow-inner focus:border-amber-400 focus:bg-white focus:ring-2 focus:ring-amber-200';
        $statLabels = ['max_hp' => 'HP', 'str' => 'ATK', 'def' => 'DEF', 'agi' => 'SPD', 'mag' => 'MAG', 'spr' => 'SPR', 'luk' => 'LUK'];
        $rewardLabels = ['exp_reward' => 'EXP', 'gold_reward' => 'Gold', 'job_exp_reward' => '職EXP', 'appearance_weight' => '出現'];
    @endphp

    <div class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <div class="text-xs font-bold tracking-[0.35em] text-orange-600">DUNGEON ENEMY TUNING</div>
            <h1 class="mt-2 text-3xl font-black text-slate-950">ダンジョン別・敵データ調整</h1>
            <p class="mt-2 text-sm font-semibold text-slate-600">詰まりが見えるダンジョンから、敵ステータス・報酬・出現率を直接調整します。</p>
        </div>
        <button type="button" wire:click="createNew" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-black text-white shadow hover:bg-slate-800">
            選択中ダンジョンに敵を追加
        </button>
    </div>

    @if (session()->has('message'))
        <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800 shadow-sm">
            {{ session('message') }}
        </div>
    @endif

    <div class="grid gap-6 2xl:grid-cols-[360px_minmax(0,1fr)]">
        <aside class="rounded-md border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-black text-slate-950">ダンジョン</h2>
                <p class="mt-1 text-xs font-semibold text-slate-500">直近30日の敗北率つき</p>
            </div>
            <div class="max-h-[760px] overflow-y-auto p-3">
                @foreach($areas as $area)
                    <button
                        type="button"
                        wire:click="$set('selectedAreaId', {{ $area['id'] }})"
                        class="mb-2 w-full rounded-md border px-3 py-3 text-left transition {{ (int) $selectedAreaId === (int) $area['id'] ? 'border-amber-300 bg-amber-50 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50' }}"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-sm font-black text-slate-950">{{ $area['name'] }}</div>
                                <div class="mt-1 text-xs font-bold text-slate-500">{{ $area['city'] }} / {{ $area['recommended'] }}</div>
                            </div>
                            <div class="text-right text-xs font-black {{ $area['loss_rate'] >= 35 ? 'text-red-600' : ($area['loss_rate'] >= 20 ? 'text-amber-700' : 'text-slate-500') }}">
                                {{ $area['loss_rate'] }}%
                            </div>
                        </div>
                        <div class="mt-2 flex items-center justify-between text-[11px] font-bold text-slate-500">
                            <span>敵 {{ $area['enemy_count'] }}体</span>
                            <span>{{ $area['losses'] }}敗 / {{ $area['total'] }}戦</span>
                        </div>
                    </button>
                @endforeach
            </div>
        </aside>

        <main class="min-w-0 space-y-6">
            <section class="rounded-md border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-5 py-4">
                    <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h2 class="text-xl font-black text-slate-950">{{ $selectedArea?->name ?? 'ダンジョン未選択' }}</h2>
                            <p class="mt-1 text-xs font-bold text-slate-500">
                                {{ $selectedArea?->city?->name ?? '街未設定' }}
                                @if($selectedArea)
                                    / 推奨Lv {{ $selectedArea->recommended_level_min }}-{{ $selectedArea->recommended_level_max }}
                                @endif
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <div class="rounded-md bg-slate-50 px-3 py-2 text-center">
                                <div class="text-[11px] font-bold text-slate-500">7日戦闘</div>
                                <div class="text-lg font-black text-slate-950">{{ $areaSummary['total_7d'] }}</div>
                            </div>
                            <div class="rounded-md bg-slate-50 px-3 py-2 text-center">
                                <div class="text-[11px] font-bold text-slate-500">7日敗北率</div>
                                <div class="text-lg font-black {{ $areaSummary['loss_rate_7d'] >= 35 ? 'text-red-600' : 'text-slate-950' }}">{{ $areaSummary['loss_rate_7d'] }}%</div>
                            </div>
                            <div class="rounded-md bg-slate-50 px-3 py-2 text-center">
                                <div class="text-[11px] font-bold text-slate-500">30日戦闘</div>
                                <div class="text-lg font-black text-slate-950">{{ $areaSummary['total_30d'] }}</div>
                            </div>
                            <div class="rounded-md bg-slate-50 px-3 py-2 text-center">
                                <div class="text-[11px] font-bold text-slate-500">30日敗北率</div>
                                <div class="text-lg font-black {{ $areaSummary['loss_rate_30d'] >= 35 ? 'text-red-600' : 'text-slate-950' }}">{{ $areaSummary['loss_rate_30d'] }}%</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 text-left text-xs font-black uppercase tracking-wide text-slate-500">
                                <th class="min-w-[180px] border-b border-slate-200 px-4 py-3">敵</th>
                                <th class="border-b border-slate-200 px-3 py-3">Lv</th>
                                <th class="border-b border-slate-200 px-3 py-3">生成式</th>
                                <th class="border-b border-slate-200 px-3 py-3">HP</th>
                                <th class="border-b border-slate-200 px-3 py-3">ATK</th>
                                <th class="border-b border-slate-200 px-3 py-3">DEF</th>
                                <th class="border-b border-slate-200 px-3 py-3">SPD</th>
                                <th class="border-b border-slate-200 px-3 py-3">MAG/SPR</th>
                                <th class="border-b border-slate-200 px-3 py-3">報酬</th>
                                <th class="border-b border-slate-200 px-3 py-3">出現</th>
                                <th class="border-b border-slate-200 px-3 py-3">30日敗北率</th>
                                <th class="border-b border-slate-200 px-3 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($enemies as $enemy)
                                @php
                                    $metric = $enemyMetrics[$enemy->id] ?? ['total' => 0, 'losses' => 0, 'loss_rate' => 0];
                                    $preview = $statPreviews[$enemy->id] ?? null;
                                @endphp
                                <tr class="hover:bg-amber-50/30">
                                    <td class="px-4 py-3">
                                        <div class="font-black text-slate-950">{{ $enemy->name }}</div>
                                        <div class="mt-1 flex flex-wrap gap-1 text-[11px] font-bold text-slate-500">
                                            <span>#{{ $enemy->id }}</span>
                                            @if($enemy->is_boss)
                                                <span class="rounded bg-red-100 px-1.5 py-0.5 text-red-700">BOSS</span>
                                            @endif
                                            @if($enemy->type_name)
                                                <span>{{ $enemy->type_name }}</span>
                                            @endif
                                            @if($preview)
                                                <span class="rounded px-1.5 py-0.5 {{ $preview['is_stat_locked'] ? 'bg-slate-100 text-slate-600' : 'bg-emerald-100 text-emerald-700' }}">
                                                    {{ $preview['is_stat_locked'] ? 'LOCK' : 'UNLOCK' }}
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 font-bold">{{ $enemy->level }}</td>
                                    <td class="px-3 py-3 text-xs font-bold">
                                        @if($preview)
                                            <div>Lv {{ $preview['generated_level'] }}</div>
                                            <div class="mt-1 text-[11px] text-slate-500">{{ $preview['metadata']['family_key'] }} / {{ $preview['metadata']['variant_key'] }} / {{ $preview['metadata']['role_key'] }}</div>
                                            <button type="button" wire:click="toggleStatLock({{ $enemy->id }})" class="mt-2 rounded border border-slate-300 px-2 py-1 text-[11px] font-black text-slate-700 hover:bg-slate-50">
                                                {{ $preview['is_stat_locked'] ? 'ロック解除' : 'ロック' }}
                                            </button>
                                            <button type="button" wire:click="applyGeneratedStats({{ $enemy->id }})" class="mt-2 rounded bg-emerald-600 px-2 py-1 text-[11px] font-black text-white hover:bg-emerald-500">
                                                反映
                                            </button>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 font-bold">{{ number_format($enemy->max_hp) }}</td>
                                    <td class="px-3 py-3 font-bold">{{ number_format($enemy->str) }}</td>
                                    <td class="px-3 py-3 font-bold">{{ number_format($enemy->def) }}</td>
                                    <td class="px-3 py-3 font-bold">{{ number_format($enemy->agi) }}</td>
                                    <td class="px-3 py-3 text-xs font-bold">MAG {{ $enemy->mag }}<br>SPR {{ $enemy->spr ?? 0 }}</td>
                                    <td class="px-3 py-3 text-xs font-bold">EXP {{ $enemy->exp_reward }}<br>Gold {{ $enemy->gold_reward }} / 職 {{ $enemy->job_exp_reward ?? 0 }}</td>
                                    <td class="px-3 py-3 font-bold">{{ $enemy->appearance_weight }}</td>
                                    <td class="px-3 py-3">
                                        <div class="font-black {{ $metric['loss_rate'] >= 35 ? 'text-red-600' : ($metric['loss_rate'] >= 20 ? 'text-amber-700' : 'text-slate-700') }}">{{ $metric['loss_rate'] }}%</div>
                                        <div class="text-[11px] font-bold text-slate-500">{{ $metric['losses'] }}敗 / {{ $metric['total'] }}戦</div>
                                    </td>
                                    <td class="px-3 py-3 text-right">
                                        <button type="button" wire:click="edit({{ $enemy->id }})" class="rounded-md bg-slate-900 px-3 py-2 text-xs font-black text-white hover:bg-slate-700">
                                            編集
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="12" class="px-4 py-8 text-center text-sm font-bold text-slate-500">このダンジョンには敵が登録されていません。</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
                <section class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-black text-slate-950">{{ $editingEnemyId ? '敵データ編集' : '敵データ追加' }}</h2>
                    <form wire:submit.prevent="save" class="mt-4 space-y-4">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="sm:col-span-2">
                                <label class="mb-1 block text-xs font-bold text-slate-600">敵名</label>
                                <input type="text" wire:model="form.name" class="{{ $fieldClass }}">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-bold text-slate-600">Lv</label>
                                <input type="number" wire:model="form.level" class="{{ $fieldClass }}">
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-2 md:grid-cols-7">
                            @foreach($statLabels as $field => $label)
                                <div>
                                    <label class="mb-1 block text-xs font-bold text-slate-600">{{ $label }}</label>
                                    <input type="number" wire:model="form.{{ $field }}" class="{{ $compactFieldClass }}">
                                </div>
                            @endforeach
                        </div>

                        <div class="rounded-md border border-emerald-100 bg-emerald-50/60 p-3">
                            <div class="mb-3 flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-black text-emerald-900">自動生成メタ</div>
                                    <div class="mt-1 text-[11px] font-bold text-emerald-700">ロック中の敵は生成反映で上書きされません。</div>
                                </div>
                                <label class="inline-flex items-center gap-2 text-xs font-black text-emerald-900">
                                    <input type="checkbox" wire:model="form.is_stat_locked" class="rounded border-emerald-400 text-emerald-700 focus:ring-emerald-300">
                                    ロック
                                </label>
                            </div>
                            <div class="grid gap-2 sm:grid-cols-4">
                                <div>
                                    <label class="mb-1 block text-xs font-bold text-slate-600">生成Lv</label>
                                    <input type="number" wire:model="form.enemy_level" class="{{ $compactFieldClass }}" placeholder="未指定なら現Lv">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-bold text-slate-600">種族</label>
                                    <select wire:model="form.family_key" class="{{ $compactFieldClass }}">
                                        @foreach($statGenerationOptions['families'] as $key)
                                            <option value="{{ $key }}">{{ $key }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-bold text-slate-600">変種</label>
                                    <select wire:model="form.variant_key" class="{{ $compactFieldClass }}">
                                        @foreach($statGenerationOptions['variants'] as $key)
                                            <option value="{{ $key }}">{{ $key }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-bold text-slate-600">役割</label>
                                    <select wire:model="form.role_key" class="{{ $compactFieldClass }}">
                                        @foreach($statGenerationOptions['roles'] as $key)
                                            <option value="{{ $key }}">{{ $key }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="mt-2">
                                <label class="mb-1 block text-xs font-bold text-slate-600">調整メモ</label>
                                <textarea wire:model="form.manual_adjustment_note" rows="2" class="{{ $compactFieldClass }}"></textarea>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
                            @foreach($rewardLabels as $field => $label)
                                <div>
                                    <label class="mb-1 block text-xs font-bold text-slate-600">{{ $label }}</label>
                                    <input type="number" wire:model="form.{{ $field }}" class="{{ $compactFieldClass }}">
                                </div>
                            @endforeach
                        </div>

                        <div class="grid gap-3 sm:grid-cols-5">
                            @foreach(['role' => '役割', 'type_name' => '型', 'element' => '属性', 'action_pattern' => '行動', 'drop_type' => 'ドロップ'] as $field => $label)
                                <div>
                                    <label class="mb-1 block text-xs font-bold text-slate-600">{{ $label }}</label>
                                    <input type="text" wire:model="form.{{ $field }}" class="{{ $compactFieldClass }}">
                                </div>
                            @endforeach
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="inline-flex items-center gap-2 text-sm font-bold text-slate-700">
                                <input type="checkbox" wire:model="form.is_boss" class="rounded border-slate-400 text-amber-600 focus:ring-amber-400">
                                BOSSとして扱う
                            </label>
                            <div>
                                <label class="mb-1 block text-xs font-bold text-slate-600">並び順</label>
                                <input type="number" wire:model="form.sort_order" class="{{ $compactFieldClass }}">
                            </div>
                        </div>

                        <div class="flex gap-2 pt-2">
                            <button type="submit" class="flex-1 rounded-md bg-amber-500 px-4 py-2.5 text-sm font-black text-slate-950 shadow hover:bg-amber-400">
                                {{ $editingEnemyId ? '更新する' : '追加する' }}
                            </button>
                            <button type="button" wire:click="resetForm" class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-black text-slate-700 hover:bg-slate-50">
                                クリア
                            </button>
                        </div>
                    </form>
                </section>

                <section class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-black text-slate-950">ダンジョン一括倍率</h2>
                    <p class="mt-1 text-xs font-semibold text-slate-500">選択ダンジョン内の敵に倍率をかけます。100%は変更なしです。</p>

                    <form wire:submit.prevent="applyAreaScale" class="mt-4 space-y-4">
                        <div class="grid grid-cols-2 gap-2">
                            @foreach(['hp_rate' => 'HP', 'str_rate' => 'ATK', 'def_rate' => 'DEF', 'agi_rate' => 'SPD', 'mag_rate' => 'MAG', 'spr_rate' => 'SPR', 'luk_rate' => 'LUK', 'reward_rate' => '報酬'] as $field => $label)
                                <div>
                                    <label class="mb-1 block text-xs font-bold text-slate-600">{{ $label }}倍率</label>
                                    <div class="flex items-center gap-2">
                                        <input type="number" wire:model="bulkForm.{{ $field }}" class="{{ $compactFieldClass }}">
                                        <span class="text-xs font-black text-slate-500">%</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <label class="inline-flex items-center gap-2 text-sm font-bold text-slate-700">
                            <input type="checkbox" wire:model="bulkForm.include_boss" class="rounded border-slate-400 text-amber-600 focus:ring-amber-400">
                            BOSSにも適用する
                        </label>

                        <button type="submit" class="w-full rounded-md bg-slate-950 px-4 py-2.5 text-sm font-black text-white shadow hover:bg-slate-800">
                            一括倍率を適用
                        </button>
                    </form>
                </section>
            </div>
        </main>
    </div>
</div>
