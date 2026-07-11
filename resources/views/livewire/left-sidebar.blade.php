<div class="w-full" x-data="{ showJobMasterModal: false }">
    @if($character)
        @php
            $maxHp = $finalStats['max_hp'] ?? 1;
            $maxMp = $finalStats['max_mp'] ?? 1;
            $hpPercent = $maxHp > 0 ? min(100, max(0, ($character->current_hp / $maxHp) * 100)) : 0;
            $mpPercent = $maxMp > 0 ? min(100, max(0, ($character->current_mp / $maxMp) * 100)) : 0;
            $expPercent = $nextExp > 0 ? min(100, max(0, ($character->exp / $nextExp) * 100)) : 0;
            $combatPower = app(\App\Services\CharacterPowerService::class)->fromFinalStats($finalStats ?? []);

            $equippedItems = app(\App\Services\EquipmentService::class)->getEquippedItems($character);
            $weapon = $equippedItems['weapon'] ?? null;
            $armor = $equippedItems['armor'] ?? null;
            $accessory = $equippedItems['accessory'] ?? null;
            $activeSupport = app(\App\Services\ExplorationSupportService::class)->payload($character);

            $jobExpPercent = 0;
            if (isset($jobExpInfo) && $jobExpInfo) {
                if ($jobExpInfo['is_mastered']) {
                    $jobExpPercent = 100;
                } else if ($jobExpInfo['next_required'] > 0) {
                    $jobExpPercent = min(100, max(0, ($jobExpInfo['current'] / $jobExpInfo['next_required']) * 100));
                }
            }

            $arenaRanking = $character->arenaRanking;
            $arenaRank = $arenaRanking ? (int) $arenaRanking->rank : null;
            $partnerValmon = $character->partnerValmon;
        @endphp

        <!-- ステータスパネル -->
        <div class="relative mb-4 rounded-xl border border-[#d4af37] bg-white p-3 shadow-[0_8px_22px_rgba(126,96,28,0.18)]">

            <!-- プロフィール帯 -->
            <div class="mb-2 space-y-2">
                <div class="grid grid-cols-[4.75rem_minmax(0,1fr)] items-center gap-2 sm:grid-cols-[5.25rem_minmax(0,1fr)]">
                    <div class="flex h-24 w-[4.75rem] flex-shrink-0 items-center justify-center overflow-hidden sm:h-28 sm:w-[5.25rem]">
                        @if($character->icon_path)
                            <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($character->icon_path) }}" alt="{{ $character->name }}" class="w-full h-full object-contain">
                        @else
                            <span class="text-4xl text-slate-400">👤</span>
                        @endif
                    </div>

                    <div class="min-w-0 flex-1 text-left">
                        <div class="border-b border-slate-100 pb-1">
                            <div class="min-w-0">
                                <h2 class="font-extrabold text-[#003366] text-lg tracking-wide truncate leading-tight sm:text-xl">{{ $character->name }}</h2>
                            </div>
                        </div>
                        <div class="mt-1">
                            <div class="min-w-0">
                                <div class="truncate text-[11px] font-bold text-slate-600 leading-snug">
                                    Lv <span class="text-slate-900">{{ $character->level }}</span>
                                    <span class="mx-1 text-slate-300">/</span>
                                    {{ $character->jobClass->name ?? '無職' }}★{{ $jobLevel ?? 1 }}
                                </div>
                            </div>

                            <div class="mt-1.5 flex min-w-0 items-center gap-1.5">
                                <div class="flex min-w-0 items-center gap-1.5">
                                    <img src="{{ asset('images/icon/icon_009.webp') }}" alt="" class="h-5 w-5 shrink-0 object-contain">
                                    <span class="shrink-0 text-[11px] font-black text-slate-500">戦力</span>
                                    <span class="text-base font-black text-slate-800 tabular-nums">{{ number_format($combatPower) }}</span>
                                </div>
                            </div>

                            <div class="mt-1 flex min-w-0 items-center gap-1.5">
                                <img src="{{ asset('images/icon/colosseum01.webp') }}" alt="ランク" class="h-5 w-5 shrink-0 object-contain">
                                <span class="shrink-0 text-[11px] font-black text-slate-500">ランク</span>
                                <div class="shrink-0 leading-none">
                                    @if($arenaRank)
                                        <span class="text-base font-black text-slate-800 tabular-nums">{{ number_format($arenaRank) }}</span><span class="text-[11px] font-bold text-slate-500">位</span>
                                    @else
                                        <span class="text-[10px] font-bold text-slate-400">未参加</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                @if($partnerValmon)
                        <a href="{{ route('valmons.index') }}" wire:navigate class="block min-w-0 rounded-xl border border-slate-200 bg-white/80 px-3 py-2 shadow-sm transition hover:bg-slate-50 hover:opacity-90">
                            <div class="grid min-w-0 grid-cols-[4.75rem_minmax(0,1fr)] items-center gap-x-2 sm:grid-cols-[5.25rem_minmax(0,1fr)]">
                                <div class="min-w-0 text-center">
                                    <div class="flex h-16 w-[4.75rem] shrink-0 items-center justify-center p-0.5 sm:h-[4.5rem] sm:w-[5.25rem]">
                                        @if($partnerValmon->master?->image_path)
                                            <img src="{{ $partnerValmon->master->imageUrl() }}" alt="{{ $partnerValmon->displayName() }}" class="h-full w-full object-contain">
                                        @else
                                            <img src="{{ asset('images/icon/icon_038.webp') }}" alt="" class="h-full w-full object-contain">
                                        @endif
                                    </div>
                                    <div class="mt-0.5 text-[10px] font-black leading-none text-slate-500">Lv{{ $partnerValmon->level }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="text-[10px] font-semibold leading-none text-slate-400">相棒ヴァルモン</div>
                                    <div class="mt-1 truncate text-sm font-black leading-tight text-slate-900 sm:text-base">{{ $partnerValmon->displayName() }}</div>
                                    <div class="mt-1 whitespace-nowrap text-[10px] font-bold leading-none text-slate-500 sm:text-[11px]">
                                        @if($valmonIsMaxLevel)
                                            最大Lv
                                        @else
                                            次のLvまで{{ number_format($valmonNextLevelRemaining ?? 0) }}
                                        @endif
                                    </div>
                                    <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-200">
                                        <div class="h-full rounded-full bg-slate-400" style="width: {{ $valmonExpPercent ?? 0 }}%"></div>
                                    </div>
                                </div>
                            </div>
                        </a>
                @endif
                </div>

                <div class="mt-2 grid grid-cols-4 gap-2">
                    <a href="{{ route('inventory.index') }}" wire:navigate class="flex min-h-11 items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 transition hover:bg-slate-100">
                        <img src="{{ asset('images/icon/icon_025.webp') }}" alt="" class="w-5 h-5 object-contain">
                        <span class="text-xs font-bold text-slate-600 leading-none whitespace-nowrap">倉庫</span>
                    </a>
                    <a href="{{ route('equipment.index') }}" wire:navigate class="flex min-h-11 items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 transition hover:bg-slate-100">
                        <img src="{{ asset('images/icon/icon_006.webp') }}" alt="" class="w-5 h-5 object-contain">
                        <span class="text-xs font-bold text-slate-600 leading-none whitespace-nowrap">装備</span>
                    </a>
                    <a href="{{ route('monster-marks.index') }}" wire:navigate class="flex min-h-11 items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 transition hover:bg-slate-100">
                        <img src="{{ asset('images/icon/icon_013.webp') }}" alt="" class="w-5 h-5 object-contain">
                        <span class="text-xs font-bold text-slate-600 leading-none whitespace-nowrap">印</span>
                    </a>
                    <a href="{{ route('titles.index') }}" wire:navigate class="flex min-h-11 items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 transition hover:bg-slate-100">
                        <img src="{{ asset('images/icon/icon_014.webp') }}" alt="" class="w-5 h-5 object-contain">
                        <span class="text-xs font-bold text-slate-600 leading-none whitespace-nowrap">称号</span>
                    </a>
                </div>

            <hr class="border-slate-100 my-2 border-dashed">

            <!-- HP / EXP ゲージ -->
            <div class="mb-3 grid grid-cols-2 gap-2">
                <div class="px-1 py-1">
                    <div class="mb-1 flex items-end justify-between gap-1">
                        <span class="text-xs font-black text-red-600">HP</span>
                        <span class="text-[11px] font-black tabular-nums text-slate-800">{{ number_format($character->current_hp) }} / {{ number_format($maxHp) }}</span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
                        <div class="h-full rounded-full bg-red-500 transition-all duration-300" style="width: {{ $hpPercent }}%"></div>
                    </div>
                </div>
                <div class="px-1 py-1">
                    <div class="mb-1 flex items-end justify-between gap-1">
                        <span class="text-xs font-black text-blue-600">SP</span>
                        <span class="text-[11px] font-black tabular-nums text-slate-800">{{ number_format($character->current_mp) }} / {{ number_format($maxMp) }}</span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
                        <div class="h-full rounded-full bg-blue-500 transition-all duration-300" style="width: {{ $mpPercent }}%"></div>
                    </div>
                </div>
                <div class="px-1 py-1">
                    <div class="mb-1 flex items-end justify-between gap-1">
                        <span class="text-xs font-black text-amber-500">経験値</span>
                        <span class="text-[11px] font-black tabular-nums text-slate-800">{{ number_format($character->exp) }} / {{ number_format($nextExp) }}</span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
                        <div class="h-full rounded-full bg-amber-500 transition-all duration-300" style="width: {{ $expPercent }}%"></div>
                    </div>
                </div>
                @if(isset($jobExpInfo) && $jobExpInfo)
                <div class="px-1 py-1">
                    <div class="mb-1 flex items-end justify-between gap-1">
                        <span class="text-xs font-black text-green-600">職業EXP</span>
                        <span class="text-[11px] font-black tabular-nums text-slate-800">
                            @if($jobExpInfo['is_mastered'])
                                <span class="text-green-600">MASTER</span>
                            @else
                                {{ number_format($jobExpInfo['current']) }} / {{ number_format($jobExpInfo['next_required']) }}
                            @endif
                        </span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
                        <div class="h-full rounded-full bg-green-500 transition-all duration-300" style="width: {{ $jobExpPercent }}%"></div>
                    </div>
                </div>
                @else
                <div class="px-1 py-1">
                    <div class="mb-1 flex items-end justify-between gap-1">
                        <span class="text-xs font-black text-slate-500">職業EXP</span>
                        <span class="text-[11px] font-black text-slate-400">-</span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200"></div>
                </div>
                @endif
            </div>

            <hr class="border-slate-100 my-2 border-dashed">

            @if(($character->bonus_points ?? 0) > 0)
                <a href="{{ route('bonus-points.index') }}" wire:navigate class="mb-2 flex items-center justify-between rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-extrabold text-amber-800 shadow-sm hover:bg-amber-100">
                    <span>能力割振り</span>
                    <span>未使用BP {{ number_format($character->bonus_points) }}</span>
                </a>
            @endif

            <!-- 各種ステータス -->
            <div class="grid grid-cols-2 gap-y-1.5 gap-x-2 mb-3 text-xs">
                <div class="flex items-center gap-1 min-w-0">
                    <img src="{{ asset('images/icon/icon_str.webp') }}" class="w-3.5 h-3.5 object-contain" alt="攻撃">
                    <span class="text-slate-500 font-bold text-[11px] w-7">攻撃</span>
                    <span class="font-bold">{{ ($finalStats['str'] ?? 0) - ($finalStats['bonuses']['str'] ?? 0) }}</span>
                    @if(($finalStats['bonuses']['str'] ?? 0) > 0)
                        <span class="text-[11px] text-green-600 font-bold">+{{ $finalStats['bonuses']['str'] }}</span>
                    @endif
                </div>
                <div class="flex items-center gap-1 min-w-0">
                    <img src="{{ asset('images/icon/icon_def.webp') }}" class="w-3.5 h-3.5 object-contain" alt="防御">
                    <span class="text-slate-500 font-bold text-[11px] w-7">防御</span>
                    <span class="font-bold">{{ ($finalStats['def'] ?? 0) - ($finalStats['bonuses']['def'] ?? 0) }}</span>
                    @if(($finalStats['bonuses']['def'] ?? 0) > 0)
                        <span class="text-[11px] text-green-600 font-bold">+{{ $finalStats['bonuses']['def'] }}</span>
                    @endif
                </div>
                <div class="flex items-center gap-1 min-w-0">
                    <img src="{{ asset('images/icon/icon_agi.webp') }}" class="w-3.5 h-3.5 object-contain" alt="敏捷">
                    <span class="text-slate-500 font-bold text-[11px] w-7">敏捷</span>
                    <span class="font-bold">{{ ($finalStats['agi'] ?? 0) - ($finalStats['bonuses']['agi'] ?? 0) }}</span>
                    @if(($finalStats['bonuses']['agi'] ?? 0) > 0)
                        <span class="text-[11px] text-green-600 font-bold">+{{ $finalStats['bonuses']['agi'] }}</span>
                    @endif
                </div>
                <div class="flex items-center gap-1 min-w-0">
                    <img src="{{ asset('images/icon/icon_mag.webp') }}" class="w-3.5 h-3.5 object-contain" alt="魔力">
                    <span class="text-slate-500 font-bold text-[11px] w-7">魔力</span>
                    <span class="font-bold">{{ ($finalStats['mag'] ?? 0) - ($finalStats['bonuses']['mag'] ?? 0) }}</span>
                    @if(($finalStats['bonuses']['mag'] ?? 0) > 0)
                        <span class="text-[11px] text-green-600 font-bold">+{{ $finalStats['bonuses']['mag'] }}</span>
                    @endif
                </div>
                <div class="flex items-center gap-1 min-w-0">
                    <img src="{{ asset('images/icon/icon_spr.webp') }}" class="w-3.5 h-3.5 object-contain" alt="精神">
                    <span class="text-slate-500 font-bold text-[11px] w-7">精神</span>
                    <span class="font-bold">{{ ($finalStats['spr'] ?? 0) - ($finalStats['bonuses']['spr'] ?? 0) }}</span>
                    @if(($finalStats['bonuses']['spr'] ?? 0) > 0)
                        <span class="text-[11px] text-green-600 font-bold">+{{ $finalStats['bonuses']['spr'] }}</span>
                    @endif
                </div>
                <div class="flex items-center gap-1 min-w-0">
                    <img src="{{ asset('images/icon/icon_luk.webp') }}" class="w-3.5 h-3.5 object-contain" alt="運">
                    <span class="text-slate-500 font-bold text-[11px] w-7">運</span>
                    <span class="font-bold">{{ ($finalStats['luk'] ?? 0) - ($finalStats['bonuses']['luk'] ?? 0) }}</span>
                    @if(($finalStats['bonuses']['luk'] ?? 0) > 0)
                        <span class="text-[11px] text-green-600 font-bold">+{{ $finalStats['bonuses']['luk'] }}</span>
                    @endif
                </div>
            </div>

            <hr class="border-slate-100 my-2 border-dashed">

            <!-- 現在の装備 -->
            <div class="mt-2">
                <div class="flex justify-between items-center mb-2">
                    <span class="text-[11px] text-slate-500 font-bold">◆ 現在の装備</span>
                    <a href="{{ route('equipment.index') }}" wire:navigate class="text-xs text-blue-600 hover:underline">装備変更</a>
                </div>
                @php
                    $rankColors = [
                        'EPIC' => '#e11d48', // クリムゾン（最高・特別）
                        'SSS'  => '#f97316', // オレンジ
                        'SS'   => '#c084fc', // 紫
                        'S'    => '#d4af37', // ゴールド
                        'SPECIAL' => '#0f766e', // 星樹の塔報酬
                        'A'    => '#ef4444', // 赤
                        'B'    => '#3b82f6', // 青
                        'C'    => '#22c55e', // 緑
                        'D'    => '#94a3b8', // グレー
                        'E'    => '#64748b', // ダークグレー
                        'F'    => '#b0bec5', // 薄グレー
                        'G'    => '#d1d5db', // 最薄グレー（最低）
                    ];
                    // 小文字は大文字と同色
                    foreach (['b','c','d','e','f','g'] as $_r) {
                        $rankColors[$_r] = $rankColors[strtoupper($_r)];
                    }
                    unset($_r);
                    $equipmentRankSlot = function ($label, $characterItem, $rankColumn) {
                        $item = $characterItem?->item;
                        $rank = strtoupper((string) ($item?->{$rankColumn} ?? $item?->rarity ?? ''));

                        return [
                            'label' => $label,
                            'item' => $characterItem,
                            'rank' => $rank,
                            'rank_label' => $rank === 'SPECIAL' && (string) ($item?->source_type ?? '') === 'star_tree_tower_reward'
                                ? '星樹'
                                : $rank,
                        ];
                    };

                    $equippedSlots = [
                        $equipmentRankSlot('武器', $weapon, 'weapon_rank'),
                        $equipmentRankSlot('防具', $armor, 'armor_rank'),
                        $equipmentRankSlot('装飾', $accessory, 'accessory_rank'),
                    ];
                @endphp
                <div class="space-y-1">
                    @foreach($equippedSlots as $slot)
                    <div class="flex items-center border border-slate-200 rounded text-xs bg-slate-50 overflow-hidden">
                        <div class="px-2 py-1 bg-slate-100 border-r border-slate-200 text-slate-600 w-12 text-center flex-shrink-0 text-[10px] font-bold whitespace-nowrap">{{ $slot['label'] }}</div>
                        @if($slot['item'])
                            @if($slot['rank'])
                                @php $rankColor = $rankColors[$slot['rank']] ?? '#94a3b8'; @endphp
                                <div class="ml-1 inline-flex h-5 min-w-5 shrink-0 items-center justify-center border border-black/20 px-1 text-[10px] font-black leading-none text-white shadow-sm"
                                     style="background-color:{{ $rankColor }};">{{ $slot['rank_label'] }}</div>
                            @endif
                            <div class="px-2 py-1 flex-1 truncate leading-tight text-slate-800 font-semibold">{{ $slot['item']->displayName() }}</div>
                        @else
                            <div class="px-2 py-1 flex-1 truncate leading-tight text-slate-400">なし</div>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>

            <!-- もちもの -->
            <div class="mt-2">
                <div class="flex justify-between items-center mb-2">
                    <span class="text-[11px] text-slate-500 font-bold">◆ もちもの</span>
                    <a href="{{ route('equipment.index', ['tab' => 'belongings']) }}" wire:navigate class="text-xs text-blue-600 hover:underline">もちもの変更</a>
                </div>
                <div class="space-y-1">
                    <div class="flex items-center border border-slate-200 rounded text-xs bg-slate-50 overflow-hidden">
                        <div class="px-2 py-1 bg-slate-100 border-r border-slate-200 text-slate-600 w-12 text-center flex-shrink-0 text-[10px] font-bold whitespace-nowrap">補助品</div>
                        @if($activeSupport)
                            <div class="px-2 py-1 flex-1 truncate leading-tight text-slate-800 font-semibold">{{ $activeSupport['name'] }}</div>
                        @else
                            <div class="px-2 py-1 flex-1 truncate leading-tight text-slate-400">なし</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

    @else
        <!-- 未ログイン状態またはキャラクター未作成時の表示 -->
        <div class="bg-white rounded-lg shadow-md border border-[#d4af37] p-4 text-center">
            <p class="text-slate-500 text-sm">キャラクター情報がありません</p>
        </div>
    @endif

    <!-- 職業マスター状況モーダル -->
    <div x-show="showJobMasterModal" style="display: none; z-index: 99999;" class="relative" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="fixed inset-0 transition-opacity" style="background-color: rgba(15, 23, 42, 0.4);" @click="showJobMasterModal = false"></div>

        <div class="fixed inset-0 w-screen overflow-y-auto" style="z-index: 99999;">
            <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0" @click.self="showJobMasterModal = false">
                <div class="relative transform overflow-hidden rounded-xl bg-white text-left shadow-2xl transition-all sm:my-8 sm:w-full md:max-w-3xl border-2 border-amber-400" @click.stop>
                    <div class="bg-slate-800 px-4 py-3 border-b-4 border-amber-500 flex justify-between items-center">
                        <h3 class="text-lg font-extrabold text-white flex items-center gap-2" id="modal-title">
                            <img src="{{ asset('images/icon/icon_009.webp') }}" alt="" class="w-5 h-5 object-contain"> 職業マスター状況
                        </h3>
                        <button @click="showJobMasterModal = false" class="text-slate-300 hover:text-white transition-colors">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="bg-slate-50 px-4 py-5 sm:p-6 overflow-y-auto" style="max-height: 60vh;">
                        @if($character && isset($allJobs))
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                @foreach($allJobs as $job)
                                    @php
                                        $history = $character->jobHistories->where('job_class_id', $job->id)->first();
                                        $level = $history ? (int) $history->job_level : 0;
                                        $displayLevel = max(0, min(10, $level));
                                        $starString = str_repeat('★', $displayLevel) . str_repeat('☆', 10 - $displayLevel);
                                        $displayName = ($job->rank === 'legend' && $level === 0) ? '？？？' : $job->name;
                                    @endphp
                                    <div class="flex justify-between items-center p-2 rounded {{ $character->current_job_id === $job->id ? 'bg-amber-100 border border-transparent shadow-sm' : 'bg-white border border-slate-200' }}">
                                        <div class="font-bold {{ $level > 0 ? 'text-slate-800' : 'text-slate-400' }} flex items-center gap-2 w-32 shrink-0">
                                            @if($character->current_job_id === $job->id)
                                                <span class="text-amber-600 text-xs">▶</span>
                                            @endif
                                            {{ $displayName }}
                                        </div>
                                        <div class="text-lg tracking-widest text-amber-500 font-sans">
                                            {{ $starString }}
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-center text-slate-500">データがありません</p>
                        @endif
                    </div>
                    <div class="bg-slate-100 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                        <button type="button" class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-4 py-2 text-sm font-semibold text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50 sm:mt-0 sm:w-auto" @click="showJobMasterModal = false">
                            閉じる
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
