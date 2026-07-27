<x-layouts.facility
    title="番付掲示板"
    headerIconImage="images/icon/icon_223.webp"
    bgImage="images/bg-castle.webp"
>
    @php
        $rankClasses = [
            1 => 'border-amber-300 bg-amber-50 text-amber-800',
            2 => 'border-slate-300 bg-slate-50 text-slate-700',
            3 => 'border-orange-300 bg-orange-50 text-orange-800',
        ];
        $townVoiceLines = [
            '街に番付掲示板ができたってよ！ ちょいと見ていかねえか？',
            'お前さんの名前も、そのうち掲示板に載るかもしれねえな。',
            '勝ち星だけじゃねえ。素材集めや商いまで番付になる時代さ。',
            '今日の一番手は誰だろうな。酒場でもその話でもちきりだぜ。',
            'ほら、あそこの掲示板だ。冒険者の顔と名前がずらっと並んでらあ。',
            '番付に載ったら胸を張れよ。街の連中、けっこう見てるもんだ。',
        ];
        $townVoiceLine = $townVoiceLines[array_rand($townVoiceLines)];
        $rankingImageUrl = function (array $row): string {
            $path = (string) ($row['icon_path'] ?? '/images/chara/chara_001.webp');
            if (($row['image_type'] ?? 'character') === 'asset') {
                $normalized = '/' . ltrim($path, '/');
                $absolutePath = public_path(ltrim($normalized, '/'));
                $version = is_file($absolutePath) ? (string) filemtime($absolutePath) : '1';

                return asset($normalized) . '?v=' . $version;
            }

            return \App\Support\CharacterIconCatalog::versionedAsset($path);
        };
    @endphp

    <div
        x-data="{
            activeKey: @js($activeKey),
            setBoard(key) {
                this.activeKey = key;
                const url = new URL(window.location.href);
                url.searchParams.set('board', key);
                window.history.replaceState({}, '', url);
            },
        }"
    >
        <div class="mb-4 rounded-lg border border-[#d4af37]/40 bg-white p-3 shadow-sm">
            <div class="flex items-center gap-2">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-[#d4af37]/50 bg-amber-50">
                    <img src="{{ asset('images/icon/icon_223.webp') }}" alt="" class="h-7 w-7 object-contain">
                </div>
                <div class="min-w-0">
                    <div class="text-xs font-black tracking-widest text-amber-600">街の記録</div>
                    <p class="mt-0.5 text-xs font-bold leading-relaxed text-slate-500">
                        冒険者たちの戦績、収集、商い、納品の記録を集計しています。
                    </p>
                </div>
            </div>
        </div>

        <div class="mb-4 overflow-hidden rounded-lg border border-amber-200 bg-amber-50/80 shadow-sm">
            <div class="flex items-start gap-3 px-3 py-3">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-white shadow-sm">
                    <img src="{{ asset('images/icon/icon_016.webp') }}" alt="" class="h-8 w-8 object-contain">
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-[11px] font-black tracking-widest text-amber-700">街の人の声</div>
                    <p class="mt-1 text-sm font-black leading-relaxed text-slate-800">
                        「{{ $townVoiceLine }}」
                    </p>
                </div>
            </div>
        </div>

        <div class="mb-4 flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="番付切り替え">
            @foreach($boards as $key => $board)
                <button
                    type="button"
                    @click="setBoard(@js($key))"
                    :aria-selected="activeKey === @js($key)"
                    class="shrink-0 rounded-md border px-3 py-2 text-xs font-black transition"
                    :class="activeKey === @js($key) ? 'border-[#d4af37] bg-[#003366] text-white shadow-sm' : 'border-slate-200 bg-white text-slate-600 hover:border-[#d4af37]/70 hover:text-[#003366]'"
                    role="tab"
                >
                    {{ $board['short_title'] }}
                </button>
            @endforeach
        </div>

        @foreach($boards as $key => $board)
            @php $topScore = (int) ($board['rows'][0]['score'] ?? 0); @endphp
            <section
                x-show="activeKey === @js($key)"
                x-transition.opacity.duration.150ms
                class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm"
                role="tabpanel"
                style="{{ $activeKey === $key ? '' : 'display: none;' }}"
            >
                <div class="border-b border-slate-100 bg-slate-50 px-3 py-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded bg-[#003366] px-2 py-1 text-[10px] font-black text-white">{{ $board['badge'] }}</span>
                        <h2 class="text-lg font-black text-slate-950">{{ $board['title'] }}</h2>
                    </div>
                    <p class="mt-1 text-xs font-bold leading-relaxed text-slate-500">{{ $board['description'] }}</p>
                </div>

                @if($board['is_weekly'] ?? false)
                    @php
                        $weeklyStarted = $board['availability']['is_started'] ?? true;
                    @endphp
                    <div class="border-b border-amber-100 bg-amber-50/60 px-3 py-3 sm:px-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <div class="text-[10px] font-black tracking-widest text-amber-700">
                                    {{ $weeklyStarted ? '今週の集計期間' : '第1回集計期間' }}
                                </div>
                                <div class="mt-0.5 text-sm font-black text-slate-900">{{ $board['period']['label'] }}</div>
                            </div>
                            <span class="rounded-full border border-amber-200 bg-white px-2.5 py-1 text-[10px] font-black text-amber-700">
                                {{ $weeklyStarted ? '月曜9:05に前週分を確定' : $board['availability']['starts_at_label'].'開始' }}
                            </span>
                        </div>

                        @if(! $weeklyStarted)
                            <div class="mt-2 rounded-md border border-amber-200 bg-white px-3 py-2 text-[11px] font-black leading-relaxed text-amber-800">
                                週間番付は{{ $board['availability']['starts_at_label'] }}から始まります。<br>
                                それ以前の勝利は集計・報酬の対象外です。
                            </div>
                        @endif

                        <div class="mt-3 grid grid-cols-2 gap-1.5 sm:grid-cols-4">
                            @foreach($board['reward_tiers'] as $tier)
                                <div class="rounded-md border border-amber-100 bg-white px-2 py-2">
                                    <div class="text-[10px] font-black text-slate-500">
                                        {{ $tier['label'] }}
                                        @if($tier['key'] === 'participation')
                                            ・{{ number_format($tier['minimum_wins']) }}勝以上
                                        @endif
                                    </div>
                                    <div class="mt-0.5 text-sm font-black text-[#003366]">
                                        無償輝石 {{ number_format($tier['free_kiseki']) }}個
                                    </div>
                                    @if($tier['badge_label'])
                                        <div class="mt-0.5 truncate text-[10px] font-black text-amber-700">{{ $tier['badge_label'] }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-2 space-y-0.5 text-[10px] font-bold leading-relaxed text-slate-500">
                            <p>同じ勝利数は同順位となり、同率50位までは全員が入賞します。参加賞は上位報酬へ加算されません。</p>
                            <p>番付は全冒険者を表示しますが、報酬はGoogle連携またはメール登録済みの通常アカウントが対象です。</p>
                        </div>
                    </div>

                    @if($weeklyWinStatus)
                        <div class="border-b border-slate-100 bg-white px-3 py-3 sm:px-4">
                            <div class="rounded-lg border border-[#003366]/15 bg-blue-50/50 px-3 py-2.5">
                                <div class="flex flex-wrap items-end justify-between gap-2">
                                    <div>
                                        <div class="text-[10px] font-black tracking-widest text-[#003366]">あなたの今週</div>
                                        <div class="mt-1 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                            <span class="text-lg font-black tabular-nums text-slate-950">
                                                {{ number_format($weeklyWinStatus['wins']) }}勝
                                            </span>
                                            <span class="text-sm font-black text-slate-600">
                                                {{ $weeklyWinStatus['rank'] ? number_format($weeklyWinStatus['rank']).'位' : '順位未確定' }}
                                            </span>
                                        </div>
                                    </div>

                                    @if($weeklyWinStatus['excluded'])
                                        <span class="rounded bg-slate-100 px-2 py-1 text-[10px] font-black text-slate-500">集計対象外</span>
                                    @elseif(!$weeklyWinStatus['is_account_eligible'])
                                        <span class="rounded bg-slate-100 px-2 py-1 text-[10px] font-black text-slate-600">表示のみ・報酬対象外</span>
                                    @elseif($weeklyWinStatus['potential_reward_free_kiseki'] > 0)
                                        <span class="rounded bg-amber-100 px-2 py-1 text-[11px] font-black text-amber-800">
                                            現在の報酬 無償輝石{{ number_format($weeklyWinStatus['potential_reward_free_kiseki']) }}個
                                        </span>
                                    @elseif($weeklyWinStatus['participation_remaining_wins'] > 0)
                                        <span class="rounded bg-slate-100 px-2 py-1 text-[10px] font-black text-slate-600">
                                            参加賞まであと{{ number_format($weeklyWinStatus['participation_remaining_wins']) }}勝
                                        </span>
                                    @endif
                                </div>

                                @if($weeklyWinStatus['excluded'])
                                    <p class="mt-1.5 text-[10px] font-bold text-slate-500">運営・検証用の冒険者は週間番付へ掲載されません。</p>
                                @elseif(!$weeklyWinStatus['is_account_eligible'])
                                    <p class="mt-1.5 text-[10px] font-bold text-slate-500">報酬を受け取るには、週の確定前にGoogle連携またはメール登録を完了してください。</p>
                                @else
                                    <p class="mt-1.5 text-[10px] font-bold text-slate-500">報酬は月曜9:05の確定順位に応じて、自動で通知ベルと所持輝石へ届きます。</p>
                                @endif
                            </div>
                        </div>
                    @endif
                @endif

                <div class="divide-y divide-slate-100">
                    @forelse($board['rows'] as $row)
                        @php
                            $rank = (int) ($row['rank'] ?? $loop->iteration);
                            $rankClass = $rankClasses[$rank] ?? 'border-slate-200 bg-white text-slate-500';
                            $rankLayout = match ($rank) {
                                1 => [
                                    'row' => 'bg-amber-50/60 px-3 py-4 sm:px-4',
                                    'rank' => 'h-12 w-12 text-base',
                                    'icon' => 'h-20 w-20',
                                    'name' => 'text-base sm:text-lg',
                                    'level' => 'text-xs',
                                    'score' => 'text-xl',
                                    'bar' => 'h-2',
                                    'detail' => 'pl-[9.5rem] sm:pl-[10rem]',
                                    'medal' => '1位',
                                ],
                                2 => [
                                    'row' => 'bg-slate-50/70 px-3 py-3.5',
                                    'rank' => 'h-11 w-11 text-base',
                                    'icon' => 'h-16 w-16',
                                    'name' => 'text-[15px] sm:text-base',
                                    'level' => 'text-[11px]',
                                    'score' => 'text-lg',
                                    'bar' => 'h-2',
                                    'detail' => 'pl-[8.5rem]',
                                    'medal' => '2位',
                                ],
                                3 => [
                                    'row' => 'bg-orange-50/40 px-3 py-3.5',
                                    'rank' => 'h-10 w-10 text-sm',
                                    'icon' => 'h-[3.75rem] w-[3.75rem]',
                                    'name' => 'text-sm sm:text-[15px]',
                                    'level' => 'text-[11px]',
                                    'score' => 'text-lg',
                                    'bar' => 'h-1.5',
                                    'detail' => 'pl-[8rem]',
                                    'medal' => '3位',
                                ],
                                default => [
                                    'row' => 'px-3 py-3',
                                    'rank' => 'h-9 w-9 text-sm',
                                    'icon' => 'h-14 w-14',
                                    'name' => 'text-sm',
                                    'level' => 'text-[11px]',
                                    'score' => 'text-base',
                                    'bar' => 'h-1.5',
                                    'detail' => 'pl-[7.25rem]',
                                    'medal' => null,
                                ],
                            };
                            $barWidth = $topScore > 0 && (int) $row['score'] > 0
                                ? max(6, (int) round(((int) $row['score'] / $topScore) * 100))
                                : 0;
                            $canOpenWeeklyProfile = ($board['is_weekly'] ?? false)
                                && ! empty($row['character_id']);
                        @endphp
                        <div class="{{ $rankLayout['row'] }}">
                            <div class="flex items-center gap-3">
                                <div class="flex shrink-0 items-center justify-center rounded-md border font-black tabular-nums {{ $rankLayout['rank'] }} {{ $rankClass }}">
                                    {{ $rank }}
                                </div>
                                <div class="flex shrink-0 items-center justify-center {{ $rankLayout['icon'] }}">
                                    <img
                                        src="{{ $rankingImageUrl($row) }}"
                                        alt=""
                                        class="h-full w-full object-contain drop-shadow-sm"
                                    >
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex min-w-0 items-baseline gap-2">
                                        @if($canOpenWeeklyProfile)
                                            <button
                                                type="button"
                                                x-on:click="Livewire.dispatch('open-adventurer-card', { characterId: {{ (int) $row['character_id'] }} })"
                                                class="truncate text-left font-black text-[#1e40af] underline-offset-2 hover:underline {{ $rankLayout['name'] }}"
                                                aria-label="{{ $row['name'] }}の冒険者カードを見る"
                                            >
                                                {{ $row['name'] }}
                                            </button>
                                        @else
                                            <div class="truncate font-black text-slate-900 {{ $rankLayout['name'] }}">{{ $row['name'] }}</div>
                                        @endif
                                        @if(!is_null($row['level'] ?? null))
                                            <div class="shrink-0 font-bold text-slate-400 {{ $rankLayout['level'] }}">Lv{{ number_format($row['level']) }}</div>
                                        @endif
                                        @if($rankLayout['medal'])
                                            <div class="hidden shrink-0 rounded bg-white/80 px-1.5 py-0.5 text-[10px] font-black text-slate-500 sm:block">{{ $rankLayout['medal'] }}</div>
                                        @endif
                                    </div>
                                    <div class="mt-0.5 line-clamp-1 text-[11px] font-bold leading-snug text-slate-500 sm:text-xs">
                                        {{ $row['profile_comment'] }}
                                    </div>
                                    <div class="mt-1 overflow-hidden rounded-full bg-slate-100 {{ $rankLayout['bar'] }}">
                                        <div class="h-full rounded-full bg-[#d4af37]" style="width: {{ $barWidth }}%"></div>
                                    </div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div class="font-black tabular-nums text-[#003366] {{ $rankLayout['score'] }}">{{ number_format($row['score']) }}</div>
                                    <div class="text-[10px] font-bold text-slate-400">{{ $board['unit'] }}</div>
                                </div>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] font-bold text-slate-400 {{ $rankLayout['detail'] }}">
                                <span>{{ $row['detail'] }}</span>
                                @if($board['is_weekly'] ?? false)
                                    @if(!($row['is_account_eligible'] ?? false))
                                        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-black text-slate-500">表示のみ・報酬対象外</span>
                                    @elseif(($row['reward_free_kiseki'] ?? 0) > 0)
                                        <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-black text-amber-800">
                                            無償輝石{{ number_format($row['reward_free_kiseki']) }}個
                                        </span>
                                    @endif
                                    @if($row['badge_label'] ?? null)
                                        <span class="rounded bg-[#003366] px-1.5 py-0.5 text-[10px] font-black text-white">{{ $row['badge_label'] }}</span>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-10 text-center">
                            @if(($board['is_weekly'] ?? false) && ! ($board['availability']['is_started'] ?? true))
                                <div class="text-sm font-black text-slate-500">次シーズンの開始をお待ちください。</div>
                                <p class="mt-1 text-xs font-bold text-slate-400">開始前の勝利は番付へ加算されません。</p>
                            @else
                                <div class="text-sm font-black text-slate-500">まだ番付に載る記録がありません。</div>
                                <p class="mt-1 text-xs font-bold text-slate-400">冒険が進むとここに名前が並びます。</p>
                            @endif
                        </div>
                    @endforelse
                </div>
            </section>
        @endforeach

        <section class="mt-5">
            <div class="mb-2 flex items-center gap-2">
                <h2 class="text-sm font-black tracking-widest text-slate-700">各番付の一番手</h2>
                <div class="h-px flex-1 bg-slate-200"></div>
            </div>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                @foreach($boards as $key => $board)
                    @php $leader = $board['rows'][0] ?? null; @endphp
                    <button
                        type="button"
                        @click="setBoard(@js($key))"
                        class="rounded-lg border px-3 py-2.5 text-left shadow-sm transition hover:border-[#d4af37]/70"
                        :class="activeKey === @js($key) ? 'border-[#d4af37] bg-amber-50' : 'border-slate-100 bg-white'"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="truncate text-[11px] font-black text-slate-500">{{ $board['short_title'] }}</div>
                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-black text-slate-500">{{ $board['badge'] }}</span>
                        </div>
                        @if($leader)
                            <div class="mt-2 flex items-center gap-2">
                                <div class="flex h-12 w-12 shrink-0 items-center justify-center">
                                    <img
                                        src="{{ $rankingImageUrl($leader) }}"
                                        alt=""
                                        class="h-full w-full object-contain drop-shadow-sm"
                                    >
                                </div>
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-black text-slate-900">{{ $leader['name'] }}</div>
                                    <div class="text-xs font-black tabular-nums text-[#003366]">{{ number_format($leader['score']) }} {{ $board['unit'] }}</div>
                                </div>
                            </div>
                        @else
                            <div class="mt-1 text-sm font-black text-slate-400">記録なし</div>
                            <div class="text-xs font-bold text-slate-300">-</div>
                        @endif
                    </button>
                @endforeach
            </div>
        </section>
    </div>
</x-layouts.facility>
