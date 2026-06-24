<x-layouts.facility title="奥義セット" headerIcon="✦" bgImage="images/bg-castle.webp" :showExit="false">
    <div class="mx-auto w-full max-w-[560px] space-y-4 px-3 pb-24" data-job-art-root>
        <div class="flex items-center justify-between gap-3">
            <a href="{{ route('home') }}" class="text-sm font-bold text-slate-500 hover:text-slate-800">← 戻る</a>
            <a href="{{ route('jobs.index') }}" class="rounded-md border border-amber-300 px-3 py-1.5 text-xs font-extrabold text-amber-700">神殿へ</a>
        </div>

        @if(session('message'))
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700">
                {{ session('message') }}
            </div>
        @endif
        @if($errors->any())
            <div class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-bold text-rose-700">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="rounded-lg border border-[#d4af37]/70 bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between gap-3 border-b border-slate-100 pb-3">
                <div>
                    <div class="text-xs font-black uppercase tracking-[0.16em] text-amber-600">JOB ARTS</div>
                    <h1 class="text-xl font-black text-slate-900">奥義をセットする</h1>
                    <p class="mt-1 text-xs font-bold leading-relaxed text-slate-500">現在職Rankで習得した奥義と、マスター済み職業から継承した奥義を最大3つまで使えます。</p>
                </div>
                <div class="shrink-0 rounded-md bg-slate-900 px-3 py-2 text-center text-white">
                    <div class="text-[10px] font-bold text-slate-300">コスト</div>
                    <div class="text-lg font-black"><span data-job-art-total-cost>{{ $totalCost }}</span> / 5</div>
                </div>
            </div>

            <form method="POST" action="{{ route('job-arts.set') }}" class="mt-4 space-y-2">
                @csrf
                @for($slotNo = 1; $slotNo <= 3; $slotNo++)
                    @php
                        $slot = $selectedSlots->firstWhere('slot_no', $slotNo);
                        $selectedId = (int) old('slot_' . $slotNo, $slot?->skill_id ?? 0);
                        $slotArt = $allAvailableArts->firstWhere('id', $selectedId);
                        $hasArt = $slotArt !== null;
                        $artCost = $hasArt ? (int) $slotArt->art_cost : 0;
                        $artOrigin = $hasArt ? ($slotArt->getAttribute('job_art_origin') ?: 'current') : '';
                        $artSpCost = $hasArt ? $slotArt->jobArtSpCostForMaxSp($maxSp, $artOrigin) : 0;
                        $costBadgeClass = match ($artCost) {
                            1 => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                            2 => 'bg-sky-100 text-sky-700 border-sky-200',
                            3 => 'bg-amber-100 text-amber-800 border-amber-300',
                            default => 'bg-slate-100 text-slate-600 border-slate-200',
                        };
                        $slotBorderClass = $hasArt ? match ($artCost) {
                            1 => 'border-emerald-200',
                            2 => 'border-sky-200',
                            3 => 'border-amber-200',
                            default => 'border-slate-200',
                        } : 'border-slate-200';
                        $slotHeaderBg = $hasArt ? match ($artCost) {
                            1 => 'bg-emerald-50/60',
                            2 => 'bg-sky-50/60',
                            3 => 'bg-amber-50/60',
                            default => 'bg-slate-50',
                        } : 'bg-slate-50';
                    @endphp

                    @if($hasArt)
                    {{-- スロットに奥義がセット済み: カード表示 ↔ ドロップダウン切替 --}}
                    <div x-data="{ editing: false }" class="rounded-lg border {{ $slotBorderClass }} overflow-hidden">
                        {{-- ヘッダー --}}
                        <div class="flex items-center justify-between gap-2 px-3 py-1.5 {{ $slotHeaderBg }} border-b {{ $slotBorderClass }}">
                            <span class="text-[10px] font-black tracking-widest text-slate-400">SLOT {{ $slotNo }}</span>
                            <span class="inline-flex items-center rounded border px-2 py-0.5 text-[11px] font-black {{ $costBadgeClass }}">Cost {{ $artCost }}</span>
                        </div>
                        {{-- カード表示 --}}
                        <div x-show="!editing" class="flex items-start justify-between gap-2 px-3 py-2.5">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline gap-1.5 flex-wrap">
                                    <span class="text-[15px] font-black text-slate-900">{{ $slotArt->name }}</span>
                                    <span class="shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-black {{ $artOrigin === 'current' ? 'bg-amber-50 text-amber-700' : 'bg-indigo-50 text-indigo-700' }}">{{ $artOrigin === 'current' ? '本職' : '継承' }}</span>
                                </div>
                                <div class="mt-0.5 text-[11px] font-bold text-slate-400">{{ $slotArt->jobClass?->name ?? '職業' }} · Rank{{ $slotArt->learn_rank }}</div>
                                <div class="mt-1.5 flex flex-wrap gap-1">
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">SP {{ $artSpCost }}</span>
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">発動 {{ $slotArt->activation_rate }}%</span>
                                    @if($slotArt->cooldown_turns)
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">CT{{ $slotArt->cooldown_turns }}</span>
                                    @endif
                                    @if($slotArt->max_uses_per_battle)
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">1戦{{ $slotArt->max_uses_per_battle }}回</span>
                                    @endif
                                </div>
                                @php $artDesc = $slotArt->memo ?: $slotArt->description; @endphp
                                @if($artDesc)
                                    <p class="mt-1 text-[11px] font-bold text-slate-500 leading-relaxed line-clamp-1">{{ $artDesc }}</p>
                                @endif
                            </div>
                            <button type="button" @click="editing = true"
                                class="shrink-0 mt-0.5 rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] font-black text-slate-500 hover:bg-slate-50 hover:text-slate-700 transition-colors">
                                変更
                            </button>
                        </div>
                        {{-- ドロップダウン（編集時のみ表示、フォーム送信には常に含まれる） --}}
                        <div x-show="editing" class="px-3 py-2.5 space-y-2">
                            <select
                                name="slot_{{ $slotNo }}"
                                data-job-art-main-slot-select
                                data-slot-no="{{ $slotNo }}"
                                class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-800"
                            >
                                <option value="">未設定</option>
                                @foreach($allAvailableArts as $art)
                                    @php
                                        $optionOrigin = $art->getAttribute('job_art_origin') === 'current' ? 'current' : 'inherited';
                                        $optionSpCost = $art->jobArtSpCostForMaxSp($maxSp, $optionOrigin);
                                    @endphp
                                    <option value="{{ $art->id }}" @selected((int) $selectedId === (int) $art->id)>
                                        {{ $art->jobClass?->name ?? '職業' }} Rank{{ $art->learn_rank }} / {{ $art->name }} / cost{{ $art->art_cost }} / SP{{ $optionSpCost }} / {{ $art->getAttribute('job_art_origin') === 'current' ? '本職' : '継承' }}
                                    </option>
                                @endforeach
                            </select>
                            <button type="button" @click="editing = false" class="text-[11px] font-bold text-slate-400 hover:text-slate-600 transition-colors">← キャンセル</button>
                        </div>
                    </div>
                    @else
                    {{-- スロット未設定 --}}
                    <div class="rounded-lg border border-slate-200 overflow-hidden">
                        <div class="flex items-center justify-between gap-2 px-3 py-1.5 bg-slate-50 border-b border-slate-200">
                            <span class="text-[10px] font-black tracking-widest text-slate-400">SLOT {{ $slotNo }}</span>
                            <span class="text-[11px] font-bold text-slate-400">未設定</span>
                        </div>
                        <div class="px-3 py-2.5">
                            <select
                                name="slot_{{ $slotNo }}"
                                data-job-art-main-slot-select
                                data-slot-no="{{ $slotNo }}"
                                class="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-800"
                            >
                                <option value="">未設定</option>
                                @foreach($allAvailableArts as $art)
                                    @php
                                        $optionOrigin = $art->getAttribute('job_art_origin') === 'current' ? 'current' : 'inherited';
                                        $optionSpCost = $art->jobArtSpCostForMaxSp($maxSp, $optionOrigin);
                                    @endphp
                                    <option value="{{ $art->id }}" @selected((int) $selectedId === (int) $art->id)>
                                        {{ $art->jobClass?->name ?? '職業' }} Rank{{ $art->learn_rank }} / {{ $art->name }} / cost{{ $art->art_cost }} / SP{{ $optionSpCost }} / {{ $art->getAttribute('job_art_origin') === 'current' ? '本職' : '継承' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    @endif
                @endfor
                <button type="submit" class="w-full rounded-md bg-slate-950 px-4 py-3 text-sm font-black text-white shadow active:scale-[0.99]">奥義セットを保存する</button>
            </form>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="mb-3 flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-black text-slate-900">奥義発動方針</h2>
                    <p class="mt-1 text-xs font-bold text-slate-500">{{ $activationPolicyDescriptions[$activationPolicy] ?? $activationPolicyDescriptions['normal'] }}</p>
                </div>
                <div class="shrink-0 rounded-md bg-indigo-50 px-2 py-1 text-xs font-black text-indigo-700">SP {{ number_format((int) ($character->current_mp ?? 0)) }} / {{ number_format($maxSp) }}</div>
            </div>
            <form method="POST" action="{{ route('job-arts.policy') }}" class="space-y-3">
                @csrf
                <input type="hidden" name="filter" value="{{ $filter }}">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach($activationPolicyLabels as $policyKey => $policyLabel)
                        <label class="block">
                            <input type="radio" name="activation_policy" value="{{ $policyKey }}" class="peer sr-only" @checked($activationPolicy === $policyKey)>
                            <span class="flex min-h-[44px] items-center justify-center rounded-md border border-slate-200 bg-white px-2 py-2 text-xs font-black text-slate-600 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 peer-checked:text-indigo-700">{{ $policyLabel }}</span>
                        </label>
                    @endforeach
                </div>
                <button type="submit" class="w-full rounded-md border border-indigo-200 bg-indigo-600 px-4 py-2.5 text-sm font-black text-white shadow-sm active:scale-[0.99]">方針を保存する</button>
            </form>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="text-sm font-black text-slate-900">使用可能な奥義</h2>
                <div class="text-xs font-bold text-slate-400"><span data-job-art-visible-count>{{ $availableArts->count() }}</span>件</div>
            </div>
            <div class="mb-3 flex gap-1 overflow-x-auto pb-1 text-xs font-black">
                @foreach(['available' => '使用可能', 'current' => '現在職', 'inherited' => '継承', 'attack' => '攻撃', 'heal' => '回復', 'support' => '補助', 'reward' => '報酬', 'time' => '時空'] as $key => $label)
                    <button type="button" data-job-art-filter="{{ $key }}" class="shrink-0 rounded-full border px-3 py-1.5 {{ $filter === $key ? 'border-amber-400 bg-amber-50 text-amber-700' : 'border-slate-200 bg-white text-slate-500' }}">{{ $label }}</button>
                @endforeach
            </div>

            <div class="space-y-2">
                @forelse($availableArts as $art)
                    @php
                        $cost = (int) $art->art_cost;
                        $costCardClass = match ($cost) {
                            1 => 'border-emerald-200 bg-emerald-50/70',
                            2 => 'border-sky-200 bg-sky-50/80',
                            3 => 'border-amber-300 bg-amber-50/90',
                            default => 'border-slate-200 bg-slate-50',
                        };
                        $costBadgeClass = match ($cost) {
                            1 => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                            2 => 'bg-sky-100 text-sky-700 border-sky-200',
                            3 => 'bg-amber-100 text-amber-800 border-amber-300',
                            default => 'bg-white text-slate-600 border-slate-200',
                        };
                        $filterTokens = ['available'];
                        $filterTokens[] = $art->getAttribute('job_art_origin') === 'current' ? 'current' : 'inherited';
                        if ($art->art_category === 'attack') {
                            $filterTokens[] = 'attack';
                        }
                        if ($art->limit_group === 'HEAL') {
                            $filterTokens[] = 'heal';
                        }
                        if (in_array($art->art_category, ['buff', 'debuff', 'guard'], true)) {
                            $filterTokens[] = 'support';
                        }
                        if ($art->limit_group === 'REWARD') {
                            $filterTokens[] = 'reward';
                        }
                        if ($art->limit_group === 'TIME') {
                            $filterTokens[] = 'time';
                        }
                        $limitLabel = $art->jobArtLimitLabel();
                        $baseSpCost = $art->jobArtBaseSpCostForMaxSp($maxSp);
                        $currentSpCost = $art->jobArtSpCostForMaxSp($maxSp, 'current');
                        $inheritedSpCost = $art->jobArtSpCostForMaxSp($maxSp, 'inherited');
                    @endphp
                    <article data-job-art-card data-filters="{{ implode(' ', array_unique($filterTokens)) }}" class="rounded-md border px-3 py-2 {{ $costCardClass }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="text-[11px] font-black text-slate-500">{{ $art->jobClass?->name ?? '職業' }} / Rank{{ $art->learn_rank }} / {{ $art->getAttribute('job_art_origin') === 'current' ? '本職' : '継承 ' . (int) round(((float) $art->getAttribute('job_art_rate')) * 100) . '%' }}</div>
                                <div class="truncate text-sm font-black text-slate-900">{{ $art->name }}</div>
                            </div>
                            <div class="shrink-0 space-y-1 text-right">
                                <div class="inline-flex rounded border px-2 py-1 text-xs font-black {{ $costBadgeClass }}">Cost {{ $art->art_cost }}</div>
                                <form method="POST" action="{{ route('job-arts.assign') }}" data-job-art-assign-form>
                                    @csrf
                                    <input type="hidden" name="skill_id" value="{{ $art->id }}">
                                    <input type="hidden" name="filter" value="{{ $filter }}">
                                    <select
                                        name="slot_no"
                                        data-job-art-slot-select
                                        data-skill-id="{{ $art->id }}"
                                        data-previous-slot="{{ (int) ($selectedSlotBySkill[$art->id] ?? 0) ?: '' }}"
                                        class="w-[84px] rounded-md border border-slate-200 bg-white px-2 py-1 text-[11px] font-black text-slate-700"
                                    >
                                        <option value="">未設定</option>
                                        @for($slotNo = 1; $slotNo <= 3; $slotNo++)
                                            <option value="{{ $slotNo }}" @selected((int) ($selectedSlotBySkill[$art->id] ?? 0) === $slotNo)>Slot {{ $slotNo }}</option>
                                        @endfor
                                    </select>
                                </form>
                            </div>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1 text-[10px] font-black">
                            <span class="rounded bg-white px-2 py-0.5 text-slate-600">{{ $art->jobArtEffectLabel() }}</span>
                            @if($limitLabel)
                                <span class="rounded bg-white px-2 py-0.5 text-slate-500">{{ $limitLabel }}</span>
                            @endif
                            <span class="rounded bg-white px-2 py-0.5 text-slate-500">発動{{ $art->activation_rate }}%</span>
                            <span class="rounded bg-white px-2 py-0.5 text-slate-500">消費SP {{ $baseSpCost }}</span>
                            <span class="rounded bg-white px-2 py-0.5 text-slate-500">本職 {{ $currentSpCost }}</span>
                            <span class="rounded bg-white px-2 py-0.5 text-slate-500">継承 {{ $inheritedSpCost }}</span>
                            @if($art->isHealArt())
                                <span class="rounded bg-white px-2 py-0.5 text-emerald-600">HP70%以下</span>
                            @endif
                            @if($art->cooldown_turns)
                                <span class="rounded bg-white px-2 py-0.5 text-slate-500">CT{{ $art->cooldown_turns }}</span>
                            @endif
                            @if($art->max_uses_per_battle)
                                <span class="rounded bg-white px-2 py-0.5 text-slate-500">1戦{{ $art->max_uses_per_battle }}回</span>
                            @endif
                        </div>
                        <p class="mt-2 text-xs font-bold leading-relaxed text-slate-600">{{ $art->memo ?: $art->description }}</p>
                    </article>
                @empty
                    <div class="rounded-md bg-slate-50 px-3 py-6 text-center text-sm font-bold text-slate-400">条件を満たした奥義はまだありません。</div>
                @endforelse
                <div data-job-art-empty class="hidden rounded-md bg-slate-50 px-3 py-6 text-center text-sm font-bold text-slate-400">この絞り込みに該当する奥義はありません。</div>
            </div>
        </section>

        <x-back-button href="{{ route('home') }}" label="ホームに戻る" icon="🏠" />
    </div>
    <script>
        (() => {
            const root = document.querySelector('[data-job-art-root]');
            if (!root) return;

            const csrfToken = @js(csrf_token());
            const assignUrl = @js(route('job-arts.assign'));
            const activeClasses = ['border-amber-400', 'bg-amber-50', 'text-amber-700'];
            const inactiveClasses = ['border-slate-200', 'bg-white', 'text-slate-500'];
            const countEl = root.querySelector('[data-job-art-visible-count]');
            const emptyEl = root.querySelector('[data-job-art-empty]');
            const totalCostEl = root.querySelector('[data-job-art-total-cost]');
            let currentFilter = @js($filter);

            const setChipState = (filter) => {
                root.querySelectorAll('[data-job-art-filter]').forEach((button) => {
                    const active = button.dataset.jobArtFilter === filter;
                    button.classList.remove(...(active ? inactiveClasses : activeClasses));
                    button.classList.add(...(active ? activeClasses : inactiveClasses));
                });
            };

            const applyFilter = (filter) => {
                currentFilter = filter || 'available';
                let visibleCount = 0;
                root.querySelectorAll('[data-job-art-card]').forEach((card) => {
                    const filters = (card.dataset.filters || '').split(/\s+/);
                    const visible = currentFilter === 'available' || filters.includes(currentFilter);
                    card.classList.toggle('hidden', !visible);
                    if (visible) visibleCount += 1;
                });
                if (countEl) countEl.textContent = visibleCount;
                if (emptyEl) emptyEl.classList.toggle('hidden', visibleCount > 0);
                setChipState(currentFilter);
            };

            const updateSlotSelections = (selectedSlotBySkill) => {
                const selectedSkillBySlot = {};
                Object.entries(selectedSlotBySkill || {}).forEach(([skillId, slotNo]) => {
                    if (slotNo) {
                        selectedSkillBySlot[String(slotNo)] = String(skillId);
                    }
                });

                root.querySelectorAll('[data-job-art-main-slot-select]').forEach((select) => {
                    const skillId = selectedSkillBySlot[String(select.dataset.slotNo)] || '';
                    select.value = skillId;
                });

                root.querySelectorAll('[data-job-art-slot-select]').forEach((select) => {
                    const slot = selectedSlotBySkill[select.dataset.skillId] || '';
                    select.value = slot === '' ? '' : String(slot);
                    select.dataset.previousSlot = slot === '' ? '' : String(slot);
                });
            };

            root.querySelectorAll('[data-job-art-filter]').forEach((button) => {
                button.addEventListener('click', () => applyFilter(button.dataset.jobArtFilter));
            });

            root.querySelectorAll('[data-job-art-slot-select]').forEach((select) => {
                select.addEventListener('change', async () => {
                    const previous = select.dataset.previousSlot || '';
                    select.disabled = true;

                    try {
                        const formData = new FormData();
                        formData.append('skill_id', select.dataset.skillId);
                        if (select.value) {
                            formData.append('slot_no', select.value);
                        }
                        formData.append('filter', currentFilter);

                        const response = await fetch(assignUrl, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: formData,
                        });
                        const payload = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            throw new Error(payload.message || '奥義スロットを更新できませんでした。');
                        }

                        if (totalCostEl && typeof payload.total_cost !== 'undefined') {
                            totalCostEl.textContent = payload.total_cost;
                        }
                        updateSlotSelections(payload.selected_slot_by_skill || {});
                    } catch (error) {
                        select.value = previous;
                        alert(error.message || '奥義スロットを更新できませんでした。');
                    } finally {
                        select.disabled = false;
                    }
                });
            });

            applyFilter(currentFilter);
        })();
    </script>
</x-layouts.facility>
