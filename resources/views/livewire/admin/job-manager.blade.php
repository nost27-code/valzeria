<div class="w-full px-4 sm:px-6 lg:px-8 py-8">
    @php
        $fieldClass = 'w-full rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-900 shadow-inner placeholder:text-slate-400 focus:border-[#d4af37] focus:bg-white focus:ring-2 focus:ring-[#d4af37]/30';
        $compactFieldClass = 'w-full rounded-md border border-slate-300 bg-slate-50 px-2 py-1.5 text-sm text-slate-900 shadow-inner placeholder:text-slate-400 focus:border-[#d4af37] focus:bg-white focus:ring-2 focus:ring-[#d4af37]/30';
        $checkboxClass = 'rounded border-slate-400 bg-white text-[#1e40af] focus:ring-[#d4af37]';
        $rankLabels = [
            'normal' => '基本',
            'middle' => '中級',
            'advanced' => '上級',
            'legend' => '伝説',
        ];
        $requirementLabels = [
            'master_job' => '職業マスター',
            'character_level' => 'キャラLv',
            'item' => 'アイテム',
            'title' => '称号',
            'quest' => 'クエスト',
            'event_flag' => 'イベント',
        ];
        $damageTypeLabels = [
            'physical' => '物理',
            'magical' => '魔法',
            'hybrid' => '複合',
            'heal' => '回復',
            'support' => '支援',
            'gold' => '獲得補正',
            'drop' => 'ドロップ補正',
        ];
    @endphp

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-bold text-[#1e293b]">職業管理</h1>
            <p class="text-sm text-gray-500 mt-1">職業マスタ、転職条件、職業ごとの必殺技を編集します。保存内容は転職所と戦闘に反映されます。</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button wire:click="createNew('normal')" class="px-3 py-2 rounded bg-blue-600 text-white text-sm font-bold shadow hover:bg-blue-700">基本職を追加</button>
            <button wire:click="createNew('advanced')" class="px-3 py-2 rounded bg-slate-700 text-white text-sm font-bold shadow hover:bg-slate-800">上級職を追加</button>
            <button wire:click="createNew('legend')" class="px-3 py-2 rounded bg-amber-600 text-white text-sm font-bold shadow hover:bg-amber-700">伝説職を追加</button>
        </div>
    </div>

    @if (session()->has('message'))
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded shadow-sm">
            {{ session('message') }}
        </div>
    @endif

    <div class="grid grid-cols-1 2xl:grid-cols-12 gap-6">
        <div class="2xl:col-span-5 bg-white p-5 rounded-lg shadow border-t-4 border-[#d4af37] h-fit">
            <h2 class="text-xl font-bold text-gray-800 mb-4">{{ $editingJobId ? '職業を編集' : '新しい職業を追加' }}</h2>

            <form wire:submit.prevent="save" class="space-y-6">
                <section class="space-y-4">
                    <h3 class="text-sm font-extrabold text-slate-600 border-b pb-2">基本設定</h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">管理キー</label>
                            <input type="text" wire:model="form.key" placeholder="warrior" class="{{ $compactFieldClass }}">
                            @error('form.key') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">職業名</label>
                            <input type="text" wire:model="form.name" class="{{ $compactFieldClass }}">
                            @error('form.name') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">職業ランク</label>
                            <select wire:model="form.rank" class="{{ $compactFieldClass }}">
                                @foreach($rankLabels as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">カテゴリ</label>
                            <input type="text" wire:model="form.category" placeholder="物理 / 魔法 / 回復 など" class="{{ $compactFieldClass }}">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">最大職業Lv</label>
                            <input type="number" wire:model="form.max_job_level" class="{{ $compactFieldClass }}">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">並び順</label>
                            <input type="number" wire:model="form.sort_order" class="{{ $compactFieldClass }}">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">説明</label>
                        <textarea wire:model="form.description" rows="2" class="{{ $fieldClass }}"></textarea>
                    </div>

                    <div class="flex flex-wrap gap-4 text-sm font-bold">
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" wire:model="form.is_active" class="{{ $checkboxClass }}">
                            転職所に出す
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" wire:model="form.is_hidden" class="{{ $checkboxClass }}">
                            条件未達時は隠す
                        </label>
                    </div>
                </section>

                <section class="space-y-4">
                    <h3 class="text-sm font-extrabold text-slate-600 border-b pb-2">成長倍率</h3>
                    <div class="grid grid-cols-4 gap-2">
                        @foreach(['hp_rate' => 'HP', 'mp_rate' => 'SP', 'atk_rate' => 'ATK', 'def_rate' => 'DEF', 'mag_rate' => 'MAG', 'spr_rate' => 'SPR', 'spd_rate' => 'SPD', 'luck_rate' => 'LUK'] as $field => $label)
                            <div>
                                <label class="block text-xs font-bold text-gray-600 mb-1">{{ $label }}</label>
                                <input type="number" wire:model="form.{{ $field }}" class="{{ $compactFieldClass }}">
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="space-y-4">
                    <h3 class="text-sm font-extrabold text-slate-600 border-b pb-2">職業Lvボーナス</h3>
                    <div class="grid grid-cols-4 gap-2">
                        @foreach(['bonus_hp' => 'HP', 'bonus_mp' => 'SP', 'bonus_str' => 'ATK', 'bonus_def' => 'DEF', 'bonus_mag' => 'MAG', 'bonus_spr' => 'SPR', 'bonus_spd' => 'SPD', 'bonus_luk' => 'LUK'] as $field => $label)
                            <div>
                                <label class="block text-xs font-bold text-gray-600 mb-1">{{ $label }}</label>
                                <input type="number" wire:model="form.{{ $field }}" class="{{ $compactFieldClass }}">
                            </div>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        @foreach(['bonus_gold_rate' => 'Gold%', 'bonus_drop_rate' => 'Drop%', 'bonus_critical_rate' => '会心%', 'special_skill_rate' => '技率%'] as $field => $label)
                            <div>
                                <label class="block text-xs font-bold text-gray-600 mb-1">{{ $label }}</label>
                                <input type="number" wire:model="form.{{ $field }}" class="{{ $compactFieldClass }}">
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="space-y-4">
                    <div class="flex items-center justify-between border-b pb-2">
                        <h3 class="text-sm font-extrabold text-slate-600">転職条件</h3>
                        <button type="button" wire:click="addRequirement" class="px-3 py-1.5 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold border">条件を追加</button>
                    </div>

                    @forelse($requirementRows as $index => $row)
                        <div class="rounded-md border border-slate-200 p-3 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold text-slate-500">条件 {{ $index + 1 }}</span>
                                <button type="button" wire:click="removeRequirement({{ $index }})" class="text-xs font-bold text-red-600 hover:text-red-700">削除</button>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-xs font-bold text-gray-600 mb-1">条件タイプ</label>
                                    <select wire:model="requirementRows.{{ $index }}.requirement_type" class="{{ $compactFieldClass }}">
                                        @foreach($requirementLabels as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-600 mb-1">必要職業</label>
                                    <select wire:model="requirementRows.{{ $index }}.required_job_id" class="{{ $compactFieldClass }}">
                                        <option value="">指定なし</option>
                                        @foreach($allJobs as $job)
                                            <option value="{{ $job->id }}">{{ $job->id }}: {{ $job->name }} / {{ $rankLabels[$job->rank] ?? $job->rank }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-600 mb-1">必要値</label>
                                    <input type="number" wire:model="requirementRows.{{ $index }}.required_value" placeholder="LvやアイテムID" class="{{ $compactFieldClass }}">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-600 mb-1">キー</label>
                                    <input type="text" wire:model="requirementRows.{{ $index }}.required_key" placeholder="title_key など" class="{{ $compactFieldClass }}">
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-sm text-slate-500 bg-slate-50 border border-dashed border-slate-300 rounded-md p-3">
                            条件なし。基本職は条件なしで転職候補になります。
                        </div>
                    @endforelse
                </section>

                <section class="space-y-4">
                    <h3 class="text-sm font-extrabold text-slate-600 border-b pb-2">職業ごとの技</h3>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">技名</label>
                        <input type="text" wire:model="skillForm.name" placeholder="空欄で技なし" class="{{ $fieldClass }}">
                        @error('skillForm.name') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">発動率%</label>
                            <input type="number" wire:model="skillForm.activation_rate" class="{{ $compactFieldClass }}">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">固定SP</label>
                            <input type="number" wire:model="skillForm.sp_cost_base" class="{{ $compactFieldClass }}">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">SP係数</label>
                            <input type="number" step="0.0001" wire:model="skillForm.sp_cost_rate" class="{{ $compactFieldClass }}">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">旧SP消費</label>
                            <input type="number" wire:model="skillForm.mp_cost" class="{{ $compactFieldClass }}">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">タイプ</label>
                            <select wire:model="skillForm.damage_type" class="{{ $compactFieldClass }}">
                                @foreach($damageTypeLabels as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">倍率</label>
                            <input type="number" step="0.01" wire:model="skillForm.power_multiplier" class="{{ $compactFieldClass }}">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Hit数</label>
                            <input type="number" wire:model="skillForm.hit_count" class="{{ $compactFieldClass }}">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">回復%</label>
                            <input type="number" wire:model="skillForm.heal_percent" class="{{ $compactFieldClass }}">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        @foreach([
                            'self_damage_percent' => '反動%',
                            'gold_bonus_percent' => 'Gold%',
                            'drop_bonus_percent' => 'Drop%',
                            'def_ignore_percent' => '防御無視%',
                            'damage_reduction_percent' => '軽減%',
                            'enemy_def_down_percent' => '敵DEF低下%',
                            'enemy_spr_down_percent' => '敵SPR低下%',
                            'enemy_spd_down_percent' => '敵SPD低下%',
                            'mp_recover_percent' => 'SP回復%',
                        ] as $field => $label)
                            <div>
                                <label class="block text-xs font-bold text-gray-600 mb-1">{{ $label }}</label>
                                <input type="number" wire:model="skillForm.{{ $field }}" class="{{ $compactFieldClass }}">
                            </div>
                        @endforeach
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">技説明</label>
                        <textarea wire:model="skillForm.description" rows="2" class="{{ $fieldClass }}"></textarea>
                    </div>
                </section>

                <div class="flex gap-2 pt-2">
                    <button type="submit" class="flex-1 bg-[#1e40af] hover:bg-[#1e3a8a] text-white font-bold py-2 rounded shadow">
                        {{ $editingJobId ? '更新する' : '追加する' }}
                    </button>
                    <button type="button" wire:click="resetForm" class="px-4 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-2 rounded border">
                        クリア
                    </button>
                </div>
            </form>
        </div>

        <div class="2xl:col-span-7 bg-white p-5 rounded-lg shadow">
            <div class="flex flex-col lg:flex-row gap-3 lg:items-center lg:justify-between mb-4">
                <h2 class="text-xl font-bold text-gray-800">登録済み職業</h2>
                <div class="flex flex-col sm:flex-row gap-2">
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="名前・キーで検索" class="{{ $fieldClass }}">
                    <select wire:model.live="rankFilter" class="{{ $compactFieldClass }}">
                        <option value="all">全ランク</option>
                        @foreach($rankLabels as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="perPage" class="{{ $compactFieldClass }}">
                        <option value="30">30件</option>
                        <option value="60">60件</option>
                        <option value="100">100件</option>
                    </select>
                </div>
            </div>

            <div class="mb-3 text-xs font-bold text-slate-500">
                {{ number_format($jobs->total()) }} 件中 {{ number_format($jobs->firstItem() ?? 0) }} - {{ number_format($jobs->lastItem() ?? 0) }} 件を表示
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-500">
                        <tr>
                            <th class="px-3 py-2 text-left">ID</th>
                            <th class="px-3 py-2 text-left">職業</th>
                            <th class="px-3 py-2 text-left">倍率</th>
                            <th class="px-3 py-2 text-left">技</th>
                            <th class="px-3 py-2 text-left">条件</th>
                            <th class="px-3 py-2 text-left">状態</th>
                            <th class="px-3 py-2 text-right">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($jobs as $job)
                            <tr class="hover:bg-gray-50 align-top">
                                <td class="px-3 py-2 text-gray-400">{{ $job->id }}</td>
                                <td class="px-3 py-2 min-w-40">
                                    <div class="font-bold text-gray-800">{{ $job->name }}</div>
                                    <div class="text-xs text-gray-400">{{ $job->key }} / {{ $rankLabels[$job->rank] ?? $job->rank }} / Lv{{ $job->max_job_level }}</div>
                                    @if($job->category)
                                        <div class="text-xs text-slate-500 mt-1">{{ $job->category }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs text-blue-700 font-bold min-w-44">
                                    @foreach(['hp_rate' => 'HP', 'mp_rate' => 'SP', 'atk_rate' => 'ATK', 'def_rate' => 'DEF', 'mag_rate' => 'MAG', 'spr_rate' => 'SPR', 'spd_rate' => 'SPD', 'luck_rate' => 'LUK'] as $field => $label)
                                        @if((int) $job->{$field} !== 100)
                                            <span class="mr-1">{{ $label }}{{ $job->{$field} }}</span>
                                        @endif
                                    @endforeach
                                    @if(collect(['hp_rate','mp_rate','atk_rate','def_rate','mag_rate','spr_rate','spd_rate','luck_rate'])->every(fn($field) => (int) $job->{$field} === 100))
                                        <span class="text-slate-400">標準</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 min-w-44">
                                    @if($job->skill)
                                        <div class="font-bold text-slate-800">{{ $job->skill->name }}</div>
                                        <div class="text-xs text-gray-500">{{ $damageTypeLabels[$job->skill->damage_type] ?? $job->skill->damage_type }} / {{ $job->skill->effectiveActivationRate() }}%</div>
                                    @else
                                        <span class="text-xs text-gray-400">なし</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs min-w-52">
                                    @forelse($job->requirements as $requirement)
                                        <div class="mb-1">
                                            <span class="font-bold text-slate-600">{{ $requirementLabels[$requirement->requirement_type] ?? $requirement->requirement_type }}</span>
                                            @if($requirement->requiredJob)
                                                <span class="text-slate-500">{{ $requirement->requiredJob->name }}</span>
                                            @elseif($requirement->required_value)
                                                <span class="text-slate-500">{{ $requirement->required_value }}</span>
                                            @elseif($requirement->required_key)
                                                <span class="text-slate-500">{{ $requirement->required_key }}</span>
                                            @endif
                                        </div>
                                    @empty
                                        <span class="text-gray-400">なし</span>
                                    @endforelse
                                </td>
                                <td class="px-3 py-2">
                                    <button wire:click="toggleActive({{ $job->id }})" class="px-2 py-1 rounded text-xs font-bold {{ $job->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                                        {{ $job->is_active ? '公開中' : '非公開' }}
                                    </button>
                                    @if($job->is_hidden)
                                        <div class="mt-1 text-xs font-bold text-amber-700">隠し</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button wire:click="edit({{ $job->id }})" class="px-3 py-1 rounded bg-white border border-gray-300 text-gray-700 font-bold hover:bg-gray-100">
                                        編集
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $jobs->links() }}
            </div>
        </div>
    </div>
</div>
