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

            <div
                class="mt-4 space-y-3"
                x-data="{
                    activeContext: 'normal',
                    activeContextStorageKey: 'valzeria.jobArtActiveContext.v1',
                    init() {
                        try {
                            const savedContext = localStorage.getItem(this.activeContextStorageKey);
                            if (['normal', 'boss'].includes(savedContext)) {
                                this.activeContext = savedContext;
                            }
                        } catch (error) {}
                    },
                    setActiveContext(context) {
                        this.activeContext = context;
                        try {
                            localStorage.setItem(this.activeContextStorageKey, context);
                        } catch (error) {}
                        if (window.jobArtClearTarget) {
                            window.jobArtClearTarget();
                        }
                    },
                }"
            >
                <div class="grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1">
                    @foreach($slotContextLabels as $slotContext => $slotContextLabel)
                        <button
                            type="button"
                            @click="setActiveContext(@js($slotContext))"
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

                        <div data-job-art-slots="{{ $slotContext }}" class="space-y-2">
                            @for($slotNo = 1; $slotNo <= 3; $slotNo++)
                                @include('job-arts.partials.slot-card', [
                                    'slotContext' => $slotContext,
                                    'slotNo' => $slotNo,
                                    'slot' => $contextSlots->firstWhere('slot_no', $slotNo),
                                    'contextArts' => $contextArts,
                                    'allAvailableArts' => $allAvailableArts,
                                    'maxSp' => $maxSp,
                                    'activationPolicyLabels' => $activationPolicyLabels,
                                    'activationPolicyDescriptions' => $activationPolicyDescriptions,
                                    'contextTotalCost' => $totalCostByContext[$slotContext] ?? 0,
                                ])
                            @endfor
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <h2 class="text-sm font-black text-slate-900">使用可能な奥義</h2>
                    <button type="button" data-job-art-tips-toggle aria-expanded="false" class="inline-flex h-6 w-6 items-center justify-center rounded-full border border-sky-200 bg-sky-50 text-xs font-black text-sky-700 shadow-sm transition-colors hover:bg-sky-100" title="バッジの見方">
                        ?
                    </button>
                </div>
                <div class="text-xs font-bold text-slate-400"><span data-job-art-visible-count>{{ $availableArts->count() }}</span>件</div>
            </div>
            <div data-job-art-target-banner class="mb-3 hidden items-center justify-between gap-2 rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-2 text-xs font-black text-indigo-800">
                <span>SLOT<span data-job-art-target-slot-no></span>（<span data-job-art-target-context-label></span>）にセットする奥義を選んでください</span>
                <div class="flex shrink-0 gap-1.5">
                    <button type="button" data-job-art-target-unset class="rounded border border-indigo-300 bg-white px-2 py-1 text-indigo-700">未設定にする</button>
                    <button type="button" data-job-art-target-cancel class="rounded border border-slate-300 bg-white px-2 py-1 text-slate-600">キャンセル</button>
                </div>
            </div>
            <div data-job-art-tips-panel class="mb-3 hidden rounded-md border border-sky-100 bg-sky-50/80 px-3 py-2 text-[11px] font-bold leading-relaxed text-slate-600">
                <div class="font-black text-sky-800">バッジの見方</div>
                <div class="mt-1 grid gap-1 sm:grid-cols-2">
                    <div><span class="font-black text-slate-800">効果種別</span>：攻撃、回復、強化など奥義の主な効果。</div>
                    <div><span class="font-black text-slate-800">発動率</span>：奥義候補になった時に発動する確率。</div>
                    <div><span class="font-black text-slate-800">消費SP</span>：発動時に使うSPの基本値。</div>
                    <div><span class="font-black text-slate-800">本職</span>：現在職で使う時の実消費SP。</div>
                    <div><span class="font-black text-slate-800">継承</span>：継承奥義として使う時の実消費SP。マスター職から継承した奥義は、威力・効果量が本来の70〜85%になります。</div>
                    <div><span class="font-black text-slate-800">CT</span>：使用後、再発動までに必要なターン数。</div>
                    <div><span class="font-black text-slate-800">1戦回数</span>：1戦中に発動できる最大回数。</div>
                    <div><span class="font-black text-slate-800">発動条件</span>：HP割合など、発動候補に入る条件。</div>
                </div>
            </div>
            <div class="mb-3 space-y-2">
                <details class="rounded-md border border-slate-200 bg-slate-50/70 px-3 py-2" open>
                    <summary class="cursor-pointer text-xs font-black text-slate-700">絞り込み</summary>
                    <div class="mt-2 flex flex-wrap gap-1.5 text-xs font-black">
                        @foreach(['available' => '使用可能', 'favorite' => 'お気に入り', 'current' => '現在職', 'inherited' => '継承', 'cost1' => 'Cost1', 'cost2' => 'Cost2', 'cost3' => 'Cost3', 'attack' => '攻撃', 'buff' => 'バフ', 'debuff' => 'デバフ', 'hp_recover' => 'HP回復', 'sp_recover' => 'SP回復', 'reward' => '報酬', 'time' => '時空'] as $key => $label)
                            <button type="button" data-job-art-filter="{{ $key }}" class="rounded-full border px-3 py-1.5 {{ $filter === $key ? 'border-amber-400 bg-amber-50 text-amber-700' : 'border-slate-200 bg-white text-slate-500' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                </details>
                <details class="rounded-md border border-slate-200 bg-slate-50/70 px-3 py-2">
                    <summary class="cursor-pointer text-xs font-black text-slate-700">並び替え</summary>
                    <div class="mt-2 flex flex-wrap gap-1.5 text-xs font-black">
                        @foreach(['default' => '初期順', 'cost_asc' => 'Cost低い', 'cost_desc' => 'Cost高い', 'rate_desc' => '発動率高い', 'name_asc' => '名前順'] as $key => $label)
                            <button type="button" data-job-art-sort="{{ $key }}" class="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-slate-500">{{ $label }}</button>
                        @endforeach
                    </div>
                </details>
            </div>

            <div class="space-y-2" data-job-art-list>
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
                        $filterTokens[] = 'cost' . $cost;
                        $filterTokens[] = $art->getAttribute('job_art_origin') === 'current' ? 'current' : 'inherited';
                        if ($art->art_category === 'attack') {
                            $filterTokens[] = 'attack';
                        }
                        if (in_array($art->effect_template, ['DAMAGE_BUFF', 'MAGICAL_DAMAGE_BUFF', 'SELF_BUFF'], true) || $art->art_category === 'buff') {
                            $filterTokens[] = 'buff';
                        }
                        if (in_array($art->effect_template, ['DAMAGE_DEBUFF', 'ENEMY_DEBUFF'], true) || $art->art_category === 'debuff') {
                            $filterTokens[] = 'debuff';
                        }
                        if ($art->isHealArt() || (int) $art->heal_percent > 0) {
                            $filterTokens[] = 'hp_recover';
                        }
                        if ((int) $art->mp_recover_percent > 0) {
                            $filterTokens[] = 'sp_recover';
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
                        $statLabelReplacements = [
                            'ATK' => '攻撃',
                            'DEF' => '防御',
                            'SPD' => '敏捷',
                            'MAG' => '魔力',
                            'SPR' => '精神',
                            'LUK' => '運',
                        ];
                        $displayMemo = strtr((string) ($art->memo ?: $art->description), $statLabelReplacements);
                        $numericEffectLabels = $art->jobArtNumericEffectLabels();
                        $validContexts = [];
                        if (($availableArtsByContext['normal'] ?? collect())->contains('id', $art->id)) {
                            $validContexts[] = 'normal';
                        }
                        if (($availableArtsByContext['boss'] ?? collect())->contains('id', $art->id)) {
                            $validContexts[] = 'boss';
                        }
                    @endphp
                    <article
                        data-job-art-card
                        data-job-art-id="{{ $art->id }}"
                        data-filters="{{ implode(' ', array_unique($filterTokens)) }}"
                        data-sort-index="{{ $loop->index }}"
                        data-cost="{{ $cost }}"
                        data-activation-rate="{{ (int) $art->activation_rate }}"
                        data-name="{{ $art->name }}"
                        data-job-art-contexts="{{ implode(' ', $validContexts) }}"
                        class="rounded-md border px-3 py-2 {{ $costCardClass }}"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="text-[11px] font-black text-slate-500">{{ $art->jobClass?->name ?? '職業' }} / Rank{{ $art->learn_rank }} / {{ $art->getAttribute('job_art_origin') === 'current' ? '本職' : '継承 ' . (int) round(((float) $art->getAttribute('job_art_rate')) * 100) . '%' }}</div>
                                <div class="truncate text-sm font-black text-slate-900">{{ $art->name }}</div>
                            </div>
                            <div class="shrink-0 space-y-1 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button type="button" data-job-art-favorite-toggle="{{ $art->id }}" aria-pressed="false" class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white text-sm font-black text-slate-300 shadow-sm transition-colors hover:border-amber-300 hover:text-amber-500" title="お気に入り">
                                        ☆
                                    </button>
                                    <div class="inline-flex rounded border px-2 py-1 text-xs font-black {{ $costBadgeClass }}">Cost {{ $art->art_cost }}</div>
                                </div>
                                @foreach(['normal' => '通常', 'boss' => 'ボス'] as $slotContext => $shortLabel)
                                    @php
                                        $selectedSlotForContext = (int) (($selectedSlotBySkillByContext[$slotContext][$art->id] ?? 0) ?: 0);
                                    @endphp
                                    <div
                                        data-job-art-status="{{ $slotContext }}"
                                        class="text-[10px] font-black {{ $slotContext === 'boss' ? 'text-indigo-600' : 'text-emerald-600' }} {{ $selectedSlotForContext ? '' : 'hidden' }}"
                                    >{{ $shortLabel }} Slot<span data-job-art-status-slot>{{ $selectedSlotForContext ?: '' }}</span> セット中</div>
                                @endforeach
                            </div>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1 text-[10px] font-black">
                            <span class="rounded bg-white px-2 py-0.5 text-slate-600">{{ $art->jobArtEffectLabel() }}</span>
                            @foreach($numericEffectLabels as $numericEffectLabel)
                                <span class="rounded bg-indigo-50 px-2 py-0.5 text-indigo-700">{{ $numericEffectLabel }}</span>
                            @endforeach
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
                        <p class="mt-2 text-xs font-bold leading-relaxed text-slate-600">{{ $displayMemo }}</p>
                        <button type="button"
                            data-job-art-assign-btn
                            data-art-id="{{ $art->id }}"
                            class="mt-2 hidden w-full rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-black text-white shadow-sm transition-colors hover:bg-indigo-700">
                            この奥義をセットする
                        </button>
                        <div data-job-art-assign-unavailable class="mt-2 hidden rounded-md bg-slate-100 px-3 py-1.5 text-center text-[11px] font-bold text-slate-400">
                            このセットでは使用できません
                        </div>
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

            const SLOT_SET_URL = @json(route('job-arts.slot-set'));
            const CSRF_TOKEN = @json(csrf_token());
            const CONTEXT_LABELS = { normal: '通常', boss: 'ボス' };

            const targetBanner = root.querySelector('[data-job-art-target-banner]');
            const targetSlotNoEl = root.querySelector('[data-job-art-target-slot-no]');
            const targetContextLabelEl = root.querySelector('[data-job-art-target-context-label]');
            const targetUnsetBtn = root.querySelector('[data-job-art-target-unset]');
            const targetCancelBtn = root.querySelector('[data-job-art-target-cancel]');

            let target = null; // { context: 'normal'|'boss', slotNo: 1|2|3 }

            const updateAssignButtons = () => {
                root.querySelectorAll('[data-job-art-card]').forEach((card) => {
                    const assignBtn = card.querySelector('[data-job-art-assign-btn]');
                    const unavailableEl = card.querySelector('[data-job-art-assign-unavailable]');
                    if (!assignBtn || !unavailableEl) return;

                    if (!target) {
                        assignBtn.classList.add('hidden');
                        unavailableEl.classList.add('hidden');
                        return;
                    }

                    const contexts = (card.dataset.jobArtContexts || '').split(/\s+/).filter(Boolean);
                    const eligible = contexts.includes(target.context);
                    assignBtn.classList.toggle('hidden', !eligible);
                    unavailableEl.classList.toggle('hidden', eligible);
                });
            };

            const setTarget = (context, slotNo) => {
                target = { context, slotNo: Number(slotNo) };

                root.querySelectorAll('[data-job-art-slot-card]').forEach((card) => {
                    const isTarget = card.dataset.slotContext === context && Number(card.dataset.slotNo) === target.slotNo;
                    card.classList.toggle('ring-2', isTarget);
                    card.classList.toggle('ring-indigo-400', isTarget);
                    card.classList.toggle('border-indigo-300', isTarget);
                });

                if (targetBanner) {
                    targetBanner.classList.remove('hidden');
                    targetBanner.classList.add('flex');
                }
                if (targetSlotNoEl) targetSlotNoEl.textContent = target.slotNo;
                if (targetContextLabelEl) targetContextLabelEl.textContent = CONTEXT_LABELS[context] || context;

                updateAssignButtons();

                const listSection = root.querySelector('[data-job-art-list]');
                if (listSection) {
                    listSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            };

            const clearTarget = () => {
                target = null;
                root.querySelectorAll('[data-job-art-slot-card]').forEach((card) => {
                    card.classList.remove('ring-2', 'ring-indigo-400', 'border-indigo-300');
                });
                if (targetBanner) {
                    targetBanner.classList.add('hidden');
                    targetBanner.classList.remove('flex');
                }
                updateAssignButtons();
            };

            window.jobArtClearTarget = clearTarget;

            const assignSkillToSlot = async (context, slotNo, skillId, policy, anchorSelector) => {
                const formData = new FormData();
                if (skillId) formData.append('skill_id', skillId);
                formData.append('slot_no', String(slotNo));
                formData.append('slot_context', context);
                formData.append('activation_policy', policy || 'normal');

                const anchorBefore = anchorSelector ? root.querySelector(anchorSelector) : null;
                const beforeTop = anchorBefore ? anchorBefore.getBoundingClientRect().top : null;

                let payload;
                try {
                    const response = await fetch(SLOT_SET_URL, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': CSRF_TOKEN,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData,
                    });
                    payload = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        throw new Error(payload.message || '保存できませんでした。');
                    }
                } catch (error) {
                    alert(error.message || '保存できませんでした。');
                    return;
                }

                const slotsContainer = root.querySelector('[data-job-art-slots="' + context + '"]');
                if (slotsContainer && typeof payload.slots_html === 'string') {
                    slotsContainer.innerHTML = payload.slots_html;
                }

                const totalEl = root.querySelector('[data-job-art-total-cost="' + context + '"]');
                if (totalEl && typeof payload.total_cost !== 'undefined') {
                    totalEl.textContent = payload.total_cost;
                }

                if (payload.selected_slot_by_skill) {
                    root.querySelectorAll('[data-job-art-card]').forEach((card) => {
                        const badge = card.querySelector('[data-job-art-status="' + context + '"]');
                        if (!badge) return;
                        const cardSlotNo = payload.selected_slot_by_skill[card.dataset.jobArtId] || 0;
                        if (cardSlotNo) {
                            badge.classList.remove('hidden');
                            const slotSpan = badge.querySelector('[data-job-art-status-slot]');
                            if (slotSpan) slotSpan.textContent = cardSlotNo;
                        } else {
                            badge.classList.add('hidden');
                        }
                    });
                }

                if (target && target.context === context && Number(target.slotNo) === Number(slotNo)) {
                    clearTarget();
                }

                if (beforeTop !== null) {
                    const anchorAfter = anchorSelector ? root.querySelector(anchorSelector) : null;
                    const afterTop = anchorAfter ? anchorAfter.getBoundingClientRect().top : beforeTop;
                    if (afterTop !== beforeTop) {
                        window.scrollBy(0, afterTop - beforeTop);
                    }
                }
            };

            root.addEventListener('click', (event) => {
                const targetBtn = event.target.closest('[data-job-art-target-btn]');
                if (targetBtn) {
                    setTarget(targetBtn.dataset.slotContext, targetBtn.dataset.slotNo);
                    return;
                }

                if (targetCancelBtn && event.target.closest('[data-job-art-target-cancel]')) {
                    clearTarget();
                    return;
                }

                if (targetUnsetBtn && event.target.closest('[data-job-art-target-unset]')) {
                    if (!target) return;
                    const { context, slotNo } = target;
                    const slotCardSelector = '[data-job-art-slot-card="' + context + '-' + slotNo + '"]';
                    assignSkillToSlot(context, slotNo, null, 'normal', slotCardSelector);
                    return;
                }

                const assignBtn = event.target.closest('[data-job-art-assign-btn]');
                if (assignBtn && target) {
                    const artId = assignBtn.dataset.artId;
                    const cardSelector = '[data-job-art-id="' + artId + '"]';
                    const slotCard = root.querySelector('[data-job-art-slot-card="' + target.context + '-' + target.slotNo + '"]');
                    const policy = slotCard ? (slotCard.dataset.policy || 'normal') : 'normal';
                    assignSkillToSlot(target.context, target.slotNo, artId, policy, cardSelector);
                }
            });

            root.addEventListener('change', (event) => {
                const radio = event.target.closest('[data-job-art-policy-radio]');
                if (!radio) return;
                const slotContext = radio.dataset.slotContext;
                const slotNo = radio.dataset.slotNo;
                const slotCard = radio.closest('[data-job-art-slot-card]');
                const skillId = slotCard ? slotCard.dataset.skillId : null;
                const slotCardSelector = '[data-job-art-slot-card="' + slotContext + '-' + slotNo + '"]';
                assignSkillToSlot(slotContext, slotNo, skillId, radio.value, slotCardSelector);
            });

            const activeClasses = ['border-amber-400', 'bg-amber-50', 'text-amber-700'];
            const inactiveClasses = ['border-slate-200', 'bg-white', 'text-slate-500'];
            const sortActiveClasses = ['border-sky-400', 'bg-sky-50', 'text-sky-700'];
            const sortInactiveClasses = ['border-slate-200', 'bg-white', 'text-slate-500'];
            const listEl = root.querySelector('[data-job-art-list]');
            const countEl = root.querySelector('[data-job-art-visible-count]');
            const emptyEl = root.querySelector('[data-job-art-empty]');
            const tipsButton = root.querySelector('[data-job-art-tips-toggle]');
            const tipsPanel = root.querySelector('[data-job-art-tips-panel]');
            const favoriteStorageKey = 'valzeria.jobArtFavorites.v1';
            const sortStorageKey = 'valzeria.jobArtSort.v1';
            let currentFilter = @js($filter);
            let currentSort = 'default';
            let favoriteIds = new Set();

            try {
                favoriteIds = new Set(JSON.parse(localStorage.getItem(favoriteStorageKey) || '[]').map(String));
            } catch (error) {
                favoriteIds = new Set();
            }

            try {
                const savedSort = localStorage.getItem(sortStorageKey);
                if (['default', 'cost_asc', 'cost_desc', 'rate_desc', 'name_asc'].includes(savedSort)) {
                    currentSort = savedSort;
                }
            } catch (error) {}

            const saveFavorites = () => {
                localStorage.setItem(favoriteStorageKey, JSON.stringify([...favoriteIds]));
            };

            const syncFavoriteButtons = () => {
                root.querySelectorAll('[data-job-art-favorite-toggle]').forEach((button) => {
                    const artId = String(button.dataset.jobArtFavoriteToggle || '');
                    const active = favoriteIds.has(artId);
                    button.setAttribute('aria-pressed', active ? 'true' : 'false');
                    button.textContent = active ? '★' : '☆';
                    button.classList.toggle('border-amber-300', active);
                    button.classList.toggle('bg-amber-100', active);
                    button.classList.toggle('text-amber-600', active);
                    button.classList.toggle('border-slate-200', !active);
                    button.classList.toggle('bg-white', !active);
                    button.classList.toggle('text-slate-300', !active);
                });

                root.querySelectorAll('[data-job-art-card]').forEach((card) => {
                    const active = favoriteIds.has(String(card.dataset.jobArtId || ''));
                    card.classList.toggle('ring-2', active);
                    card.classList.toggle('ring-amber-200', active);
                });
            };

            const setChipState = (filter) => {
                root.querySelectorAll('[data-job-art-filter]').forEach((button) => {
                    const active = button.dataset.jobArtFilter === filter;
                    button.classList.remove(...(active ? inactiveClasses : activeClasses));
                    button.classList.add(...(active ? activeClasses : inactiveClasses));
                });
            };

            const setSortState = (sort) => {
                root.querySelectorAll('[data-job-art-sort]').forEach((button) => {
                    const active = button.dataset.jobArtSort === sort;
                    button.classList.remove(...(active ? sortInactiveClasses : sortActiveClasses));
                    button.classList.add(...(active ? sortActiveClasses : sortInactiveClasses));
                });
            };

            const numberValue = (card, key) => Number.parseInt(card.dataset[key] || '0', 10) || 0;
            const originalIndex = (card) => numberValue(card, 'sortIndex');

            const compareCards = (a, b) => {
                const fallback = originalIndex(a) - originalIndex(b);
                if (currentSort === 'cost_asc') return numberValue(a, 'cost') - numberValue(b, 'cost') || fallback;
                if (currentSort === 'cost_desc') return numberValue(b, 'cost') - numberValue(a, 'cost') || fallback;
                if (currentSort === 'rate_desc') return numberValue(b, 'activationRate') - numberValue(a, 'activationRate') || fallback;
                if (currentSort === 'name_asc') return (a.dataset.name || '').localeCompare(b.dataset.name || '', 'ja') || fallback;

                return fallback;
            };

            const applySort = (sort) => {
                currentSort = sort || 'default';
                try {
                    localStorage.setItem(sortStorageKey, currentSort);
                } catch (error) {}
                if (listEl) {
                    [...root.querySelectorAll('[data-job-art-card]')]
                        .sort(compareCards)
                        .forEach((card) => {
                            if (emptyEl && emptyEl.parentElement === listEl) {
                                listEl.insertBefore(card, emptyEl);
                            } else {
                                listEl.appendChild(card);
                            }
                        });
                }
                setSortState(currentSort);
            };

            const applyFilter = (filter) => {
                currentFilter = filter || 'available';
                let visibleCount = 0;
                root.querySelectorAll('[data-job-art-card]').forEach((card) => {
                    const filters = (card.dataset.filters || '').split(/\s+/);
                    const isFavorite = favoriteIds.has(String(card.dataset.jobArtId || ''));
                    const visible = currentFilter === 'available'
                        || (currentFilter === 'favorite' ? isFavorite : filters.includes(currentFilter));
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

            root.querySelectorAll('[data-job-art-sort]').forEach((button) => {
                button.addEventListener('click', () => {
                    applySort(button.dataset.jobArtSort);
                    applyFilter(currentFilter);
                });
            });

            root.querySelectorAll('[data-job-art-favorite-toggle]').forEach((button) => {
                button.addEventListener('click', () => {
                    const artId = String(button.dataset.jobArtFavoriteToggle || '');
                    if (!artId) return;
                    if (favoriteIds.has(artId)) {
                        favoriteIds.delete(artId);
                    } else {
                        favoriteIds.add(artId);
                    }
                    saveFavorites();
                    syncFavoriteButtons();
                    applyFilter(currentFilter);
                });
            });

            if (tipsButton && tipsPanel) {
                tipsButton.addEventListener('click', () => {
                    const expanded = tipsButton.getAttribute('aria-expanded') === 'true';
                    tipsButton.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                    tipsPanel.classList.toggle('hidden', expanded);
                });
            }

            syncFavoriteButtons();
            applySort(currentSort);
            applyFilter(currentFilter);
        })();
    </script>
</x-layouts.facility>
