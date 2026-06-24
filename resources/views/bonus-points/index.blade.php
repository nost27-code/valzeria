@php
    $jsonFlags = JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
    $statLabels = ['hp' => 'HP', 'mp' => 'SP', 'str' => '攻撃', 'def' => '防御', 'agi' => '敏捷', 'mag' => '魔力', 'spr' => '精神', 'luk' => '運'];
    $finalStatKeys = ['hp' => 'max_hp', 'mp' => 'max_mp', 'str' => 'str', 'def' => 'def', 'agi' => 'agi', 'mag' => 'mag', 'spr' => 'spr', 'luk' => 'luk'];
    $baseStats = [
        'hp' => (int) $character->hp_base,
        'mp' => (int) $character->mp_base,
        'str' => (int) $character->attack_base,
        'def' => (int) $character->defense_base,
        'agi' => (int) $character->speed_base,
        'mag' => (int) $character->magic_base,
        'spr' => (int) $character->spirit_base,
        'luk' => (int) $character->luck_base,
    ];
    $previewFinalStats = [];
    foreach ($finalStatKeys as $stat => $finalKey) {
        $previewFinalStats[$finalKey] = (int) ($finalStats[$finalKey] ?? 0);
    }
@endphp

<x-layouts.facility title="能力割振り" headerIcon="✦" bgImage="images/bg-castle.webp">
    <div
        class="py-4 w-full mx-auto sm:px-6 lg:px-8"
        x-data='bonusPointAllocator({
            csrfToken: "{{ csrf_token() }}",
            allocateUrl: "{{ route('bonus-points.allocate') }}",
            initialBp: {{ (int) ($character->bonus_points ?? 0) }},
            statOptions: {!! json_encode($statOptions, $jsonFlags) !!},
            statLabels: {!! json_encode($statLabels, $jsonFlags) !!},
            finalStatKeys: {!! json_encode($finalStatKeys, $jsonFlags) !!},
            baseStats: {!! json_encode($baseStats, $jsonFlags) !!},
            finalStats: {!! json_encode($previewFinalStats, $jsonFlags) !!},
            statusMessage: {!! json_encode(session('status'), $jsonFlags) !!},
            errorMessage: {!! json_encode(session('error'), $jsonFlags) !!}
        })'
    >
        <div class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-[#d4af37]/50">
            <div x-show="statusMessage" x-cloak class="mb-3 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-800" x-text="statusMessage"></div>
            <div x-show="errorMessage" x-cloak class="mb-3 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm font-bold text-red-700" x-text="errorMessage"></div>

            {{-- ヘッダー: タイトル + BPカウンター --}}
            <div class="mb-3 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-800">能力割振り</h2>
                    <p class="text-xs text-slate-500">Lv UP ごとに {{ $pointsPerLevel }} BP 獲得</p>
                </div>
                <div class="flex gap-2 text-center">
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 min-w-[72px]">
                        <div class="text-[10px] font-bold text-amber-700">未使用BP</div>
                        <div class="text-xl font-extrabold text-slate-900 leading-tight" x-text="formatNumber(availableBp())"></div>
                    </div>
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 min-w-[72px]">
                        <div class="text-[10px] font-bold text-amber-700">未確定</div>
                        <div class="text-xl font-extrabold text-slate-900 leading-tight" x-text="formatNumber(spentBp())"></div>
                    </div>
                </div>
            </div>

            {{-- 最終能力プレビュー --}}
            <div class="mb-3 rounded-lg border border-slate-200 bg-slate-50 p-2">
                <div class="mb-1.5 flex items-center justify-between">
                    <div class="text-xs font-extrabold text-slate-600">確定後の最終能力</div>
                    <button type="button" @click="resetAllocations()" :disabled="!hasPending()" class="rounded border border-slate-300 bg-white px-2 py-1 text-[11px] font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40">
                        リセット
                    </button>
                </div>
                <div class="grid grid-cols-4 gap-1 text-center text-[11px] font-bold sm:grid-cols-8">
                    @foreach($statLabels as $stat => $label)
                        @php $finalKey = $finalStatKeys[$stat]; @endphp
                        <div class="rounded border px-1 py-1.5" :class="allocations.{{ $stat }} > 0 ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white'">
                            <div class="text-slate-400">{{ $label }}</div>
                            <div class="mt-0.5 text-slate-900 text-xs">
                                <template x-if="allocations.{{ $stat }} > 0">
                                    <span class="text-amber-700 font-extrabold" x-text="formatNumber(previewFinal('{{ $stat }}'))"></span>
                                </template>
                                <template x-if="allocations.{{ $stat }} <= 0">
                                    <span x-text="formatNumber(finalStats['{{ $finalKey }}'])"></span>
                                </template>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- ステータス割振りカード（2列グリッド） --}}
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach($statOptions as $key => $option)
                    <div class="rounded-lg border border-slate-200 bg-white p-2.5 shadow-sm">
                        {{-- 上段: ラベル・基礎値・仮BP --}}
                        <div class="flex items-center justify-between mb-1.5">
                            <div>
                                <span class="text-sm font-extrabold text-slate-900">{{ $option['label'] }}</span>
                                <span class="ml-1.5 text-[11px] text-slate-400">
                                    基礎 <span x-text="formatNumber(baseStats['{{ $key }}'])"></span>
                                    <template x-if="allocations.{{ $key }} > 0">
                                        <span class="text-amber-600">→ <span x-text="formatNumber(previewBase('{{ $key }}'))"></span></span>
                                    </template>
                                    <span class="text-slate-300">/ +{{ $option['gain'] }}</span>
                                </span>
                            </div>
                            <div class="text-[11px] font-bold text-amber-600 bg-amber-50 border border-amber-100 rounded px-1.5 py-0.5 whitespace-nowrap">
                                仮 +<span x-text="allocations.{{ $key }}"></span>
                            </div>
                        </div>
                        {{-- 下段: − スライダー + --}}
                        <div class="flex items-center gap-2">
                            <button type="button"
                                @click="remove('{{ $key }}', 1)"
                                :disabled="allocations.{{ $key }} <= 0 || isSubmitting"
                                class="w-8 h-8 rounded border border-slate-300 bg-slate-50 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-30 flex-shrink-0">
                                −
                            </button>
                            <input type="range" min="0"
                                :max="allocations.{{ $key }} + availableBp()"
                                :value="allocations.{{ $key }}"
                                @input="setAllocation('{{ $key }}', $event.target.value)"
                                :disabled="isSubmitting"
                                class="flex-1 h-2 accent-[#1e40af] cursor-pointer disabled:opacity-40">
                            <button type="button"
                                @click="add('{{ $key }}', 1)"
                                :disabled="availableBp() <= 0 || isSubmitting"
                                class="w-8 h-8 rounded border border-[#1e3a8a] bg-[#1e40af] text-sm font-bold text-white transition hover:bg-[#1e3a8a] disabled:cursor-not-allowed disabled:border-slate-300 disabled:bg-slate-300 disabled:text-slate-500 flex-shrink-0">
                                +
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- 確定ボタン（sticky） --}}
            <div class="sticky bottom-3 mt-3 rounded-lg border border-slate-200 bg-white/95 px-3 py-2.5 shadow-lg backdrop-blur">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-xs font-bold text-slate-600">
                        <span x-show="hasPending()">未確定: <span class="text-amber-700" x-text="formatNumber(spentBp())"></span> BP</span>
                        <span x-show="!hasPending()" class="text-slate-400">割り振る能力を選んでください。</span>
                    </div>
                    <button type="button" @click="commit()" :disabled="!hasPending() || isSubmitting" class="rounded-lg bg-slate-900 px-5 py-2 text-sm font-extrabold text-white shadow-sm transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500">
                        <span x-text="isSubmitting ? '確定中...' : 'この内容で確定'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function bonusPointAllocator(initialState) {
            const allocations = {};
            Object.keys(initialState.statOptions).forEach((stat) => {
                allocations[stat] = 0;
            });

            return {
                csrfToken: initialState.csrfToken,
                allocateUrl: initialState.allocateUrl,
                remainingBp: initialState.initialBp,
                statOptions: initialState.statOptions,
                statLabels: initialState.statLabels,
                finalStatKeys: initialState.finalStatKeys,
                baseStats: initialState.baseStats,
                finalStats: initialState.finalStats,
                allocations,
                statusMessage: initialState.statusMessage,
                errorMessage: initialState.errorMessage,
                isSubmitting: false,
                formatNumber(value) {
                    return Number(value || 0).toLocaleString();
                },
                spentBp() {
                    return Object.values(this.allocations).reduce((sum, points) => sum + Number(points || 0), 0);
                },
                availableBp() {
                    return Math.max(0, this.remainingBp - this.spentBp());
                },
                hasPending() {
                    return this.spentBp() > 0;
                },
                previewBase(stat) {
                    return Number(this.baseStats[stat] || 0) + Number(this.allocations[stat] || 0) * Number(this.statOptions[stat].gain || 0);
                },
                previewFinal(stat) {
                    const finalKey = this.finalStatKeys[stat];
                    return Number(this.finalStats[finalKey] || 0) + Number(this.allocations[stat] || 0) * Number(this.statOptions[stat].gain || 0);
                },
                add(stat, points) {
                    if (this.isSubmitting || !this.statOptions[stat]) {
                        return;
                    }

                    const addable = Math.min(points, this.availableBp());
                    if (addable > 0) {
                        this.allocations[stat] += addable;
                        this.errorMessage = null;
                    }
                },
                remove(stat, points) {
                    if (this.isSubmitting || !this.statOptions[stat]) {
                        return;
                    }

                    this.allocations[stat] = Math.max(0, this.allocations[stat] - points);
                },
                setAllocation(stat, value) {
                    if (this.isSubmitting || !this.statOptions[stat]) {
                        return;
                    }

                    const val = parseInt(value) || 0;
                    const max = this.allocations[stat] + this.availableBp();
                    this.allocations[stat] = Math.max(0, Math.min(val, max));
                    this.errorMessage = null;
                },
                resetAllocations() {
                    Object.keys(this.allocations).forEach((stat) => {
                        this.allocations[stat] = 0;
                    });
                    this.errorMessage = null;
                },
                async commit() {
                    if (!this.hasPending() || this.isSubmitting) {
                        return;
                    }

                    this.isSubmitting = true;
                    this.errorMessage = null;
                    this.statusMessage = null;

                    try {
                        const response = await fetch(this.allocateUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': this.csrfToken
                            },
                            body: JSON.stringify({ allocations: this.allocations })
                        });
                        const data = await response.json();

                        if (!response.ok) {
                            throw new Error(data.message || '割り振りに失敗しました。');
                        }

                        Object.entries(data.result.applied || {}).forEach(([stat, applied]) => {
                            const gain = Number(applied.gain || 0);
                            this.baseStats[stat] = Number(this.baseStats[stat] || 0) + gain;

                            const finalKey = this.finalStatKeys[stat];
                            this.finalStats[finalKey] = Number(this.finalStats[finalKey] || 0) + gain;
                        });

                        this.remainingBp = Number(data.result.remaining || 0);
                        this.resetAllocations();
                        this.statusMessage = data.message || '能力を割り振りました。';
                    } catch (error) {
                        this.errorMessage = error.message || '割り振りに失敗しました。';
                    } finally {
                        this.isSubmitting = false;
                    }
                }
            };
        }
    </script>
</x-layouts.facility>
