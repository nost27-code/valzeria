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

        <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-xs font-black uppercase tracking-[0.16em] text-amber-600">JOB ARTS</div>
                    <h1 class="text-xl font-black text-slate-900">奥義をセットする</h1>
                    <p class="mt-1 text-xs font-bold leading-relaxed text-slate-500">選ぶとその場で自動保存されます。最大3つまで。</p>
                </div>
                <div class="shrink-0 space-y-0.5 text-right text-xs font-black text-slate-500">
                    <div>通常 <span data-job-art-total-cost="normal">{{ $totalCostByContext['normal'] ?? 0 }}</span>/5</div>
                    <div>ボス <span data-job-art-total-cost="boss">{{ $totalCostByContext['boss'] ?? 0 }}</span>/5</div>
                </div>
            </div>

            <div class="mt-4 space-y-3" x-data="{ activeContext: 'normal' }">
                <div class="grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1">
                    @foreach($slotContextLabels as $slotContext => $slotContextLabel)
                        <button
                            type="button"
                            @click="activeContext = @js($slotContext)"
                            class="rounded-md px-3 py-1.5 text-sm font-black transition-colors"
                            :class="activeContext === @js($slotContext) ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                        >
                            {{ $slotContextLabel }}
                        </button>
                    @endforeach
                </div>

                @foreach($slotContextLabels as $slotContext => $slotContextLabel)
                    @php
                        $contextSlots = $selectedSlotsByContext[$slotContext] ?? collect();
                        $contextArts = $availableArtsByContext[$slotContext] ?? collect();
                    @endphp
                    <div x-show="activeContext === @js($slotContext)" class="space-y-2">
                        <p class="text-[11px] font-bold leading-relaxed text-slate-400">{{ $slotContextDescriptions[$slotContext] ?? '' }}</p>

                        @for($slotNo = 1; $slotNo <= 3; $slotNo++)
                            @php
                                $slot = $contextSlots->firstWhere('slot_no', $slotNo);
                                $selectedId = (int) ($slot?->skill_id ?? 0);
                                $slotPolicy = (string) ($slot?->activation_policy ?? 'normal');
                                $slotPolicy = array_key_exists($slotPolicy, $activationPolicyLabels) ? $slotPolicy : 'normal';
                                $slotArt = $contextArts->firstWhere('id', $selectedId) ?: $allAvailableArts->firstWhere('id', $selectedId);
                                $hasArt = $slotArt !== null;
                                $artCost = $hasArt ? (int) $slotArt->art_cost : 0;
                                $artOrigin = $hasArt ? ($slotArt->getAttribute('job_art_origin') ?: 'current') : '';
                                $artSpCost = $hasArt ? $slotArt->jobArtSpCostForMaxSp($maxSp, $artOrigin) : 0;
                                $costBadgeClass = match ($artCost) {
                                    1 => 'bg-emerald-50 text-emerald-700',
                                    2 => 'bg-sky-50 text-sky-700',
                                    3 => 'bg-amber-50 text-amber-800',
                                    default => 'bg-slate-100 text-slate-600',
                                };
                            @endphp

                            <div
                                x-data="{
                                    editing: {{ $hasArt ? 'false' : 'true' }},
                                    policy: @js($slotPolicy),
                                    policyDescriptions: @js($activationPolicyDescriptions),
                                    query: '',
                                    saving: false,
                                    async save(skillId, policy) {
                                        this.saving = true;
                                        try {
                                            const formData = new FormData();
                                            if (skillId) formData.append('skill_id', skillId);
                                            formData.append('slot_no', '{{ $slotNo }}');
                                            formData.append('slot_context', @js($slotContext));
                                            formData.append('activation_policy', policy || 'normal');
                                            const response = await fetch(@js(route('job-arts.slot-set')), {
                                                method: 'POST',
                                                headers: {
                                                    'Accept': 'application/json',
                                                    'X-CSRF-TOKEN': @js(csrf_token()),
                                                    'X-Requested-With': 'XMLHttpRequest',
                                                },
                                                body: formData,
                                            });
                                            const payload = await response.json().catch(() => ({}));
                                            if (!response.ok) {
                                                throw new Error(payload.message || '保存できませんでした。');
                                            }
                                            window.location.reload();
                                        } catch (error) {
                                            alert(error.message || '保存できませんでした。');
                                            this.saving = false;
                                        }
                                    },
                                }"
                                class="rounded-lg border border-slate-100 bg-white px-3 py-2.5"
                                :class="{ 'opacity-50 pointer-events-none': saving }"
                            >
                                <div class="mb-1.5 flex items-center justify-between gap-2">
                                    <span class="text-[10px] font-black tracking-widest text-slate-300">SLOT {{ $slotNo }}</span>
                                    @if($hasArt)
                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-black {{ $costBadgeClass }}">Cost {{ $artCost }}</span>
                                    @else
                                        <span class="text-[11px] font-bold text-slate-300">未設定</span>
                                    @endif
                                </div>
                                @if($hasArt)
                                    <div x-show="!editing" class="flex items-start justify-between gap-2">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-baseline gap-1.5 flex-wrap">
                                                <span class="text-[15px] font-black text-slate-900">{{ $slotArt->name }}</span>
                                                <span class="shrink-0 text-[10px] font-black {{ $artOrigin === 'current' ? 'text-amber-600' : 'text-indigo-600' }}">{{ $artOrigin === 'current' ? '本職' : '継承' }}</span>
                                            </div>
                                            <div class="mt-0.5 text-[11px] font-bold text-slate-400">{{ $slotArt->jobClass?->name ?? '職業' }} · Rank{{ $slotArt->learn_rank }} · SP{{ $artSpCost }} · {{ $activationPolicyLabels[$slotPolicy] ?? '通常' }}</div>
                                        </div>
                                        <button type="button" @click="editing = true"
                                            class="shrink-0 mt-0.5 text-[11px] font-black text-slate-400 hover:text-slate-700 transition-colors">
                                            変更
                                        </button>
                                    </div>
                                @endif
                                <div x-show="editing" class="space-y-2">
                                    <input
                                        type="text"
                                        x-model="query"
                                        placeholder="奥義名・職業名で検索"
                                        class="w-full rounded-md bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-700 placeholder:text-slate-300"
                                    >
                                    <div class="max-h-60 divide-y divide-slate-100 overflow-y-auto rounded-md bg-slate-50/60">
                                        <label class="block" x-show="!query">
                                            <input type="radio" name="{{ $slotContext }}_slot_{{ $slotNo }}_picker" value="" class="peer sr-only" @checked($selectedId === 0) @change="save(null, policy)">
                                            <span class="block px-2 py-2 text-xs font-bold text-slate-400 cursor-pointer hover:bg-slate-100 peer-checked:bg-slate-100 peer-checked:text-slate-600">未設定にする</span>
                                        </label>
                                        @foreach($contextArts as $art)
                                            @php
                                                $optionOrigin = $art->getAttribute('job_art_origin') === 'current' ? 'current' : 'inherited';
                                                $optionSpCost = $art->jobArtSpCostForMaxSp($maxSp, $optionOrigin);
                                                $optionCost = (int) $art->art_cost;
                                                $optionAccent = match ($optionCost) {
                                                    1 => 'text-emerald-600',
                                                    2 => 'text-sky-600',
                                                    3 => 'text-amber-700',
                                                    default => 'text-slate-500',
                                                };
                                                $optionSearch = \Illuminate\Support\Str::lower(($art->jobClass?->name ?? '') . ' ' . $art->name);
                                            @endphp
                                            <label
                                                class="block"
                                                data-job-art-picker-option
                                                data-search="{{ $optionSearch }}"
                                                x-show="!query || $el.dataset.search.includes(query.toLowerCase())"
                                            >
                                                <input type="radio" name="{{ $slotContext }}_slot_{{ $slotNo }}_picker" value="{{ $art->id }}" class="peer sr-only" @checked($selectedId === (int) $art->id) @change="save({{ $art->id }}, policy)">
                                                <span class="flex items-center justify-between gap-2 px-2 py-2 cursor-pointer hover:bg-slate-100 peer-checked:bg-indigo-50">
                                                    <span class="min-w-0">
                                                        <span class="block text-[10px] font-black text-slate-400">{{ $art->jobClass?->name ?? '職業' }} Rank{{ $art->learn_rank }} · {{ $optionOrigin === 'current' ? '本職' : '継承' }}</span>
                                                        <span class="block truncate text-sm font-black text-slate-900">{{ $art->name }}</span>
                                                    </span>
                                                    <span class="shrink-0 text-[11px] font-black {{ $optionAccent }}">Cost{{ $optionCost }} · SP{{ $optionSpCost }}</span>
                                                </span>
                                            </label>
                                        @endforeach
                                        <p class="px-2 py-3 text-center text-[11px] font-bold text-slate-400" x-show="query && ![...$el.parentElement.querySelectorAll('[data-job-art-picker-option]')].some(el => el.dataset.search.includes(query.toLowerCase()))">該当する奥義がありません。</p>
                                    </div>
                                    @if($hasArt)
                                        <div class="flex items-center gap-1.5">
                                            @foreach($activationPolicyLabels as $policyKey => $policyLabel)
                                                <label class="block flex-1">
                                                    <input type="radio" name="{{ $slotContext }}_policy_{{ $slotNo }}_picker" value="{{ $policyKey }}" x-model="policy" class="peer sr-only" @checked($slotPolicy === $policyKey) @change="save({{ $selectedId }}, $event.target.value)">
                                                    <span class="flex h-7 items-center justify-center rounded-md text-[11px] font-black text-slate-400 cursor-pointer peer-checked:bg-indigo-50 peer-checked:text-indigo-700">{{ $policyLabel }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                        <button type="button" @click="editing = false" class="text-[11px] font-bold text-slate-400 hover:text-slate-600 transition-colors">← キャンセル</button>
                                    @endif
                                </div>
                            </div>
                        @endfor
                    </div>
                @endforeach
            </div>
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
                                @foreach(['normal' => '通常', 'boss' => 'ボス'] as $slotContext => $shortLabel)
                                    @php
                                        $selectedSlotForContext = (int) (($selectedSlotBySkillByContext[$slotContext][$art->id] ?? 0) ?: 0);
                                    @endphp
                                    @if($selectedSlotForContext)
                                        <div class="text-[10px] font-black {{ $slotContext === 'boss' ? 'text-indigo-600' : 'text-emerald-600' }}">{{ $shortLabel }} Slot{{ $selectedSlotForContext }} セット中</div>
                                    @endif
                                @endforeach
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

            const activeClasses = ['border-amber-400', 'bg-amber-50', 'text-amber-700'];
            const inactiveClasses = ['border-slate-200', 'bg-white', 'text-slate-500'];
            const countEl = root.querySelector('[data-job-art-visible-count]');
            const emptyEl = root.querySelector('[data-job-art-empty]');
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

            root.querySelectorAll('[data-job-art-filter]').forEach((button) => {
                button.addEventListener('click', () => applyFilter(button.dataset.jobArtFilter));
            });

            applyFilter(currentFilter);
        })();
    </script>
</x-layouts.facility>
