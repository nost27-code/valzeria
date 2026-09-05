<x-layouts.facility
    title="国家対抗レイド"
    subtitle="全国家共闘イベント"
    headerIcon="🐉"
    :exit-url="route('home')"
    exitLabel="国家画面へ戻る"
>
    @php
        $isOfficial = (bool) ($screen['official'] ?? false);
        $strategySelectionEnabled = ! empty($screen['strategies']);
        $sortieStatus = $sortieStatus ?? null;
        $canChallenge = ! $isOfficial || (($screen['can_challenge'] ?? false) && ! in_array($sortieStatus, ['started', 'aborted'], true));
        $currentEncounter = $screen['encounter'];
        $currentForm = $currentEncounter['form'];
        $bossMaxHp = max(1, (int) $currentEncounter['max_hp']);
        $bossCurrentHp = max(0, min($bossMaxHp, (int) $currentEncounter['current_hp']));
        $bossHpPercent = round(($bossCurrentHp / $bossMaxHp) * 100, 1);
        $completedStages = $screen['completed_stages'] ?? max(0, (int) $currentEncounter['stage'] - 1);
        $nextProgressRewardStage = (int) $currentEncounter['stage'] <= 10 ? 10 : 20;
        $remainingStagesToReward = max(1, $nextProgressRewardStage - $completedStages);
        $survived = ($lastResult['outcome'] ?? null) === 'survived';
        $resultCoordination = $lastResult['coordination'] ?? [
            'eligible' => false,
            'nation_name' => '無所属',
            'window_minutes' => 180,
            'unique_count' => 0,
            'bonus_rate' => 0,
            'left_supporters' => [],
            'right_supporters' => [],
            'hidden_supporter_count' => 0,
        ];
        $coordinationPercent = (int) round(((float) ($resultCoordination['bonus_rate'] ?? 0)) * 100);
        $battleLog = is_array($lastResult['battle_log'] ?? null) ? $lastResult['battle_log'] : null;
        $sortieStaminaCost = (int) ($screen['sortie_stamina_cost'] ?? 10);
        $stamina = is_array($screen['exploration_stamina'] ?? null) ? $screen['exploration_stamina'] : ['current' => 0, 'max' => 0];
        $resultStamina = is_array($lastResult['exploration_stamina'] ?? null) ? $lastResult['exploration_stamina'] : $stamina;
    @endphp

    <div class="mx-auto max-w-5xl space-y-4 pb-8" data-nation-raid-trial>
        @if($isOfficial)
            @include('nation-raid.partials.navigation', ['eventId' => $screen['event_id'], 'active' => 'show'])
            <a href="{{ route('nation-raid.history') }}" class="inline-flex min-h-11 items-center text-sm font-bold text-blue-700 underline">過去の戦果・未受取報酬</a>
        @endif
        @if(! $isOfficial)
        <aside class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] font-bold leading-relaxed text-amber-950" data-nation-raid-local-safety-notice>
            <span class="shrink-0 rounded bg-amber-200 px-1.5 py-0.5 text-[10px] font-black">ローカル確認</span>
            <p>本開催と同じ情報配置を確認する試遊画面です。探索力{{ number_format($sortieStaminaCost) }}は実際に消費します。レイド進行・出撃回数・報酬・ランキングは保存されません。</p>
        </aside>
        @else
            <p class="px-1 text-xs font-bold text-slate-600" data-nation-raid-official-notice>出撃回数・戦闘記録・ボスへのダメージが保存されます。報酬はイベント終了後の戦果確定をお待ちください。</p>
        @endif

        <section class="overflow-hidden rounded-xl border border-slate-800 bg-slate-950 text-white shadow-lg" data-nation-raid-event-status>
            <div class="grid items-center gap-2 px-4 pb-3 pt-4 sm:grid-cols-[minmax(0,1fr)_17rem] sm:gap-5 sm:px-6 sm:pb-5 sm:pt-5">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2 text-[10px] font-black tracking-wider">
                        <span class="text-amber-300">RAID BOSS</span>
                        <span class="rounded-full border border-red-300/30 bg-red-300/10 px-2 py-0.5 text-red-100">種族 {{ $screen['boss_species_label'] }}</span>
                        <span class="rounded-full border border-sky-300/30 bg-sky-300/10 px-2 py-0.5 text-sky-100" data-nation-raid-server-context>{{ $currentForm['ordinal'] }}《{{ $currentForm['name'] }}》</span>
                    </div>
                    <h1 class="mt-2 text-xl font-black sm:text-2xl">{{ $screen['boss_name'] }}</h1>
                    <p class="mt-1 text-xs font-black text-amber-200 sm:text-sm">
                        第{{ number_format((int) $currentEncounter['stage']) }} / {{ count($screen['stages']) }}再臨《{{ $currentEncounter['stage_name'] }}》
                    </p>
                    <p class="mt-2 text-[11px] font-bold leading-relaxed text-slate-300 sm:text-xs">
                        全国家の冒険者と力を合わせ、再臨を重ねる黒天竜を撃退せよ。
                    </p>
                    <p class="mt-1 text-[10px] font-bold text-sky-200">本日の対抗対象：{{ $currentEncounter['dominant_lineage_label'] }}</p>

                    <div class="mt-4" data-nation-raid-boss-hp>
                        <div class="flex items-end justify-between gap-3">
                            <span class="text-[10px] font-black tracking-wider text-slate-300">現在個体HP</span>
                            <span class="text-base font-black tabular-nums text-white sm:text-lg">
                                {{ number_format($bossCurrentHp) }} <span class="text-xs text-slate-400">/ {{ number_format($bossMaxHp) }}</span>
                            </span>
                        </div>
                        <div class="mt-1.5 h-3 overflow-hidden rounded-full border border-white/10 bg-slate-800" role="progressbar" aria-label="ヴァルグレイドの現在HP" aria-valuemin="0" aria-valuemax="{{ $bossMaxHp }}" aria-valuenow="{{ $bossCurrentHp }}">
                            <div class="h-full rounded-full bg-gradient-to-r from-rose-700 via-red-500 to-amber-400" style="width: {{ $bossHpPercent }}%"></div>
                        </div>
                        <div class="mt-2 grid grid-cols-10 gap-1" aria-label="全20再臨の進行状況" data-nation-raid-stage-track>
                            @foreach($screen['stages'] as $stage)
                                @php
                                    $stageNo = (int) $stage['stage'];
                                @endphp
                                <span
                                    class="h-1.5 rounded-full {{ $stageNo < (int) $currentEncounter['stage'] ? 'bg-emerald-400' : ($stageNo === (int) $currentEncounter['stage'] ? 'bg-amber-300' : 'bg-slate-700') }}"
                                    title="第{{ $stageNo }}再臨"
                                ></span>
                            @endforeach
                        </div>
                    </div>
                </div>
                <img
                    src="{{ asset($currentForm['image_path']) }}"
                    alt="{{ $screen['boss_name'] }} {{ $currentForm['ordinal'] }}《{{ $currentForm['name'] }}》"
                    class="mx-auto aspect-square w-48 object-contain drop-shadow-[0_18px_24px_rgba(0,0,0,0.65)] sm:w-64"
                    width="400"
                    height="400"
                    data-nation-raid-hero-boss-art
                >
            </div>

            <div class="grid grid-cols-2 border-t border-white/10 bg-white/[0.04] text-center sm:grid-cols-4" data-nation-raid-event-summary>
                <div class="border-b border-r border-white/10 px-2 py-3 sm:border-b-0">
                    <div class="text-[9px] font-black text-slate-400">本編進行</div>
                    <div class="mt-1 text-sm font-black tabular-nums">{{ $completedStages }} / {{ count($screen['stages']) }}再臨撃破</div>
                </div>
                <div class="border-b border-white/10 px-2 py-3 sm:border-b-0 sm:border-r">
                    <div class="text-[9px] font-black text-slate-400">{{ $isOfficial ? '出撃受付' : '次の進行報酬' }}</div>
                    <div class="mt-1 text-sm font-black text-amber-200">{{ $isOfficial ? ($canChallenge ? '受付中' : '受付停止中') : 'あと'.$remainingStagesToReward.'再臨' }}</div>
                </div>
                <div class="border-r border-white/10 px-2 py-3">
                    <div class="text-[9px] font-black text-slate-400">出撃回数</div>
                    <div class="mt-1 text-sm font-black">回数制限なし</div>
                    @if($isOfficial)<div class="mt-0.5 text-[9px] text-slate-300">本日 {{ number_format($screen['used_sorties']) }}回出撃</div>@endif
                    <div class="mt-0.5 text-[9px] font-bold text-amber-200">現在の探索力 {{ number_format((int) $stamina['current']) }} / {{ number_format((int) $stamina['max']) }}・1回{{ number_format($sortieStaminaCost) }}</div>
                </div>
                <div class="px-2 py-3">
                    <div class="text-[9px] font-black text-slate-400">残り時間</div>
                    <div class="mt-1 text-sm font-black text-slate-300">{{ $screen['ends_label'] ?? '本開催時に表示' }}</div>
                </div>
            </div>
        </section>

        @if(session('error'))
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-800">
                {{ session('error') }}
            </div>
        @endif

        @if($isOfficial && ($screen['unavailable_reason'] ?? null))
            <p class="rounded-lg bg-amber-50 p-4 text-sm font-bold text-amber-900">{{ $screen['unavailable_reason'] }}</p>
        @endif
        @if(in_array($sortieStatus, ['started', 'aborted'], true))
            <p class="rounded-lg bg-amber-50 p-4 text-sm font-bold text-amber-900">出撃結果を確認中です。画面を再読み込みしてください。確定できない場合は探索力と出撃回数が返却されます。</p>
        @elseif($sortieStatus === 'refunded')
            <p class="rounded-lg bg-sky-50 p-4 text-sm font-bold text-sky-900">出撃を確定できなかったため、探索力と出撃回数を返却しました。</p>
        @endif

        @if($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-800">
                {{ $errors->first() }}
            </div>
        @endif

        @if($lastResult)
            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" data-nation-raid-trial-result>
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-3" data-nation-raid-result-header>
                    <div>
                        <div class="text-[10px] font-black tracking-widest {{ $survived ? 'text-emerald-700' : 'text-red-700' }}">
                            第{{ number_format((int) $lastResult['stage']) }} / {{ count($screen['stages']) }}再臨《{{ $lastResult['stage_name'] }}》
                        </div>
                        <h2 class="mt-0.5 text-base font-black text-slate-950">
                            {{ $lastResult['form']['ordinal'] }}《{{ $lastResult['form']['name'] }}》への出撃結果
                        </h2>
                        <p class="mt-1 text-[11px] font-bold text-slate-500">
                            @if($strategySelectionEnabled)作戦：{{ $lastResult['strategy_label'] }} ／ @endif対抗対象：{{ $lastResult['dominant_lineage_label'] }}
                        </p>
                    </div>
                </div>

                <div class="relative isolate overflow-hidden border-b border-slate-200 bg-gradient-to-b from-white via-slate-50 to-slate-100 px-3 py-4 text-slate-900 sm:px-5" data-nation-raid-battle-scene>
                    <div class="pointer-events-none absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-slate-200/70 to-transparent" aria-hidden="true"></div>
                    <div class="relative z-10 grid min-w-0 grid-cols-[minmax(0,1fr)_9rem] items-end gap-2 sm:grid-cols-[minmax(0,1fr)_16rem] sm:gap-5">
                        <div class="min-w-0">
                            <div class="mb-2 text-center text-[10px] font-black tracking-widest text-sky-800">
                                @if($resultCoordination['eligible'])
                                    {{ $resultCoordination['nation_name'] }}・国家連携 {{ number_format((int) $resultCoordination['unique_count']) }}人
                                @else
                                    無所属・国家連携なし
                                @endif
                            </div>
                            <div class="grid min-w-0 grid-cols-[1fr_auto_1fr] items-end gap-1">
                                <div class="grid min-w-0 grid-cols-1 content-end justify-items-end gap-1 sm:grid-cols-2" data-nation-raid-supporters-left aria-label="左側で共闘する国民">
                                    @foreach(($resultCoordination['left_supporters'] ?? []) as $supporter)
                                        <figure class="w-9 min-w-0 sm:w-16" title="{{ $supporter['name'] }}">
                                            <div data-nation-raid-supporter-art class="aspect-square">
                                                <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($supporter['battle_image_path']) }}" alt="{{ $supporter['name'] }}" class="h-full w-full -scale-x-100 object-contain drop-shadow-[0_5px_7px_rgba(15,23,42,0.2)]" data-nation-raid-supporter-pose="battle">
                                            </div>
                                            <figcaption class="mt-0.5 truncate text-center text-[8px] font-black text-slate-600 sm:text-[9px]">{{ $supporter['name'] }}</figcaption>
                                        </figure>
                                    @endforeach
                                </div>

                                <figure class="w-16 shrink-0 sm:w-28" data-nation-raid-current-player>
                                    <div class="aspect-square" data-nation-raid-current-player-art>
                                        <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($lastResult['character']['battle_image_path'] ?? null) }}" alt="{{ $lastResult['character']['name'] }}" class="h-full w-full -scale-x-100 object-contain drop-shadow-[0_8px_12px_rgba(15,23,42,0.2)]">
                                    </div>
                                    <figcaption class="mt-1 truncate text-center text-[10px] font-black text-slate-900 sm:text-xs">{{ $lastResult['character']['name'] }}</figcaption>
                                </figure>

                                <div class="grid min-w-0 grid-cols-1 content-end justify-items-start gap-1 sm:grid-cols-2" data-nation-raid-supporters-right aria-label="右側で共闘する国民">
                                    @foreach(($resultCoordination['right_supporters'] ?? []) as $supporter)
                                        <figure class="w-9 min-w-0 sm:w-16" title="{{ $supporter['name'] }}">
                                            <div data-nation-raid-supporter-art class="aspect-square">
                                                <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($supporter['battle_image_path']) }}" alt="{{ $supporter['name'] }}" class="h-full w-full -scale-x-100 object-contain drop-shadow-[0_5px_7px_rgba(15,23,42,0.2)]" data-nation-raid-supporter-pose="battle">
                                            </div>
                                            <figcaption class="mt-0.5 truncate text-center text-[8px] font-black text-slate-600 sm:text-[9px]">{{ $supporter['name'] }}</figcaption>
                                        </figure>
                                    @endforeach
                                </div>
                            </div>
                            <div class="mt-2 text-center text-[10px] font-bold text-slate-600">
                                @if($resultCoordination['eligible'])
                                    3時間以内のユニーク国民 {{ number_format((int) $resultCoordination['unique_count']) }}人
                                    @if($coordinationPercent > 0)
                                        ・連携ダメージ +{{ $coordinationPercent }}%
                                    @else
                                        ・次の国民が続くと +3%
                                    @endif
                                    @if(($resultCoordination['hidden_supporter_count'] ?? 0) > 0)
                                        ・ほか{{ number_format((int) $resultCoordination['hidden_supporter_count']) }}人
                                    @endif
                                @else
                                    国家へ所属すると、国民の連続出撃で連携が発生します。
                                @endif
                            </div>
                        </div>

                        <figure class="min-w-0 text-center">
                            <img
                                src="{{ asset($lastResult['form']['image_path']) }}"
                                alt="{{ $lastResult['boss_name'] }} {{ $lastResult['form']['ordinal'] }}《{{ $lastResult['form']['name'] }}》"
                                class="mx-auto aspect-square w-full max-w-36 object-contain drop-shadow-[0_14px_18px_rgba(15,23,42,0.2)] sm:max-w-64"
                                width="400"
                                height="400"
                                data-nation-raid-boss-art
                            >
                            <figcaption class="mt-1 text-[10px] font-black text-rose-700 sm:text-xs">{{ $lastResult['boss_name'] }}</figcaption>
                        </figure>
                    </div>

                    <div class="relative z-10 mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white/95 shadow-sm" data-nation-raid-player-status>
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-3 py-2">
                            <div>
                                <span class="text-xs font-black text-slate-950">{{ $lastResult['character']['name'] }}</span>
                                <span class="ml-1 text-[10px] font-bold text-slate-500">Lv{{ number_format((int) $lastResult['character']['level']) }}・{{ $lastResult['character']['job_name'] }}</span>
                            </div>
                            <div class="flex gap-3 text-[10px] font-black tabular-nums">
                                <span class="text-emerald-700">HP {{ number_format((int) $lastResult['player_remaining_hp']) }} / {{ number_format((int) $lastResult['player_max_hp']) }}</span>
                                <span class="text-blue-700">SP {{ number_format((int) $lastResult['player_remaining_sp']) }} / {{ number_format((int) $lastResult['player_max_sp']) }}</span>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 divide-x divide-y divide-slate-100 border-b border-slate-100 text-center sm:grid-cols-6 sm:divide-y-0">
                            @foreach([
                                '攻撃' => 'attack', '防御' => 'defense', '魔力' => 'magic',
                                '精神' => 'spirit', '敏捷' => 'agility', '運' => 'luck',
                            ] as $label => $key)
                                <div class="px-2 py-2">
                                    <div class="text-[9px] font-black text-slate-400">{{ $label }}</div>
                                    <div class="mt-0.5 text-xs font-black tabular-nums text-slate-900">{{ number_format((int) $lastResult['abilities'][$key]) }}</div>
                                </div>
                            @endforeach
                        </div>
                        <div class="grid gap-px bg-slate-100 sm:grid-cols-3" data-nation-raid-player-equipment>
                            @forelse($lastResult['equipment'] as $equipment)
                                @php
                                    $rankBadgeClass = match ($equipment['rank'] ?? '') {
                                        'S', 'SS', 'SSS', 'EPIC' => 'border-amber-500 bg-amber-400 text-white',
                                        'A' => 'border-violet-500 bg-violet-500 text-white',
                                        'B' => 'border-sky-500 bg-sky-500 text-white',
                                        'C' => 'border-emerald-500 bg-emerald-500 text-white',
                                        default => 'border-slate-400 bg-slate-400 text-white',
                                    };
                                @endphp
                                <div
                                    class="min-w-0 border-l-2 bg-white px-3 py-2 {{ $equipment['is_killer_active'] ? 'border-emerald-400 text-emerald-700' : ($equipment['is_resist_active'] ? 'border-sky-400 text-sky-700' : 'border-transparent text-slate-700') }}"
                                    data-nation-raid-killer-active="{{ $equipment['is_killer_active'] ? 'true' : 'false' }}"
                                    data-nation-raid-resistance-active="{{ $equipment['is_resist_active'] ? 'true' : 'false' }}"
                                >
                                    <div class="flex items-center gap-1.5 text-[9px] font-black">
                                        <span>{{ $equipment['slot'] }}</span>
                                        @if($equipment['rank'])
                                            <span class="inline-flex min-w-5 items-center justify-center rounded border px-1 py-0.5 text-[8px] {{ $rankBadgeClass }}">{{ $equipment['rank'] }}</span>
                                        @endif
                                    </div>
                                    <div class="mt-0.5 flex min-w-0 items-center gap-1.5">
                                        @if($equipment['icon'])
                                            <img src="{{ asset($equipment['icon']) }}" alt="" class="h-5 w-5 shrink-0 object-contain">
                                        @endif
                                        <div class="truncate text-[11px] font-black" title="{{ $equipment['name'] }}">{{ $equipment['name'] }}</div>
                                    </div>
                                    @if($equipment['trait_label'])
                                        <div class="mt-0.5 text-[9px] font-black">{{ $equipment['trait_label'] }}</div>
                                    @endif
                                </div>
                            @empty
                                <div class="bg-white px-3 py-2 text-[10px] font-bold text-slate-400 sm:col-span-3">装備品なし</div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="space-y-4 px-4 py-4">
                    @if($resultCoordination['eligible'])
                        <div class="border-l-2 border-sky-400 pl-3 text-xs font-bold leading-relaxed text-slate-600">
                            @if($coordinationPercent > 0)
                                {{ $resultCoordination['nation_name'] }}の力が重なった！ 個人ダメージ確定後に国家連携 +{{ $coordinationPercent }}%（{{ number_format((int) ($lastResult['coordination_damage'] ?? 0)) }}）を別枠で加算しました。
                            @else
                                {{ $resultCoordination['nation_name'] }}の先陣を切った！ 3時間以内に別の国民が続くと、次の出撃から国家連携が発生します。
                            @endif
                        </div>
                    @endif
                    <div>
                        <div class="flex flex-wrap items-end justify-between gap-2">
                            <h3 class="text-sm font-black text-slate-950">戦闘ログ</h3>
                            <span class="text-[10px] font-bold text-slate-400">上から順に戦闘が進行します</span>
                        </div>

                        <style>
                            .nation-raid-battle-log .battle-log-enemy-action,
                            .nation-raid-battle-log .battle-log-telegraph,
                            .nation-raid-battle-log .battle-log-condition,
                            .nation-raid-battle-log .battle-log-percent {
                                display: inline-block;
                                margin: .12rem 0;
                                padding: .08rem .42rem;
                                border-radius: .35rem;
                                font-weight: 800;
                                line-height: 1.65;
                            }
                            .nation-raid-battle-log .battle-log-enemy-action { color: #be123c; background: #fff1f2; border-left: 3px solid #fb7185; }
                            .nation-raid-battle-log .battle-log-telegraph { color: #92400e; background: #fffbeb; border-left: 3px solid #f59e0b; }
                            .nation-raid-battle-log .battle-log-condition { color: #6d28d9; background: #f5f3ff; border-left: 3px solid #8b5cf6; }
                            .nation-raid-battle-log .battle-log-percent { color: #9f1239; background: #fff1f2; border-left: 3px solid #e11d48; }
                            .nation-raid-battle-log .battle-log-special-title { color: #4338ca; font-weight: 900; }
                            .nation-raid-battle-log .battle-log-special-phrase { color: #312e81; font-weight: 900; }
                            .nation-raid-battle-log .battle-log-special-description { color: #3730a3; font-weight: 900; }
                        </style>

                        @if($battleLog)
                            <div class="nation-raid-battle-log mt-2 border-y border-slate-200 px-1 py-3 font-mono text-xs font-semibold leading-loose text-slate-700 sm:px-2 sm:text-sm" data-nation-raid-full-battle-log>
                                @foreach($battleLog['opening_logs'] as $log)
                                    <div class="mb-1">{!! $log !!}</div>
                                @endforeach

                                @foreach($battleLog['turns'] as $turn)
                                    <section class="mt-3 border-t border-slate-200 pt-2" data-nation-raid-log-turn="{{ $turn['turn'] }}">
                                        <div class="mb-1 font-black tracking-wide text-slate-500">--- ターン {{ $turn['turn'] }} ---</div>

                                        @foreach($turn['player_logs'] as $log)
                                            <div>{!! $log !!}</div>
                                        @endforeach
                                        @if($turn['player_action_damage'] > 0)
                                            <div>
                                                {{ $lastResult['boss_name'] }} に
                                                <span class="text-lg font-extrabold tabular-nums text-red-600">{{ number_format((int) $turn['player_action_damage']) }}</span>
                                                の有効ダメージ！
                                            </div>
                                        @endif

                                        @if($turn['counterplay_message'])
                                            <div class="font-bold text-indigo-700">【予告対抗】{{ $turn['counterplay_message'] }}</div>
                                        @endif
                                        @if($turn['player_self_damage'] > 0)
                                            <div class="font-bold text-purple-700">
                                                黒鏡の反射により、{{ $lastResult['character']['name'] }} は
                                                <span class="font-extrabold tabular-nums">{{ number_format((int) $turn['player_self_damage']) }}</span>
                                                のダメージを受けた！
                                            </div>
                                        @endif

                                        @if($turn['enemy_action_kind'] === 'delayed')
                                            <div><span class="battle-log-telegraph">ヴァルグレイドの予告行動は遅延した！</span></div>
                                        @elseif($turn['enemy_action_kind'] === 'observation')
                                            <div><span class="battle-log-telegraph">【系譜観測】{{ $turn['note'] }}</span></div>
                                        @else
                                            <div>
                                                <span class="battle-log-enemy-action">
                                                    {{ $turn['enemy_action_kind'] === 'ultimate' ? '【大技】' : '【敵技】' }}
                                                    {{ $lastResult['boss_name'] }} の《{{ $turn['enemy_action_name'] }}》！
                                                </span>
                                            </div>

                                            @if($turn['enemy_critical'])
                                                <div class="font-extrabold text-orange-600">【痛恨の一撃！】</div>
                                            @endif
                                            @if($turn['enemy_damage'] > 0)
                                                <div>
                                                    {{ $lastResult['character']['name'] }} は
                                                    <span class="text-lg font-extrabold tabular-nums text-rose-700">{{ number_format((int) $turn['enemy_damage']) }}</span>
                                                    のダメージを受けた！
                                                    @if($turn['enemy_total_hits'] > 1)
                                                        <span class="text-[10px] font-bold text-slate-400">（{{ $turn['enemy_hit_count'] }}/{{ $turn['enemy_total_hits'] }} Hit）</span>
                                                    @endif
                                                </div>
                                            @elseif($turn['enemy_evade_count'] > 0)
                                                <div>{{ $lastResult['character']['name'] }} は攻撃をかわした！</div>
                                            @elseif($turn['enemy_miss_count'] > 0)
                                                <div>ヴァルグレイドの攻撃は空を切った！</div>
                                            @endif

                                            @if($turn['damage_cap_hit'])
                                                <div class="text-[10px] font-bold text-amber-700 sm:text-xs">
                                                    出撃時の被ダメージ上限が働いた
                                                    （{{ number_format((int) $turn['damage_before_cap']) }} → {{ number_format((int) $turn['damage_after_cap']) }}）
                                                </div>
                                            @endif
                                            @foreach($turn['defense_messages'] as $message)
                                                <div class="font-bold text-sky-700">【防御】{{ $message }}</div>
                                            @endforeach
                                            @foreach($turn['effect_messages'] as $message)
                                                <div><span class="battle-log-condition">{{ $message }}</span></div>
                                            @endforeach
                                        @endif

                                        @if($turn['counter_damage'] > 0)
                                            <div class="font-bold text-cyan-700">
                                                【反撃】{{ $lastResult['boss_name'] }} に
                                                <span class="font-extrabold tabular-nums">{{ number_format((int) $turn['counter_damage']) }}</span>
                                                ダメージ！
                                            </div>
                                        @endif
                                        @if($turn['eclipse_backlash_damage'] > 0)
                                            <div class="font-bold text-violet-700">
                                                【暗黒剣】刻印が炸裂し、{{ $lastResult['boss_name'] }} に
                                                <span class="font-extrabold tabular-nums">{{ number_format((int) $turn['eclipse_backlash_damage']) }}</span>
                                                ダメージ！
                                            </div>
                                        @endif
                                        @if($turn['note'] && !in_array($turn['enemy_action_kind'], ['observation', 'delayed'], true))
                                            <div class="text-slate-500">{{ $turn['note'] }}</div>
                                        @endif
                                        @if($turn['telegraph'])
                                            <div><span class="battle-log-telegraph">{{ $turn['telegraph']['message'] }}</span></div>
                                        @endif

                                        <div class="mt-1 text-[10px] font-bold tabular-nums text-slate-400 sm:text-xs">
                                            {{ $lastResult['character']['name'] }} HP {{ number_format((int) $turn['player_hp_after']) }} / {{ number_format((int) $lastResult['player_max_hp']) }}
                                            ・SP {{ number_format((int) $turn['player_sp_after']) }}
                                            ・黒天竜SP {{ number_format((int) $turn['boss_sp_after']) }} / {{ \App\Services\Nation\Raid\NationRaidRules::BOSS_MAX_SP }}
                                        </div>
                                    </section>
                                @endforeach

                                <div class="mt-4 border-t border-slate-300 pt-3 text-base font-extrabold text-slate-950">{{ $battleLog['outcome_message'] }}</div>
                                <div class="mt-1 font-bold text-blue-800">
                                    【出撃結果】{{ $lastResult['boss_name'] }}へ合計 {{ number_format((int) $lastResult['calculated_boss_damage']) }} ダメージ！
                                </div>
                                @if(($lastResult['coordination_damage'] ?? 0) > 0)
                                    <div class="font-bold text-sky-700">
                                        【国家連携】{{ number_format((int) $resultCoordination['unique_count']) }}人の共闘で、さらに {{ number_format((int) $lastResult['coordination_damage']) }} ダメージ！
                                    </div>
                                @endif
                                <div class="font-extrabold text-slate-900">
                                    【レイドボスへのダメージ】合計 {{ number_format((int) ($lastResult['shared_hp_damage'] ?? $lastResult['calculated_boss_damage'])) }} ダメージ
                                </div>
                                @unless($isOfficial)<div class="mt-1 text-[10px] font-bold text-slate-400">※ローカル試遊のため、実際のレイドボスHPには反映していません。</div>@endunless
                            </div>
                        @else
                            <div class="mt-2 border-y border-slate-100 py-4 text-xs font-bold text-slate-500">
                                戦闘ログの表示形式を更新しました。もう一度挑戦すると、本番想定のログを確認できます。
                            </div>
                        @endif
                    </div>

                    <div class="border-t border-slate-200 pt-4" data-nation-raid-result-summary>
                        <div class="flex flex-wrap items-end justify-between gap-2">
                            <div>
                                <div class="text-[10px] font-black tracking-widest {{ $survived ? 'text-emerald-700' : 'text-red-700' }}">出撃結果</div>
                                <h3 class="mt-0.5 text-base font-black text-slate-950" data-nation-raid-outcome-label>{{ $lastResult['outcome_label'] }}</h3>
                            </div>
                            <div class="text-[10px] font-bold text-slate-500">@if($strategySelectionEnabled)作戦：{{ $lastResult['strategy_label'] }} ／ @endif{{ $lastResult['form']['ordinal'] }}《{{ $lastResult['form']['name'] }}》</div>
                        </div>

                        <div class="mt-3 grid grid-cols-2 divide-x divide-y divide-slate-100 overflow-hidden rounded-lg border border-slate-100 text-center sm:grid-cols-3 lg:grid-cols-6 lg:divide-y-0">
                            <div class="px-2 py-3">
                                <div class="text-[10px] font-black text-slate-500">今回ダメージ</div>
                                <div class="mt-1 text-base font-black tabular-nums text-slate-950">{{ number_format((int) $lastResult['calculated_boss_damage']) }}</div>
                            </div>
                            <div class="px-2 py-3">
                                <div class="text-[10px] font-black text-slate-500">国家連携</div>
                                <div class="mt-1 text-base font-black tabular-nums text-sky-700">+{{ number_format((int) ($lastResult['coordination_damage'] ?? 0)) }}</div>
                            </div>
                            <div class="px-2 py-3">
                                <div class="text-[10px] font-black text-slate-500">レイドボスへのダメージ</div>
                                <div class="mt-1 text-base font-black tabular-nums text-slate-950">{{ number_format((int) ($lastResult['shared_hp_damage'] ?? $lastResult['calculated_boss_damage'])) }}</div>
                            </div>
                            <div class="px-2 py-3">
                                <div class="text-[10px] font-black text-slate-500">1行動最大</div>
                                <div class="mt-1 text-base font-black tabular-nums text-slate-950">{{ number_format((int) $lastResult['max_one_action_damage']) }}</div>
                            </div>
                            <div class="px-2 py-3">
                                <div class="text-[10px] font-black text-slate-500">ボスの残りHP</div>
                                <div class="mt-1 text-base font-black tabular-nums text-slate-950">{{ number_format((int) $lastResult['boss_remaining_hp']) }} / {{ number_format((int) $lastResult['form']['max_hp']) }}</div>
                            </div>
                            <div class="px-2 py-3">
                                <div class="text-[10px] font-black text-slate-500">到達ターン</div>
                                <div class="mt-1 text-base font-black tabular-nums text-slate-950">{{ number_format((int) $lastResult['turns_completed']) }} / {{ $screen['max_turns'] }}</div>
                            </div>
                        </div>

                        <div class="mt-3 text-xs font-black text-amber-700">
                            探索力 -{{ number_format((int) $lastResult['exploration_stamina_cost']) }}（残り {{ number_format((int) $resultStamina['current']) }} / {{ number_format((int) $resultStamina['max']) }}）
                        </div>
                        <p class="mt-2 border-l-2 border-amber-300 pl-3 text-[10px] font-bold leading-relaxed text-slate-500">
                            @if($isOfficial)
                                ボスへのダメージと出撃記録を保存しました。ボスの残りHPは、この出撃を確定した時点の値です。
                            @else
                                ローカル確認のため、表示したダメージや報酬進行は実際のレイドボスHP・戦績へ保存されません。探索力のみ実際に消費しています。
                            @endif
                        </p>
                    </div>

                    @unless($isOfficial)
                    <details class="text-[10px] font-bold leading-relaxed text-slate-400" data-nation-raid-local-debug>
                        <summary class="cursor-pointer select-none">ローカル検証情報</summary>
                        <div class="mt-1 pl-3">
                            出撃内仮想HP残量 {{ number_format((int) $lastResult['boss_virtual_remaining_hp']) }} / {{ number_format(\App\Services\Nation\Raid\NationRaidRules::VIRTUAL_MAX_HP) }} ・ seed {{ $lastResult['seed'] }}
                        </div>
                    </details>
                    @endunless
                </div>
            </section>
        @endif

        @if($isOfficial)
            <a href="{{ route('nation-raid.rewards', $screen['event_id']) }}" class="inline-flex min-h-11 items-center px-2 text-sm font-bold text-blue-700 underline">レイドの戦果・報酬を確認</a>
        @endif

        @if(! $lastResult)
            @if($isOfficial)
                <details class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-xs" data-nation-raid-lineage-votes>
                    <summary class="cursor-pointer font-black text-slate-800">{{ $screen['lineage_vote']['day'] }}日目の対抗系譜：{{ $currentEncounter['dominant_lineage_label'] }}</summary>
                    @if($screen['lineage_vote']['pending'])
                        <p class="mt-2 text-slate-600">前日の出撃の精算と、編成の集計を待っています。</p>
                    @elseif($screen['lineage_vote']['day'] === 1)
                        <p class="mt-2 text-slate-600">黒天竜は冒険者たちの戦い方を観測している。明日から前日の編成に応じて対抗技が変わる。</p>
                    @else
                        <p class="mt-2 text-slate-600">前日に最初に戦い終えた編成を1人1組ずつ集計。同じ系譜を複数枠に入れても1票です。</p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-5">
                            @foreach($screen['lineage_vote']['votes'] as $vote)
                                <div class="flex justify-between gap-2"><dt class="text-slate-600">{{ $vote['label'] }}</dt><dd class="font-bold">{{ $vote['count'] }}票</dd></div>
                            @endforeach
                        </dl>
                    @endif
                    @if($screen['lineage_vote']['next_switch_label'])<p class="mt-2 text-slate-500">次の切替：{{ $screen['lineage_vote']['next_switch_label'] }}</p>@endif
                </details>
            @endif
            <form method="POST" action="{{ $screen['battle_url'] ?? route('nation-raid.trial.battle') }}" class="space-y-4" data-submit-lock data-nation-raid-sortie-form>
                @csrf
                @if($isOfficial)<input type="hidden" name="battle_token" value="{{ $screen['battle_token'] }}">@endif

                <section class="rounded-xl border border-slate-200 bg-white px-4 py-4 shadow-sm">
                    <div class="flex flex-wrap items-end justify-between gap-2 border-b border-slate-100 pb-3">
                        <div>
                            <h2 class="text-base font-black text-slate-950">{{ $strategySelectionEnabled ? '作戦を選ぶ' : '出撃準備' }}</h2>
                            <p class="mt-1 text-xs font-bold text-slate-500">敵の再臨・形態・対抗系譜は、現在の戦況から自動的に決まります。</p>
                        </div>
                        <a href="{{ route('equipment.index') }}" class="text-xs font-black text-blue-700 underline underline-offset-2">装備を整える</a>
                    </div>

                    @if($strategySelectionEnabled)
                    <fieldset class="mt-4">
                        <legend class="text-xs font-black text-slate-700">作戦</legend>
                        <div class="mt-2 grid gap-2 sm:grid-cols-3">
                            @foreach($screen['strategies'] as $strategy)
                                <label class="cursor-pointer">
                                    <input type="radio" name="strategy" value="{{ $strategy['key'] }}" class="peer sr-only" @checked($selection['strategy'] === $strategy['key'])>
                                    <span class="block min-h-full rounded-lg border border-slate-200 px-3 py-3 peer-checked:border-blue-700 peer-checked:bg-blue-50">
                                        <span class="block text-sm font-black text-slate-950">{{ $strategy['label'] }}</span>
                                        <span class="mt-1 block text-[11px] font-bold leading-relaxed text-slate-500">{{ $strategy['description'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    @endif

                    <button type="submit" @disabled(! $canChallenge) class="mt-5 min-h-12 w-full rounded-md bg-slate-950 px-4 text-sm font-black text-white shadow-sm disabled:cursor-not-allowed disabled:opacity-50">
                        <span class="inline-flex items-center justify-center gap-1">
                            <span>ヴァルグレイドに挑む</span>
                            <span class="inline-flex items-center gap-0.5" data-nation-raid-sortie-stamina-cost>
                                <span>（</span>
                                <img src="{{ asset('images/icon/icon_082.webp') }}" alt="" class="h-4 w-4 object-contain">
                                <span>-{{ number_format($sortieStaminaCost) }}）</span>
                            </span>
                        </span>
                    </button>
                </section>
            </form>

            <section class="rounded-xl border border-slate-200 bg-white px-4 py-4 shadow-sm" data-nation-raid-sortie-preparation>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-black text-slate-950">{{ $screen['character']['name'] }}の出撃準備</h2>
                        <p class="mt-1 text-xs font-bold text-slate-500">Lv{{ number_format((int) $screen['character']['level']) }}・{{ $screen['character']['job_name'] }}</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-black {{ $screen['counterplay_enabled'] ? 'text-emerald-700' : 'text-slate-500' }}">
                        {{ $screen['counterplay_enabled'] ? '予告対抗 有効' : '予告対抗 無効' }}
                    </span>
                </div>

                <div class="mt-3 grid grid-cols-4 gap-x-3 gap-y-2 border-y border-slate-100 py-3 text-center text-xs">
                    @foreach([
                        'HP' => 'max_hp', 'SP' => 'max_sp', '攻撃' => 'attack', '防御' => 'defense',
                        '魔力' => 'magic', '精神' => 'spirit', '敏捷' => 'agility', '運' => 'luck',
                    ] as $label => $key)
                        <div>
                            <div class="text-[10px] font-black text-slate-400">{{ $label }}</div>
                            <div class="mt-0.5 font-black tabular-nums text-slate-900">{{ number_format((int) $screen['abilities'][$key]) }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-3">
                    <h3 class="text-xs font-black text-slate-700">ボス戦セット</h3>
                    <div class="mt-2 grid gap-1 sm:grid-cols-5">
                        @foreach($screen['boss_set'] as $art)
                            <div class="flex items-center gap-2 border-b border-slate-100 py-2 text-xs sm:block sm:border-b-0 sm:border-r sm:px-2 sm:py-0 last:border-0">
                                <span class="w-8 shrink-0 font-black text-slate-400 sm:block sm:w-auto">{{ $art['slot'] }}</span>
                                <span class="min-w-0 flex-1 font-black text-slate-900 sm:mt-1 sm:block sm:truncate">{{ $art['name'] }}</span>
                                @if($art['lineage_name'])
                                    <span class="shrink-0 text-[10px] font-bold text-slate-500 sm:mt-0.5 sm:block">{{ $art['lineage_name'] }}系譜{{ $art['is_counterplay'] ? '・対抗技' : '' }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
        @else
            <div class="flex justify-center pt-1">
                <a href="{{ $screen['index_url'] ?? route('nation-raid.trial') }}" class="inline-flex min-h-11 items-center justify-center rounded-md bg-slate-950 px-6 text-sm font-black text-white shadow-sm" data-nation-raid-next-sortie>
                    次の出撃準備へ
                </a>
            </div>
        @endif
    </div>
</x-layouts.facility>
