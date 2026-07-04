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

                <div class="divide-y divide-slate-100">
                    @forelse($board['rows'] as $row)
                        @php
                            $rank = $loop->iteration;
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
                                        <div class="truncate font-black text-slate-900 {{ $rankLayout['name'] }}">{{ $row['name'] }}</div>
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
                            <div class="mt-1 text-[11px] font-bold text-slate-400 {{ $rankLayout['detail'] }}">{{ $row['detail'] }}</div>
                        </div>
                    @empty
                        <div class="px-4 py-10 text-center">
                            <div class="text-sm font-black text-slate-500">まだ番付に載る記録がありません。</div>
                            <p class="mt-1 text-xs font-bold text-slate-400">冒険が進むとここに名前が並びます。</p>
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
