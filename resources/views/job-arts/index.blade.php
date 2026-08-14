@php
    $jobArtV2UiEnabled = (bool) ($jobArtV2UiEnabled ?? false);
    $jobArtTerm = $jobArtV2UiEnabled ? '戦技' : '奥義';
    $pageTitle = $jobArtV2UiEnabled ? '戦技セット' : '奥義セット';
    $lineageGuides = collect($lineageGuides ?? []);
    $lineageTabOrder = ['counter', 'eclipse', 'pierce', 'hunt', 'aim', 'guard', 'transmute', 'break', 'command', 'field'];
    $availableLineages = collect();
    $lineageCounts = collect();
    if ($jobArtV2UiEnabled) {
        foreach ($availableArts as $availableArt) {
            $availableArtDisplay = $availableArt->getAttribute('job_art_v2_loadout_display');
            $lineageKey = (string) ($availableArtDisplay['source_lineage_key'] ?? '');
            $lineageName = (string) ($availableArtDisplay['source_lineage_name'] ?? '');
            if ($lineageKey === '' || $lineageName === '') {
                continue;
            }

            $availableLineages->put($lineageKey, $lineageName);
            $lineageCounts->put($lineageKey, (int) $lineageCounts->get($lineageKey, 0) + 1);
        }
    }
    $lineageTabs = collect($lineageTabOrder)
        ->filter(fn (string $lineageKey) => $lineageGuides->has($lineageKey) || $availableLineages->has($lineageKey))
        ->mapWithKeys(fn (string $lineageKey) => [
            $lineageKey => $lineageGuides->get($lineageKey)['lineage_name'] ?? $availableLineages->get($lineageKey),
        ]);
@endphp
<x-layouts.facility :title="$pageTitle" headerIcon="✦" bgImage="images/bg-castle.webp" :showExit="false">
    <div class="mx-auto w-full max-w-[560px] space-y-4 px-3 pb-24" data-job-art-root data-job-art-v2-ui="{{ $jobArtV2UiEnabled ? '1' : '0' }}">
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

        <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" data-job-art-overview>
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-xs font-black uppercase tracking-[0.16em] text-amber-600">{{ $jobArtV2UiEnabled ? 'BATTLE ARTS' : 'JOB ARTS' }}</div>
                    <h1 class="text-xl font-black text-slate-900">{{ $jobArtTerm }}をセットする</h1>
                    <p class="mt-1 text-xs font-bold leading-relaxed text-slate-500">選ぶとその場で自動保存されます。@unless($jobArtV2UiEnabled)最大{{ $maxSlots }}つまで。@endunless</p>
                </div>
                @if($jobArtV2UiEnabled)
                    <div class="shrink-0 text-right text-[11px] font-bold text-slate-500" data-job-art-overview-meta>
                        <div class="text-[10px] text-slate-400">
                            <strong class="text-slate-700">{{ $maxSlots }}枠</strong>
                            <span class="mx-1 text-slate-300">/</span>
                            Cost上限 <strong class="text-slate-700">{{ $maxCost }}</strong>
                        </div>
                    </div>
                @else
                    <div class="shrink-0 space-y-0.5 text-right text-xs font-black text-slate-500">
                        @foreach(['normal' => '通常', 'boss' => 'ボス', 'pvp' => '対人'] as $slotContext => $shortLabel)
                            @if(array_key_exists($slotContext, $slotContextLabels))
                                <div>{{ $shortLabel }} <span data-job-art-total-cost="{{ $slotContext }}">{{ $totalCostByContext[$slotContext] ?? 0 }}</span>/{{ $maxCost }}</div>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            @if($jobArtV2UiEnabled)
                <div class="mt-3 border-t border-slate-100 pt-3" data-job-art-overview-rules>
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-[11px] font-bold leading-relaxed text-slate-600">
                        <span><strong class="text-slate-800">上から順</strong>に発動候補を判定</span>
                        <span>条件を満たした<strong class="text-slate-800">奥義を優先</strong></span>
                    </div>
                </div>
            @endif

            <div
                class="mt-4 space-y-3"
                x-data="{
                    activeContext: 'normal',
                    availableContexts: @js(array_keys($slotContextLabels)),
                    activeContextStorageKey: 'valzeria.jobArtActiveContext.v1',
                    init() {
                        try {
                            const savedContext = localStorage.getItem(this.activeContextStorageKey);
                            if (this.availableContexts.includes(savedContext)) {
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
                <div class="grid {{ count($slotContextLabels) === 3 ? 'grid-cols-3' : 'grid-cols-2' }} gap-1 rounded-lg bg-slate-100 p-1">
                    @foreach($slotContextLabels as $slotContext => $slotContextLabel)
                        <button
                            type="button"
                            @click="setActiveContext(@js($slotContext))"
                            class="rounded-md px-3 py-1.5 text-sm font-black transition-colors"
                            :class="activeContext === @js($slotContext) ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                        >
                            {{ $jobArtV2UiEnabled ? (['normal' => '通常', 'boss' => 'ボス', 'pvp' => 'PvP'][$slotContext] ?? $slotContextLabel) : $slotContextLabel }}
                        </button>
                    @endforeach
                </div>

                @foreach($slotContextLabels as $slotContext => $slotContextLabel)
                    @php
                        $contextSlots = $selectedSlotsByContext[$slotContext] ?? collect();
                        $contextArts = $availableArtsByContext[$slotContext] ?? collect();
                    @endphp
                    <div x-show="activeContext === @js($slotContext)" class="space-y-2">
                        <div class="flex items-start justify-between gap-3">
                            <p class="min-w-0 flex-1 text-[11px] font-bold leading-relaxed text-slate-400">{{ $slotContextDescriptions[$slotContext] ?? '' }}</p>
                            @if($jobArtV2UiEnabled)
                                <div class="shrink-0 text-xs font-bold text-slate-500">Cost <strong class="text-slate-900"><span data-job-art-total-cost="{{ $slotContext }}">{{ $totalCostByContext[$slotContext] ?? 0 }}</span> / {{ $maxCost }}</strong></div>
                            @endif
                        </div>

                        @if($jobArtV2UiEnabled)
                                <form
                                    method="POST"
                                    action="{{ route('job-arts.policy') }}"
                                    class="rounded-lg border border-slate-200 bg-slate-50/70 p-2.5"
                                    data-job-art-context-sp-policy="{{ $slotContext }}"
                                    data-saved-policy="{{ $contextSpPolicies[$slotContext] ?? 'aggressive' }}"
                                >
                                @csrf
                                <input type="hidden" name="slot_context" value="{{ $slotContext }}">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                            <div class="flex items-center gap-2">
                                                <div class="text-xs font-black text-slate-800">SP方針</div>
                                                <span class="hidden text-[10px] font-black text-emerald-600" data-job-art-context-sp-policy-status aria-live="polite"></span>
                                            </div>
                                        <p class="mt-0.5 text-[10px] font-bold leading-relaxed text-slate-500">この{{ ['normal' => '通常', 'boss' => 'ボス', 'pvp' => 'PvP'][$slotContext] ?? '' }}セットの5枠へ一括適用します。</p>
                                    </div>
                                    <div class="grid grid-cols-3 gap-1 rounded-lg bg-white p-1 shadow-sm">
                                        @foreach($activationPolicyLabels as $policyKey => $policyLabel)
                                            <label>
                                                <input
                                                    type="radio"
                                                    name="activation_policy"
                                                    value="{{ $policyKey }}"
                                                    class="peer sr-only"
                                                    data-job-art-context-sp-policy-radio
                                                    @checked(($contextSpPolicies[$slotContext] ?? 'aggressive') === $policyKey)
                                                >
                                                <span class="flex min-h-8 cursor-pointer items-center justify-center rounded-md border border-transparent px-3 text-[11px] font-black text-slate-500 transition-colors peer-checked:border-indigo-500 peer-checked:bg-indigo-600 peer-checked:text-white">{{ $policyLabel }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                                <p class="mt-2 rounded-md bg-indigo-50 px-2 py-1.5 text-[10px] font-bold text-indigo-700" data-job-art-context-sp-policy-description>
                                    {{ $activationPolicyDescriptions[$contextSpPolicies[$slotContext] ?? 'aggressive'] ?? '' }}
                                </p>
                            </form>
                        @endif

                        @if(($jobArtStarterPresetCount ?? 0) > 0)
                            @include('job-arts.partials.starter-presets', [
                                'starterPresetCount' => $jobArtStarterPresetCount,
                                'slotContext' => $slotContext,
                                'slotContextLabel' => ['normal' => '通常', 'boss' => 'ボス', 'pvp' => 'PvP'][$slotContext] ?? $slotContextLabel,
                            ])
                        @endif

                        <div data-job-art-slots="{{ $slotContext }}" @if($jobArtV2UiEnabled) data-job-art-sortable="true" @endif class="space-y-2">
                            @for($slotNo = 1; $slotNo <= $maxSlots; $slotNo++)
                                @include('job-arts.partials.slot-card', [
                                    'slotContext' => $slotContext,
                                    'slotNo' => $slotNo,
                                    'slot' => $contextSlots->firstWhere('slot_no', $slotNo),
                                    'contextArts' => $contextArts,
                                    'allAvailableArts' => $allAvailableArts,
                                    'maxSp' => $maxSp,
                                    'activationPolicyLabels' => $activationPolicyLabels,
                                    'activationPolicyDescriptions' => $activationPolicyDescriptions,
                                    'slotConditionLabels' => $slotConditionLabels,
                                    'contextTotalCost' => $totalCostByContext[$slotContext] ?? 0,
                                    'maxCost' => $maxCost,
                                    'jobArtV2UiEnabled' => $jobArtV2UiEnabled,
                                    'jobArtV2CardDetailsEnabled' => $jobArtV2CardDetailsEnabled,
                                ])
                            @endfor
                        </div>
                        @if($jobArtV2UiEnabled)
                            <div data-job-art-loadout-diagnosis="{{ $slotContext }}">
                                @include('job-arts.partials.loadout-diagnosis', [
                                    'diagnosis' => $loadoutDiagnosesByContext[$slotContext] ?? [],
                                ])
                            </div>
                        @endif
                    </div>
                @endforeach

                @if($jobArtPresetUiEnabled ?? false)
                    @include('job-arts.partials.presets', [
                        'jobArtPresets' => $jobArtPresets,
                        'jobArtPresetLimit' => $jobArtPresetLimit,
                    ])
                @endif
            </div>
        </section>

        @if($jobArtV2UiEnabled && count($recommendedBattleStyles ?? []) > 0)
            @include('job-arts.partials.recommended-styles', [
                'recommendedBattleStyles' => $recommendedBattleStyles,
            ])
        @endif

        <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <h2 class="text-sm font-black text-slate-900">使用可能な{{ $jobArtTerm }}</h2>
                    <button type="button" data-job-art-tips-toggle aria-expanded="false" class="inline-flex h-6 w-6 items-center justify-center rounded-full border border-sky-200 bg-sky-50 text-xs font-black text-sky-700 shadow-sm transition-colors hover:bg-sky-100" title="バッジの見方">
                        ?
                    </button>
                </div>
                <div class="text-xs font-bold text-slate-400"><span data-job-art-visible-count>{{ $availableArts->count() }}</span>件</div>
            </div>
            <div data-job-art-target-banner class="mb-3 hidden justify-between gap-2 rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-2 text-xs font-black text-indigo-800 {{ $jobArtV2UiEnabled ? 'flex-col items-stretch sm:flex-row sm:items-center' : 'items-center' }}">
                <span>SLOT<span data-job-art-target-slot-no></span>（<span data-job-art-target-context-label></span>）にセットする{{ $jobArtTerm }}を選んでください</span>
                <div class="flex shrink-0 gap-1.5">
                    <button type="button" data-job-art-target-unset class="rounded border border-indigo-300 bg-white px-2 py-1 text-indigo-700">未設定にする</button>
                    <button type="button" data-job-art-target-cancel class="rounded border border-slate-300 bg-white px-2 py-1 text-slate-600">キャンセル</button>
                </div>
            </div>
            <div data-job-art-tips-panel class="mb-3 hidden rounded-md border border-sky-100 bg-sky-50/80 px-3 py-2 text-[11px] font-bold leading-relaxed text-slate-600">
                <div class="font-black text-sky-800">バッジの見方</div>
                <div class="mt-1 grid gap-1 sm:grid-cols-2">
                    @if($jobArtV2UiEnabled)
                        <div><span class="font-black text-slate-800">区分</span>：始動はCost1、連携はCost2、奥義はCost3です。</div>
                        <div><span class="font-black text-slate-800">系譜</span>：その戦技が使うリソースと、得意な戦い方を表します。</div>
                        <div><span class="font-black text-slate-800">効果</span>：習得済みなら、どの職でもカードに書かれた威力と効果がすべて有効です。</div>
                        <div><span class="font-black text-slate-800">消費SP</span>：覚える職の階級と区分で固定され、現在職や系譜では増減しません。</div>
                    @else
                        <div><span class="font-black text-slate-800">効果種別</span>：攻撃、回復、強化など{{ $jobArtTerm }}の主な効果。</div>
                        <div><span class="font-black text-slate-800">発動率</span>：{{ $jobArtTerm }}候補になった時に発動する確率。</div>
                        <div><span class="font-black text-slate-800">消費SP</span>：発動時に使うSPの基本値。</div>
                        <div><span class="font-black text-slate-800">本職</span>：現在職で使う時の実消費SP。</div>
                        <div><span class="font-black text-slate-800">継承</span>：継承{{ $jobArtTerm }}として使う時の実消費SP。マスター職から継承した{{ $jobArtTerm }}は、威力・効果量が本来の70〜85%になります。</div>
                    @endif
                    @unless($jobArtV2UiEnabled)
                        <div><span class="font-black text-slate-800">CT</span>：使用後、再発動までに必要なターン数。</div>
                        <div><span class="font-black text-slate-800">1戦回数</span>：1戦中に発動できる最大回数。</div>
                    @endunless
                    @unless($jobArtV2UiEnabled)
                        <div><span class="font-black text-slate-800">発動条件</span>：HP割合など、発動候補に入る条件。</div>
                    @endunless
                </div>
            </div>
            @if($jobArtV2UiEnabled && $lineageTabs->isNotEmpty())
                <div class="mb-3" data-job-art-lineage-tabs>
                    <div class="text-xs font-black text-slate-800">系譜から探す</div>
                    <p class="mt-0.5 text-[10px] font-bold leading-relaxed text-slate-500">覚えた戦技を、得意な戦い方ごとに絞り込みます。</p>
                    <div class="mt-2 flex flex-wrap gap-1.5" aria-label="系譜で戦技を絞り込む">
                        <button type="button" data-job-art-lineage-filter="all" aria-pressed="true" class="rounded-full border border-indigo-500 bg-indigo-600 px-3 py-1.5 text-xs font-black text-white shadow-sm">すべて <span class="opacity-75">{{ $availableArts->count() }}</span></button>
                        @foreach($lineageTabs as $lineageKey => $lineageName)
                            <button type="button" data-job-art-lineage-filter="{{ $lineageKey }}" aria-pressed="false" class="rounded-full border border-indigo-100 bg-white px-3 py-1.5 text-xs font-black text-slate-600 shadow-sm transition-colors hover:border-indigo-300 hover:text-indigo-700">
                                {{ $lineageName }} <span class="text-[10px] opacity-60">{{ $lineageCounts->get($lineageKey, 0) }}</span>
                            </button>
                        @endforeach
                    </div>
                <div class="mt-3 border-l-2 border-indigo-200 pl-3" data-job-art-lineage-guide-shell data-job-art-resource-guide>
                        <section data-job-art-lineage-guide="all">
                            <div class="text-[11px] font-black text-slate-800">10系譜の共通ルール</div>
                            <p class="mt-1 text-[11px] font-bold leading-relaxed text-slate-600">
                                多くの系譜は、始動で系譜リソースを+4、連携で-4、奥義で-12します。例外は各系譜・各戦技の説明に数値で表示します。奥義は12ある時、セット順より先に発動判定を行います。
                            </p>
                            <p class="mt-1 text-[10px] font-bold leading-relaxed text-slate-500">
                                系譜を選ぶと、通常攻撃などで増える条件と、その系譜ならではの戦い方を確認できます。
                            </p>
                        </section>
                        @foreach($lineageGuides as $lineageKey => $guide)
                            <section data-job-art-lineage-guide="{{ $lineageKey }}" hidden>
                                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                    <div class="text-[11px] font-black text-slate-900">{{ $guide['lineage_name'] }}系譜の特性</div>
                                    <div class="text-[10px] font-black text-indigo-700">{{ $guide['resource_name'] }} 0〜{{ $guide['max_points'] }}</div>
                                </div>
                                <p class="mt-1 text-[11px] font-bold leading-relaxed text-slate-700">{{ $guide['identity'] }}</p>
                                <dl class="mt-2 space-y-1 text-[10px] font-bold leading-relaxed text-slate-600">
                                    <div class="flex gap-2">
                                        <dt class="shrink-0 text-slate-400">基本</dt>
                                        <dd>{{ implode(' ／ ', $guide['base_flow']) }}</dd>
                                    </div>
                                    @if(count($guide['additional_gains']) > 0)
                                        <div class="flex gap-2">
                                            <dt class="shrink-0 text-slate-400">追加獲得</dt>
                                            <dd>{{ implode(' ／ ', $guide['additional_gains']) }}</dd>
                                        </div>
                                    @endif
                                    <div class="flex gap-2">
                                        <dt class="shrink-0 text-slate-400">戦い方</dt>
                                        <dd>{{ $guide['trait'] }}</dd>
                                    </div>
                                    <div class="flex gap-2">
                                        <dt class="shrink-0 text-slate-400">奥義</dt>
                                        <dd>{{ $guide['ultimate'] }}</dd>
                                    </div>
                                </dl>
                                @if(count($guide['field_effects']) > 0)
                                    <ul class="mt-2 space-y-0.5 text-[10px] font-bold leading-relaxed text-slate-500">
                                        @foreach($guide['field_effects'] as $fieldEffect)
                                            <li>・{{ $fieldEffect }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                <p class="mt-2 text-[10px] font-bold leading-relaxed text-slate-400">{{ $guide['inheritance'] }}</p>
                            </section>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="mb-3 space-y-2">
                <details class="rounded-md border border-slate-200 bg-slate-50/70 px-3 py-2" open>
                    <summary class="cursor-pointer text-xs font-black text-slate-700">絞り込み</summary>
                    <div class="mt-2 flex flex-wrap gap-1.5 text-xs font-black">
                        @php
                            $filterOptions = $jobArtV2UiEnabled
                                ? [
                                    'available' => 'すべて',
                                    'equipped' => 'セット中',
                                    'favorite' => 'お気に入り',
                                    'starter' => '始動',
                                    'combo' => '連携',
                                    'ultimate' => '奥義',
                                    'attack' => '攻撃',
                                    'buff' => '強化',
                                    'debuff' => '弱体',
                                    'recovery' => '回復',
                                    'defense' => '防御',
                                    'reward' => '報酬',
                                ]
                                : [
                                    'available' => '使用可能',
                                    'favorite' => 'お気に入り',
                                    'current' => '現在職',
                                    'inherited' => '継承',
                                    'attack' => '攻撃',
                                    'buff' => '強化',
                                    'debuff' => '弱体',
                                    'recovery' => '回復',
                                    'defense' => '防御',
                                    'reward' => '報酬',
                                ];
                        @endphp
                        @foreach($filterOptions as $key => $label)
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
                        $cost = (int) ($art->getAttribute('job_art_effective_cost') ?? $art->art_cost);
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
                        $v2Display = $jobArtV2UiEnabled ? $art->getAttribute('job_art_v2_loadout_display') : null;
                        $sourceLineageKey = (string) ($v2Display['source_lineage_key'] ?? '');
                        $displayEffectTemplate = (string) ($v2Display['effect_template'] ?? $art->effect_template);
                        $cardDescription = (string) ($v2Display['display_description'] ?? $v2Display['card_description'] ?? '戦況に応じた効果を発動する。');
                        $filterTokens = ['available'];
                        if (!$jobArtV2UiEnabled) {
                            $filterTokens[] = $art->getAttribute('job_art_origin') === 'current' ? 'current' : 'inherited';
                        } else {
                            $stageFilterToken = match ((int) $art->learn_rank) {
                                1 => 'starter',
                                5 => 'combo',
                                9 => 'ultimate',
                                default => null,
                            };
                            if ($stageFilterToken !== null) {
                                $filterTokens[] = $stageFilterToken;
                            }
                        }
                        if (str_contains($cardDescription, 'ダメージを与える') || ($v2Display === null && $art->art_category === 'attack')) {
                            $filterTokens[] = 'attack';
                        }
                        if (
                            in_array($displayEffectTemplate, ['DAMAGE_BUFF', 'MAGICAL_DAMAGE_BUFF', 'SELF_BUFF'], true)
                            || preg_match('/自分[^。]{0,160}\+\d+%/u', $cardDescription) === 1
                            || ($v2Display === null && $art->art_category === 'buff')
                        ) {
                            $filterTokens[] = 'buff';
                        }
                        if (
                            in_array($displayEffectTemplate, ['DAMAGE_DEBUFF', 'ENEMY_DEBUFF'], true)
                            || preg_match('/相手[^。]{0,160}-\d+%/u', $cardDescription) === 1
                            || ($v2Display === null && $art->art_category === 'debuff')
                        ) {
                            $filterTokens[] = 'debuff';
                        }
                        if (preg_match('/(?:HP|SP)[^。]{0,80}回復(?:する|し、|し$)/u', $cardDescription) === 1) {
                            $filterTokens[] = 'recovery';
                        }
                        if (
                            in_array($displayEffectTemplate, ['GUARD_BARRIER', 'DAMAGE_GUARD_BARRIER', 'GUTS'], true)
                            || preg_match('/軽減|受け流し|踏みとどまり|障壁/u', $cardDescription) === 1
                            || ($v2Display === null && in_array($art->art_category, ['defense', 'guard'], true))
                        ) {
                            $filterTokens[] = 'defense';
                        }
                        if ($art->limit_group === 'REWARD' || $art->art_category === 'reward') {
                            $filterTokens[] = 'reward';
                        }
                        $limitLabel = $art->jobArtLimitLabel();
                        $artOrigin = (string) ($art->getAttribute('job_art_origin') ?: 'inherited');
                        $displaySpCost = (int) ($art->getAttribute('job_art_display_sp_cost')
                            ?? $art->jobArtSpCostForMaxSp($maxSp, $artOrigin));
                        $displayActivationRate = (int) ($art->getAttribute('job_art_display_activation_rate')
                            ?? $art->effectiveActivationRate());
                        $statLabelReplacements = [
                            'ATK' => '攻撃',
                            'DEF' => '防御',
                            'SPD' => '敏捷',
                            'MAG' => '魔力',
                            'SPR' => '精神',
                            'LUK' => '運',
                        ];
                        $displayMemo = strtr((string) ($art->memo ?: $art->description), $statLabelReplacements);
                        if (!empty($v2Display['legacy_effect_copy_suppressed'])) {
                            $displayMemo = '';
                        } elseif ($jobArtV2UiEnabled) {
                            $displayMemo = trim((string) preg_replace(
                                '/[、。・\s]*(?:CT\s*\d+|1戦\s*\d+\s*回)[、。・\s]*/u',
                                ' ',
                                $displayMemo,
                            ));
                        }
                        $numericEffectLabels = $art->jobArtNumericEffectLabels(
                            $v2Display['effective_power'] ?? null,
                            $v2Display['effect_template'] ?? null,
                            $v2Display['effective_hit_count'] ?? null,
                        );
                        $originDisplayLabel = $art->getAttribute('job_art_origin') === 'current'
                            ? '本職'
                            : '継承 ' . (int) round(((float) $art->getAttribute('job_art_rate')) * 100) . '%';
                        $lineageDisplayLabel = !empty($v2Display['source_lineage_name'])
                            ? $v2Display['source_lineage_name'] . '系譜'
                            : '系譜未設定';
                        $roleBadgeClass = match ($v2Display['role_key'] ?? null) {
                            'producer' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                            'consumer' => 'border-sky-200 bg-sky-50 text-sky-700',
                            'finisher' => 'border-amber-300 bg-amber-100 text-amber-900',
                            default => 'border-violet-200 bg-violet-50 text-violet-700',
                        };
                        $v2CardClass = match ($v2Display['role_key'] ?? null) {
                            'producer' => 'border-emerald-300 bg-gradient-to-br from-white via-white to-emerald-50/70',
                            'consumer' => 'border-sky-300 bg-gradient-to-br from-white via-white to-sky-50/80',
                            'finisher' => 'border-amber-400 bg-gradient-to-br from-white via-amber-50/30 to-amber-100/80',
                            default => 'border-violet-300 bg-gradient-to-br from-white via-white to-violet-50/70',
                        };
                        $v2HeaderClass = match ($v2Display['role_key'] ?? null) {
                            'producer' => 'border-emerald-200 bg-emerald-50/80',
                            'consumer' => 'border-sky-200 bg-sky-50/80',
                            'finisher' => 'border-amber-200 bg-amber-50/90',
                            default => 'border-violet-200 bg-violet-50/80',
                        };
                        $isUltimate = (bool) ($v2Display['is_ultimate'] ?? false);
                        $v2StageLabel = match ((int) $art->learn_rank) {
                            1 => '始動',
                            5 => '連携',
                            9 => '奥義',
                            default => (string) ($v2Display['role_label'] ?? '戦技'),
                        };
                        $validContexts = [];
                        foreach (array_keys($slotContextLabels) as $slotContext) {
                            if (($availableArtsByContext[$slotContext] ?? collect())->contains('id', $art->id)) {
                                $validContexts[] = $slotContext;
                            }
                        }
                        $jobArtIconPath = (string) ($art->getAttribute('job_art_icon_path') ?? '');
                    @endphp
                    <article
                        data-job-art-card
                        data-job-art-id="{{ $art->id }}"
                        data-lineage-key="{{ $sourceLineageKey }}"
                        data-filters="{{ implode(' ', array_unique($filterTokens)) }}"
                        data-sort-index="{{ $loop->index }}"
                        data-cost="{{ $cost }}"
                        data-activation-rate="{{ $displayActivationRate }}"
                        data-name="{{ $art->name }}"
                        data-stage-label="{{ $v2StageLabel }}"
                        data-lineage-label="{{ $lineageDisplayLabel }}"
                        data-job-art-contexts="{{ implode(' ', $validContexts) }}"
                        class="{{ $jobArtV2UiEnabled ? 'min-w-0 overflow-hidden rounded-xl border-2 p-0 shadow-sm transition-shadow hover:shadow-md ' . $v2CardClass : 'rounded-md border px-3 py-2 ' . $costCardClass }}"
                    >
                        @if($jobArtV2UiEnabled)
                            <div class="border-b px-3 py-2.5 {{ $v2HeaderClass }}" data-job-art-card-header>
                                <div class="flex min-w-0 flex-col gap-2 min-[440px]:flex-row min-[440px]:items-start min-[440px]:justify-between">
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        @if($jobArtIconPath !== '')
                                            <img
                                                src="{{ asset($jobArtIconPath) }}"
                                                alt=""
                                                width="56"
                                                height="56"
                                                loading="lazy"
                                                decoding="async"
                                                class="h-14 w-14 shrink-0 object-contain"
                                                data-job-art-icon
                                                data-job-art-card-icon
                                            >
                                        @endif
                                        <div class="min-w-0">
                                            <div class="text-[11px] font-black tracking-wide text-slate-500">{{ $art->jobClass?->name ?? '職業' }} / {{ $v2StageLabel }}</div>
                                            <div class="mt-0.5 break-words text-[15px] font-black leading-snug text-slate-950">{{ $art->name }}</div>
                                        </div>
                                    </div>
                                    <div class="ml-auto flex shrink-0 items-start gap-2">
                                        @if($v2Display['source_lineage_icon_path'] ?? null)
                                            <img
                                                src="{{ asset($v2Display['source_lineage_icon_path']) }}"
                                                alt="{{ $lineageDisplayLabel }}のアイコン"
                                                width="40"
                                                height="40"
                                                loading="lazy"
                                                decoding="async"
                                                class="h-10 w-10 shrink-0 object-contain"
                                                data-job-art-lineage-icon
                                            >
                                        @endif
                                        <div class="rounded-lg border-2 px-2.5 py-1 text-center {{ $costBadgeClass }}" aria-label="Cost {{ $cost }}">
                                            <div class="text-[9px] font-black uppercase leading-none tracking-wider">Cost</div>
                                            <div class="mt-0.5 text-base font-black leading-none">{{ $cost }}</div>
                                        </div>
                                        <button type="button" data-job-art-favorite-toggle="{{ $art->id }}" data-job-art-card-favorite aria-pressed="false" aria-label="お気に入り" class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 bg-white text-base font-black text-slate-300 shadow-sm transition-colors hover:border-amber-300 hover:text-amber-600" title="お気に入り">
                                            <span class="leading-none text-slate-300" data-job-art-favorite-icon>☆</span>
                                            <span class="sr-only">お気に入り</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-2.5 px-3 py-3">
                                <div class="flex flex-wrap gap-1.5" data-job-art-card-meta>
                                    @if($v2Display)
                                        <span class="inline-flex rounded-full border px-2.5 py-1 text-[10px] font-black {{ $roleBadgeClass }}" data-job-art-v2-role>{{ $v2Display['role_label'] }}</span>
                                    @endif
                                    <span class="inline-flex rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-[10px] font-black text-indigo-700" data-job-art-lineage-badge>{{ $lineageDisplayLabel }}</span>
                                </div>

                                <div data-job-art-card-body>
                                    <div class="rounded-lg border {{ $isUltimate ? 'border-amber-200 bg-amber-50/70' : 'border-slate-200 bg-white/85' }} px-3 py-3" data-job-art-card-description data-job-art-v2-details>
                                        <div class="text-[9px] font-black tracking-widest text-slate-400">効果</div>
                                        <p class="mt-1 break-words text-[12px] font-bold leading-6 text-slate-800">@include('job-arts.partials.effect-text', ['text' => $cardDescription])</p>
                                    </div>
                                </div>

                                <div class="flex justify-end" data-job-art-card-footer>
                                    <div class="space-y-0.5 text-right">
                                        @foreach(['normal' => '通常', 'boss' => 'ボス', 'pvp' => '対人'] as $slotContext => $shortLabel)
                                            @continue(!array_key_exists($slotContext, $slotContextLabels))
                                            @php
                                                $selectedSlotForContext = (int) (($selectedSlotBySkillByContext[$slotContext][$art->id] ?? 0) ?: 0);
                                            @endphp
                                            <div
                                                data-job-art-status="{{ $slotContext }}"
                                                class="text-[10px] font-black {{ $slotContext === 'normal' ? 'text-emerald-600' : ($slotContext === 'boss' ? 'text-indigo-600' : 'text-rose-600') }} {{ $selectedSlotForContext ? '' : 'hidden' }}"
                                            >{{ $shortLabel }} Slot<span data-job-art-status-slot>{{ $selectedSlotForContext ?: '' }}</span> セット中</div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="text-[11px] font-black text-slate-500">{{ $art->jobClass?->name ?? '職業' }} / Rank{{ $art->learn_rank }} / {{ $originDisplayLabel }}</div>
                                    <div class="truncate text-sm font-black text-slate-900">{{ $art->name }}</div>
                                </div>
                                <div class="shrink-0 space-y-1 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <button type="button" data-job-art-favorite-toggle="{{ $art->id }}" aria-pressed="false" class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white text-sm font-black text-slate-300 shadow-sm transition-colors hover:border-amber-300 hover:text-amber-500" title="お気に入り">☆</button>
                                        <div class="inline-flex rounded border px-2 py-1 text-xs font-black {{ $costBadgeClass }}">Cost {{ $cost }}</div>
                                    </div>
                                    @foreach(['normal' => '通常', 'boss' => 'ボス', 'pvp' => '対人'] as $slotContext => $shortLabel)
                                        @continue(!array_key_exists($slotContext, $slotContextLabels))
                                        @php
                                            $selectedSlotForContext = (int) (($selectedSlotBySkillByContext[$slotContext][$art->id] ?? 0) ?: 0);
                                        @endphp
                                        <div
                                            data-job-art-status="{{ $slotContext }}"
                                            class="text-[10px] font-black {{ $slotContext === 'normal' ? 'text-emerald-600' : ($slotContext === 'boss' ? 'text-indigo-600' : 'text-rose-600') }} {{ $selectedSlotForContext ? '' : 'hidden' }}"
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
                                <span class="rounded bg-white px-2 py-0.5 text-slate-500">発動{{ $displayActivationRate }}%</span>
                                <span class="rounded bg-white px-2 py-0.5 text-slate-500">消費SP {{ $displaySpCost }}</span>
                                @if($art->isHealArt())
                                    <span class="rounded bg-white px-2 py-0.5 text-emerald-600">HP70%以下</span>
                                @endif
                                @if(!$jobArtV2UiEnabled && $art->cooldown_turns)
                                    <span class="rounded bg-white px-2 py-0.5 text-slate-500">CT{{ $art->cooldown_turns }}</span>
                                @endif
                                @if(!$jobArtV2UiEnabled && $art->max_uses_per_battle)
                                    <span class="rounded bg-white px-2 py-0.5 text-slate-500">1戦{{ $art->max_uses_per_battle }}回</span>
                                @endif
                            </div>
                            @if($displayMemo !== '')
                                <div class="mt-2 flex items-start gap-2 text-[11px] font-bold leading-relaxed text-slate-600">
                                    <span class="mt-0.5 shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-black tracking-wide text-slate-500">補足</span>
                                    <p class="min-w-0 break-words">{{ $displayMemo }}</p>
                                </div>
                            @endif
                        @endif
                        <div class="{{ $jobArtV2UiEnabled ? 'px-3 pb-3' : '' }}">
                            @if($jobArtV2UiEnabled)
                                <button type="button"
                                    data-job-art-open-replace
                                    data-art-id="{{ $art->id }}"
                                    class="mt-2 inline-flex min-h-10 w-full items-center justify-center rounded-md bg-indigo-600 px-3 py-2 text-xs font-black text-white shadow-sm transition-colors hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:ring-offset-2">
                                    セットする
                                </button>
                            @endif
                            <button type="button"
                                data-job-art-assign-btn
                                data-art-id="{{ $art->id }}"
                                class="mt-2 hidden w-full rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-black text-white shadow-sm transition-colors hover:bg-indigo-700">
                                この{{ $jobArtTerm }}をセットする
                            </button>
                            <div data-job-art-assign-unavailable class="mt-2 hidden rounded-md bg-slate-100 px-3 py-1.5 text-center text-[11px] font-bold text-slate-400">
                                このセットでは使用できません
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="rounded-md bg-slate-50 px-3 py-6 text-center text-sm font-bold text-slate-400">条件を満たした{{ $jobArtTerm }}はまだありません。</div>
                @endforelse
                <div data-job-art-empty class="hidden rounded-md bg-slate-50 px-3 py-6 text-center text-sm font-bold text-slate-400">この絞り込みに該当する{{ $jobArtTerm }}はありません。</div>
            </div>
        </section>

        @if($jobArtV2UiEnabled)
            <div
                data-job-art-replace-modal
                class="fixed inset-0 z-[100] hidden overflow-y-auto bg-slate-950/60 px-3 py-5 backdrop-blur-[1px] sm:py-10"
                role="dialog"
                aria-modal="true"
                aria-labelledby="job-art-replace-title"
            >
                <div class="mx-auto flex min-h-full max-w-lg items-start justify-center">
                    <section class="my-auto w-full overflow-hidden rounded-2xl bg-white shadow-2xl">
                        <header class="flex items-start justify-between gap-3 bg-slate-900 px-4 py-3 text-white">
                            <div class="min-w-0">
                                <div class="text-[10px] font-black tracking-[0.16em] text-indigo-200">SET BATTLE ART</div>
                                <h2 id="job-art-replace-title" class="mt-0.5 text-base font-black">交換する枠を選ぶ</h2>
                                <p class="mt-1 text-[11px] font-bold leading-relaxed text-slate-300">
                                    <span data-job-art-replace-context-label>通常</span>セットへ
                                    「<span data-job-art-replace-art-name></span>」を設定します。
                                </p>
                            </div>
                            <button type="button" data-job-art-replace-close aria-label="交換画面を閉じる" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-lg font-black hover:bg-white/20">×</button>
                        </header>

                        <div class="p-3 sm:p-4">
                            <p class="mb-2 text-[11px] font-bold leading-relaxed text-slate-500">現在セット中の5枠です。入れ替える枠をタップしてください。</p>
                            <div data-job-art-replace-slots class="space-y-2"></div>
                            <button type="button" data-job-art-replace-close class="mt-3 inline-flex min-h-10 w-full items-center justify-center rounded-md border border-slate-300 bg-white px-4 text-xs font-black text-slate-700 hover:bg-slate-50">キャンセル</button>
                        </div>
                    </section>
                </div>
            </div>
        @endif

        <x-back-button href="{{ route('home') }}" label="ホームに戻る" icon="🏠" />
    </div>
    <script>
        (() => {
            const root = document.querySelector('[data-job-art-root]');
            if (!root) return;

            const SLOT_SET_URL = @json(route('job-arts.slot-set'));
            const REORDER_URL = @json(route('job-arts.reorder'));
            const POLICY_URL = @json(route('job-arts.policy'));
            const CSRF_TOKEN = @json(csrf_token());
            const CONTEXT_LABELS = { normal: '通常', boss: 'ボス', pvp: '対人' };
            const SP_POLICY_DESCRIPTIONS = @json($activationPolicyDescriptions);

            const replaceDiagnosis = (context, html) => {
                if (!context || typeof html !== 'string') return;
                const container = root.querySelector('[data-job-art-loadout-diagnosis="' + context + '"]');
                if (container) container.innerHTML = html;
            };

            const targetBanner = root.querySelector('[data-job-art-target-banner]');
            const targetSlotNoEl = root.querySelector('[data-job-art-target-slot-no]');
            const targetContextLabelEl = root.querySelector('[data-job-art-target-context-label]');
            const targetUnsetBtn = root.querySelector('[data-job-art-target-unset]');
            const targetCancelBtn = root.querySelector('[data-job-art-target-cancel]');
            const replaceModal = root.querySelector('[data-job-art-replace-modal]');
            const replaceSlots = root.querySelector('[data-job-art-replace-slots]');
            const replaceArtName = root.querySelector('[data-job-art-replace-art-name]');
            const replaceContextLabel = root.querySelector('[data-job-art-replace-context-label]');

            let target = null; // { context, slotNo, skillName }
            let assignmentPending = false;
            let replacement = null; // { artId, context, opener }

            const setAssignmentPending = (pending) => {
                assignmentPending = pending;
                root.toggleAttribute('aria-busy', pending);

                root.querySelectorAll('[data-job-art-assign-btn], [data-job-art-open-replace], [data-job-art-replace-slot], [data-job-art-replace-close], [data-job-art-target-btn], [data-job-art-target-unset], [data-job-art-target-cancel], [data-job-art-policy-radio], [data-job-art-context-sp-policy-radio], [data-job-art-drag-handle]').forEach((control) => {
                    control.disabled = pending;
                    control.classList.toggle('is-action-processing', pending);
                });
            };

            // Dormant while BATTLE_JOB_ART_LOADOUT_CARD_DETAILS=false. Kept so
            // the compact-card accordion can be restored without rebuilding it.
            const setSlotCardExpanded = (card, expanded) => {
                if (!card) return;
                card.toggleAttribute('data-job-art-expanded', expanded);
                card.querySelectorAll('[data-job-art-slot-expanded]').forEach((element) => {
                    element.classList.toggle('hidden', !expanded);
                });
                card.querySelector('[data-job-art-slot-summary]')?.classList.toggle('line-clamp-3', !expanded);
                const toggle = card.querySelector('[data-job-art-slot-accordion-toggle]');
                if (!toggle) return;
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                toggle.setAttribute('aria-label', (card.dataset.skillName || '戦技') + 'の詳細を' + (expanded ? '閉じる' : '開く'));
                const label = toggle.querySelector('[data-job-art-slot-accordion-label]');
                const icon = toggle.querySelector('[data-job-art-slot-accordion-icon]');
                if (label) label.textContent = expanded ? '閉じる' : '詳細';
                if (icon) icon.textContent = expanded ? '⌃' : '⌄';
            };

            const initializeSlotAccordions = (scope = root) => {
                scope.querySelectorAll('[data-job-art-slot-card]').forEach((card) => {
                    if (card.querySelector('[data-job-art-slot-accordion-toggle]')) {
                        setSlotCardExpanded(card, false);
                    }
                });
            };

            const syncAvailableSlotBadges = (context, selectedSlotBySkill) => {
                root.querySelectorAll('[data-job-art-card]').forEach((card) => {
                    const badge = card.querySelector('[data-job-art-status="' + context + '"]');
                    if (!badge) return;
                    const cardSlotNo = selectedSlotBySkill?.[card.dataset.jobArtId] || 0;
                    if (cardSlotNo) {
                        badge.classList.remove('hidden');
                        const slotSpan = badge.querySelector('[data-job-art-status-slot]');
                        if (slotSpan) slotSpan.textContent = cardSlotNo;
                    } else {
                        badge.classList.add('hidden');
                    }
                });
            };

            const reindexSlotCards = (container) => {
                const context = container.dataset.jobArtSlots;
                [...container.querySelectorAll(':scope > [data-job-art-slot-card]')].forEach((card, index) => {
                    const slotNo = index + 1;
                    card.dataset.slotNo = String(slotNo);
                    card.dataset.jobArtSlotCard = context + '-' + slotNo;
                    const slotIndex = card.querySelector('[data-job-art-slot-index]');
                    if (slotIndex) slotIndex.textContent = String(slotNo);
                    card.querySelectorAll('[data-slot-no]').forEach((element) => {
                        element.dataset.slotNo = String(slotNo);
                    });
                    const condition = card.querySelector('[data-job-art-condition-select]');
                    if (condition) condition.name = context + '_condition_' + slotNo;
                });
            };

            const persistSlotOrder = async (container, originalCards) => {
                if (assignmentPending) return false;
                setAssignmentPending(true);
                const context = container.dataset.jobArtSlots;
                const cards = [...container.querySelectorAll(':scope > [data-job-art-slot-card]')];
                const formData = new FormData();
                formData.append('slot_context', context);
                cards.forEach((card, index) => {
                    const skillId = Number.parseInt(card.dataset.skillId || '0', 10);
                    formData.append('ordered_skill_ids[' + index + ']', skillId > 0 ? String(skillId) : '');
                });

                try {
                    const response = await fetch(REORDER_URL, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': CSRF_TOKEN,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData,
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        throw new Error(payload.message || '並び順を保存できませんでした。');
                    }
                    reindexSlotCards(container);
                    syncAvailableSlotBadges(context, payload.selected_slot_by_skill || {});
                    replaceDiagnosis(context, payload.diagnosis_html);
                    return true;
                } catch (error) {
                    originalCards.forEach((card) => container.appendChild(card));
                    reindexSlotCards(container);
                    alert(error.message || '並び順を保存できませんでした。');
                    return false;
                } finally {
                    setAssignmentPending(false);
                }
            };

            let slotDrag = null;
            let nativeSlotDrag = null;

            const setSlotDragVisual = (card, active) => {
                if (!card) return;
                card.toggleAttribute('data-job-art-dragging', active);
                card.classList.toggle('scale-[1.01]', active);
                card.classList.toggle('border-indigo-400', active);
                card.classList.toggle('bg-indigo-50', active);
                card.classList.toggle('shadow-xl', active);
                card.classList.toggle('ring-2', active);
                card.classList.toggle('ring-indigo-300', active);
                card.classList.toggle('opacity-90', active);
                const handle = card.querySelector('[data-job-art-drag-handle]');
                handle?.classList.toggle('cursor-grabbing', active);
                handle?.classList.toggle('border-indigo-400', active);
                handle?.classList.toggle('bg-indigo-100', active);
                handle?.classList.toggle('text-indigo-700', active);
                card.querySelector('[data-job-art-drag-label]')?.classList.toggle('hidden', !active);
            };

            const setSlotDropTarget = (card) => {
                root.querySelectorAll('[data-job-art-drop-target]').forEach((targetCard) => {
                    if (targetCard !== card) targetCard.removeAttribute('data-job-art-drop-target');
                });
                card?.setAttribute('data-job-art-drop-target', 'true');
            };

            const clearSlotDropTargets = () => {
                root.querySelectorAll('[data-job-art-drop-target]').forEach((card) => {
                    card.removeAttribute('data-job-art-drop-target');
                });
            };

            root.addEventListener('pointerdown', (event) => {
                const handle = event.target.closest('[data-job-art-drag-handle]');
                if (!handle || assignmentPending || event.pointerType === 'mouse') return;
                const card = handle.closest('[data-job-art-slot-card]');
                const container = card?.closest('[data-job-art-sortable]');
                if (!card || !container) return;
                event.preventDefault();
                slotDrag = {
                    card,
                    container,
                    originalCards: [...container.querySelectorAll(':scope > [data-job-art-slot-card]')],
                    moved: false,
                };
                setSlotDragVisual(card, true);
            });

            window.addEventListener('pointermove', (event) => {
                if (!slotDrag) return;
                event.preventDefault();
                const element = document.elementFromPoint(event.clientX, event.clientY);
                const overCard = element?.closest?.('[data-job-art-slot-card]');
                if (!overCard || overCard === slotDrag.card || overCard.parentElement !== slotDrag.container) return;
                setSlotDropTarget(overCard);
                const rect = overCard.getBoundingClientRect();
                const before = event.clientY < rect.top + (rect.height / 2);
                slotDrag.container.insertBefore(slotDrag.card, before ? overCard : overCard.nextElementSibling);
                slotDrag.moved = true;
            }, { passive: false });

            window.addEventListener('pointerup', async () => {
                if (!slotDrag) return;
                const finishedDrag = slotDrag;
                slotDrag = null;
                setSlotDragVisual(finishedDrag.card, false);
                clearSlotDropTargets();
                if (finishedDrag.moved) {
                    await persistSlotOrder(finishedDrag.container, finishedDrag.originalCards);
                }
            });

            window.addEventListener('pointercancel', () => {
                if (!slotDrag) return;
                slotDrag.originalCards.forEach((card) => slotDrag.container.appendChild(card));
                setSlotDragVisual(slotDrag.card, false);
                clearSlotDropTargets();
                slotDrag = null;
            });

            root.addEventListener('dragstart', (event) => {
                const handle = event.target.closest('[data-job-art-drag-handle][draggable="true"]');
                const card = handle?.closest('[data-job-art-slot-card]');
                const container = card?.closest('[data-job-art-sortable]');
                if (!card || !container || assignmentPending) {
                    event.preventDefault();
                    return;
                }
                nativeSlotDrag = {
                    card,
                    container,
                    originalCards: [...container.querySelectorAll(':scope > [data-job-art-slot-card]')],
                    moved: false,
                };
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', card.dataset.skillId || '');
                setSlotDragVisual(card, true);
                const rect = card.getBoundingClientRect();
                event.dataTransfer.setDragImage(card, Math.min(36, Math.max(0, event.clientX - rect.left)), 20);
            });

            root.addEventListener('dragover', (event) => {
                if (!nativeSlotDrag) return;
                const overCard = event.target.closest('[data-job-art-slot-card]');
                if (!overCard || overCard === nativeSlotDrag.card || overCard.parentElement !== nativeSlotDrag.container) return;
                event.preventDefault();
                setSlotDropTarget(overCard);
                const rect = overCard.getBoundingClientRect();
                const before = event.clientY < rect.top + (rect.height / 2);
                nativeSlotDrag.container.insertBefore(nativeSlotDrag.card, before ? overCard : overCard.nextElementSibling);
                nativeSlotDrag.moved = true;
            });

            root.addEventListener('drop', (event) => {
                if (nativeSlotDrag) event.preventDefault();
            });

            root.addEventListener('dragend', async () => {
                if (!nativeSlotDrag) return;
                const finishedDrag = nativeSlotDrag;
                nativeSlotDrag = null;
                setSlotDragVisual(finishedDrag.card, false);
                clearSlotDropTargets();
                if (finishedDrag.moved) {
                    await persistSlotOrder(finishedDrag.container, finishedDrag.originalCards);
                }
            });

            const updateAssignButtons = () => {
                root.querySelectorAll('[data-job-art-card]').forEach((card) => {
                    const assignBtn = card.querySelector('[data-job-art-assign-btn]');
                    const openReplaceBtn = card.querySelector('[data-job-art-open-replace]');
                    const unavailableEl = card.querySelector('[data-job-art-assign-unavailable]');
                    if (!assignBtn || !unavailableEl) return;

                    if (!target) {
                        openReplaceBtn?.classList.remove('hidden');
                        openReplaceBtn?.classList.add('inline-flex');
                        assignBtn.classList.add('hidden');
                        unavailableEl.classList.add('hidden');
                        return;
                    }

                    openReplaceBtn?.classList.remove('inline-flex');
                    openReplaceBtn?.classList.add('hidden');
                    const contexts = (card.dataset.jobArtContexts || '').split(/\s+/).filter(Boolean);
                    const eligible = contexts.includes(target.context);
                    assignBtn.textContent = target.skillName
                        ? target.skillName + 'と交換する'
                        : 'この枠にセットする';
                    assignBtn.classList.toggle('hidden', !eligible);
                    unavailableEl.classList.toggle('hidden', eligible);
                });
            };

            const setTarget = (context, slotNo) => {
                const targetCard = root.querySelector('[data-job-art-slot-card="' + context + '-' + Number(slotNo) + '"]');
                target = {
                    context,
                    slotNo: Number(slotNo),
                    skillName: targetCard?.dataset.skillName || '',
                };

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

            const activeSlotContext = () => {
                const visibleContainer = [...root.querySelectorAll('[data-job-art-slots]')]
                    .find((container) => container.offsetParent !== null);
                if (visibleContainer?.dataset.jobArtSlots) {
                    return visibleContainer.dataset.jobArtSlots;
                }

                try {
                    const saved = localStorage.getItem('valzeria.jobArtActiveContext.v1');
                    if (saved && Object.prototype.hasOwnProperty.call(CONTEXT_LABELS, saved)) return saved;
                } catch (error) {}

                return 'normal';
            };

            const appendReplacementMeta = (parent, label, className) => {
                if (!label) return;
                const badge = document.createElement('span');
                badge.className = className;
                badge.textContent = label;
                parent.appendChild(badge);
            };

            const renderReplacementSlots = () => {
                if (!replacement || !replaceSlots) return;
                replaceSlots.replaceChildren();
                const container = root.querySelector('[data-job-art-slots="' + replacement.context + '"]');
                const slotCards = container
                    ? [...container.querySelectorAll(':scope > [data-job-art-slot-card]')]
                    : [];
                const alreadySet = slotCards.some(
                    (slotCard) => Number.parseInt(slotCard.dataset.skillId || '0', 10) === Number(replacement.artId),
                );

                slotCards.forEach((slotCard, index) => {
                    const slotNo = Number.parseInt(slotCard.dataset.slotNo || String(index + 1), 10);
                    const currentSkillId = Number.parseInt(slotCard.dataset.skillId || '0', 10);
                    const isCurrent = currentSkillId === Number(replacement.artId);
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.dataset.jobArtReplaceSlot = String(slotNo);
                    button.disabled = alreadySet;
                    button.className = 'flex min-h-16 w-full items-center gap-3 rounded-xl border px-3 py-2.5 text-left transition-colors ' + (isCurrent
                        ? 'cursor-default border-indigo-300 bg-indigo-50 opacity-75'
                        : 'border-slate-200 bg-white hover:border-indigo-400 hover:bg-indigo-50');

                    const number = document.createElement('span');
                    number.className = 'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-800 text-sm font-black text-white';
                    number.textContent = String(slotNo);
                    button.appendChild(number);

                    const body = document.createElement('span');
                    body.className = 'min-w-0 flex-1';
                    const name = document.createElement('span');
                    name.className = 'block break-words text-sm font-black text-slate-900';
                    name.textContent = slotCard.dataset.skillName || '空き枠';
                    body.appendChild(name);

                    const meta = document.createElement('span');
                    meta.className = 'mt-1 flex flex-wrap items-center gap-1.5';
                    if (currentSkillId > 0) {
                        appendReplacementMeta(meta, slotCard.dataset.roleLabel || '', 'rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-black text-slate-600');
                        appendReplacementMeta(meta, slotCard.dataset.lineageLabel ? slotCard.dataset.lineageLabel + '系譜' : '', 'rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-black text-indigo-700');
                        appendReplacementMeta(meta, 'Cost ' + (slotCard.dataset.effectiveCost || '0'), 'rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-black text-emerald-700');
                    } else {
                        appendReplacementMeta(meta, 'この空き枠へ設定', 'text-[10px] font-black text-indigo-600');
                    }
                    body.appendChild(meta);
                    button.appendChild(body);

                    const action = document.createElement('span');
                    action.className = 'shrink-0 text-[10px] font-black ' + (isCurrent ? 'text-indigo-600' : 'text-slate-500');
                    action.textContent = isCurrent ? 'セット中' : (alreadySet ? '—' : (currentSkillId > 0 ? '交換' : '設定'));
                    button.appendChild(action);
                    replaceSlots.appendChild(button);
                });
            };

            const closeReplacementModal = (restoreFocus = true) => {
                if (!replaceModal || !replacement) return;
                const opener = replacement.opener;
                replacement = null;
                replaceModal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
                if (restoreFocus) opener?.focus?.();
            };

            const openReplacementModal = (button) => {
                if (!replaceModal) return;
                const card = button.closest('[data-job-art-card]');
                if (!card) return;
                const context = activeSlotContext();
                const contexts = (card.dataset.jobArtContexts || '').split(/\s+/).filter(Boolean);
                if (!contexts.includes(context)) {
                    alert('この戦技は現在の' + (CONTEXT_LABELS[context] || context) + 'セットでは使用できません。');
                    return;
                }

                clearTarget();
                replacement = {
                    artId: String(button.dataset.artId || card.dataset.jobArtId || ''),
                    context,
                    opener: button,
                };
                if (replaceArtName) replaceArtName.textContent = card.dataset.name || '戦技';
                if (replaceContextLabel) replaceContextLabel.textContent = CONTEXT_LABELS[context] || context;
                renderReplacementSlots();
                replaceModal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
                replaceModal.querySelector('[data-job-art-replace-close]')?.focus();
            };

            const assignSkillToSlot = async (context, slotNo, skillId, policy, condition, anchorSelector) => {
                if (assignmentPending) return false;
                setAssignmentPending(true);
                const formData = new FormData();
                if (skillId) formData.append('skill_id', skillId);
                formData.append('slot_no', String(slotNo));
                formData.append('slot_context', context);
                formData.append('activation_policy', policy || 'normal');
                formData.append('slot_condition', 'always');

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
                    setAssignmentPending(false);
                    alert(error.message || '保存できませんでした。');
                    return false;
                }

                const slotsContainer = root.querySelector('[data-job-art-slots="' + context + '"]');
                if (slotsContainer && typeof payload.slots_html === 'string') {
                    slotsContainer.innerHTML = payload.slots_html;
                    initializeSlotAccordions(slotsContainer);
                }

                const totalEls = root.querySelectorAll('[data-job-art-total-cost="' + context + '"]');
                if (typeof payload.total_cost !== 'undefined') {
                    totalEls.forEach((totalEl) => { totalEl.textContent = payload.total_cost; });
                }

                if (payload.selected_slot_by_skill) {
                    syncAvailableSlotBadges(context, payload.selected_slot_by_skill);
                    if (currentFilters.has('equipped')) applyFilters();
                }
                replaceDiagnosis(context, payload.diagnosis_html);

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

                setAssignmentPending(false);
                return true;
            };

            root.addEventListener('click', async (event) => {
                const accordionToggle = event.target.closest('[data-job-art-slot-accordion-toggle]');
                if (accordionToggle) {
                    const card = accordionToggle.closest('[data-job-art-slot-card]');
                    const expanded = accordionToggle.getAttribute('aria-expanded') === 'true';
                    setSlotCardExpanded(card, !expanded);
                    return;
                }

                const targetBtn = event.target.closest('[data-job-art-target-btn]');
                if (targetBtn) {
                    setTarget(targetBtn.dataset.slotContext, targetBtn.dataset.slotNo);
                    return;
                }

                const openReplaceBtn = event.target.closest('[data-job-art-open-replace]');
                if (openReplaceBtn) {
                    openReplacementModal(openReplaceBtn);
                    return;
                }

                const closeReplaceBtn = event.target.closest('[data-job-art-replace-close]');
                if (closeReplaceBtn) {
                    closeReplacementModal();
                    return;
                }

                const replaceSlotBtn = event.target.closest('[data-job-art-replace-slot]');
                if (replaceSlotBtn && replacement) {
                    const { context, artId } = replacement;
                    const slotNo = Number.parseInt(replaceSlotBtn.dataset.jobArtReplaceSlot || '0', 10);
                    const slotCardSelector = '[data-job-art-slot-card="' + context + '-' + slotNo + '"]';
                    const slotCard = root.querySelector(slotCardSelector);
                    const success = await assignSkillToSlot(
                        context,
                        slotNo,
                        artId,
                        slotCard?.dataset.policy || 'normal',
                        'always',
                        '[data-job-art-id="' + artId + '"]',
                    );
                    if (success) closeReplacementModal(false);
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
                    assignSkillToSlot(context, slotNo, null, 'normal', 'always', slotCardSelector);
                    return;
                }

                const assignBtn = event.target.closest('[data-job-art-assign-btn]');
                if (assignBtn && target) {
                    const artId = assignBtn.dataset.artId;
                    const cardSelector = '[data-job-art-id="' + artId + '"]';
                    const slotCard = root.querySelector('[data-job-art-slot-card="' + target.context + '-' + target.slotNo + '"]');
                    const policy = slotCard ? (slotCard.dataset.policy || 'normal') : 'normal';
                    const condition = slotCard ? (slotCard.dataset.condition || 'always') : 'always';
                    assignSkillToSlot(target.context, target.slotNo, artId, policy, condition, cardSelector);
                }
            });

            replaceModal?.addEventListener('click', (event) => {
                if (event.target === replaceModal) closeReplacementModal();
            });

            window.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && replacement) closeReplacementModal();
            });

            root.addEventListener('change', (event) => {
                const contextPolicyRadio = event.target.closest('[data-job-art-context-sp-policy-radio]');
                if (contextPolicyRadio) {
                    const form = contextPolicyRadio.closest('[data-job-art-context-sp-policy]');
                    if (!form || assignmentPending) return;
                    const previousPolicy = form.dataset.savedPolicy || 'aggressive';
                    const status = form.querySelector('[data-job-art-context-sp-policy-status]');
                    const description = form.querySelector('[data-job-art-context-sp-policy-description]');
                    const formData = new FormData(form);
                    formData.set('activation_policy', contextPolicyRadio.value);

                    setAssignmentPending(true);
                    if (status) {
                        status.textContent = '保存中…';
                        status.classList.remove('hidden', 'text-emerald-600', 'text-rose-600');
                        status.classList.add('text-slate-500');
                    }

                    fetch(POLICY_URL, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': CSRF_TOKEN,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData,
                    })
                        .then(async (response) => {
                            const payload = await response.json().catch(() => ({}));
                            if (!response.ok) throw new Error(payload.message || 'SP方針を保存できませんでした。');
                            form.dataset.savedPolicy = contextPolicyRadio.value;
                            if (description) {
                                description.textContent = SP_POLICY_DESCRIPTIONS[contextPolicyRadio.value] || '';
                            }
                            replaceDiagnosis(formData.get('slot_context'), payload.diagnosis_html);
                            if (status) {
                                status.textContent = '保存しました';
                                status.classList.remove('text-slate-500', 'text-rose-600');
                                status.classList.add('text-emerald-600');
                            }
                        })
                        .catch((error) => {
                            const previousRadio = form.querySelector('[data-job-art-context-sp-policy-radio][value="' + previousPolicy + '"]');
                            if (previousRadio) previousRadio.checked = true;
                            if (description) {
                                description.textContent = SP_POLICY_DESCRIPTIONS[previousPolicy] || '';
                            }
                            if (status) {
                                status.textContent = error.message || '保存できませんでした';
                                status.classList.remove('text-slate-500', 'text-emerald-600');
                                status.classList.add('text-rose-600');
                            }
                        })
                        .finally(() => setAssignmentPending(false));
                    return;
                }

                const radio = event.target.closest('[data-job-art-policy-radio]');
                if (!radio) return;
                const slotContext = radio.dataset.slotContext;
                const slotNo = radio.dataset.slotNo;
                const slotCard = radio.closest('[data-job-art-slot-card]');
                const skillId = slotCard ? slotCard.dataset.skillId : null;
                const slotCardSelector = '[data-job-art-slot-card="' + slotContext + '-' + slotNo + '"]';
                const condition = slotCard ? (slotCard.dataset.condition || 'always') : 'always';
                assignSkillToSlot(slotContext, slotNo, skillId, radio.value, condition, slotCardSelector);
            });

            root.addEventListener('change', (event) => {
                const select = event.target.closest('[data-job-art-condition-select]');
                if (!select) return;
                const slotContext = select.dataset.slotContext;
                const slotNo = select.dataset.slotNo;
                const slotCard = select.closest('[data-job-art-slot-card]');
                const skillId = slotCard ? slotCard.dataset.skillId : null;
                const policy = slotCard ? (slotCard.dataset.policy || 'normal') : 'normal';
                const slotCardSelector = '[data-job-art-slot-card="' + slotContext + '-' + slotNo + '"]';
                assignSkillToSlot(slotContext, slotNo, skillId, policy, select.value, slotCardSelector);
            });

            const activeClasses = ['border-amber-400', 'bg-amber-50', 'text-amber-700'];
            const inactiveClasses = ['border-slate-200', 'bg-white', 'text-slate-500'];
            const sortActiveClasses = ['border-sky-400', 'bg-sky-50', 'text-sky-700'];
            const sortInactiveClasses = ['border-slate-200', 'bg-white', 'text-slate-500'];
            const lineageActiveClasses = ['border-indigo-500', 'bg-indigo-600', 'text-white'];
            const lineageInactiveClasses = ['border-indigo-100', 'bg-white', 'text-slate-600'];
            const listEl = root.querySelector('[data-job-art-list]');
            const countEl = root.querySelector('[data-job-art-visible-count]');
            const emptyEl = root.querySelector('[data-job-art-empty]');
            const tipsButton = root.querySelector('[data-job-art-tips-toggle]');
            const tipsPanel = root.querySelector('[data-job-art-tips-panel]');
            const favoriteStorageKey = 'valzeria.jobArtFavorites.v1';
            const sortStorageKey = 'valzeria.jobArtSort.v1';
            const lineageStorageKey = 'valzeria.jobArtLineage.v1';
            const availableFilterKeys = new Set(
                [...root.querySelectorAll('[data-job-art-filter]')]
                    .map((button) => button.dataset.jobArtFilter)
                    .filter(Boolean),
            );
            const lineageButtons = [...root.querySelectorAll('[data-job-art-lineage-filter]')];
            const lineageGuides = [...root.querySelectorAll('[data-job-art-lineage-guide]')];
            const availableLineageKeys = new Set(lineageButtons.map((button) => button.dataset.jobArtLineageFilter));
            const requestedFilter = @js($filter) || 'available';
            let currentFilters = new Set([availableFilterKeys.has(requestedFilter) ? requestedFilter : 'available']);
            let currentSort = 'default';
            let currentLineage = 'all';
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

            try {
                const savedLineage = localStorage.getItem(lineageStorageKey);
                if (savedLineage && availableLineageKeys.has(savedLineage)) {
                    currentLineage = savedLineage;
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
                    const icon = button.querySelector('[data-job-art-favorite-icon]');
                    if (icon) {
                        icon.textContent = active ? '★' : '☆';
                        icon.classList.toggle('text-amber-600', active);
                        icon.classList.toggle('text-slate-300', !active);
                        button.classList.toggle('text-amber-700', active);
                        button.classList.toggle('text-slate-500', !active);
                    } else {
                        button.textContent = active ? '★' : '☆';
                        button.classList.toggle('text-amber-600', active);
                        button.classList.toggle('text-slate-300', !active);
                    }
                    button.classList.toggle('border-amber-300', active);
                    button.classList.toggle('bg-amber-100', active);
                    button.classList.toggle('border-slate-200', !active);
                    button.classList.toggle('bg-white', !active);
                });

                root.querySelectorAll('[data-job-art-card]').forEach((card) => {
                    const active = favoriteIds.has(String(card.dataset.jobArtId || ''));
                    card.classList.toggle('ring-2', active);
                    card.classList.toggle('ring-amber-200', active);
                });
            };

            const setChipState = () => {
                root.querySelectorAll('[data-job-art-filter]').forEach((button) => {
                    const active = currentFilters.has(button.dataset.jobArtFilter);
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

            const setLineageState = () => {
                lineageButtons.forEach((button) => {
                    const active = button.dataset.jobArtLineageFilter === currentLineage;
                    button.setAttribute('aria-pressed', active ? 'true' : 'false');
                    button.classList.remove(...(active ? lineageInactiveClasses : lineageActiveClasses));
                    button.classList.add(...(active ? lineageActiveClasses : lineageInactiveClasses));
                });
                lineageGuides.forEach((guide) => {
                    guide.hidden = guide.dataset.jobArtLineageGuide !== currentLineage;
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

            const applyFilters = () => {
                let visibleCount = 0;
                root.querySelectorAll('[data-job-art-card]').forEach((card) => {
                    const filters = (card.dataset.filters || '').split(/\s+/);
                    const isFavorite = favoriteIds.has(String(card.dataset.jobArtId || ''));
                    const isEquipped = [...card.querySelectorAll('[data-job-art-status]')]
                        .some((status) => !status.classList.contains('hidden'));
                    const matchesLineage = currentLineage === 'all' || card.dataset.lineageKey === currentLineage;
                    const matchesFilters = currentFilters.has('available')
                        || [...currentFilters].some((filter) => {
                            if (filter === 'favorite') return isFavorite;
                            if (filter === 'equipped') return isEquipped;

                            return filters.includes(filter);
                        });
                    const visible = matchesLineage && matchesFilters;
                    card.classList.toggle('hidden', !visible);
                    if (visible) visibleCount += 1;
                });
                if (countEl) countEl.textContent = visibleCount;
                if (emptyEl) emptyEl.classList.toggle('hidden', visibleCount > 0);
                setChipState();
                setLineageState();
            };

            const toggleFilter = (filter) => {
                filter = filter || 'available';
                if (filter === 'available') {
                    currentFilters = new Set(['available']);
                } else {
                    currentFilters.delete('available');
                    if (currentFilters.has(filter)) {
                        currentFilters.delete(filter);
                    } else {
                        currentFilters.add(filter);
                    }
                    if (currentFilters.size === 0) {
                        currentFilters = new Set(['available']);
                    }
                }
                applyFilters();
            };

            root.querySelectorAll('[data-job-art-filter]').forEach((button) => {
                button.addEventListener('click', () => toggleFilter(button.dataset.jobArtFilter));
            });

            lineageButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    currentLineage = button.dataset.jobArtLineageFilter || 'all';
                    try {
                        localStorage.setItem(lineageStorageKey, currentLineage);
                    } catch (error) {}
                    applyFilters();
                });
            });

            root.querySelectorAll('[data-job-art-sort]').forEach((button) => {
                button.addEventListener('click', () => {
                    applySort(button.dataset.jobArtSort);
                    applyFilters();
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
                    applyFilters();
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
            applyFilters();
            initializeSlotAccordions();
        })();
    </script>
</x-layouts.facility>
