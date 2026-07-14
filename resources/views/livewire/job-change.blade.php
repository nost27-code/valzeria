@php
    $masterBonusFields = [
        'bonus_hp' => ['HP', ''],
        'bonus_mp' => ['SP', ''],
        'bonus_str' => ['攻撃', ''],
        'bonus_def' => ['防御', ''],
        'bonus_mag' => ['魔力', ''],
        'bonus_spr' => ['精神', ''],
        'bonus_spd' => ['敏捷', ''],
        'bonus_luk' => ['運', ''],
        'bonus_drop_rate' => ['ドロップ', '%'],
        'bonus_critical_rate' => ['必殺', '%'],
    ];

    $masterBonusTypeLabels = [
        'hp_rate' => ['HP', '%'],
        'mp_rate' => ['SP', '%'],
        'atk_rate' => ['攻撃', '%'],
        'def_rate' => ['防御', '%'],
        'mag_rate' => ['魔力', '%'],
        'spr_rate' => ['精神', '%'],
        'spd_rate' => ['敏捷', '%'],
        'luck_rate' => ['運', '%'],
        'drop_rate' => ['ドロップ', '%'],
        'critical_rate' => ['必殺', '%'],
        'evasion_rate' => ['回避', '%'],
        'heal_rate' => ['回復', '%'],
        'item_effect_rate' => ['道具効果', '%'],
    ];

    $formatMasterBonuses = function ($job) use ($masterBonusFields, $masterBonusTypeLabels) {
        $chips = [];

        foreach ($masterBonusFields as $field => [$label, $suffix]) {
            $value = (int) ($job->{$field} ?? 0);
            if ($value !== 0) {
                $chips[] = ['label' => $label, 'value' => $value, 'suffix' => $suffix];
            }
        }

        if ($job->relationLoaded('masterBonuses')) {
            foreach ($job->masterBonuses as $bonus) {
                $value = (int) $bonus->bonus_value;
                if ($value === 0) {
                    continue;
                }

                [$label, $suffix] = $masterBonusTypeLabels[$bonus->bonus_type] ?? [$bonus->bonus_type, '%'];
                $chips[] = ['label' => $label, 'value' => $value, 'suffix' => $suffix];
            }
        }

        return $chips;
    };

    $rankStyles = [
        'default' => [
            'card' => 'bg-white border-slate-200 hover:border-slate-400',
            'lockedCard' => 'bg-white border-slate-200',
            'hoverOverlay' => 'from-slate-400/10',
            'title' => 'group-hover:text-slate-700',
            'badge' => 'bg-slate-100 text-slate-600 border-slate-200',
            'stars' => 'text-slate-500',
        ],
        'normal' => [
            'card' => 'bg-white border-slate-200 hover:border-slate-400',
            'lockedCard' => 'bg-white border-slate-200',
            'hoverOverlay' => 'from-slate-400/10',
            'title' => 'group-hover:text-slate-700',
            'badge' => 'bg-slate-100 text-slate-600 border-slate-200',
            'stars' => 'text-slate-500',
        ],
        'middle' => [
            'card' => 'bg-sky-50/70 border-sky-200 hover:border-sky-500',
            'lockedCard' => 'bg-sky-50/40 border-sky-100',
            'hoverOverlay' => 'from-sky-400/15',
            'title' => 'group-hover:text-sky-700',
            'badge' => 'bg-sky-100 text-sky-700 border-sky-200',
            'stars' => 'text-sky-600',
        ],
        'advanced' => [
            'card' => 'bg-violet-50/70 border-violet-200 hover:border-violet-500',
            'lockedCard' => 'bg-violet-50/40 border-violet-100',
            'hoverOverlay' => 'from-violet-400/15',
            'title' => 'group-hover:text-violet-700',
            'badge' => 'bg-violet-100 text-violet-700 border-violet-200',
            'stars' => 'text-violet-600',
        ],
        'legend' => [
            'card' => 'bg-amber-50/80 border-amber-300 hover:border-amber-500',
            'lockedCard' => 'bg-amber-50/50 border-amber-200',
            'hoverOverlay' => 'from-amber-400/20',
            'title' => 'group-hover:text-amber-700',
            'badge' => 'bg-amber-100 text-amber-700 border-amber-300',
            'stars' => 'text-amber-600',
        ],
        'super' => [
            'card' => 'bg-emerald-50/70 border-emerald-200 hover:border-emerald-500',
            'lockedCard' => 'bg-emerald-50/40 border-emerald-100',
            'hoverOverlay' => 'from-emerald-400/15',
            'title' => 'group-hover:text-emerald-700',
            'badge' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
            'stars' => 'text-emerald-600',
        ],
        'crown' => [
            'card' => 'bg-rose-50/70 border-rose-200 hover:border-rose-500',
            'lockedCard' => 'bg-rose-50/40 border-rose-100',
            'hoverOverlay' => 'from-rose-400/15',
            'title' => 'group-hover:text-rose-700',
            'badge' => 'bg-rose-100 text-rose-700 border-rose-200',
            'stars' => 'text-rose-600',
        ],
        'hero' => [
            'card' => 'bg-cyan-50/70 border-cyan-200 hover:border-cyan-500',
            'lockedCard' => 'bg-cyan-50/40 border-cyan-100',
            'hoverOverlay' => 'from-cyan-400/15',
            'title' => 'group-hover:text-cyan-700',
            'badge' => 'bg-cyan-100 text-cyan-700 border-cyan-200',
            'stars' => 'text-cyan-600',
        ],
        'myth' => [
            'card' => 'bg-fuchsia-50/70 border-fuchsia-200 hover:border-fuchsia-500',
            'lockedCard' => 'bg-fuchsia-50/40 border-fuchsia-100',
            'hoverOverlay' => 'from-fuchsia-400/15',
            'title' => 'group-hover:text-fuchsia-700',
            'badge' => 'bg-fuchsia-100 text-fuchsia-700 border-fuchsia-200',
            'stars' => 'text-fuchsia-600',
        ],
    ];

    $rankLabel = fn ($rank) => \App\Support\JobRankCatalog::badge($rank);
    $isHighRankJob = fn ($rank) => \App\Support\JobRankCatalog::isHighRank($rank);
    $inheritanceFractionLabel = fn ($rank) => \App\Support\JobRankCatalog::inheritanceFractionLabel($rank);
    $inheritancePercentLabel = fn ($rank) => \App\Support\JobRankCatalog::inheritancePercentLabel($rank);
    $isRequirementMet = function ($req) use ($character, $jobProgress) {
        if ($req->requirement_type === 'master_job') {
            $requiredJobId = (int) ($req->required_job_id ?? 0);

            return (bool) ($jobProgress[$requiredJobId]['is_mastered'] ?? false);
        }

        if ($req->requirement_type === 'character_level') {
            return (int) $character->level >= (int) $req->required_value;
        }

        if (in_array($req->requirement_type, ['title', 'item'], true)) {
            return true;
        }

        return false;
    };

    // レベル・マスター等の「本来の解放条件」だけを見て判定する（未使用BPの有無は無関係）。
    // canChangeJob()は未使用BPが残っていると常にfalseを返すため、そちらだけで判定すると
    // 本来はもう解放済みの上級職まで「？？？」表示・詳細ボタン非表示になってしまう。
    $meetsRealRequirements = function ($job) use ($character, $isRequirementMet) {
        if ((int) $character->level < 30) {
            return false;
        }

        if ($job->requirements->isEmpty()) {
            return true;
        }

        foreach ($job->requirements as $req) {
            if (!$isRequirementMet($req)) {
                return false;
            }
        }

        return true;
    };
    $unspentBp = (int) ($character->bonus_points ?? 0);

    $rankOrder = \App\Support\JobRankCatalog::keys();
    $groupedAvailableJobs = collect($availableJobs)
        ->groupBy(fn ($job) => \App\Support\JobRankCatalog::normalize($job->rank))
        ->sortBy(fn ($jobs, $rank) => array_search($rank, $rankOrder, true))
        ->all();
@endphp

<div class="max-w-7xl mx-auto p-4 flex flex-col gap-4 text-sm font-sans text-[#1e293b]"
     x-data="{ confirming: @entangle('confirmingJobChange'), showingDetail: @entangle('showingJobDetail') }"
     @keydown.escape.window="if (showingDetail) $wire.closeJobDetail()">

    @if (session()->has('message'))
        <div class="bg-[#f0f9ff] border border-[#bae6fd] text-[#0369a1] px-4 py-3 rounded relative shadow-sm font-medium" role="alert">
            <span class="block sm:inline">{{ session('message') }}</span>
        </div>
    @endif

    @if($character->level < 30)
        <div class="bg-amber-50 border border-amber-300 text-amber-800 px-4 py-3 rounded-lg shadow-sm font-medium flex items-center gap-2">
            <span>🔒</span>
            <span>転職にはLv30が必要です。現在のレベル: <strong>Lv{{ $character->level }}</strong></span>
        </div>
    @endif

    @if((int) ($character->bonus_points ?? 0) > 0)
        <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 shadow-sm">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <div class="text-sm font-extrabold text-amber-900">未使用BPが {{ number_format((int) $character->bonus_points) }} あります</div>
                    <div class="mt-1 text-xs font-bold text-amber-700">未使用BPが残っている間は転職できません。先にすべて能力へ割り振ってください。</div>
                </div>
                <a href="{{ route('bonus-points.index') }}" class="inline-flex shrink-0 items-center justify-center rounded-md bg-[#1e293b] px-4 py-2 text-xs font-extrabold text-white shadow-sm transition hover:bg-[#0f172a]">
                    能力割振りへ
                </a>
            </div>
        </div>
    @endif

    <div class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 shadow-sm">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <div class="text-sm font-extrabold text-indigo-900">職業奥義をセットできます</div>
                <div class="mt-1 text-xs font-bold text-indigo-700">現在職Rankやマスター済み職業に応じて、最大3つまで奥義を選べます。</div>
            </div>
            <a href="{{ route('job-arts.index') }}" class="inline-flex shrink-0 items-center justify-center rounded-md bg-indigo-900 px-4 py-2 text-xs font-extrabold text-white shadow-sm transition hover:bg-indigo-950">
                奥義セットへ
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        
        <!-- 現在の職業 -->
        <div class="lg:col-span-1">
            <div class="bg-white border border-[#d4af37] rounded-xl p-5 shadow-md">
                <h2 class="text-lg font-bold text-[#d4af37] mb-4 border-b border-[#d4af37]/30 pb-2 flex items-center gap-2">
                    <span>📋</span> 現在のステータス
                </h2>
                
                @if($character->jobClass)
                    <div class="flex items-center gap-4 mb-4">
                        <div class="w-16 h-16 bg-gray-100 rounded-full border border-gray-300 flex items-center justify-center overflow-hidden mr-4">
                            @if($character->icon_path)
                                <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($character->icon_path) }}" alt="{{ $character->name }}" class="w-full h-full object-cover">
                            @else
                                <span class="text-3xl text-gray-400">👤</span>
                            @endif
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 font-medium">現在の職業</div>
                            <div class="text-xl font-bold text-[#1e293b]">{{ $character->jobClass->name }}</div>
                        </div>
                    </div>
                    
                    @php
                        $charJob = $character->jobHistories()->where('job_class_id', $character->current_job_id)->first();
                        $level = $charJob ? $charJob->job_level : 1;
                        $isMastered = $charJob ? $charJob->is_mastered : false;
                    @endphp
                    
                    <div class="space-y-4">
                        <div class="flex justify-between items-center bg-gray-50 p-2 rounded border border-gray-100">
                            <span class="text-sm text-gray-600 font-medium">職業ランク</span>
                            <span class="font-bold {{ $isMastered ? 'text-[#d4af37]' : 'text-[#1e293b]' }}">
                                Rank {{ $level }} {{ $isMastered ? '(MASTER)' : '' }}
                            </span>
                        </div>

                        @if($hasCrownProof)
                            <div class="flex items-center justify-between rounded border border-rose-200 bg-rose-50 p-2 text-sm font-bold text-rose-700">
                                <span>👑 冠位の証</span>
                                <span>所持済</span>
                            </div>
                        @endif
                        
                        @if(isset($expInfo) && !$expInfo['is_mastered'])
                            @php
                                $percent = $expInfo['next_required'] > 0 
                                    ? min(100, ($expInfo['current'] / $expInfo['next_required']) * 100) 
                                    : 100;
                            @endphp
                            <div class="pt-2">
                                <div class="flex justify-between text-xs mb-1 font-medium">
                                    <span class="text-gray-500">次のランクまで</span>
                                    <span class="text-[#1e293b]">{{ $expInfo['current'] }} / {{ $expInfo['next_required'] }}</span>
                                </div>
                                <div class="w-full bg-gray-100 rounded-full h-2 border border-gray-200 overflow-hidden shadow-inner">
                                    <div class="bg-gradient-to-r from-[#d4af37] to-[#fcebb6] h-full rounded-full transition-all duration-500" style="width: {{ $percent }}%"></div>
                                </div>
                            </div>
                        @elseif(isset($expInfo) && $expInfo['is_mastered'])
                            <div class="pt-3 text-sm text-[#d4af37] font-bold text-center border-t border-gray-100 mt-2 flex items-center justify-center gap-1">
                                <img src="{{ asset('images/icon/icon_009.webp') }}" alt="" class="w-4 h-4 object-contain"> この職業を極めました！
                            </div>
                        @endif
                    </div>
                @else
                    <div class="text-gray-400 text-sm text-center py-6 font-medium">
                        現在、職業に就いていません
                    </div>
                @endif
            </div>
        </div>

        <!-- 転職可能・条件未達成一覧 -->
        <div class="lg:col-span-3 space-y-6">
            
            <!-- 転職可能 -->
            <div class="bg-white border border-[#d4af37] rounded-xl p-5 shadow-md">
                <h2 class="text-lg font-bold text-[#1e293b] mb-4 flex items-center gap-2 border-b border-gray-100 pb-2">
                    <img src="{{ asset('images/icon/icon_042.webp') }}" alt="" class="w-5 h-5 object-contain"> 転職可能な職業
                </h2>
                
                @if(count($availableJobs) > 0)
                    <div class="space-y-3">
                        @foreach($groupedAvailableJobs as $rank => $jobsInRank)
                            <div class="border border-gray-200 rounded-lg overflow-hidden" x-data="{ open: false }">
                                <button type="button"
                                        @click="open = !open"
                                        class="w-full flex items-center justify-between gap-2 px-4 py-2.5 bg-gray-50 hover:bg-gray-100 transition-colors text-left">
                                    <span class="flex items-center gap-2">
                                        <span class="text-[11px] px-2 py-0.5 rounded border {{ $rankStyles[$rank]['badge'] ?? $rankStyles['normal']['badge'] }} font-bold">
                                            {{ $rankLabel($rank) }}
                                        </span>
                                        <span class="text-sm font-bold text-[#1e293b]">{{ \App\Support\JobRankCatalog::label($rank) }}</span>
                                        <span class="text-xs text-gray-400 font-medium">({{ count($jobsInRank) }})</span>
                                    </span>
                                    <span class="text-gray-400 text-xs font-bold transition-transform" x-bind:class="open ? 'rotate-180' : ''">▼</span>
                                </button>
                                <div x-show="open" class="p-3">
                                    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-3">
                                        @foreach($jobsInRank as $job)
                                            @php
                                                $progress = $jobProgress[$job->id] ?? ['level' => 0, 'is_mastered' => false];
                                                $stars = max(0, min(9, (int) ($progress['level'] ?? 0)));
                                                $bonusChips = ($progress['is_mastered'] ?? false) ? $formatMasterBonuses($job) : [];
                                                $rankStyle = $rankStyles[$job->rank] ?? $rankStyles['normal'];
                                            @endphp
                                            <div class="text-left group border {{ $rankStyle['card'] }} rounded-lg p-3 transition-all duration-200 hover:shadow-md relative overflow-hidden">
                                                <div class="absolute inset-0 bg-gradient-to-r {{ $rankStyle['hoverOverlay'] }} to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                                                <div class="relative z-10 flex justify-between items-start mb-1">
                                                    <div class="font-bold text-[#1e293b] {{ $rankStyle['title'] }} transition-colors">{{ $job->name }}</div>
                                                    <span class="text-[10px] px-2 py-0.5 rounded border {{ $rankStyle['badge'] }} font-bold">
                                                        {{ $rankLabel($job->rank) }}
                                                    </span>
                                                </div>
                                                <div class="relative z-10 mt-1 font-sans">
                                                    @if($progress['is_mastered'] ?? false)
                                                        <span class="inline-flex items-center rounded bg-amber-100 border border-amber-300 px-2 py-0.5 text-[10px] font-extrabold text-amber-700 tracking-wide">MASTER</span>
                                                        @if(!empty($bonusChips))
                                                            <div class="mt-1 flex flex-wrap gap-1">
                                                                @foreach($bonusChips as $bonus)
                                                                    <span class="rounded bg-amber-50 border border-amber-200 px-1.5 py-0.5 text-[10px] font-bold text-amber-700">
                                                                        {{ $bonus['label'] }}+{{ $bonus['value'] }}{{ $bonus['suffix'] }}
                                                                    </span>
                                                                @endforeach
                                                            </div>
                                                        @endif
                                                    @else
                                                        <span class="text-[11px] tracking-[0.08em] {{ $rankStyle['stars'] }} font-bold">
                                                            {{ str_repeat('★', $stars) }}{{ str_repeat('☆', 10 - $stars) }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="relative z-10 text-xs text-gray-500 mt-2 line-clamp-2 leading-relaxed">{{ $job->description ?? '説明なし' }}</div>
                                                <div class="relative z-10 mt-3 flex gap-2">
                                                    <button type="button"
                                                            wire:click="showJobDetail({{ $job->id }})"
                                                            class="flex-1 rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-extrabold text-slate-600 shadow-sm hover:bg-slate-50">
                                                        詳細
                                                    </button>
                                                    <button type="button"
                                                            wire:click="confirmJobChange({{ $job->id }})"
                                                            class="flex-1 rounded-md border border-amber-600 bg-amber-500 px-3 py-1.5 text-xs font-extrabold text-white shadow-sm hover:bg-amber-600">
                                                        転職する
                                                    </button>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-gray-500 text-sm text-center py-6 bg-gray-50 rounded-lg border border-gray-100 font-medium">
                        現在転職できる職業はありません
                    </div>
                @endif
            </div>

            <!-- 条件未達成 -->
            <div class="bg-gray-50 border border-gray-200 rounded-xl p-5 shadow-inner">
                <h2 class="text-lg font-bold text-gray-500 mb-4 flex items-center gap-2 border-b border-gray-200 pb-2">
                    <span>🔒</span> 条件未達成の職業
                </h2>
                
                @if(count($unavailableJobs) > 0)
                    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-3 opacity-80">
                        @foreach($unavailableJobs as $job)
                            @php
                                $progress = $jobProgress[$job->id] ?? ['level' => 0, 'is_mastered' => false];
                                $stars = max(0, min(9, (int) ($progress['level'] ?? 0)));
                                $bonusChips = ($progress['is_mastered'] ?? false) ? $formatMasterBonuses($job) : [];
                                $rankStyle = $rankStyles[$job->rank] ?? $rankStyles['normal'];
                                $isRevealed = $meetsRealRequirements($job);
                                $blockedOnlyByBp = $meetsRealRequirements($job) && $unspentBp > 0;
                            @endphp
                            <div class="border {{ $rankStyle['lockedCard'] }} rounded-lg p-3">
                                <div class="flex justify-between items-start mb-2">
                                    <div class="font-bold text-gray-400">
                                        {{ $job->rank === 'advanced' && ! $isRevealed ? '？？？' : $job->name }}
                                    </div>
                                    <span class="text-[10px] px-2 py-0.5 rounded border {{ $rankStyle['badge'] }} font-bold opacity-70">
                                        {{ $rankLabel($job->rank) }}
                                    </span>
                                </div>
                                <div class="font-sans mb-2">
                                    @if($progress['is_mastered'] ?? false)
                                        <span class="inline-flex items-center rounded bg-amber-100 border border-amber-300 px-2 py-0.5 text-[10px] font-extrabold text-amber-700 tracking-wide">MASTER</span>
                                        @if(!empty($bonusChips))
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                @foreach($bonusChips as $bonus)
                                                    <span class="rounded bg-amber-50 border border-amber-200 px-1.5 py-0.5 text-[10px] font-bold text-amber-700">
                                                        {{ $bonus['label'] }}+{{ $bonus['value'] }}{{ $bonus['suffix'] }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-[11px] tracking-[0.08em] {{ $rankStyle['stars'] }} font-bold opacity-70">
                                            {{ str_repeat('★', $stars) }}{{ str_repeat('☆', 10 - $stars) }}
                                        </span>
                                    @endif
                                </div>
                                <div class="mt-2 pt-2 border-t border-gray-50 space-y-1.5">
                                    @if($job->requirements->isEmpty())
                                        @php $isLevelRequirementMet = (int) $character->level >= 30; @endphp
                                        <div class="text-xs text-gray-500 flex items-center gap-1.5 font-medium">
                                            <span class="{{ $isLevelRequirementMet ? 'text-emerald-500' : 'text-rose-400' }} text-[10px] font-black">
                                                {{ $isLevelRequirementMet ? '◯' : '✕' }}
                                            </span>
                                            Lv30 以上
                                        </div>
                                    @else
                                        <div class="text-[10px] text-gray-400 font-bold">【必要条件】</div>
                                        @foreach($job->requirements as $req)
                                            @php $isMet = $isRequirementMet($req); @endphp
                                            <div class="text-xs text-gray-500 flex items-center gap-1.5 font-medium">
                                                <span class="{{ $isMet ? 'text-emerald-500' : 'text-rose-400' }} text-[10px] font-black">
                                                    {{ $isMet ? '◯' : '✕' }}
                                                </span>
                                                @if($req->requirement_type === 'master_job')
                                                    {{ $req->requiredJob->name ?? '不明' }} のマスター
                                                @elseif($req->requirement_type === 'character_level')
                                                    Lv {{ $req->required_value }} 以上
                                                @elseif($req->requirement_type === 'title')
                                                    称号の獲得
                                                @elseif($req->requirement_type === 'item')
                                                    特別な品の所持
                                                @else
                                                    特定の条件
                                                @endif
                                            </div>
                                        @endforeach
                                    @endif
                                    @if($blockedOnlyByBp)
                                        <div class="text-xs text-amber-600 flex items-center gap-1.5 font-bold">
                                            <span class="text-[10px] font-black">⚠</span>
                                            未使用BPを振ってから転職できます
                                        </div>
                                    @endif
                                </div>
                                @if((!$job->is_hidden || $isRevealed) && ($job->rank !== 'advanced' || $isRevealed))
                                    <div class="mt-3">
                                        <button type="button"
                                                wire:click="showJobDetail({{ $job->id }})"
                                                class="w-full rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-extrabold text-slate-500 shadow-sm hover:bg-slate-50">
                                            詳細
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-gray-400 text-sm text-center py-4 font-medium">
                        条件未達成の職業はありません
                    </div>
                @endif
            </div>

        </div>
    </div>

    <!-- 職業詳細モーダル -->
    <div x-show="showingDetail" style="display: none;" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div x-show="showingDetail"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm"
             wire:click="closeJobDetail"></div>

        <div x-show="showingDetail"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-xl border border-amber-400 bg-white shadow-2xl">
            @if($detailJob)
                <div class="border-b border-amber-400 bg-slate-900 px-5 py-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-[10px] font-extrabold tracking-[0.18em] text-amber-300">
                                {{ $rankLabel($detailJob->rank) }}
                            </div>
                            <h3 class="mt-1 text-xl font-extrabold text-white">{{ $detailJob->name }}</h3>
                        </div>
                        <button type="button"
                                wire:click="closeJobDetail"
                                class="rounded-md border border-white/20 px-2 py-1 text-xs font-bold text-white/80 hover:bg-white/10">
                            閉じる
                        </button>
                    </div>
                </div>

                <div class="space-y-5 p-5">
                    <section>
                        @php
                            $rankAccent = match ($detailJob->rank ?? 'normal') {
                                'legend'   => ['border' => '#c2410c', 'bg' => 'rgba(255,237,213,0.55)', 'quote' => '#f97316'],
                                'advanced' => ['border' => '#7c3aed', 'bg' => 'rgba(237,233,254,0.55)', 'quote' => '#a78bfa'],
                                'middle'   => ['border' => '#1d4ed8', 'bg' => 'rgba(219,234,254,0.50)', 'quote' => '#60a5fa'],
                                default    => ['border' => '#d4af37', 'bg' => 'rgba(254,249,195,0.55)', 'quote' => '#d4af37'],
                            };
                        @endphp
                        <div class="relative rounded-r-lg py-3 pl-5 pr-4"
                             style="border-left: 4px solid {{ $rankAccent['border'] }}; background: {{ $rankAccent['bg'] }};">
                            <span class="pointer-events-none absolute -top-3 left-3 select-none font-serif text-5xl leading-none"
                                  style="color: {{ $rankAccent['quote'] }}; opacity: 0.45;">"</span>
                            <p class="relative z-10 text-[15px] font-bold leading-relaxed tracking-wide text-slate-800">
                                {{ $detailJob->description ?? '説明なし' }}
                            </p>
                        </div>
                    </section>

                    <section>
                        <h4 class="text-sm font-extrabold text-slate-900">伸びやすい能力</h4>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @forelse($detailJobGrowthStats as $stat)
                                <span class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-extrabold text-emerald-700">
                                    {{ $stat['label'] }}
                                </span>
                            @empty
                                <span class="text-xs font-bold text-slate-400">成長傾向は未設定です。</span>
                            @endforelse
                        </div>
                    </section>

                    <section>
                        <h4 class="text-sm font-extrabold text-slate-900">覚える奥義</h4>
                        <div class="mt-2 space-y-2">
                            @forelse($detailJob->jobArts->sortBy('learn_rank') as $art)
                                <div class="rounded-lg border border-indigo-100 bg-indigo-50/60 p-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-xs font-extrabold text-indigo-500">Rank {{ $art->learn_rank }}</div>
                                            <div class="text-sm font-extrabold text-slate-900">{{ $art->name }}</div>
                                        </div>
                                        <div class="flex shrink-0 flex-wrap justify-end gap-1">
                                            <span class="rounded border border-indigo-100 bg-white px-2 py-0.5 text-[10px] font-bold text-indigo-700">
                                                発動{{ (int) $art->effectiveActivationRate() }}%
                                            </span>
                                            <span class="rounded border border-slate-100 bg-white px-2 py-0.5 text-[10px] font-bold text-slate-600">
                                                Cost {{ (int) $art->art_cost }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="mt-2 text-xs font-medium leading-relaxed text-slate-600">
                                        {{ $art->memo ?? $art->description ?? '効果説明なし' }}
                                    </div>
                                    @if($art->jobArtNumericEffectLabels())
                                        <div class="mt-2 flex flex-wrap gap-1 text-[10px] font-bold text-indigo-700">
                                            @foreach($art->jobArtNumericEffectLabels() as $numericEffectLabel)
                                                <span class="rounded border border-indigo-100 bg-white px-2 py-0.5">{{ $numericEffectLabel }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if($art->activation_phrase || $art->activation_description)
                                        <div class="mt-2 rounded-md bg-white/70 px-2 py-1.5 text-[11px] font-bold leading-relaxed text-indigo-700">
                                            @if($art->activation_phrase)
                                                <div>{{ $art->activation_phrase }}</div>
                                            @endif
                                            @if($art->activation_description)
                                                <div class="text-slate-500">{{ str_replace(['{user}', '{target}', '{skill}'], ['冒険者', '敵', $art->name], $art->activation_description) }}</div>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <div class="rounded-lg border border-slate-100 bg-slate-50 p-3 text-xs font-bold text-slate-400">
                                    覚える奥義はまだ登録されていません。
                                </div>
                            @endforelse
                        </div>
                    </section>

                    <section>
                        <h4 class="text-sm font-extrabold text-slate-900">マスター恩恵</h4>
                        @if(!empty($detailJobMasterBonusChips))
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach($detailJobMasterBonusChips as $bonus)
                                    <span class="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-extrabold text-amber-700">
                                        {{ $bonus['label'] }}+{{ $bonus['value'] }}{{ $bonus['suffix'] }}
                                    </span>
                                @endforeach
                            </div>
                        @else
                            <p class="mt-2 text-xs font-medium leading-relaxed text-slate-500">
                                この職業を極めると、転職後も成長の証が残ります。
                            </p>
                        @endif
                    </section>

                    <section>
                        <h4 class="text-sm font-extrabold text-slate-900">必要条件</h4>
                        <div class="mt-2 space-y-1">
                            @if($detailJob->requirements->isEmpty())
                                <div class="text-xs font-bold text-slate-500">なし</div>
                            @else
                                @foreach($detailJob->requirements as $req)
                                    <div class="text-xs font-bold text-slate-500">
                                        @if($req->requirement_type === 'master_job')
                                            {{ $req->requiredJob->name ?? '不明な職業' }} のマスター
                                        @elseif($req->requirement_type === 'character_level')
                                            Lv {{ $req->required_value }} 以上
                                        @elseif($req->requirement_type === 'title')
                                            称号の獲得
                                        @elseif($req->requirement_type === 'item')
                                            特別な品の所持
                                        @else
                                            特定の条件
                                        @endif
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    </section>

                    <div class="flex gap-3 border-t border-slate-100 pt-4">
                        <button type="button"
                                wire:click="closeJobDetail"
                                class="flex-1 rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-extrabold text-slate-600 hover:bg-slate-50">
                            閉じる
                        </button>
                        @if($detailJobCanChange)
                            <button type="button"
                                    wire:click="confirmJobChangeFromDetail"
                                    class="flex-1 rounded-md border border-amber-600 bg-amber-500 px-4 py-2.5 text-sm font-extrabold text-white shadow-sm hover:bg-amber-600">
                                この職業に転職する
                            </button>
                        @else
                            <button type="button"
                                    disabled
                                    class="flex-1 rounded-md border border-slate-200 bg-slate-100 px-4 py-2.5 text-sm font-extrabold text-slate-400">
                                条件未達成
                            </button>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    <!-- 転職確認モーダル -->
    <div x-show="confirming" style="display: none;" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <!-- 背景のオーバーレイ -->
        <div x-show="confirming" 
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm"
             wire:click="$set('confirmingJobChange', false)"></div>

        <!-- モーダル本体 -->
        <div x-show="confirming"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="relative bg-white border border-amber-500 rounded-xl shadow-2xl max-w-md w-full overflow-hidden">
            
            <!-- モーダルヘッダー -->
            <div class="bg-slate-900 p-4 text-center border-b border-amber-500">
                <h3 class="text-lg font-bold text-white tracking-widest">転職確認</h3>
            </div>

            @if($selectedJob)
            <div class="p-6">
                <p class="text-slate-900 text-center mb-6 font-medium">
                    本当に <span class="text-amber-500 font-bold text-lg mx-1">「{{ $selectedJob->name }}」</span> に転職しますか？
                </p>

                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6 shadow-inner">
                    <h4 class="text-xs text-gray-500 font-bold mb-3 uppercase tracking-wider text-center border-b border-gray-200 pb-2">転職後のステータス予測</h4>
                    <div class="grid grid-cols-2 gap-4 px-2 w-full">
                        <div class="flex justify-between items-center border-b border-gray-100 pb-1 w-full">
                            <span class="text-sm text-gray-500 font-medium mr-2">HP</span>
                            <span class="text-sm font-bold text-slate-900">{{ $statPreview['hp'] ?? 0 }}</span>
                        </div>
                        <div class="flex justify-between items-center border-b border-gray-100 pb-1 w-full">
                            <span class="text-sm text-gray-500 font-medium mr-2">SP</span>
                            <span class="text-sm font-bold text-slate-900">{{ $statPreview['mp'] ?? 0 }}</span>
                        </div>
                        <div class="flex justify-between items-center border-b border-gray-100 pb-1 w-full">
                            <span class="text-sm text-gray-500 font-medium mr-2">攻撃力</span>
                            <span class="text-sm font-bold text-slate-900">{{ $statPreview['str'] ?? 0 }}</span>
                        </div>
                        <div class="flex justify-between items-center border-b border-gray-100 pb-1 w-full">
                            <span class="text-sm text-gray-500 font-medium mr-2">防御力</span>
                            <span class="text-sm font-bold text-slate-900">{{ $statPreview['def'] ?? 0 }}</span>
                        </div>
                        <div class="flex justify-between items-center border-b border-gray-100 pb-1 w-full">
                            <span class="text-sm text-gray-500 font-medium mr-2">魔力</span>
                            <span class="text-sm font-bold text-slate-900">{{ $statPreview['mag'] ?? 0 }}</span>
                        </div>
                        <div class="flex justify-between items-center border-b border-gray-100 pb-1 w-full">
                            <span class="text-sm text-gray-500 font-medium mr-2">敏捷性</span>
                            <span class="text-sm font-bold text-slate-900">{{ $statPreview['agi'] ?? 0 }}</span>
                        </div>
                        <div class="flex justify-between items-center border-b border-gray-100 pb-1 w-full">
                            <span class="text-sm text-gray-500 font-medium mr-2">精神力</span>
                            <span class="text-sm font-bold text-slate-900">{{ $statPreview['spr'] ?? 0 }}</span>
                        </div>
                        <div class="flex justify-between items-center border-b border-gray-100 pb-1 w-full">
                            <span class="text-sm text-gray-500 font-medium mr-2">運</span>
                            <span class="text-sm font-bold text-slate-900">{{ $statPreview['luk'] ?? 0 }}</span>
                        </div>
                    </div>
                    <div class="mt-4 text-xs text-gray-500 text-center flex flex-col items-center gap-1.5 leading-relaxed bg-white p-2 rounded border border-gray-100">
                        <img src="{{ asset('images/icon/icon_046.webp') }}" alt="" class="w-5 h-5 object-contain">
                        @if($isHighRankJob($selectedJob->rank ?? null))
                            <span class="font-extrabold text-amber-700">
                                忠告：{{ \App\Support\JobRankCatalog::label($selectedJob->rank ?? null) }}は高位転職となるため、転職時に現在の基礎能力値の約{{ $inheritancePercentLabel($selectedJob->rank ?? null) }}になります。<br>
                                マスターボーナスは維持されますが、HP/SPや攻撃力などの基礎能力値は{{ $inheritanceFractionLabel($selectedJob->rank ?? null) }}に圧縮されます。
                            </span>
                        @else
                            <span>転職後、基礎能力値の1/2（50%）を引き継ぎます。<br>マスターボーナスは維持されます。</span>
                        @endif
                    </div>
                </div>

                <div class="flex gap-3 w-full">
                    <button wire:click="$set('confirmingJobChange', false)" class="flex-1 px-4 py-2.5 bg-white border border-gray-300 text-gray-600 hover:bg-gray-50 rounded shadow-sm transition-colors font-bold">
                        キャンセル
                    </button>
                    <button wire:click="changeJob" class="flex-1 px-4 py-2.5 bg-amber-500 hover:bg-amber-600 text-white rounded shadow-md transition-colors font-bold border border-amber-600">
                        転職する
                    </button>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>
