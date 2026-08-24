<div
    class="space-y-5 text-slate-800"
    x-data="{ sixHeroGuideOpen: false }"
    x-on:keydown.escape.window="sixHeroGuideOpen = false"
    data-six-hero-hall
    data-color-scheme="light"
>
    @if($battleNotice !== '')
        <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-bold leading-relaxed text-amber-800" role="status" data-battle-notice>
            {{ $battleNotice }}
        </div>
    @endif

    @if(! $screenError && ($ready ?? false) && isset($rooms))
        <section class="overflow-hidden rounded-xl border border-amber-300/50 bg-white shadow-xl" data-current-six-heroes>
            <div class="flex flex-col gap-2 border-b border-amber-200 bg-amber-50/70 px-4 py-4 sm:flex-row sm:items-start sm:justify-between sm:px-5">
                <div>
                    <div class="text-xs font-black tracking-[0.2em] text-amber-700">{{ $seasonLabel }} 月間競技</div>
                    <h2 class="mt-1 text-2xl font-black text-slate-900">現在の六英雄</h2>
                    <p class="mt-1 text-[11px] font-bold leading-relaxed text-slate-500">進行中の各間で現在1位の冒険者です。月末確定後の英雄記録とは分けて表示しています。</p>
                </div>
                <div class="flex w-full items-center justify-between gap-2 sm:w-auto sm:justify-end">
                    <span class="rounded-full border border-amber-300 bg-white px-3 py-1 text-[10px] font-black text-amber-800">現在首位</span>
                    <button
                        type="button"
                        x-on:click="sixHeroGuideOpen = true"
                        x-bind:aria-expanded="sixHeroGuideOpen.toString()"
                        class="inline-flex min-h-8 items-center gap-1.5 rounded-full border border-amber-400 bg-white px-3 py-1 text-[11px] font-black text-amber-800 shadow-sm transition hover:bg-amber-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                        aria-haspopup="dialog"
                        data-six-hero-rules-button
                    >
                        <span class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-amber-500 text-[10px] text-white" aria-hidden="true">?</span>
                        遊び方
                    </button>
                </div>
            </div>

            <div class="grid lg:grid-cols-[minmax(0,1.08fr)_minmax(20rem,0.92fr)] lg:items-start" data-six-hero-top-layout>
                <div class="min-w-0 border-b border-amber-100 pb-10 pt-4 sm:pb-11 sm:pt-5 lg:border-b-0 lg:border-r lg:pb-12 lg:pt-4">
                    <div class="relative aspect-[3/4] w-full" aria-label="六つの間の現在首位">
                        <img
                            src="{{ \App\Support\SixHeroRoomUiCatalog::chambersImageUrl() }}"
                            alt=""
                            class="absolute inset-0 h-full w-full object-fill"
                            data-current-six-heroes-image
                        >

                        @foreach($rooms as $room)
                            @php
                                $leader = $room['leader']?->character;
                                $position = $room['chamberPosition'];
                            @endphp
                            @if($leader)
                                <button
                                    type="button"
                                    x-on:click="Livewire.dispatch('open-adventurer-card', { characterId: {{ $leader->id }} })"
                                    class="group absolute z-10 flex aspect-square w-[21%] -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full transition hover:scale-105 focus-visible:scale-105 focus-visible:outline-none {{ $room['leaderIsCurrentCharacter'] ? 'bg-amber-50/90 shadow-[0_0_22px_rgba(251,191,36,0.95)] ring-2 ring-amber-400' : 'bg-white/75 shadow-sm ring-1 ring-white/90 focus-visible:ring-2 focus-visible:ring-amber-400' }}"
                                    style="left: {{ $position['x'] }}%; top: {{ $position['y'] }}%;"
                                    title="{{ $room['label'] }} 現在首位：{{ $leader->name }}（在位 {{ $room['leaderTenureDays'] ?? 1 }}日目）"
                                    aria-label="{{ $room['label'] }}の現在首位、{{ $leader->name }}、在位{{ $room['leaderTenureDays'] ?? 1 }}日目の戦績付き冒険者カードを見る"
                                    data-current-six-hero-room="{{ $room['key'] }}"
                                    data-current-six-hero-character-id="{{ $leader->id }}"
                                    @if($room['leaderIsCurrentCharacter']) data-current-six-hero-self @endif
                                    @if($room['leaderIsNew']) data-current-six-hero-new @endif
                                    data-current-six-hero-crowns="{{ $room['leaderCrownCount'] }}"
                                >
                                    <img
                                        src="{{ \App\Support\CharacterIconCatalog::versionedAsset($leader->icon_path) }}"
                                        alt=""
                                        class="h-full w-full object-contain drop-shadow"
                                    >
                                    @if($room['leaderIsNew'])
                                        <span class="pointer-events-none absolute -left-1 -top-1 rounded-full border border-rose-200 bg-rose-600 px-1.5 py-0.5 text-[8px] font-black tracking-wide text-white shadow">NEW</span>
                                    @endif
                                    @if($room['leaderCrownCount'] > 1)
                                        <span class="pointer-events-none absolute -right-1 -top-1 rounded-full border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-[8px] font-black text-amber-800 shadow" title="現在{{ $room['leaderCrownCount'] }}つの間で首位">
                                            👑{{ $room['leaderCrownCount'] }}
                                        </span>
                                    @endif
                                    <span class="pointer-events-none absolute left-1/2 top-full z-20 mt-0.5 flex w-max max-w-28 -translate-x-1/2 flex-col items-center rounded-md border border-amber-200/90 bg-white/95 px-1.5 py-0.5 leading-tight shadow-sm">
                                        <span class="max-w-full truncate text-[8px] font-black text-amber-700" data-current-six-hero-room-label>{{ $room['label'] }}</span>
                                        <span class="max-w-full truncate text-[9px] font-black text-slate-800">{{ $leader->name }}</span>
                                        <span class="mt-0.5 text-[8px] font-bold text-amber-800">在位 {{ $room['leaderTenureDays'] ?? 1 }}日目</span>
                                    </span>
                                </button>
                            @else
                                <span
                                    class="absolute z-10 flex aspect-square w-[16%] -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-dashed border-slate-300 bg-white/75 text-sm font-black text-slate-600 shadow-sm"
                                    style="left: {{ $position['x'] }}%; top: {{ $position['y'] }}%;"
                                    title="{{ $room['label'] }}：現在首位なし"
                                    data-current-six-hero-room="{{ $room['key'] }}"
                                    data-current-six-hero-empty
                                >—</span>
                            @endif
                        @endforeach
                    </div>
                </div>

                <div class="min-w-0 bg-white" data-six-hero-room-navigation>
                    <div class="border-b border-amber-100 px-4 py-4 sm:px-5">
                        <div class="text-xs font-black tracking-[0.22em] text-amber-700">{{ $seasonLabel }}</div>
                        <div class="mt-1 text-[11px] font-bold leading-relaxed text-slate-500">{{ $seasonPeriodLabel }}（終了時刻は含みません）</div>
                        <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-center shadow-inner">
                            <div class="text-[10px] font-black tracking-widest text-amber-800">{{ $selectedOverview['label'] }} 本日分</div>
                            <div class="mt-0.5 text-base font-black text-slate-900">
                                公式戦 残り {{ $attemptsRemaining }} / {{ $attemptLimit }}
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2 p-3 sm:gap-3 sm:p-4" aria-label="六つの間">
                        @foreach($rooms as $room)
                            @php
                                $isSelected = $selectedRoom === $room['key'];
                            @endphp
                            <button
                                type="button"
                                wire:key="six-hero-room-{{ $room['key'] }}"
                                wire:click="selectRoom('{{ $room['key'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="executeConfirmedBattle"
                                data-room-selector="{{ $room['key'] }}"
                                aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                                class="min-w-0 rounded-lg border p-3 text-left transition disabled:cursor-wait disabled:opacity-50 {{ $room['accentClasses'] }} {{ $isSelected ? 'ring-2 ring-amber-300 shadow-[0_0_22px_rgba(251,191,36,0.2)]' : 'opacity-80 hover:opacity-100' }}"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <span class="text-sm font-black text-slate-950 sm:text-base">{{ $room['label'] }}</span>
                                    @if($room['myRanking'])
                                        <span class="shrink-0 rounded bg-amber-300 px-1.5 py-0.5 text-[10px] font-black text-slate-950">{{ $room['myRanking']->rank }}位</span>
                                    @endif
                                </div>
                                <p class="mt-1 line-clamp-2 text-[10px] font-bold leading-relaxed text-slate-700 sm:text-xs">{{ $room['description'] }}</p>
                                <div class="mt-2 space-y-0.5 text-[10px] font-bold text-slate-700 sm:text-xs">
                                    <div class="truncate">現在首位：{{ $room['leader']?->character?->name ?? '—' }}</div>
                                    <div>自分の順位：{{ $room['myRanking'] ? $room['myRanking']->rank.'位' : '未登録' }}</div>
                                </div>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>
    @endif

    @if(! $screenError && isset($previousSixHeroes))
        <details class="overflow-hidden rounded-lg border border-amber-200 bg-gradient-to-br from-amber-50 to-amber-100 shadow-sm" data-previous-six-heroes>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 sm:px-4" data-previous-six-heroes-summary>
                <div class="min-w-0">
                    <div class="text-[9px] font-black tracking-[0.16em] text-amber-700">月次競技の記録</div>
                    <div class="mt-0.5 truncate text-sm font-black text-slate-800">前月の六英雄</div>
                </div>
                <div class="flex shrink-0 items-center gap-2 text-right">
                    <div>
                        <div class="text-[10px] font-black text-amber-800">{{ $previousSixHeroes['seasonLabel'] }}</div>
                        @if($previousSixHeroes['status'] === 'finalized')
                            <span class="text-[9px] font-black text-emerald-700">確定済</span>
                        @elseif($previousSixHeroes['status'] === 'pending')
                            <span class="text-[9px] font-black text-amber-700">結果確定中</span>
                        @elseif($previousSixHeroes['status'] === 'unrecorded')
                            <span class="text-[9px] font-black text-slate-500">記録対象外</span>
                        @endif
                    </div>
                    <span class="text-xs font-black text-slate-600" aria-hidden="true">▼</span>
                </div>
            </summary>

            <div class="border-t border-amber-100">
                @if($previousSixHeroes['status'] === 'finalized')
                    <div class="grid grid-cols-3 gap-1.5 p-2 sm:gap-2 sm:p-3">
                        @foreach($previousSixHeroes['results'] as $result)
                            <article
                                class="flex min-w-0 flex-col rounded-md border p-2 text-center {{ $result['isVacant'] ? 'border-slate-200 bg-slate-50' : $result['accentClasses'] }}"
                                data-previous-six-hero-room="{{ $result['roomKey'] }}"
                            >
                                <div class="truncate text-[9px] font-black tracking-wider text-slate-500">{{ $result['roomLabel'] }}</div>
                                @if($result['isVacant'])
                                    <div class="mt-3 text-[10px] font-black text-slate-500">— 空位 —</div>
                                    <div class="mt-1 text-[8px] font-bold leading-relaxed text-slate-600">{{ $result['vacancyReasonLabel'] }}</div>
                                @else
                                    @if($result['liveCharacterId'] !== null)
                                        <button
                                            type="button"
                                            x-on:click="Livewire.dispatch('open-adventurer-card', { characterId: {{ $result['liveCharacterId'] }} })"
                                            class="group mt-1.5 flex w-full min-w-0 flex-col items-center"
                                            data-hero-profile-character-id="{{ $result['liveCharacterId'] }}"
                                            aria-label="{{ $result['heroName'] }}の冒険者カードを見る"
                                        >
                                            <span class="flex h-12 w-12 items-center justify-center overflow-hidden rounded-full border border-white bg-white shadow-sm transition group-hover:border-amber-300 sm:h-14 sm:w-14">
                                                <img
                                                    src="{{ \App\Support\CharacterIconCatalog::versionedAsset($result['heroIconPath']) }}"
                                                    alt="{{ $result['heroName'] }}"
                                                    class="h-full w-full object-contain p-0.5 drop-shadow"
                                                    data-previous-six-hero-icon="{{ $result['liveCharacterId'] }}"
                                                >
                                            </span>
                                            <span class="mt-1 max-w-full truncate text-[9px] font-black text-slate-700 underline decoration-amber-300/50 underline-offset-2">{{ $result['heroName'] }}</span>
                                        </button>
                                    @else
                                        <div class="mt-1.5 flex flex-col items-center">
                                            <span
                                                class="flex h-12 w-12 items-center justify-center rounded-full border border-slate-300 bg-white text-xl text-slate-600 shadow-sm sm:h-14 sm:w-14"
                                                data-previous-six-hero-icon-placeholder
                                                aria-hidden="true"
                                            >👤</span>
                                            <div class="mt-1 max-w-full truncate text-[9px] font-black text-slate-700">{{ $result['heroName'] }}</div>
                                        </div>
                                    @endif
                                @endif
                                <div class="mt-auto pt-1.5 text-[8px] font-bold leading-relaxed text-slate-600">
                                    <div>登録者 {{ $result['registeredCount'] }}人</div>
                                    <div>公式戦 {{ $result['officialBattleCount'] }}戦</div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @elseif($previousSixHeroes['status'] === 'pending')
                    <div class="px-4 py-5 text-center sm:px-5">
                        <div class="text-sm font-black text-amber-800">結果確定中</div>
                        <p class="mt-1 text-[10px] font-bold leading-relaxed text-slate-500">直前暦月の公式戦結果を確定しています。以前の月を代わりに表示することはありません。</p>
                    </div>
                @elseif($previousSixHeroes['status'] === 'unrecorded')
                    <div class="px-4 py-5 text-center text-xs font-bold leading-relaxed text-slate-600 sm:px-5">
                        8月はプレシーズンのため、英雄・空位の記録はありません。<br>
                        月間英雄の記録は9月より開始します。
                    </div>
                @else
                    <div class="px-4 py-5 text-center text-xs font-bold text-slate-500 sm:px-5">前月の記録はありません。</div>
                @endif
            </div>
        </details>
    @endif

    @if($screenError)
        <section class="rounded-xl border border-red-300 bg-red-50 p-6 text-center shadow-xl">
            <h2 class="text-lg font-black text-red-700">六極殿を表示できません</h2>
            <p class="mt-2 text-sm font-bold text-red-700">しばらく待ってから、もう一度お試しください。</p>
        </section>
    @elseif(! $ready)
        <section class="rounded-xl border border-amber-300 bg-white p-6 text-center shadow-xl">
            <div class="text-xs font-black tracking-[0.24em] text-amber-800">{{ $seasonLabel }}</div>
            <h2 class="mt-3 text-xl font-black text-slate-950">月次ランキング準備中</h2>
            <p class="mx-auto mt-3 max-w-xl text-sm font-bold leading-relaxed text-slate-700">
                前月の公式戦結果を確定しています。準備が整うまで、参加登録や対戦は始まりません。
            </p>
            @if($seasonPeriodLabel !== '')
                <p class="mt-3 text-xs font-bold text-slate-500">{{ $seasonPeriodLabel }}（終了時刻は含みません）</p>
            @endif
        </section>
    @else
        <section class="rounded-xl border border-slate-300 bg-white shadow-xl">
            <div class="border-b border-slate-300 px-4 py-4 sm:px-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="text-xs font-black tracking-widest text-amber-800">現在のランキング</div>
                        <h2 class="mt-1 text-2xl font-black text-slate-950">{{ $selectedOverview['label'] }}</h2>
                        <p class="mt-1 text-sm font-bold text-slate-600">{{ $selectedOverview['description'] }}</p>
                    </div>
                    <div class="flex min-w-0 items-center gap-3 rounded-lg border border-amber-400/30 bg-amber-400/10 px-3 py-2">
                        @if($selectedOverview['leader']?->character)
                            <img
                                src="{{ \App\Support\CharacterIconCatalog::versionedAsset($selectedOverview['leader']->character->icon_path) }}"
                                alt=""
                                class="h-12 w-12 shrink-0 object-contain drop-shadow"
                            >
                        @endif
                        <div class="min-w-0">
                            <div class="text-[10px] font-black tracking-widest text-amber-800">現在首位</div>
                            <div class="truncate text-sm font-black text-slate-950">{{ $selectedOverview['leader']?->character?->name ?? 'まだ首位はいません' }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="p-4 lg:p-5">
                <div class="rounded-lg border border-slate-300 bg-slate-50 p-4">
                    <h3 class="text-sm font-black text-slate-950">自分の参加状況</h3>
                    @if($selectedOverview['myRanking'])
                        <div class="mt-3 flex items-center justify-between rounded border border-amber-400/30 bg-amber-400/10 px-4 py-3">
                            <span class="text-xs font-bold text-slate-700">自分の順位</span>
                            <span class="text-xl font-black text-amber-800">{{ $selectedOverview['myRanking']->rank }}位</span>
                        </div>
                    @else
                        <p class="mt-3 text-sm font-bold leading-relaxed text-slate-700">この間にはまだ参加していません。参加時点の最下位から始まります。</p>
                        <button
                            type="button"
                            wire:click="registerRoom('{{ $selectedOverview['key'] }}')"
                            wire:loading.attr="disabled"
                            wire:target="registerRoom"
                            class="mt-3 w-full rounded-lg bg-amber-400 px-4 py-3 text-sm font-black text-slate-950 shadow transition hover:bg-amber-300 disabled:cursor-wait disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="registerRoom">{{ $selectedOverview['label'] }}へ参加登録する</span>
                            <span wire:loading wire:target="registerRoom">登録中...</span>
                        </button>
                        <p class="mt-2 text-[11px] font-bold leading-relaxed text-slate-500">次月以降は前月順位を引き継いで自動登録されます。</p>
                    @endif

                    @if($registrationNotice !== '')
                        <div class="mt-3 rounded border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-700" role="status">
                            {{ $registrationNotice }}
                        </div>
                    @endif
                    @error('registration')
                        <div class="mt-3 rounded border border-red-300 bg-red-50 px-3 py-2 text-xs font-bold text-red-700" role="alert">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-[minmax(0,0.82fr)_minmax(0,1.18fr)]">
            <div class="rounded-xl border border-slate-300 bg-white p-4 shadow-xl sm:p-5">
                    <h3 class="text-base font-black text-slate-950">直上3人</h3>
                    <p class="mt-1 text-xs font-bold text-slate-500">公式戦が順位を上げる本番です。必要なら先に相性だけ確認できます。</p>

                    @if($selectedOverview['myRanking'] && $attemptsRemaining === 0)
                        <div class="mt-3 rounded border border-slate-300 bg-slate-50 px-3 py-2 text-center text-xs font-black text-slate-700">
                            この間の本日の公式戦は終了しました。相性確認は引き続き利用できます。
                        </div>
                    @endif

                @if(! $selectedOverview['myRanking'])
                    <div class="mt-4 rounded border border-dashed border-slate-300 px-3 py-5 text-center text-xs font-bold text-slate-500">参加登録後に候補が表示されます。</div>
                @elseif($selectedOverview['myRanking']->rank === 1)
                    <div class="mt-4 rounded border border-amber-300 bg-amber-50 px-3 py-5 text-center text-xs font-black text-amber-800">現在1位のため、上位候補はいません。</div>
                @else
                    <div class="mt-4 space-y-2">
                        @forelse($targets as $target)
                            <div class="flex min-w-0 flex-col gap-3 rounded-lg border border-slate-300 bg-slate-50 px-3 py-3 sm:flex-row sm:items-center" data-candidate-character-id="{{ $target->character_id }}">
                                <div class="flex min-w-0 items-center gap-3 sm:flex-1">
                                    <span class="w-10 shrink-0 text-center text-sm font-black text-amber-800">{{ $target->rank }}位</span>
                                    @if($target->character)
                                        <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($target->character->icon_path) }}" alt="" class="h-9 w-9 shrink-0 object-contain">
                                    @endif
                                    <span class="min-w-0 flex-1 truncate text-sm font-black text-slate-950">{{ $target->character?->name ?? '冒険者' }}</span>
                                </div>
                                <div class="flex w-full flex-col gap-1.5 sm:w-40">
                                    <button
                                        type="button"
                                        wire:click="openBattleConfirmation('official', {{ $target->character_id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="openBattleConfirmation,executeConfirmedBattle"
                                        @disabled($attemptsRemaining === 0 || $battleSubmitting)
                                        class="w-full rounded-md bg-amber-400 px-3 py-2.5 text-xs font-black text-slate-950 shadow-sm transition hover:bg-amber-300 disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-500"
                                        data-official-battle-character-id="{{ $target->character_id }}"
                                    >
                                        公式戦
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="openBattleConfirmation('practice', {{ $target->character_id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="openBattleConfirmation,executeConfirmedBattle"
                                        @disabled($battleSubmitting)
                                        class="self-center rounded px-2 py-1 text-[11px] font-black text-sky-700 underline decoration-sky-300 underline-offset-4 transition hover:bg-sky-50 hover:text-sky-800 disabled:cursor-wait disabled:opacity-50"
                                        data-practice-battle-character-id="{{ $target->character_id }}"
                                    >
                                        公式戦前に相性を試す
                                    </button>
                                </div>
                            </div>
                        @empty
                            <div class="rounded border border-dashed border-slate-300 px-3 py-5 text-center text-xs font-bold text-slate-500">現在、上位候補はいません。</div>
                        @endforelse
                    </div>
                @endif
            </div>

            <livewire:six-hero-room-ranking
                :season-id="$season->id"
                :room-key="$selectedRoom"
                :key="'six-hero-room-ranking-'.$season->id.'-'.$selectedRoom.'-'.$selectedOverview['registeredCount']"
            />
        </section>
    @endif

    @if(! $screenError && isset($selectedRoomHistory, $heroSummary))
        <section class="grid gap-4 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]" data-six-hero-hall-history>
            <div class="overflow-hidden rounded-xl border border-slate-300 bg-white shadow-xl">
                <div class="border-b border-slate-300 px-4 py-4 sm:px-5">
                    <div class="text-xs font-black tracking-[0.2em] text-amber-800">殿堂・確定済みの歴史</div>
                    <h2 class="mt-1 text-lg font-black text-slate-950">{{ $selectedRoomHistoryTitle }}</h2>
                    <p class="mt-1 text-xs font-bold text-slate-500">最近12期を新しい順に表示しています。</p>
                </div>
                <div class="divide-y divide-slate-200">
                    @forelse($selectedRoomHistory as $history)
                        <div class="flex min-w-0 flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5" data-room-history-season="{{ $history['seasonKey'] }}">
                            <div class="shrink-0 text-xs font-black text-slate-600">{{ $history['seasonLabel'] }}</div>
                            <div class="min-w-0 sm:text-right">
                                @if($history['isVacant'])
                                    <div class="text-sm font-black text-slate-600">— 空位 —</div>
                                    <div class="mt-0.5 text-[10px] font-bold text-slate-600">{{ $history['vacancyReasonLabel'] }}</div>
                                @elseif($history['liveCharacterId'] !== null)
                                    <button
                                        type="button"
                                        x-on:click="Livewire.dispatch('open-adventurer-card', { characterId: {{ $history['liveCharacterId'] }} })"
                                        class="max-w-full break-words text-left text-sm font-black text-amber-800 underline decoration-amber-300/50 underline-offset-2 hover:text-amber-900 sm:text-right"
                                        data-history-profile-character-id="{{ $history['liveCharacterId'] }}"
                                        aria-label="{{ $history['heroName'] }}の冒険者カードを見る"
                                    >
                                        {{ $history['heroName'] }}
                                    </button>
                                @else
                                    <div class="break-words text-sm font-black text-amber-800">{{ $history['heroName'] }}</div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-8 text-center text-sm font-bold text-slate-500">歴代英雄の記録はまだありません。</div>
                    @endforelse
                </div>
            </div>

            <div class="rounded-xl border border-amber-300 bg-white p-4 shadow-xl sm:p-5" data-six-hero-character-achievement>
                <div class="text-xs font-black tracking-[0.2em] text-amber-800">確定済み履歴から集計</div>
                <h2 class="mt-1 text-lg font-black text-slate-950">自分の六英雄実績</h2>

                <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                    <div class="min-w-0 rounded-lg border border-slate-300 bg-slate-50 px-2 py-3">
                        <div class="text-[9px] font-black text-slate-500">英雄獲得</div>
                        <div class="mt-1 text-base font-black text-amber-800">{{ $heroSummary['heroCount'] }}回</div>
                    </div>
                    <div class="min-w-0 rounded-lg border border-slate-300 bg-slate-50 px-2 py-3">
                        <div class="text-[9px] font-black text-slate-500">制覇した間</div>
                        <div class="mt-1 text-base font-black text-slate-950">{{ $heroSummary['conqueredRoomCount'] }} / {{ $heroSummary['totalRoomCount'] }}</div>
                    </div>
                    <div class="min-w-0 rounded-lg border {{ $heroSummary['maxCrownsInSeason'] === 6 ? 'border-amber-300 bg-amber-50' : 'border-slate-300 bg-slate-50' }} px-2 py-3">
                        <div class="text-[9px] font-black text-slate-500">最高同時冠</div>
                        <div class="mt-1 text-base font-black {{ $heroSummary['maxCrownsInSeason'] === 6 ? 'text-amber-800' : 'text-slate-950' }}">{{ $heroSummary['maxCrownLabel'] }}</div>
                    </div>
                </div>

                @if(! $heroSummary['hasHeroHistory'])
                    <div class="mt-4 rounded-lg border border-dashed border-slate-300 px-3 py-5 text-center text-xs font-bold text-slate-500">まだ六英雄の記録はありません。</div>
                @else
                    <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3">
                        @foreach($heroSummary['rooms'] as $roomAchievement)
                            <div class="min-w-0 rounded-lg border border-slate-300 bg-slate-50 p-3" data-achievement-room="{{ $roomAchievement['key'] }}">
                                <div class="flex min-w-0 items-center justify-between gap-2">
                                    <span class="truncate text-xs font-black text-slate-950">{{ $roomAchievement['shortLabel'] }}</span>
                                    <span class="shrink-0 text-xs font-black {{ $roomAchievement['heroCount'] > 0 ? 'text-amber-800' : 'text-slate-600' }}">×{{ $roomAchievement['heroCount'] }}</span>
                                </div>
                                @if($roomAchievement['currentStreakLabel'] || $roomAchievement['longestStreakLabel'])
                                    <div class="mt-2 space-y-0.5 text-[9px] font-black">
                                        @if($roomAchievement['currentStreakLabel'])
                                            <div class="text-emerald-700">{{ $roomAchievement['currentStreakLabel'] }}</div>
                                        @endif
                                        @if($roomAchievement['longestStreakLabel'])
                                            <div class="text-slate-600">{{ $roomAchievement['longestStreakLabel'] }}</div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4 overflow-hidden rounded-lg border border-slate-300 bg-slate-50">
                        <div class="border-b border-slate-300 px-3 py-2 text-xs font-black text-slate-950">冠の履歴</div>
                        <div class="divide-y divide-slate-200">
                            @foreach($heroSummary['crownSeasons'] as $crownSeason)
                                <div class="px-3 py-3 {{ $crownSeason['isSixCrown'] ? 'bg-amber-400/10' : '' }}" data-crown-season="{{ $crownSeason['seasonKey'] }}">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="text-xs font-black text-slate-600">{{ $crownSeason['seasonLabel'] }}</span>
                                        <span class="rounded-full border px-2 py-0.5 text-xs font-black {{ $crownSeason['isSixCrown'] ? 'border-amber-200 bg-amber-300 text-slate-950' : ($crownSeason['isMultiCrown'] ? 'border-amber-300 bg-amber-50 text-amber-800' : 'border-slate-300 text-slate-700') }}">{{ $crownSeason['crownLabel'] }}</span>
                                    </div>
                                    <div class="mt-1.5 break-words text-[10px] font-bold text-slate-500">{{ implode(' / ', $crownSeason['rooms']) }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </section>
    @endif

    @if(! $screenError && ($ready ?? false) && isset($rooms))
        <div
            x-cloak
            x-show="sixHeroGuideOpen"
            x-transition.opacity
            x-on:click.self="sixHeroGuideOpen = false"
            class="fixed inset-0 z-[110] flex items-center justify-center overflow-y-auto bg-black/50 px-3 py-4 backdrop-blur-sm sm:py-6"
            role="presentation"
            data-six-hero-rules-modal
        >
            <section
                class="flex max-h-[calc(100dvh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-xl border border-amber-300 bg-white shadow-2xl"
                role="dialog"
                aria-modal="true"
                aria-labelledby="six-hero-rules-title"
            >
                <div class="flex shrink-0 items-start justify-between gap-4 border-b border-amber-200 bg-amber-50 px-4 py-4 sm:px-5">
                    <div>
                        <div class="text-[10px] font-black tracking-[0.18em] text-amber-700">六つの間を知る</div>
                        <h2 id="six-hero-rules-title" class="mt-1 text-xl font-black text-slate-950">六英雄戦の遊び方</h2>
                        <p class="mt-1 text-xs font-bold leading-relaxed text-slate-600">共通ルールと、各間で変わる戦闘計算を確認できます。</p>
                    </div>
                    <button
                        type="button"
                        x-on:click="sixHeroGuideOpen = false"
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white text-xl font-black text-slate-600 shadow-sm transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                        aria-label="遊び方を閉じる"
                        data-six-hero-rules-close
                    >×</button>
                </div>

                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-4 py-5 sm:px-5">
                    <section aria-labelledby="six-hero-common-rules-title">
                        <h3 id="six-hero-common-rules-title" class="text-base font-black text-slate-950">競技の基本</h3>
                        <ul class="mt-3 grid gap-x-5 gap-y-2 text-xs font-bold leading-relaxed text-slate-700 sm:grid-cols-2">
                            <li class="flex gap-2"><span class="text-amber-600" aria-hidden="true">●</span><span>六つの間は、それぞれ独立したランキングです。</span></li>
                            <li class="flex gap-2"><span class="text-amber-600" aria-hidden="true">●</span><span>複数の間へ同時に参加できます。</span></li>
                            <li class="flex gap-2"><span class="text-amber-600" aria-hidden="true">●</span><span>公式戦で挑める相手は、自分の直上3人です。</span></li>
                            <li class="flex gap-2"><span class="text-amber-600" aria-hidden="true">●</span><span>格上への公式戦に勝つと、相手の順位を奪います。</span></li>
                            <li class="flex gap-2"><span class="text-amber-600" aria-hidden="true">●</span><span>公式戦は各間ごとに1日{{ $attemptLimit }}戦です。</span></li>
                            <li class="flex gap-2"><span class="text-amber-600" aria-hidden="true">●</span><span>相性確認は回数無制限です。PvP用戦技セット・この間の特殊ルール・現在の相手ビルドで戦い、順位や公式戦回数に影響しません。</span></li>
                            <li class="flex gap-2"><span class="text-amber-600" aria-hidden="true">●</span><span>冒険者訓練所では、自由な条件でビルドを試せます。</span></li>
                            <li class="flex gap-2"><span class="text-amber-600" aria-hidden="true">●</span><span>順位は翌月へ引き継がれ、公式戦績は月ごとにリセットされます。</span></li>
                        </ul>
                    </section>

                    <section aria-labelledby="six-hero-special-rules-title">
                        <div class="border-t border-slate-200 pt-5">
                            <h3 id="six-hero-special-rules-title" class="text-base font-black text-slate-950">六つの間の特殊ルール</h3>
                            <p class="mt-1 text-xs font-bold leading-relaxed text-slate-600">同じ攻撃や戦技でも、間によって有利になる能力や計算が変わります。</p>
                            <p class="mt-1 text-[11px] font-medium leading-relaxed text-amber-700">※ 現在はバランス調整を検討中のため、特殊ルールによる能力・ダメージの変化は控えめに設定しています。</p>
                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            @foreach($rooms as $room)
                                <article
                                    class="rounded-lg border p-3 {{ $room['accentClasses'] }}"
                                    data-six-hero-rule-room="{{ $room['key'] }}"
                                >
                                    <h4 class="text-sm font-black text-slate-950">{{ $room['label'] }}</h4>
                                    <p class="mt-1 text-xs font-black leading-relaxed text-slate-800">{{ $room['ruleGuide']['summary'] }}</p>
                                    <ul class="mt-2 space-y-1.5 text-[11px] font-bold leading-relaxed text-slate-700">
                                        @foreach($room['ruleGuide']['points'] as $point)
                                            <li class="flex gap-1.5">
                                                <span class="shrink-0 text-amber-700" aria-hidden="true">・</span>
                                                <span>{{ $point }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </article>
                            @endforeach
                        </div>
                    </section>
                </div>

                <div class="shrink-0 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-5">
                    <button
                        type="button"
                        x-on:click="sixHeroGuideOpen = false"
                        class="w-full rounded-lg bg-amber-400 px-4 py-3 text-sm font-black text-slate-950 shadow-sm transition hover:bg-amber-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500"
                    >閉じる</button>
                </div>
            </section>
        </div>
    @endif

    @if($battleConfirmation !== [])
        <div
            class="fixed inset-0 z-[100] flex items-center justify-center overflow-y-auto bg-black/50 px-3 py-6 backdrop-blur-sm"
            role="presentation"
            wire:click.self="closeBattleConfirmation"
            data-battle-confirmation-modal
        >
            <section
                class="w-full max-w-md overflow-hidden rounded-xl border {{ $battleConfirmation['mode'] === 'official' ? 'border-amber-300' : 'border-sky-300' }} bg-white shadow-2xl"
                role="dialog"
                aria-modal="true"
                aria-labelledby="six-hero-battle-confirmation-title"
            >
                <div class="border-b border-slate-300 px-4 py-4 sm:px-5">
                    <div class="text-xs font-black tracking-widest {{ $battleConfirmation['mode'] === 'official' ? 'text-amber-800' : 'text-sky-700' }}">
                        {{ $battleConfirmation['seasonLabel'] }}・{{ $battleConfirmation['roomLabel'] }}
                    </div>
                    <h2 id="six-hero-battle-confirmation-title" class="mt-1 text-xl font-black text-slate-950">
                        {{ $battleConfirmation['mode'] === 'official' ? '公式戦の確認' : '相性確認' }}
                    </h2>
                </div>
                <div class="space-y-4 px-4 py-5 sm:px-5">
                    <div class="rounded-lg border border-slate-300 bg-slate-50 p-4 text-center">
                        <div class="text-xs font-bold text-slate-600">対戦相手</div>
                        <div class="mt-1 break-words text-lg font-black text-slate-950">
                            {{ $battleConfirmation['opponentRank'] }}位「{{ $battleConfirmation['opponentName'] }}」
                        </div>
                    </div>

                    @if($battleConfirmation['mode'] === 'official')
                        <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-bold leading-relaxed text-amber-800">
                            公式戦は開始後、勝敗にかかわらず1回消費されます。
                            <div class="mt-2 text-center text-base font-black text-slate-950">
                                現在の残り {{ $battleConfirmation['officialAttemptsRemaining'] }} / {{ $battleConfirmation['officialAttemptLimit'] }}
                            </div>
                        </div>
                    @else
                        <div class="rounded-lg border border-sky-300 bg-sky-50 px-4 py-3 text-sm font-bold leading-relaxed text-sky-700">
                            相性確認は、PvP用戦技セット・この間の特殊ルール・現在の相手ビルドで対戦します。順位・公式戦績・公式戦回数に影響しません。
                        </div>
                    @endif

                    <div class="grid grid-cols-2 gap-3">
                        <button
                            type="button"
                            wire:click="closeBattleConfirmation"
                            wire:loading.attr="disabled"
                            wire:target="executeConfirmedBattle"
                            class="rounded-lg border border-slate-300 bg-slate-200 px-3 py-3 text-sm font-black text-slate-700 hover:bg-slate-300 disabled:cursor-wait disabled:opacity-50"
                        >
                            キャンセル
                        </button>
                        <button
                            type="button"
                            wire:click="executeConfirmedBattle"
                            wire:loading.attr="disabled"
                            wire:target="executeConfirmedBattle"
                            @disabled($battleSubmitting)
                            class="rounded-lg px-3 py-3 text-sm font-black text-slate-950 transition disabled:cursor-wait disabled:opacity-60 {{ $battleConfirmation['mode'] === 'official' ? 'bg-amber-400 hover:bg-amber-300' : 'bg-sky-300 hover:bg-sky-200' }}"
                            data-confirm-battle-button
                        >
                            <span wire:loading.remove wire:target="executeConfirmedBattle">{{ $battleConfirmation['modeLabel'] }}を開始</span>
                            <span wire:loading wire:target="executeConfirmedBattle">戦闘中...</span>
                        </button>
                    </div>
                </div>
            </section>
        </div>
    @endif

</div>
