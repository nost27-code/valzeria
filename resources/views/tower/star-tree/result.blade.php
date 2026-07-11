@php
    $towerUi = $towerUi ?? [];
    $towerAssets = $towerUi['assets'] ?? [];
    $towerName = (string) ($towerUi['name'] ?? '星樹の塔');
    $genericEnemyName = (string) ($towerUi['generic_enemy_name'] ?? '星樹の魔物');
    $merchantFoundTitle = (string) ($towerUi['merchant_found_title'] ?? '星灯の行商人を見つけた');
    $merchantIntro = (string) ($towerUi['merchant_intro'] ?? '戦いを終えた枝道の先で、小さな灯りを掲げた行商人が待っている。');
    $towerSymbolImage = (string) ($towerAssets['symbol'] ?? 'images/tower/01_tower_symbol.webp');
    $towerBackgroundImage = (string) ($towerAssets['background'] ?? 'images/tower/01_tower.webp');
    $merchantIconImage = (string) ($towerAssets['merchant_icon'] ?? 'images/icon/icon_082.webp');
    $isMerchantPending = $run?->pending_event === \App\Services\TowerMerchantService::PENDING_EVENT;
    $isBattleEvent = $event->event_type === 'battle';
    $titleFloor = ! $isBattleEvent && $run?->status === \App\Services\StarTreeTowerService::STATUS_RUNNING
        ? (int) ($run->current_floor ?? $event->floor)
        : (int) $event->floor;
    $titleLayer = $isBattleEvent
        ? ($eventFloor?->layer_name ?? null)
        : ($currentFloor?->layer_name ?? null);
    $title = $titleFloor > 0
        ? $towerName . ' ' . number_format($titleFloor) . '階'
        : $towerName;
    $statusFloor = (int) ($currentFloor?->floor ?? $titleFloor);
    $statusLayerLabel = $titleLayer
        ? number_format($statusFloor) . '階（' . $titleLayer . '）'
        : ($statusFloor > 0 ? number_format($statusFloor) . '階' : $towerName);
    $resultLabel = match ($event->result) {
        'victory' => '突破',
        'defeat', 'defeated' => '敗北',
        'timeout' => '撤退',
        default => $event->result,
    };
    $battleLogs = collect($event->metadata['logs'] ?? []);
    $unlockedTitles = collect($event->metadata['unlocked_titles'] ?? [])->filter();
    $claimedCardBackgroundRewards = collect($event->metadata['pending_rewards'] ?? [])
        ->filter(fn ($reward) => ($reward['reward_type'] ?? '') === \App\Services\StarTreeTowerRewardService::TYPE_CARD_BACKGROUND)
        ->values();
    $activatedWard = $event->metadata['ward'] ?? null;
    $enemyStats = $event->metadata['enemy_stats'] ?? [];
    $enemyNameForSpecies = (string) ($event->enemy_name ?? '');
    $enemySpeciesLabel = match (true) {
        str_contains($enemyNameForSpecies, '小鬼') => '小鬼',
        str_contains($enemyNameForSpecies, 'スライム') => 'スライム',
        str_contains($enemyNameForSpecies, 'リス') || str_contains($enemyNameForSpecies, '猿') || str_contains($enemyNameForSpecies, '鹿') || str_contains($enemyNameForSpecies, '獣') || str_contains($enemyNameForSpecies, '幻獣') => '獣',
        str_contains($enemyNameForSpecies, '蝶') || str_contains($enemyNameForSpecies, '精') || str_contains($enemyNameForSpecies, '精霊') || str_contains($enemyNameForSpecies, '妖精') => '妖精',
        str_contains($enemyNameForSpecies, '鳥') => '飛行',
        str_contains($enemyNameForSpecies, '虫') || str_contains($enemyNameForSpecies, '甲虫') => '虫',
        str_contains($enemyNameForSpecies, '樹') || str_contains($enemyNameForSpecies, '蔦') || str_contains($enemyNameForSpecies, '葉') => '植物',
        default => '魔物',
    };
    $isVictory = $event->result === 'victory';
    $isPositiveResult = $isVictory || !$isBattleEvent;
    $isRunning = $run && $run->status === \App\Services\StarTreeTowerService::STATUS_RUNNING;
    $maxTowerFloor = max(1, (int) config('star_tree_tower.star_tree.seed_floor_count', 100));
    $isTowerCleared = $run && (int) ($run->cleared_floor ?? 0) >= $maxTowerFloor;
    $isFinalFloorVictory = $isBattleEvent && $isVictory && (int) $event->floor >= $maxTowerFloor;
    $hasMerchant = $isRunning && $isMerchantPending;
    $staminaCost = (int) ($currentFloor?->stamina_cost ?? 0);
    $nextFloorActionLabel = $currentFloor
        ? number_format((int) $currentFloor->floor) . '階へ進む'
        : '次の階へ進む';
    $hasStamina = !$currentFloor || (int) ($stamina['current'] ?? 0) >= $staminaCost;
    $towerHpPercent = $run && (int) $run->tower_max_hp > 0
        ? min(100, (int) floor(((int) $run->tower_current_hp / max(1, (int) $run->tower_max_hp)) * 100))
        : 0;
    $towerMpPercent = $run && (int) $run->tower_max_mp > 0
        ? min(100, (int) floor(((int) $run->tower_current_mp / max(1, (int) $run->tower_max_mp)) * 100))
        : 0;
    $expGained = (int) ($event->exp_gained ?? 0);
    $jobExpGained = (int) ($event->job_exp_gained ?? 0);
    $hasPurchasedMerchantProduct = collect($merchantProducts)->contains(fn ($product) => (bool) ($product['purchased'] ?? false));
    $supportItemCounts = $supportItemCounts ?? [];
    $staminaRecoveryChoices = collect(['explore_stamina_small_bottle', 'explore_stamina_potion'])
        ->map(function (string $itemKey) use ($supportItemCounts) {
            $item = config("adventure_support.items.{$itemKey}");
            if (!$item) {
                return null;
            }

            return [
                'key' => $itemKey,
                'name' => (string) ($item['name'] ?? $itemKey),
                'icon_image' => $item['icon_image'] ?? null,
                'effect_value' => (int) ($item['effect_value'] ?? 0),
                'price' => (int) ($item['price'] ?? 0),
                'quantity' => (int) ($supportItemCounts[$itemKey] ?? 0),
                'use_url' => route('inventory.support-items.use', ['itemKey' => $itemKey]),
                'purchase_url' => route('kiseki.support.purchase'),
            ];
        })
        ->filter()
        ->values();
    $currentKiseki = (int) ($character->free_kiseki ?? 0) + (int) ($character->paid_kiseki ?? 0);
    $hasScoutedCurrentFloor = (bool) ($hasScoutedCurrentFloor ?? false);
    $hasPendingTowerStance = !empty($pendingTowerStance) && !$isTowerCleared && $currentFloor;
    $towerActionStrategies = collect($towerActionStrategies ?? [])
        ->reject(fn ($strategy) => $hasScoutedCurrentFloor && (string) ($strategy['key'] ?? '') === 'scout')
        ->values();
    $currentStrategyKey = (string) ($event->metadata['strategy']['key'] ?? 'normal');
    $isScoutEvent = $event->event_type === 'scout';
    $battleStartStatus = (array) ($event->metadata['battle_start_status'] ?? []);
    $battleStartHp = (int) ($battleStartStatus['hp'] ?? ($event->hp_after ?? ($run->tower_current_hp ?? 0)));
    $battleStartMaxHp = (int) ($battleStartStatus['max_hp'] ?? ($run->tower_max_hp ?? ($finalStats['max_hp'] ?? 0)));
    $battleStartSp = (int) ($battleStartStatus['sp'] ?? ($event->mp_after ?? ($run->tower_current_mp ?? 0)));
    $battleStartMaxSp = (int) ($battleStartStatus['max_sp'] ?? ($run->tower_max_mp ?? ($finalStats['max_mp'] ?? 0)));
    $battleStartHpPercent = $battleStartMaxHp > 0
        ? min(100, (int) floor(($battleStartHp / max(1, $battleStartMaxHp)) * 100))
        : 0;
    $playerStartStats = array_merge([
        'str' => (int) ($finalStats['str'] ?? 0),
        'def' => (int) ($finalStats['def'] ?? 0),
        'mag' => (int) ($finalStats['mag'] ?? 0),
        'spr' => (int) ($finalStats['spr'] ?? 0),
        'agi' => (int) ($finalStats['agi'] ?? 0),
        'luk' => (int) ($finalStats['luk'] ?? 0),
    ], (array) ($event->metadata['player_start_stats'] ?? ($battleStartStatus['stats'] ?? [])));
    $playerBaseStats = array_merge($playerStartStats, (array) ($event->metadata['player_base_stats'] ?? []));
    $enemyBattleStats = array_merge([
        'max_hp' => 0,
        'mp' => 0,
        'max_mp' => 0,
        'str' => 0,
        'def' => 0,
        'mag' => 0,
        'spr' => 0,
        'agi' => 0,
        'luk' => 0,
    ], (array) $enemyStats);
    $enemyBaseStats = array_merge($enemyBattleStats, (array) ($event->metadata['enemy_base_stats'] ?? []));
    $formatStatWithDelta = function (array $stats, array $baseStats, string $key): string {
        $value = (int) ($stats[$key] ?? 0);
        $base = (int) ($baseStats[$key] ?? $value);
        $delta = $value - $base;
        $html = number_format($delta === 0 ? $value : $base);

        if ($delta > 0) {
            $html .= ' <span class="ml-1 text-[10px] font-black text-emerald-600">+'.number_format($delta).'</span>';
        } elseif ($delta < 0) {
            $html .= ' <span class="ml-1 text-[10px] font-black text-rose-600">'.number_format($delta).'</span>';
        }

        return $html;
    };
@endphp

<x-layouts.facility
    :title="$title"
    :headerIconImage="$towerSymbolImage"
    :bgImage="$towerBackgroundImage"
    :pageBgImage="$towerBackgroundImage"
    pageBgOverlay="bg-emerald-950/60"
    headerOverlayClass="bg-transparent"
    headerTitleClass="text-white"
    headerBorderClass="border-emerald-700"
    :battleResultLayout="true"
    :showBattleChatLog="true"
    :showExit="false"
>
    <div class="py-1 flex flex-col items-center" data-battle-result-page>
        <div class="w-full mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-xl sm:rounded-lg overflow-hidden border border-slate-200">
                <div class="p-6 text-slate-800">
                    @if($isBattleEvent)
                        <div class="mb-8 flex flex-col items-center justify-center gap-4 md:flex-row">
                            <div class="w-full border-2 border-amber-200 rounded-lg overflow-hidden md:w-5/12">
                                <div class="bg-amber-100 text-amber-900 font-bold text-center py-1 border-b border-amber-200">
                                    {{ $character->name }}
                                </div>
                                <table class="w-full text-sm text-center">
                                    <tbody>
                                        <tr class="border-b border-amber-100">
                                            <th class="bg-amber-50 w-1/4 py-1 text-slate-600">職業</th>
                                            <td class="w-1/4">{{ $character->jobClass->name ?? '冒険者' }} <span class="text-xs">(Lv.{{ $jobLevel ?? 1 }})</span></td>
                                            <th class="bg-amber-50 w-1/4 text-slate-600">Lv</th>
                                            <td class="w-1/4 font-bold">{{ number_format((int) $character->level) }}</td>
                                        </tr>
                                        <tr>
                                            <th class="bg-amber-50 py-1 text-slate-600">HP</th>
                                            <td class="font-bold text-xs sm:text-sm {{ $battleStartHpPercent <= 20 ? 'text-red-600' : 'text-slate-800' }}" data-tower-hp-summary>
                                                {{ number_format($battleStartHp) }} / {{ number_format($battleStartMaxHp) }}
                                            </td>
                                            <th class="bg-amber-50 py-1 text-slate-600">SP</th>
                                            <td class="font-bold text-xs text-blue-700 sm:text-sm" data-tower-sp-summary>
                                                {{ number_format($battleStartSp) }} / {{ number_format($battleStartMaxSp) }}
                                            </td>
                                        </tr>
                                        <tr class="border-t border-amber-100">
                                            <th class="bg-amber-50 py-1 text-slate-600">ATK</th>
                                            <td class="font-bold">{!! $formatStatWithDelta($playerStartStats, $playerBaseStats, 'str') !!}</td>
                                            <th class="bg-amber-50 py-1 text-slate-600">DEF</th>
                                            <td class="font-bold">{!! $formatStatWithDelta($playerStartStats, $playerBaseStats, 'def') !!}</td>
                                        </tr>
                                        <tr class="border-t border-amber-100">
                                            <th class="bg-amber-50 py-1 text-slate-600">MAG</th>
                                            <td class="font-bold">{!! $formatStatWithDelta($playerStartStats, $playerBaseStats, 'mag') !!}</td>
                                            <th class="bg-amber-50 py-1 text-slate-600">SPR</th>
                                            <td class="font-bold">{!! $formatStatWithDelta($playerStartStats, $playerBaseStats, 'spr') !!}</td>
                                        </tr>
                                        <tr class="border-t border-amber-100">
                                            <th class="bg-amber-50 py-1 text-slate-600">SPD</th>
                                            <td class="font-bold">{!! $formatStatWithDelta($playerStartStats, $playerBaseStats, 'agi') !!}</td>
                                            <th class="bg-amber-50 py-1 text-slate-600">LUK</th>
                                            <td class="font-bold">{!! $formatStatWithDelta($playerStartStats, $playerBaseStats, 'luk') !!}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="w-full flex justify-center items-center md:w-2/12">
                                <span class="text-3xl font-extrabold text-red-500 italic drop-shadow-md">VS</span>
                            </div>

                            <div class="w-full border-2 border-red-200 rounded-lg overflow-hidden md:w-5/12">
                                <div class="bg-red-100 text-red-900 font-bold text-center py-1 border-b border-red-200">
                                    {{ $event->enemy_name ?? $genericEnemyName }}
                                </div>
                                <table class="w-full text-sm text-center">
                                    <tbody>
                                        <tr class="border-b border-red-100">
                                            <th class="bg-red-50 w-1/4 py-1 text-slate-600">HP</th>
                                            <td class="w-1/4 font-bold text-xs sm:text-sm">{{ number_format((int) ($enemyBattleStats['max_hp'] ?? 0)) }} / {{ number_format((int) ($enemyBattleStats['max_hp'] ?? 0)) }}</td>
                                            <th class="bg-red-50 w-1/4 text-slate-600">SP</th>
                                            <td class="w-1/4 font-bold text-xs text-blue-700 sm:text-sm">{{ number_format((int) ($enemyBattleStats['mp'] ?? 0)) }} / {{ number_format((int) ($enemyBattleStats['max_mp'] ?? 0)) }}</td>
                                        </tr>
                                        <tr>
                                            <th class="bg-red-50 py-1 text-slate-600">ATK</th>
                                            <td class="font-bold">{!! $formatStatWithDelta($enemyBattleStats, $enemyBaseStats, 'str') !!}</td>
                                            <th class="bg-red-50 py-1 text-slate-600">DEF</th>
                                            <td class="font-bold">{!! $formatStatWithDelta($enemyBattleStats, $enemyBaseStats, 'def') !!}</td>
                                        </tr>
                                        <tr class="border-t border-red-100">
                                            <th class="bg-red-50 py-1 text-slate-600">MAG</th>
                                            <td class="font-bold">{!! $formatStatWithDelta($enemyBattleStats, $enemyBaseStats, 'mag') !!}</td>
                                            <th class="bg-red-50 py-1 text-slate-600">SPR</th>
                                            <td class="font-bold">{!! $formatStatWithDelta($enemyBattleStats, $enemyBaseStats, 'spr') !!}</td>
                                        </tr>
                                        <tr class="border-t border-red-100">
                                            <th class="bg-red-50 py-1 text-slate-600">SPD</th>
                                            <td class="font-bold">{!! $formatStatWithDelta($enemyBattleStats, $enemyBaseStats, 'agi') !!}</td>
                                            <th class="bg-red-50 py-1 text-slate-600">LUK</th>
                                            <td class="font-bold">{!! $formatStatWithDelta($enemyBattleStats, $enemyBaseStats, 'luk') !!}</td>
                                        </tr>
                                        <tr class="border-t border-red-100">
                                            <th class="bg-red-50 py-1 text-slate-600">種族</th>
                                            <td colspan="3" class="font-bold">{{ $enemySpeciesLabel }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    @if($battleLogs->isNotEmpty())
                        <div class="px-2 mb-6 font-mono text-sm sm:text-base leading-loose text-slate-700">
                            @foreach($battleLogs as $log)
                                <div>{!! $log !!}</div>
                            @endforeach
                            @if($isBattleEvent && $isVictory)
                                <div class="mt-5 text-xl font-black leading-relaxed text-slate-950 sm:text-2xl">
                                    {{ $character->name }}は、{{ $event->enemy_name ?? $genericEnemyName }}を倒した！
                                </div>
                            @elseif($isBattleEvent && in_array($event->result, ['defeat', 'defeated'], true))
                                <div class="mt-5 text-xl font-black leading-relaxed text-slate-950 sm:text-2xl">
                                    {{ $character->name }}は、倒れてしまった……。
                                </div>
                                <div class="mt-2 text-sm font-bold leading-relaxed text-slate-600">
                                    次回は{{ number_format((int) ($checkpointStartFloor ?? 1)) }}階からスタートです。
                                </div>
                            @endif
                        </div>
                    @endif

                    @if($isFinalFloorVictory)
                        <div class="mb-5 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-4 shadow-sm">
                            <div class="text-xs font-black text-emerald-700">{{ number_format($maxTowerFloor) }}階踏破</div>
                            <div class="mt-1 text-xl font-black leading-relaxed text-slate-950">
                                {{ $character->name }}は、{{ $towerName }}の頂に立った！
                            </div>
                            <p class="mt-2 text-sm font-bold leading-6 text-slate-700">
                                星樹の梢を越え、長い挑戦の果てに最上階へ到達しました。おめでとうございます！
                            </p>
                        </div>
                    @endif

                    @if($unlockedTitles->isNotEmpty())
                        <div class="mb-4 rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3">
                            <div class="text-xs font-black text-yellow-700">称号獲得</div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach($unlockedTitles as $unlockedTitle)
                                    <span class="rounded-full bg-yellow-100 px-3 py-1 text-xs font-black text-yellow-800">{{ $unlockedTitle }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if($claimedCardBackgroundRewards->isNotEmpty())
                        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 shadow-sm">
                            <div class="text-xs font-black text-emerald-700">冒険者カード背景獲得</div>
                            <div class="mt-2 space-y-2 text-sm font-bold leading-6 text-slate-700">
                                @foreach($claimedCardBackgroundRewards as $reward)
                                    <p>
                                        「{{ $reward['name'] ?? '冒険者カード背景「エルフィア」' }}」を手に入れた！
                                        プロフィール変更から背景画像を変更できるぞ！
                                    </p>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="mb-5">
                        @include('tower.star-tree.partials.reward-claims', ['pendingTowerRewards' => $pendingTowerRewards ?? []])
                    </div>

                    @if($isScoutEvent)
                        <div class="mb-5 rounded-lg border border-teal-200 bg-teal-50 px-4 py-3 shadow-sm">
                            <div class="text-xs font-black text-teal-700">様子を見る</div>
                            <div class="mt-1 text-lg font-black text-slate-950">
                                {{ number_format((int) $event->floor) }}階 / {{ $event->enemy_name ?? $genericEnemyName }}
                            </div>
                            <div class="mt-3 grid grid-cols-2 overflow-hidden rounded-lg border border-teal-100 bg-white text-center text-sm sm:grid-cols-4">
                                <div class="border-b border-r border-teal-100 p-2 sm:border-b-0">
                                    <div class="text-[11px] font-black text-slate-500">HP</div>
                                    <div class="font-bold">{{ number_format((int) ($enemyStats['max_hp'] ?? 0)) }}</div>
                                </div>
                                <div class="border-b border-teal-100 p-2 sm:border-b-0 sm:border-r">
                                    <div class="text-[11px] font-black text-slate-500">種族</div>
                                    <div class="font-bold">{{ $enemySpeciesLabel }}</div>
                                </div>
                                <div class="border-r border-teal-100 p-2">
                                    <div class="text-[11px] font-black text-slate-500">攻撃</div>
                                    <div class="font-bold">{{ number_format((int) ($enemyStats['str'] ?? 0)) }}</div>
                                </div>
                                <div class="p-2">
                                    <div class="text-[11px] font-black text-slate-500">防御</div>
                                    <div class="font-bold">{{ number_format((int) ($enemyStats['def'] ?? 0)) }}</div>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if($expGained > 0 || $jobExpGained > 0)
                        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 shadow-sm">
                            <div class="mb-2 text-sm font-black text-amber-800">🏆 獲得報酬</div>
                            <div class="space-y-1 text-sm font-bold text-slate-700">
                                @if($expGained > 0)
                                    <div>EXP: <span class="font-black text-orange-600">+{{ number_format($expGained) }}</span></div>
                                @endif
                                @if($jobExpGained > 0)
                                    <div>Job EXP: <span class="font-black text-emerald-700">+{{ number_format($jobExpGained) }}</span></div>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if($hasMerchant)
                        <section class="mb-5 overflow-hidden rounded-lg border border-amber-300 bg-amber-50 shadow-sm">
                            <div class="border-b border-amber-200 bg-gradient-to-r from-amber-100 via-yellow-50 to-white px-4 py-4">
                                <div class="flex items-start gap-3">
                                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-amber-200 bg-white shadow-sm">
                                        <img src="{{ asset($merchantIconImage) }}" alt="" class="h-8 w-8 object-contain">
                                    </div>
                                    <div class="min-w-0">
                                        <div class="text-xs font-black text-amber-700">塔内イベント発生</div>
                                        <h2 class="mt-1 text-xl font-black text-slate-950">{{ $merchantFoundTitle }}</h2>
                                        <p class="mt-1 text-sm font-bold leading-relaxed text-slate-700">
                                            {{ $merchantIntro }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="grid gap-2 p-4 md:grid-cols-2">
                                @foreach($merchantProducts as $product)
                                    @php($isPurchased = (bool) ($product['purchased'] ?? false))
                                    <form
                                        method="POST"
                                        action="{{ route('tower.star-tree.merchant.buy') }}"
                                        class="rounded-lg border border-amber-100 bg-white p-3"
                                        data-tower-submit-form
                                        data-tower-merchant-buy-form
                                        data-tower-product-key="{{ $product['key'] }}"
                                        data-loading-text="購入中..."
                                    >
                                        @csrf
                                        <input type="hidden" name="item_key" value="{{ $product['key'] }}">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <div class="font-black text-slate-950">{{ $product['name'] }}</div>
                                                <div class="mt-1 text-xs font-bold text-slate-600">{{ $product['description'] }}</div>
                                                <div class="mt-1 text-xs font-black text-amber-700">{{ number_format((int) $product['price']) }}G</div>
                                            </div>
                                            <button type="submit" @disabled($isPurchased) class="shrink-0 rounded-lg {{ $isPurchased ? 'bg-slate-300 text-slate-600' : 'bg-amber-600 text-white hover:bg-amber-700' }} px-4 py-2 text-xs font-black shadow-sm transition disabled:cursor-not-allowed disabled:opacity-80" data-tower-submit-button>
                                                <span class="inline-flex items-center gap-1.5">
                                                    <x-loading-spinner class="hidden" data-tower-submit-spinner size="h-3.5 w-3.5" />
                                                <span data-tower-submit-text>{{ $isPurchased ? '購入済み' : '購入' }}</span>
                                                </span>
                                            </button>
                                        </div>
                                    </form>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if($hasPendingTowerStance)
                        @include('tower.star-tree.partials.stance-choice', [
                            'pendingTowerStance' => $pendingTowerStance ?? null,
                            'towerStanceState' => $towerStanceState ?? null,
                            'towerStanceChoices' => $towerStanceChoices ?? [],
                        ])
                    @endif

                    @if($isRunning && $currentFloor && $towerActionStrategies->isNotEmpty())
                        <section class="mb-5 rounded-lg border border-emerald-100 bg-emerald-50/70 px-4 py-3 shadow-sm">
                            <div class="mb-3 flex items-center justify-between gap-2">
                                <div>
                                    <div class="text-xs font-black text-emerald-700">次階前の行動</div>
                                    <div class="text-sm font-bold text-slate-700">毎回ひとつだけ選べます。</div>
                                </div>
                            </div>
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach($towerActionStrategies as $strategy)
                                    <label class="flex cursor-pointer gap-2 rounded-lg border border-emerald-100 bg-white px-3 py-2 text-sm shadow-sm transition has-[:checked]:border-emerald-400 has-[:checked]:bg-emerald-50">
                                        <input
                                            type="radio"
                                            name="tower_strategy_choice"
                                            value="{{ (string) ($strategy['key'] ?? 'normal') }}"
                                            class="mt-1 h-4 w-4 border-emerald-300 text-emerald-600 focus:ring-emerald-500"
                                            data-tower-strategy-option
                                            data-strategy-key="{{ (string) ($strategy['key'] ?? 'normal') }}"
                                            data-stamina-extra="{{ (int) ($strategy['stamina_extra'] ?? 0) }}"
                                            data-fixed-stamina-cost="{{ isset($strategy['fixed_stamina_cost']) ? (int) $strategy['fixed_stamina_cost'] : '' }}"
                                            data-battle="{{ ($strategy['battle'] ?? true) ? '1' : '0' }}"
                                            @checked((string) ($strategy['key'] ?? 'normal') === 'normal')
                                        >
                                        <span class="min-w-0">
                                            <span class="block font-black text-slate-950">{{ $strategy['name'] }}</span>
                                            <span class="mt-0.5 block text-xs font-bold leading-5 text-slate-500">{{ $strategy['summary'] }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if($isRunning && $currentFloor)
                        <div
                            class="mb-3 rounded-lg border border-slate-300 bg-white px-4 py-3 shadow-sm"
                            data-tower-status-card
                            data-use-url-template="{{ route('tower.star-tree.merchant.use', ['purchase' => '__PURCHASE_ID__']) }}"
                        >
                            <div class="mb-3 flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2 text-sm font-black text-slate-700">
                                    <img src="{{ asset($merchantIconImage) }}" alt="" class="h-4 w-4 object-contain">
                                    塔内状況
                                </div>
                                <span class="rounded-full border border-violet-200 bg-violet-50 px-3 py-1 text-[11px] font-black text-violet-700">{{ $statusLayerLabel }}</span>
                            </div>
                            <div data-tower-status-body>
                                <div class="mb-3">
                                    <div class="mb-1 flex items-center justify-between text-[11px] font-black text-slate-500">
                                        <span>HP</span>
                                        <span class="{{ $towerHpPercent <= 20 ? 'text-red-600' : 'text-emerald-700' }}" data-tower-hp-text>{{ number_format((int) ($run->tower_current_hp ?? 0)) }} / {{ number_format((int) ($run->tower_max_hp ?? 0)) }} {{ $towerHpPercent }}%</span>
                                    </div>
                                    <div class="h-1.5 overflow-hidden rounded-full bg-slate-100">
                                        <div class="h-full rounded-full {{ $towerHpPercent <= 20 ? 'bg-red-500' : 'bg-emerald-500' }}" style="width: {{ $towerHpPercent }}%;" data-tower-hp-bar></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="mb-1 flex items-center justify-between text-[11px] font-black text-slate-500">
                                        <span>SP</span>
                                        <span class="text-blue-700" data-tower-sp-text>{{ number_format((int) ($run->tower_current_mp ?? 0)) }} / {{ number_format((int) ($run->tower_max_mp ?? 0)) }} {{ $towerMpPercent }}%</span>
                                    </div>
                                    <div class="h-1.5 overflow-hidden rounded-full bg-slate-100">
                                        <div class="h-full rounded-full bg-blue-500" style="width: {{ $towerMpPercent }}%;" data-tower-sp-bar></div>
                                    </div>
                                </div>
                                @if(!empty($towerRecoveryItems) || !empty($activatedWard))
                                    <div class="mt-4 border-t border-slate-100 pt-3" data-tower-items-section>
                                        <div class="mb-2 text-[11px] font-black text-slate-400">塔内アイテム</div>
                                        <div class="space-y-1.5" data-tower-items-list>
                                            @foreach($towerRecoveryItems as $item)
                                                @if($item['usable'] ?? true)
                                                    <form
                                                        method="POST"
                                                        action="{{ route('tower.star-tree.merchant.use', ['purchase' => $item['purchase_id']]) }}"
                                                        class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2"
                                                        data-tower-submit-form
                                                        data-tower-item-form
                                                        data-tower-item-key="{{ $item['key'] }}"
                                                        data-use-url-template="{{ route('tower.star-tree.merchant.use', ['purchase' => '__PURCHASE_ID__']) }}"
                                                        data-loading-text="使用中..."
                                                    >
                                                        @csrf
                                                        <div class="min-w-0 text-sm">
                                                            <span class="font-black text-slate-900">{{ $item['name'] }}</span>
                                                            <span class="ml-2 text-xs font-bold text-slate-400">{{ $item['description'] }}・<span data-tower-item-count>残{{ number_format((int) $item['count']) }}</span></span>
                                                        </div>
                                                        <button type="submit" class="shrink-0 rounded-lg bg-sky-600 px-4 py-1.5 text-xs font-black text-white shadow-sm transition hover:bg-sky-700 disabled:cursor-not-allowed disabled:opacity-70" data-tower-submit-button>
                                                            <span class="inline-flex items-center gap-1.5">
                                                                <x-loading-spinner class="hidden" data-tower-submit-spinner size="h-3.5 w-3.5" />
                                                                <span data-tower-submit-text>使う</span>
                                                            </span>
                                                        </button>
                                                    </form>
                                                @else
                                                    <div class="flex items-center justify-between gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2" data-tower-auto-item data-tower-item-key="{{ $item['key'] }}">
                                                        <div class="min-w-0 text-sm">
                                                            <span class="font-black text-slate-900" data-tower-item-name>{{ $item['name'] }}</span>
                                                            <span class="ml-2 text-xs font-bold text-emerald-700"><span data-tower-item-description>{{ $item['description'] }}</span>・<span data-tower-item-count>残{{ number_format((int) $item['count']) }}</span></span>
                                                        </div>
                                                        <span class="shrink-0 rounded-full bg-emerald-600 px-3 py-1 text-[11px] font-black text-white">{{ ($item['armed'] ?? false) ? '待機中' : '次戦闘' }}</span>
                                                    </div>
                                                @endif
                                            @endforeach
                                            @if(!empty($activatedWard))
                                                <div class="flex items-center justify-between gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                                                    <div class="min-w-0 text-sm">
                                                        <span class="font-black text-slate-900">{{ $activatedWard['name'] ?? '木霊の護符' }}</span>
                                                        <span class="ml-2 text-xs font-bold text-amber-700">
                                                            次戦闘の被ダメ-{{ number_format((int) ($activatedWard['damage_reduction_rate'] ?? 0)) }}%・発動済み
                                                        </span>
                                                    </div>
                                                    <span class="shrink-0 rounded-full bg-amber-500 px-3 py-1 text-[11px] font-black text-white">発動済み</span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                        <div class="mb-2 flex justify-center">
                            <span class="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-[11px] font-black text-emerald-700" data-tower-stamina-badge>
                                <img src="{{ asset('images/icon/icon_082.webp') }}" alt="" class="h-3 w-3 object-contain">
                                探索力 <span data-tower-stamina-current>{{ number_format((int) ($stamina['current'] ?? 0)) }}</span> / <span data-tower-stamina-max>{{ number_format((int) ($stamina['max'] ?? 0)) }}</span>
                            </span>
                        </div>
                    @endif

                    <div class="flex flex-col items-stretch justify-center gap-2 sm:flex-row sm:items-center">
                        @if($isRunning && !$hasMerchant && $currentFloor)
                            <form action="{{ route('tower.star-tree.challenge') }}" method="POST" class="w-full sm:w-auto" data-tower-submit-form data-tower-challenge-form data-loading-text="探索中..." data-current-stamina="{{ (int) ($stamina['current'] ?? 0) }}" data-required-stamina="{{ $staminaCost }}" data-base-stamina-cost="{{ $staminaCost }}" data-ready-text="{{ $nextFloorActionLabel }}（探索力 -{{ number_format($staminaCost) }}）">
                                @csrf
                                <input type="hidden" name="strategy" value="normal" data-tower-strategy-input>
                                @if($hasPendingTowerStance)
                                    <input type="hidden" name="stance" value="none" data-tower-stance-input>
                                @endif
                                <button type="submit" class="w-full bg-amber-600 hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-80 text-white font-bold py-2.5 px-8 rounded-lg shadow-md transition duration-200 text-sm flex items-center justify-center gap-2" data-tower-submit-button>
                                    <x-loading-spinner class="hidden" data-tower-submit-spinner size="h-4 w-4" />
                                    <img src="{{ asset('images/icon/icon_005.webp') }}" alt="" class="w-4 h-4 object-contain">
                                    <span data-tower-submit-text>{{ $nextFloorActionLabel }}（探索力 -{{ number_format($staminaCost) }}）</span>
                                </button>
                            </form>
                            @if((int) $run->cleared_floor > 0)
                                <form method="POST" action="{{ route('tower.star-tree.return') }}" class="w-full sm:w-auto" data-tower-submit-form data-loading-text="移動中...">
                                    @csrf
                                    <button type="submit" class="w-full rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-black text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 flex items-center justify-center gap-2" data-tower-submit-button>
                                        <x-loading-spinner class="hidden" data-tower-submit-spinner size="h-4 w-4" />
                                        <span data-tower-submit-text>いったん{{ $towerName }}から出る</span>
                                    </button>
                                </form>
                            @endif
                        @elseif($isRunning && $hasMerchant && $currentFloor)
                            <form method="POST" action="{{ route('tower.star-tree.merchant.skip') }}" class="w-full sm:w-auto" data-tower-submit-form data-tower-challenge-form data-loading-text="探索中..." data-current-stamina="{{ (int) ($stamina['current'] ?? 0) }}" data-required-stamina="{{ $staminaCost }}" data-base-stamina-cost="{{ $staminaCost }}" data-ready-text="{{ $nextFloorActionLabel }}（探索力 -{{ number_format($staminaCost) }}）">
                                @csrf
                                <input type="hidden" name="strategy" value="normal" data-tower-strategy-input>
                                @if($hasPendingTowerStance)
                                    <input type="hidden" name="stance" value="none" data-tower-stance-input>
                                @endif
                                <button type="submit" class="w-full rounded-lg bg-amber-600 px-8 py-3 text-sm font-black text-white shadow-md transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-80" data-tower-submit-button>
                                    <span class="inline-flex items-center justify-center gap-1.5">
                                        <x-loading-spinner class="hidden" data-tower-submit-spinner size="h-4 w-4" />
                                        <span data-tower-submit-text data-tower-merchant-continue-text>
                                            {{ $nextFloorActionLabel }}（探索力 -{{ number_format($staminaCost) }}）
                                        </span>
                                    </span>
                                </button>
                            </form>
                        @elseif($isRunning && $isTowerCleared)
                            <form method="POST" action="{{ route('tower.star-tree.return') }}" class="w-full sm:w-auto" data-tower-submit-form data-loading-text="移動中...">
                                @csrf
                                <button type="submit" class="w-full rounded-lg bg-emerald-600 px-8 py-3 text-sm font-black text-white shadow-md transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-80 flex items-center justify-center gap-2" data-tower-submit-button>
                                    <x-loading-spinner class="hidden" data-tower-submit-spinner size="h-4 w-4" />
                                    <span data-tower-submit-text>{{ $towerName }}の入口へ戻る</span>
                                </button>
                            </form>
                        @elseif(!$isRunning)
                            <a href="{{ route('tower.star-tree.index') }}" class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-black text-slate-700 shadow-sm transition hover:bg-slate-50 sm:w-auto">
                                {{ $towerName }}の入口へ
                            </a>
                        @endif
                    </div>

                    @if($isRunning && $currentFloor)
                        <div id="tower-stamina-modal" class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-950/45 px-4 py-6" role="dialog" aria-modal="true" aria-labelledby="tower-stamina-modal-title">
                            <div class="w-full max-w-sm rounded-lg bg-white p-4 shadow-2xl">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <h3 id="tower-stamina-modal-title" class="text-base font-black text-slate-900">探索力を回復して探索を続けますか？</h3>
                                        <p class="mt-1 text-xs font-bold leading-5 text-slate-500">
                                            探索力が足りません。使うアイテムを選んでください。
                                        </p>
                                    </div>
                                    <button type="button" data-tower-stamina-modal-close class="rounded-full p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" aria-label="閉じる">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18" />
                                        </svg>
                                    </button>
                                </div>
                                <div class="mt-3 rounded border border-sky-100 bg-sky-50 px-3 py-2 text-xs font-extrabold text-sky-800">
                                    探索力 <span data-tower-modal-stamina-current>{{ number_format((int) ($stamina['current'] ?? 0)) }}</span> / 必要 <span data-tower-modal-stamina-required>{{ number_format($staminaCost) }}</span>
                                </div>
                                <div class="mt-2 rounded border border-amber-100 bg-amber-50 px-3 py-2 text-xs font-extrabold text-amber-800">
                                    所持輝石 <span data-tower-modal-kiseki>{{ number_format($currentKiseki) }}</span>
                                </div>
                                <div class="mt-3 flex flex-col gap-2">
                                    @foreach($staminaRecoveryChoices as $choice)
                                        @php($hasOwnedStaminaItem = (int) $choice['quantity'] > 0)
                                        <div class="rounded-lg border {{ $hasOwnedStaminaItem ? 'border-emerald-200 bg-emerald-50/60' : 'border-slate-200 bg-white' }} p-2">
                                            <button type="button"
                                                    data-tower-stamina-item
                                                    data-item-key="{{ $choice['key'] }}"
                                                    data-use-url="{{ $choice['use_url'] }}"
                                                    data-quantity="{{ $choice['quantity'] }}"
                                                    @disabled($choice['quantity'] <= 0)
                                                    class="flex w-full items-center justify-between rounded-md px-2 py-2 text-left transition {{ $hasOwnedStaminaItem ? 'bg-white text-emerald-900 shadow-sm ring-1 ring-emerald-200 hover:bg-emerald-50' : 'bg-slate-50 text-slate-500 disabled:cursor-not-allowed disabled:opacity-75' }}">
                                                <span class="flex min-w-0 items-center gap-2">
                                                    @if($choice['icon_image'])
                                                        <img src="{{ asset($choice['icon_image']) }}" alt="" class="h-5 w-5 object-contain">
                                                    @endif
                                                    <span class="min-w-0">
                                                        <span class="block text-sm font-black text-slate-800">{{ $choice['name'] }}</span>
                                                        <span class="block text-[11px] font-bold text-slate-500">探索力 +{{ number_format($choice['effect_value']) }}</span>
                                                    </span>
                                                </span>
                                                <span class="flex shrink-0 flex-col items-end gap-1">
                                                    <span class="rounded bg-white px-2 py-0.5 text-[11px] font-black {{ $hasOwnedStaminaItem ? 'text-emerald-700 ring-1 ring-emerald-100' : 'text-slate-500 ring-1 ring-slate-100' }}">
                                                        所持 <span data-tower-stamina-item-count>{{ number_format($choice['quantity']) }}</span>
                                                    </span>
                                                    <span class="rounded px-2 py-0.5 text-[11px] font-black {{ $hasOwnedStaminaItem ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-500' }}">
                                                        {{ $hasOwnedStaminaItem ? '所持分を使う' : '所持なし' }}
                                                    </span>
                                                </span>
                                            </button>
                                            @unless($hasOwnedStaminaItem)
                                                <div class="mt-2 rounded-md border border-amber-200 bg-amber-50 p-2">
                                                    <div class="text-[11px] font-bold text-amber-800">所持していないため、輝石を消費して購入後に使用します。</div>
                                                    <button type="button"
                                                            data-tower-stamina-buy
                                                            data-item-key="{{ $choice['key'] }}"
                                                            data-purchase-url="{{ $choice['purchase_url'] }}"
                                                            data-price="{{ $choice['price'] }}"
                                                            class="mt-1.5 flex w-full items-center justify-center gap-1 rounded-md border border-amber-400 bg-white px-3 py-1.5 text-xs font-black text-amber-900 transition hover:bg-amber-100 active:scale-95">
                                                        <span>輝石で購入して使う</span>
                                                        <img src="{{ asset('images/icon/kiseki.webp') }}" alt="" class="h-3.5 w-3.5 object-contain">
                                                        <span>{{ number_format($choice['price']) }}</span>
                                                    </button>
                                                </div>
                                            @endunless
                                        </div>
                                    @endforeach
                                    <button type="button" data-tower-stamina-modal-close class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-black text-slate-700 transition hover:bg-slate-50">
                                        閉じる
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @once
        <script>
            function setTowerFormLoading(form, isLoading) {
                const button = form.querySelector('[data-tower-submit-button]');
                const spinner = form.querySelector('[data-tower-submit-spinner]');
                const buttonText = form.querySelector('[data-tower-submit-text]');

                if (button) {
                    button.disabled = isLoading;
                    button.classList.toggle('scale-95', isLoading);
                    button.classList.toggle('opacity-80', isLoading);
                }
                if (spinner) {
                    spinner.classList.toggle('hidden', !isLoading);
                }
                if (buttonText) {
                    if (!buttonText.dataset.originalText) {
                        buttonText.dataset.originalText = buttonText.textContent;
                    }
                    buttonText.textContent = isLoading && form.dataset.loadingText
                        ? form.dataset.loadingText
                        : buttonText.dataset.originalText;
                }
            }

            function towerNumber(value) {
                return new Intl.NumberFormat('ja-JP').format(Number(value || 0));
            }

            function csrfToken() {
                const token = document.querySelector('input[name="_token"]');
                return token ? token.value : '';
            }

            const towerChallengeGuardInitialMs = Math.max(0, Number(@js($towerChallengeGuardRemainingMs ?? 0)));
            const towerChallengeGuardStartedAt = Date.now();
            let towerChallengeGuardTimer = null;

            function towerChallengeGuardRemainingMs() {
                return Math.max(0, towerChallengeGuardInitialMs - (Date.now() - towerChallengeGuardStartedAt));
            }

            function isTowerChallengeGuarded() {
                return towerChallengeGuardRemainingMs() > 0;
            }

            function applyTowerChallengeGuard() {
                const remainingMs = towerChallengeGuardRemainingMs();
                const remainingSeconds = Math.max(1, Math.ceil(remainingMs / 1000));

                document.querySelectorAll('[data-tower-challenge-form]').forEach(function(form) {
                    const button = form.querySelector('[data-tower-submit-button]');
                    const text = form.querySelector('[data-tower-submit-text]');
                    if (!button) return;

                    if (remainingMs > 0) {
                        form.dataset.towerGuarded = '1';
                        button.disabled = true;
                        button.classList.add('opacity-80');
                        if (text) {
                            if (!text.dataset.guardOriginalText) {
                                text.dataset.guardOriginalText = text.textContent;
                            }
                            text.textContent = '少し待ってください（' + towerNumber(remainingSeconds) + '秒）';
                        }
                        return;
                    }

                    form.dataset.towerGuarded = '0';
                    if (form.dataset.submitted !== '1') {
                        button.disabled = false;
                        button.classList.remove('opacity-80');
                    }
                    if (text && text.dataset.guardOriginalText && form.dataset.submitted !== '1') {
                        text.textContent = text.dataset.originalText || text.dataset.guardOriginalText;
                        delete text.dataset.guardOriginalText;
                    }
                });

                if (remainingMs <= 0 && towerChallengeGuardTimer) {
                    window.clearInterval(towerChallengeGuardTimer);
                    towerChallengeGuardTimer = null;
                    updateTowerStrategyForms();
                }
            }

            function startTowerChallengeGuard() {
                if (!isTowerChallengeGuarded()) return;

                applyTowerChallengeGuard();
                towerChallengeGuardTimer = window.setInterval(applyTowerChallengeGuard, 250);
            }

            function selectedTowerStrategy() {
                return document.querySelector('[data-tower-strategy-option]:checked');
            }

            function selectedTowerStance() {
                return document.querySelector('[data-tower-stance-option]:checked');
            }

            function towerStrategyRequiredStamina(form, option) {
                const base = Number(form.dataset.baseStaminaCost || form.dataset.requiredStamina || 0);
                const fixed = option && option.dataset.fixedStaminaCost !== ''
                    ? Number(option.dataset.fixedStaminaCost || 0)
                    : null;
                const extra = option ? Number(option.dataset.staminaExtra || 0) : 0;

                return Math.max(1, fixed !== null && fixed > 0 ? fixed : base + extra);
            }

            function updateTowerStrategyForms() {
                const option = selectedTowerStrategy();
                const key = option ? option.dataset.strategyKey || 'normal' : 'normal';
                const isScout = option ? option.dataset.battle === '0' : false;

                document.querySelectorAll('[data-tower-challenge-form]').forEach(function(form) {
                    const required = towerStrategyRequiredStamina(form, option);
                    const input = form.querySelector('[data-tower-strategy-input]');
                    const text = form.querySelector('[data-tower-submit-text]');
                    const baseReadyText = form.dataset.readyText || '次の階へ進む';
                    const nextText = isScout
                        ? '様子を見る（探索力 -' + towerNumber(required) + '）'
                        : baseReadyText.replace(/探索力 -[\d,]+/, '探索力 -' + towerNumber(required));

                    form.dataset.requiredStamina = String(required);
                    if (input) {
                        input.value = key;
                    }
                    if (text && form.dataset.submitted !== '1' && form.dataset.towerGuarded !== '1') {
                        text.textContent = nextText;
                        text.dataset.originalText = nextText;
                    }
                });
            }

            function updateTowerStanceForms() {
                const option = selectedTowerStance();
                const key = option ? option.value || 'none' : 'none';

                document.querySelectorAll('[data-tower-stance-input]').forEach(function(input) {
                    input.value = key;
                });
                document.querySelectorAll('[data-tower-stance-option]').forEach(function(stanceOption) {
                    const label = stanceOption.closest('label')?.querySelector('[data-tower-stance-label]');
                    if (label) {
                        label.textContent = stanceOption.checked ? '選択中' : '選択';
                    }
                });
            }

            let pendingTowerChallengeForm = null;

            function towerStaminaModal() {
                return document.getElementById('tower-stamina-modal');
            }

            function openTowerStaminaModal(form) {
                const modal = towerStaminaModal();
                if (!modal) {
                    alert('探索力が足りません。探索力の小瓶や薬で回復してから進んでください。');
                    return;
                }

                pendingTowerChallengeForm = form;

                if (modal.parentElement !== document.body) {
                    document.body.appendChild(modal);
                }

                const current = Number(form.dataset.currentStamina || 0);
                const required = Math.max(1, Number(form.dataset.requiredStamina || 1));
                const currentText = modal.querySelector('[data-tower-modal-stamina-current]');
                const requiredText = modal.querySelector('[data-tower-modal-stamina-required]');
                if (currentText) {
                    currentText.textContent = towerNumber(current);
                }
                if (requiredText) {
                    requiredText.textContent = towerNumber(required);
                }

                modal.style.position = 'fixed';
                modal.style.inset = '0';
                modal.style.display = 'flex';
                modal.style.alignItems = 'center';
                modal.style.justifyContent = 'center';
                modal.style.minHeight = '100dvh';
                document.documentElement.style.overflow = 'hidden';
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }

            function closeTowerStaminaModal() {
                const modal = towerStaminaModal();
                if (!modal) return;

                modal.style.display = '';
                document.documentElement.style.overflow = '';
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }

            function updateTowerStaminaKiseki(value) {
                const kisekiText = towerStaminaModal()?.querySelector('[data-tower-modal-kiseki]');
                if (kisekiText && value !== null && value !== undefined) {
                    kisekiText.textContent = towerNumber(value);
                }
            }

            function submitTowerChallengeForm(form) {
                if (!form) return;

                if (isTowerChallengeGuarded()) {
                    applyTowerChallengeGuard();
                    return;
                }

                form.dataset.submitted = '1';
                setTowerFormLoading(form, true);
                form.submit();
            }

            function updateTowerStaminaItems(supportItems) {
                if (!Array.isArray(supportItems)) return;

                const quantities = {};
                supportItems.forEach(function(item) {
                    if (item && item.key) {
                        quantities[item.key] = Number(item.quantity || 0);
                    }
                });

                document.querySelectorAll('[data-tower-stamina-item]').forEach(function(button) {
                    const key = button.dataset.itemKey;
                    const quantity = quantities[key] ?? 0;
                    const count = button.querySelector('[data-tower-stamina-item-count]');

                    if (count) {
                        count.textContent = towerNumber(quantity);
                    }
                    button.dataset.quantity = String(quantity);
                    button.disabled = quantity <= 0;
                });
            }

            function updateTowerStamina(stamina) {
                if (!stamina) return;

                const current = Number(stamina.current || 0);
                const max = Number(stamina.max || 0);

                document.querySelectorAll('[data-tower-stamina-current]').forEach(function(el) {
                    el.textContent = towerNumber(current);
                });
                document.querySelectorAll('[data-tower-stamina-max]').forEach(function(el) {
                    el.textContent = towerNumber(max);
                });

                document.querySelectorAll('[data-tower-challenge-form]').forEach(function(form) {
                    const required = Math.max(1, Number(form.dataset.requiredStamina || 1));
                    form.dataset.currentStamina = String(current);

                    const button = form.querySelector('[data-tower-submit-button]');
                    const text = form.querySelector('[data-tower-submit-text]');
                    const readyText = form.dataset.readyText || '次の階へ進む';

                    if (text && form.dataset.towerGuarded !== '1') {
                        text.textContent = readyText;
                        text.dataset.originalText = text.textContent;
                    }
                });
                updateTowerStrategyForms();
            }

            function ensureTowerItemsList() {
                let section = document.querySelector('[data-tower-items-section]');
                if (section) {
                    return section.querySelector('[data-tower-items-list]');
                }

                const body = document.querySelector('[data-tower-status-body]');
                if (!body) {
                    return null;
                }

                section = document.createElement('div');
                section.className = 'mt-4 border-t border-slate-100 pt-3';
                section.dataset.towerItemsSection = '';
                section.innerHTML = '<div class="mb-2 text-[11px] font-black text-slate-400">塔内アイテム</div><div class="space-y-1.5" data-tower-items-list></div>';
                body.appendChild(section);

                return section.querySelector('[data-tower-items-list]');
            }

            function buildTowerItemForm(item) {
                if (item && item.usable === false) {
                    const row = document.createElement('div');
                    row.className = 'flex items-center justify-between gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2';
                    row.dataset.towerAutoItem = '';
                    row.dataset.towerItemKey = item.key;
                    const statusText = item.armed ? '待機中' : '次戦闘';
                    row.innerHTML = ''
                        + '<div class="min-w-0 text-sm">'
                        + '<span class="font-black text-slate-900" data-tower-item-name></span>'
                        + '<span class="ml-2 text-xs font-bold text-emerald-700"><span data-tower-item-description></span>・<span data-tower-item-count></span></span>'
                        + '</div>'
                        + '<span class="shrink-0 rounded-full bg-emerald-600 px-3 py-1 text-[11px] font-black text-white">' + statusText + '</span>';

                    return row;
                }

                const statusCard = document.querySelector('[data-tower-status-card]');
                const template = statusCard ? statusCard.dataset.useUrlTemplate : '';
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = template.replace('__PURCHASE_ID__', String(item.purchase_id));
                form.className = 'flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2';
                form.dataset.towerSubmitForm = '';
                form.dataset.towerItemForm = '';
                form.dataset.towerItemKey = item.key;
                form.dataset.useUrlTemplate = template;
                form.dataset.loadingText = '使用中...';
                form.innerHTML = ''
                    + '<input type="hidden" name="_token" value="' + csrfToken() + '">'
                    + '<div class="min-w-0 text-sm">'
                    + '<span class="font-black text-slate-900" data-tower-item-name></span>'
                    + '<span class="ml-2 text-xs font-bold text-slate-400"><span data-tower-item-description></span>・<span data-tower-item-count></span></span>'
                    + '</div>'
                    + '<button type="submit" class="shrink-0 rounded-lg bg-sky-600 px-4 py-1.5 text-xs font-black text-white shadow-sm transition hover:bg-sky-700 disabled:cursor-not-allowed disabled:opacity-70" data-tower-submit-button>'
                    + '<span class="inline-flex items-center gap-1.5">'
                    + '<svg class="hidden animate-spin" data-tower-submit-spinner width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path></svg>'
                    + '<span data-tower-submit-text>使う</span>'
                    + '</span>'
                    + '</button>';

                return form;
            }

            function updateTowerMerchantProducts(data) {
                if (!data.products) return;

                document.querySelectorAll('[data-tower-merchant-buy-form]').forEach(function(form) {
                    const key = form.dataset.towerProductKey;
                    const product = data.products[key];
                    if (!product || !product.purchased) return;

                    form.dataset.towerPurchased = '1';
                    const button = form.querySelector('[data-tower-submit-button]');
                    const text = form.querySelector('[data-tower-submit-text]');
                    if (button) {
                        button.disabled = true;
                        button.classList.remove('bg-amber-600', 'text-white', 'hover:bg-amber-700', 'scale-95', 'opacity-80');
                        button.classList.add('bg-slate-300', 'text-slate-600');
                    }
                    if (text) {
                        text.textContent = '購入済み';
                        text.dataset.originalText = '購入済み';
                    }
                });

                const continueText = document.querySelector('[data-tower-merchant-continue-text]');
                if (continueText && data.has_purchased_merchant_product && continueText.textContent.includes('何も買わずに進む')) {
                    continueText.textContent = continueText.textContent.replace('何も買わずに進む', '次の階へ進む');
                    continueText.dataset.originalText = continueText.textContent;
                    const continueForm = continueText.closest('[data-tower-challenge-form]');
                    if (continueForm) {
                        continueForm.dataset.readyText = continueText.textContent;
                        updateTowerStrategyForms();
                    }
                }
            }

            function updateTowerRecoveryStatus(data) {
                updateTowerMerchantProducts(data);

                if (data.hp) {
                    document.querySelectorAll('[data-tower-hp-summary]').forEach(function(el) {
                        el.textContent = towerNumber(data.hp.current) + ' / ' + towerNumber(data.hp.max);
                        el.classList.toggle('text-red-600', Number(data.hp.percent || 0) <= 20);
                        el.classList.toggle('text-slate-800', Number(data.hp.percent || 0) > 20);
                    });

                    const hpText = document.querySelector('[data-tower-hp-text]');
                    const hpBar = document.querySelector('[data-tower-hp-bar]');
                    if (hpText) {
                        hpText.textContent = towerNumber(data.hp.current) + ' / ' + towerNumber(data.hp.max) + ' ' + Number(data.hp.percent || 0) + '%';
                        hpText.classList.toggle('text-red-600', Number(data.hp.percent || 0) <= 20);
                        hpText.classList.toggle('text-emerald-700', Number(data.hp.percent || 0) > 20);
                    }
                    if (hpBar) {
                        hpBar.style.width = Number(data.hp.percent || 0) + '%';
                        hpBar.classList.toggle('bg-red-500', Number(data.hp.percent || 0) <= 20);
                        hpBar.classList.toggle('bg-emerald-500', Number(data.hp.percent || 0) > 20);
                    }
                }

                if (data.sp) {
                    document.querySelectorAll('[data-tower-sp-summary]').forEach(function(el) {
                        el.textContent = towerNumber(data.sp.current) + ' / ' + towerNumber(data.sp.max);
                    });

                    const spText = document.querySelector('[data-tower-sp-text]');
                    const spBar = document.querySelector('[data-tower-sp-bar]');
                    if (spText) {
                        spText.textContent = towerNumber(data.sp.current) + ' / ' + towerNumber(data.sp.max) + ' ' + Number(data.sp.percent || 0) + '%';
                    }
                    if (spBar) {
                        spBar.style.width = Number(data.sp.percent || 0) + '%';
                    }
                }

                const items = data.items || {};
                Object.keys(items).forEach(function(key) {
                    const item = items[key];
                    if (!item || Number(item.count || 0) <= 0) return;

                    let itemForm = document.querySelector('[data-tower-item-key="' + key + '"][data-tower-item-form], [data-tower-item-key="' + key + '"][data-tower-auto-item]');
                    if (!itemForm) {
                        const list = ensureTowerItemsList();
                        if (!list) return;

                        itemForm = buildTowerItemForm(item);
                        list.appendChild(itemForm);
                    } else if (
                        (item.usable === false && itemForm.hasAttribute('data-tower-item-form'))
                        || (item.usable !== false && itemForm.hasAttribute('data-tower-auto-item'))
                    ) {
                        const replacement = buildTowerItemForm(item);
                        itemForm.replaceWith(replacement);
                        itemForm = replacement;
                    }

                    const name = itemForm.querySelector('[data-tower-item-name]');
                    const description = itemForm.querySelector('[data-tower-item-description]');
                    const count = itemForm.querySelector('[data-tower-item-count]');
                    if (name && item.name) {
                        name.textContent = item.name;
                    }
                    if (description && item.description) {
                        description.textContent = item.description;
                    }
                    if (count) {
                        count.textContent = '残' + towerNumber(item.count);
                    }
                    if (item.purchase_id && itemForm.dataset.useUrlTemplate) {
                        itemForm.action = itemForm.dataset.useUrlTemplate.replace('__PURCHASE_ID__', String(item.purchase_id));
                    }
                });

                document.querySelectorAll('[data-tower-item-form], [data-tower-auto-item]').forEach(function(itemForm) {
                    const key = itemForm.dataset.towerItemKey;
                    const item = items[key] || null;
                    if (!item || Number(item.count || 0) <= 0) {
                        itemForm.remove();
                        return;
                    }
                });

                const itemSection = document.querySelector('[data-tower-items-section]');
                if (itemSection && !itemSection.querySelector('[data-tower-item-form], [data-tower-auto-item]')) {
                    itemSection.remove();
                }
            }

            async function useTowerStaminaItem(button) {
                const modal = towerStaminaModal();
                if (!modal || !button?.dataset.useUrl) return;

                const buttons = modal.querySelectorAll('button');
                buttons.forEach(function(modalButton) {
                    modalButton.disabled = true;
                });

                try {
                    const formData = new FormData();
                    const token = csrfToken();
                    if (token) {
                        formData.append('_token', token);
                    }

                    const response = await fetch(button.dataset.useUrl, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                    });
                    const data = await response.json();

                    if (!response.ok || data.success !== true) {
                        throw new Error(data.message || '探索力を回復できませんでした。');
                    }

                    updateTowerStamina(data.stamina);
                    updateTowerStaminaItems(data.support_items);
                    closeTowerStaminaModal();

                    if (pendingTowerChallengeForm) {
                        submitTowerChallengeForm(pendingTowerChallengeForm);
                    }
                } catch (error) {
                    alert(error.message || '探索力回復アイテムの使用に失敗しました。');
                } finally {
                    buttons.forEach(function(modalButton) {
                        const quantity = modalButton.hasAttribute('data-tower-stamina-item')
                            ? Number(modalButton.dataset.quantity || 0)
                            : 1;
                        modalButton.disabled = quantity <= 0;
                    });
                }
            }

            async function purchaseAndUseTowerStaminaItem(button) {
                const modal = towerStaminaModal();
                if (!modal || !button?.dataset.purchaseUrl || !button?.dataset.itemKey) return;

                const buttons = modal.querySelectorAll('button');
                buttons.forEach(function(modalButton) {
                    modalButton.disabled = true;
                });

                try {
                    const formData = new FormData();
                    const token = csrfToken();
                    if (token) {
                        formData.append('_token', token);
                    }
                    formData.append('item_key', button.dataset.itemKey);

                    const response = await fetch(button.dataset.purchaseUrl, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                    });
                    const data = await response.json();

                    if (!response.ok || data.success !== true) {
                        throw new Error(data.message || '探索力回復アイテムを購入できませんでした。');
                    }

                    updateTowerStaminaKiseki(data.kiseki);
                    updateTowerStaminaItems(data.support_items);

                    const itemButton = modal.querySelector('[data-tower-stamina-item][data-item-key="' + CSS.escape(button.dataset.itemKey) + '"]');
                    if (itemButton) {
                        await useTowerStaminaItem(itemButton);
                    }
                } catch (error) {
                    alert(error.message || '探索力回復アイテムの購入に失敗しました。');
                } finally {
                    buttons.forEach(function(modalButton) {
                        const quantity = modalButton.hasAttribute('data-tower-stamina-item')
                            ? Number(modalButton.dataset.quantity || 0)
                            : 1;
                        modalButton.disabled = quantity <= 0;
                    });
                }
            }

            document.addEventListener('submit', function(event) {
                const form = event.target.closest('[data-tower-submit-form]');
                if (!form) return;

                if (form.dataset.submitted === '1') {
                    event.preventDefault();
                    return;
                }

                if (form.matches('[data-tower-challenge-form]')) {
                    event.preventDefault();
                    if (isTowerChallengeGuarded()) {
                        applyTowerChallengeGuard();
                        return;
                    }

                    const current = Number(form.dataset.currentStamina || 0);
                    const required = Math.max(1, Number(form.dataset.requiredStamina || 1));
                    if (current < required) {
                        openTowerStaminaModal(form);
                        return;
                    }

                    submitTowerChallengeForm(form);
                    return;
                }

                if (form.matches('[data-tower-item-form], [data-tower-merchant-buy-form]')) {
                    event.preventDefault();
                    form.dataset.submitted = '1';
                    setTowerFormLoading(form, true);
                    const errorMessage = form.matches('[data-tower-merchant-buy-form]')
                        ? '商品を購入できませんでした。'
                        : 'アイテムを使用できませんでした。';

                    fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                    })
                        .then(function(response) {
                            return response.json()
                                .catch(function() {
                                    return {};
                                })
                                .then(function(data) {
                                    if (!response.ok || !data.ok) {
                                        throw new Error(data.message || errorMessage);
                                    }
                                    return data;
                                });
                        })
                        .then(updateTowerRecoveryStatus)
                        .catch(function(error) {
                            alert(error.message || errorMessage);
                        })
                        .finally(function() {
                            if (document.body.contains(form)) {
                                form.dataset.submitted = '0';
                                setTowerFormLoading(form, false);
                                if (form.dataset.towerPurchased === '1') {
                                    const button = form.querySelector('[data-tower-submit-button]');
                                    if (button) {
                                        button.disabled = true;
                                    }
                                }
                            }
                        });

                    return;
                }

                form.dataset.submitted = '1';
                setTowerFormLoading(form, true);
            });

            document.addEventListener('change', function(event) {
                if (event.target.closest('[data-tower-strategy-option]')) {
                    updateTowerStrategyForms();
                }
                if (event.target.closest('[data-tower-stance-option]')) {
                    updateTowerStanceForms();
                }
            });

            updateTowerStanceForms();

            document.addEventListener('click', function(event) {
                const closeButton = event.target.closest('[data-tower-stamina-modal-close]');
                if (closeButton) {
                    closeTowerStaminaModal();
                    return;
                }

                const staminaItemButton = event.target.closest('[data-tower-stamina-item]');
                if (staminaItemButton) {
                    event.preventDefault();
                    useTowerStaminaItem(staminaItemButton);
                    return;
                }

                const staminaBuyButton = event.target.closest('[data-tower-stamina-buy]');
                if (staminaBuyButton) {
                    event.preventDefault();
                    purchaseAndUseTowerStaminaItem(staminaBuyButton);
                }
            });

            updateTowerStrategyForms();
            startTowerChallengeGuard();
        </script>
    @endonce
</x-layouts.facility>
