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
    ];

    $rankLabel = fn ($rank) => [
        'normal' => 'NORMAL',
        'middle' => 'MIDDLE',
        'advanced' => 'ADVANCE',
        'legend' => 'LEGEND',
    ][$rank] ?? strtoupper((string) $rank);

    $isRequirementMet = function ($req) use ($character, $jobProgress) {
        if ($req->requirement_type === 'master_job') {
            $requiredJobId = (int) ($req->required_job_id ?? 0);

            return (bool) ($jobProgress[$requiredJobId]['is_mastered'] ?? false);
        }

        if ($req->requirement_type === 'character_level') {
            return (int) $character->level >= (int) $req->required_value;
        }

        return false;
    };
@endphp

<div class="max-w-7xl mx-auto p-4 flex flex-col gap-4 text-sm font-sans text-[#1e293b]" x-data="{ confirming: @entangle('confirmingJobChange') }">

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
                    <div class="mt-1 text-xs font-bold text-amber-700">転職前に能力へ割り振ると、強化した基礎能力も転職時の引き継ぎ対象になります。</div>
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
                    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-3">
                        @foreach($availableJobs as $job)
                            @php
                                $progress = $jobProgress[$job->id] ?? ['level' => 0, 'is_mastered' => false];
                                $stars = max(0, min(9, (int) ($progress['level'] ?? 0)));
                                $bonusChips = ($progress['is_mastered'] ?? false) ? $formatMasterBonuses($job) : [];
                                $rankStyle = $rankStyles[$job->rank] ?? $rankStyles['normal'];
                            @endphp
                            <button wire:click="confirmJobChange({{ $job->id }})" class="text-left group border {{ $rankStyle['card'] }} rounded-lg p-3 transition-all duration-200 hover:shadow-md relative overflow-hidden">
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
                            </button>
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
                            @endphp
                            <div class="border {{ $rankStyle['lockedCard'] }} rounded-lg p-3">
                                <div class="flex justify-between items-start mb-2">
                                    <div class="font-bold text-gray-400">
                                        {{ $job->rank === 'advanced' ? '？？？' : $job->name }}
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
                                                @else
                                                    特定の条件
                                                @endif
                                            </div>
                                        @endforeach
                                    @endif
                                </div>
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
                        <span>転職すると現在の職業のステータス補正が失われますが、<br>獲得した経験値やマスターボーナスは維持されます。</span>
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
