@php
    $heroJobNumber = str_pad((string) $detailJob->id, 3, '0', STR_PAD_LEFT);
    $heroBadgeImage = 'images/jobbadge/jobbadge_' . $heroJobNumber . '.webp';
    $heroTrialImage = 'images/symbol/hero_trial_' . $heroJobNumber . '.webp';
    $heroPortraitImage = 'images/job_portrait/hero_trial_' . $heroJobNumber . '.webp';
    $hasHeroPortrait = is_file(public_path($heroPortraitImage));
    $targetProgress = $jobProgress[$detailJob->id] ?? ['level' => 0, 'is_mastered' => false];
    $targetRank = max(0, min(10, (int) ($targetProgress['level'] ?? 0)));
    $currentProgress = $character->jobHistories()->where('job_class_id', $character->current_job_id)->first();
    $currentRank = (int) ($currentProgress->job_level ?? 1);
    $currentMastered = (bool) ($currentProgress->is_mastered ?? false) || $currentRank >= 10;
@endphp

<div class="fixed inset-0 z-[60] overflow-y-auto bg-[#f5f7fb] text-slate-900"
     data-hero-job-detail="{{ $detailJob->id }}">
    <div class="min-h-full bg-[radial-gradient(circle_at_78%_16%,rgba(253,230,138,0.38),transparent_28%),linear-gradient(180deg,#f8fbff_0%,#eef4fb_48%,#f8fafc_100%)]">
        <header class="relative overflow-hidden border-t-4 border-blue-950 bg-white/90 shadow-md">
            <div class="pointer-events-none absolute inset-0 bg-cover bg-center opacity-[0.09]" style="background-image: url('{{ asset('images/bg-castle.webp') }}');"></div>
            <div class="relative mx-auto flex max-w-6xl items-center gap-4 px-4 py-5 sm:px-7">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white p-1.5 shadow-sm">
                    <img src="{{ asset('images/symbol/hero_trial_hall.webp') }}" alt="" class="h-full w-full object-contain">
                </div>
                <div>
                    <h1 class="text-2xl font-black tracking-[0.08em] text-blue-950 sm:text-3xl">英雄職の間</h1>
                    <p class="mt-1 text-sm font-bold text-slate-500">英雄職詳細</p>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-6xl space-y-4 px-3 py-4 sm:px-6 sm:py-6">
            <div class="flex items-center justify-between gap-3" data-hero-job-topbar>
                <button type="button"
                        wire:click="closeJobDetail"
                        class="inline-flex shrink-0 items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-black text-blue-950 transition hover:bg-white/80">
                    <span class="text-xl">←</span> 神殿へ戻る
                </button>

                <section class="ml-auto inline-flex min-w-0 max-w-full items-center gap-3 rounded-xl border border-amber-400 bg-white/90 p-3 shadow-md sm:min-w-80"
                         aria-label="現在の職業">
                    <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-full border border-slate-200 bg-slate-50">
                        @if($character->icon_path)
                            <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($character->icon_path) }}" alt="{{ $character->name }}" class="h-full w-full object-cover">
                        @else
                            <span class="text-2xl text-slate-400">👤</span>
                        @endif
                    </div>
                    <div class="min-w-0">
                        <div class="text-[11px] font-black tracking-wide text-slate-400">現在の職業</div>
                        <div class="truncate text-lg font-black text-blue-950">{{ $character->jobClass->name ?? '無職' }}</div>
                        <div class="text-sm font-black text-amber-600">
                            Rank {{ $currentRank }}{{ $currentMastered ? '（MASTER）' : '' }}
                        </div>
                    </div>
                </section>
            </div>

            <section class="relative overflow-hidden rounded-2xl border border-amber-300 bg-white shadow-xl">
                <div class="pointer-events-none absolute inset-0 bg-cover bg-center opacity-[0.16]" style="background-image: url('{{ asset('images/bg-castle.webp') }}');"></div>
                <div class="pointer-events-none absolute inset-0 bg-gradient-to-r from-white via-white/95 to-blue-50/70"></div>
                <img src="{{ asset($heroTrialImage) }}" alt="" class="pointer-events-none absolute -right-14 top-1/2 h-80 w-80 -translate-y-1/2 object-contain opacity-[0.12] sm:right-4">

                <div class="relative grid gap-4 px-6 pb-0 pt-6 sm:px-9 sm:pt-9 lg:min-h-[520px] lg:grid-cols-[minmax(0,1fr)_440px] lg:items-center lg:gap-6 lg:py-9">
                    <div class="relative z-10 pb-6 sm:pb-9 lg:pb-0">
                        <span class="inline-flex rounded-lg bg-gradient-to-r from-cyan-700 to-blue-900 px-4 py-1.5 text-sm font-black tracking-widest text-white shadow">HERO</span>
                        <h2 class="mt-4 font-serif text-4xl font-black tracking-[0.08em] text-blue-950 sm:text-6xl">{{ $detailJob->name }}</h2>
                        <div class="mt-3 text-xl tracking-[0.28em] text-blue-300" aria-label="職業ランク {{ $targetRank }}">
                            {{ str_repeat('★', $targetRank) }}{{ str_repeat('☆', 10 - $targetRank) }}
                        </div>
                        <p class="mt-6 max-w-2xl whitespace-pre-line text-base font-bold leading-8 text-slate-700 sm:text-lg">
                            {{ $detailJob->description ?: '試練を越えた者だけに、その力は応える。' }}
                        </p>
                    </div>

                    @if($hasHeroPortrait)
                        <div class="relative mx-auto flex h-[27rem] w-full max-w-md items-end justify-center self-end sm:h-[31rem] lg:h-[34rem]"
                             data-hero-job-portrait="{{ $detailJob->id }}">
                            <div class="absolute bottom-8 left-1/2 h-56 w-56 -translate-x-1/2 rounded-full bg-amber-200/40 blur-3xl sm:h-64 sm:w-64"></div>
                            <img src="{{ asset($heroTrialImage) }}" alt="" class="absolute bottom-10 left-1/2 h-72 w-72 -translate-x-1/2 object-contain opacity-20 sm:h-80 sm:w-80">
                            <img src="{{ asset($heroPortraitImage) }}"
                                 alt="{{ $detailJob->name }}の姿"
                                 class="relative z-10 h-full w-full object-contain object-bottom drop-shadow-[0_18px_22px_rgba(15,23,42,0.24)]">
                            <div class="absolute bottom-4 right-2 z-20 flex h-16 w-16 items-center justify-center rounded-full border border-amber-300 bg-white/85 p-2 shadow-lg backdrop-blur-sm sm:right-6">
                                <img src="{{ asset($heroBadgeImage) }}"
                                     alt="{{ $detailJob->name }}の職業紋章"
                                     data-hero-job-badge="{{ $detailJob->id }}"
                                     class="h-full w-full object-contain">
                            </div>
                        </div>
                    @else
                        <div class="relative mx-auto mb-8 flex h-64 w-64 items-center justify-center sm:h-72 sm:w-72 lg:mb-0">
                            <div class="absolute inset-0 rounded-full border border-amber-300/70 bg-[radial-gradient(circle,#fff7d6_0%,rgba(254,243,199,0.65)_45%,transparent_70%)] shadow-[0_0_55px_rgba(245,158,11,0.28)]"></div>
                            <img src="{{ asset($heroTrialImage) }}" alt="" class="absolute h-full w-full object-contain opacity-35">
                            <img src="{{ asset($heroBadgeImage) }}"
                                 alt="{{ $detailJob->name }}"
                                 data-hero-job-badge="{{ $detailJob->id }}"
                                 class="relative h-36 w-36 object-contain drop-shadow-[0_10px_18px_rgba(30,64,175,0.28)] sm:h-44 sm:w-44">
                        </div>
                    @endif
                </div>
            </section>

            <section class="rounded-2xl border border-amber-300 bg-white/95 p-5 shadow-md sm:p-6">
                <h3 class="flex items-center gap-2 border-b border-amber-200 pb-3 text-lg font-black text-blue-950">
                    <span class="text-amber-500">♜</span> 成長する能力
                </h3>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($detailHeroJobGrowthStats as $stat)
                        <div class="rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="font-black text-blue-950">{{ $stat['label'] }}</span>
                                <span class="text-xs font-black text-amber-600">{{ number_format($stat['rate']) }}%</span>
                            </div>
                            <div class="mt-2 flex gap-1" aria-label="{{ $stat['label'] }} 成長率 {{ $stat['rate'] }}パーセント">
                                @for($segment = 1; $segment <= 5; $segment++)
                                    <span class="h-3 flex-1 rounded-sm {{ $segment <= $stat['segments'] ? 'bg-gradient-to-b from-amber-300 to-amber-500' : 'bg-slate-200' }}"></span>
                                @endfor
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-2xl border border-amber-300 bg-white/95 p-5 shadow-md sm:p-6">
                    <h3 class="flex items-center gap-2 border-b border-amber-200 pb-3 text-lg font-black text-blue-950">
                        <span class="text-amber-500">⚔</span> 戦い方
                    </h3>
                    <p class="mt-4 text-sm font-bold leading-7 text-slate-600">{{ $detailJob->description ?: '英雄の力をその身に宿し、戦場を切り拓く。' }}</p>
                    <div class="mt-4 rounded-xl border border-blue-100 bg-blue-50/70 px-4 py-3">
                        <div class="text-[11px] font-black tracking-wide text-blue-500">通常攻撃</div>
                        <div class="mt-1 text-base font-black text-blue-950">{{ $detailJobCombatGuide['normal_attack_reference'] ?? '攻撃参照' }}</div>
                    </div>
                </div>

                <div class="rounded-2xl border border-amber-300 bg-white/95 p-5 shadow-md sm:p-6">
                    <h3 class="flex items-center gap-2 border-b border-amber-200 pb-3 text-lg font-black text-blue-950">
                        <span class="text-amber-500">⚔</span> 装備適性
                    </h3>
                    <div class="mt-4 text-xs font-black tracking-wide text-blue-700">適正武器</div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @forelse($detailJobCombatGuide['weapon_labels'] ?? [] as $weaponLabel)
                            <span class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-black text-blue-900">{{ $weaponLabel }}</span>
                        @empty
                            <span class="text-sm font-bold text-slate-400">未設定</span>
                        @endforelse
                    </div>
                    <div class="mt-5 text-xs font-black tracking-wide text-blue-700">適正防具</div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @forelse($detailJobCombatGuide['armor_labels'] ?? [] as $armorLabel)
                            <span class="rounded-lg border border-cyan-200 bg-cyan-50 px-3 py-2 text-sm font-black text-blue-900">{{ $armorLabel }}</span>
                        @empty
                            <span class="text-sm font-bold text-slate-400">未設定</span>
                        @endforelse
                    </div>
                    <p class="mt-4 text-xs font-bold leading-6 text-slate-500">
                        {{ ($detailJobCombatGuide['non_proficient_enabled'] ?? false) ? '適正武器・適正防具は装備効果100%。適性外は装備効果が下がります。' : '現在は適正のある武器・防具のみ装備できます。' }}
                    </p>
                </div>
            </section>

            <section class="rounded-2xl border border-amber-300 bg-white/95 p-5 shadow-md sm:p-6">
                <h3 class="flex items-center gap-2 border-b border-amber-200 pb-3 text-lg font-black text-blue-950">
                    <span class="text-amber-500">✦</span> 覚える奥義
                </h3>
                <div class="mt-4 grid gap-3">
                    @forelse($detailJob->jobArts->sortBy('learn_rank') as $art)
                        @php $damageReference = $detailJobCombatGuide['job_art_damage_references'][(int) $art->id] ?? null; @endphp
                        <article class="rounded-xl border border-blue-200 bg-gradient-to-r from-blue-50/90 to-white p-4">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <div class="text-[11px] font-black tracking-wider text-amber-600">RANK {{ $art->learn_rank }}</div>
                                    <h4 class="mt-0.5 text-lg font-black text-blue-950">{{ $art->name }}</h4>
                                </div>
                                <div class="flex flex-wrap gap-1.5 text-[10px] font-black">
                                    <span class="rounded border border-blue-100 bg-white px-2 py-1 text-blue-700">発動{{ (int) $art->effectiveActivationRate() }}%</span>
                                    <span class="rounded border border-slate-200 bg-white px-2 py-1 text-slate-600">Cost {{ (int) ($art->getAttribute('job_art_effective_cost') ?? $art->art_cost) }}</span>
                                    @if($damageReference)
                                        <span class="rounded border border-fuchsia-100 bg-white px-2 py-1 text-fuchsia-700">{{ $damageReference }}</span>
                                    @endif
                                </div>
                            </div>
                            <p class="mt-2 whitespace-pre-line text-sm font-bold leading-6 text-slate-600">{{ \App\Support\PlayerStatLabel::inText((string) ($detailJobArtDescriptions[(int) $art->id] ?? ($art->memo ?? $art->description ?? '効果説明なし'))) }}</p>
                        </article>
                    @empty
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-400">覚える奥義はまだ登録されていません。</div>
                    @endforelse
                </div>
            </section>

            <section class="rounded-2xl border border-amber-300 bg-white/95 p-5 shadow-md sm:p-6">
                <h3 class="flex items-center gap-2 border-b border-amber-200 pb-3 text-lg font-black text-blue-950">
                    <span class="text-amber-500">♛</span> マスター恩恵
                </h3>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @forelse($detailJobMasterBonusChips as $bonus)
                        <div class="rounded-xl border border-amber-200 bg-gradient-to-r from-amber-50 to-white px-4 py-3">
                            <div class="text-base font-black text-blue-950">{{ $bonus['label'] }} +{{ number_format($bonus['value']) }}{{ $bonus['suffix'] }}</div>
                        </div>
                    @empty
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-400">マスター恩恵は未設定です。</div>
                    @endforelse
                </div>
            </section>

            <div class="mx-auto flex max-w-3xl flex-col gap-3 pb-8 pt-2">
                @if($detailJobCanChange)
                    <button type="button"
                            wire:click="confirmJobChangeFromDetail"
                            class="inline-flex w-full items-center justify-center gap-3 rounded-xl border-2 border-amber-400 bg-gradient-to-r from-blue-950 via-blue-900 to-cyan-900 px-6 py-4 text-lg font-black tracking-wide text-white shadow-xl transition hover:brightness-110 active:scale-[0.99]">
                        <img src="{{ asset($heroBadgeImage) }}" alt="" class="h-8 w-8 object-contain"> この英雄職に転職する
                    </button>
                @else
                    <button type="button" disabled class="w-full cursor-not-allowed rounded-xl border-2 border-slate-300 bg-slate-200 px-6 py-4 text-lg font-black text-slate-500">
                        現在はこの英雄職へ転職できません
                    </button>
                @endif
                <button type="button"
                        wire:click="closeJobDetail"
                        class="w-full rounded-xl border border-slate-300 bg-white px-6 py-3 text-base font-black text-slate-600 shadow-sm transition hover:bg-slate-50">
                    神殿へ戻る
                </button>
            </div>
        </main>
    </div>
</div>
