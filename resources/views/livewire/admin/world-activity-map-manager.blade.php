<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">WORLD ACTIVITY MAP</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">冒険者分布マップ</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">管理者向けに、街別・探索中ダンジョン別の人数だけを集計表示します。</p>
        </div>
        <div class="rounded-md border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-500 shadow-sm">
            集計時刻 {{ $generatedAt->format('Y/m/d H:i') }}
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <section class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-lg font-black text-slate-950">ヴァルゼリア世界地図</h2>
                        <p class="mt-1 text-xs font-bold text-slate-400">街マーカーを選択すると、右側または下部に詳細を表示します。</p>
                    </div>
                    <div class="text-xs font-black text-slate-500">
                        直近アクティブ: {{ $activeWindowMinutes }}分以内
                    </div>
                </div>
            </div>

            <div class="p-4 sm:p-5">
                <div class="relative w-full overflow-hidden rounded-md border border-slate-200 bg-slate-900">
                    @if($imageExists)
                        <img src="{{ asset($imagePath) }}" alt="ラベルなしヴァルゼリア世界地図" class="block h-auto w-full">
                    @else
                        <div class="flex aspect-[16/10] w-full items-center justify-center bg-slate-800 text-center">
                            <div>
                                <div class="text-lg font-black text-white">画像未配置</div>
                                <div class="mt-2 text-xs font-bold text-slate-300">public/{{ $imagePath }}</div>
                            </div>
                        </div>
                    @endif

                    <div class="absolute left-3 top-3 z-20 hidden rounded-sm border border-amber-900/30 bg-[#fff8e7]/90 px-3 py-2 text-[11px] font-bold text-slate-900 shadow-md backdrop-blur-sm sm:block">
                        <div class="mb-1 flex items-center gap-1.5 border-b border-amber-900/20 pb-1 text-xs font-black">
                            <span class="h-2.5 w-2.5 rounded-full bg-blue-800"></span>
                            プレイヤー分布例
                        </div>
                        <div class="flex items-center gap-2 py-0.5">
                            <span class="inline-flex flex-col items-center">
                                <span class="h-1.5 w-1.5 rounded-full bg-blue-600"></span>
                                <span class="h-2 w-2 rounded-t-full bg-blue-600"></span>
                            </span>
                            直近アクティブ
                        </div>
                        <div class="flex items-center gap-2 py-0.5">
                            <span class="inline-flex flex-col items-center">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-600"></span>
                                <span class="h-2 w-2 rounded-t-full bg-emerald-600"></span>
                            </span>
                            街に滞在
                        </div>
                        <div class="flex items-center gap-2 py-0.5">
                            <span class="h-3 w-3 rotate-45 rounded-sm border border-white/70 bg-purple-700 shadow-sm"></span>
                            探索中あり
                        </div>
                    </div>

                    @foreach($markers as $marker)
                        @php
                            $isSelected = $selectedCity && $selectedCity['name'] === $marker['name'];
                            $dotLimit = 12;
                            $dotTotal = min((int) $marker['total'], $dotLimit);
                            $activeDots = min((int) $marker['active'], $dotTotal);
                        @endphp
                        <button type="button"
                                wire:click="selectCity(@js($marker['name']))"
                                class="group absolute z-10 flex -translate-x-1/2 -translate-y-1/2 flex-col items-center text-left transition hover:scale-105 focus:outline-none focus:ring-2 focus:ring-white/80 {{ $isSelected ? 'z-30' : '' }}"
                                style="left: {{ $marker['x_percent'] }}%; top: {{ $marker['y_percent'] }}%;"
                                aria-label="{{ $marker['label'] }} {{ number_format($marker['total']) }}人">
                            <span class="relative inline-flex min-w-[3.75rem] max-w-[5.25rem] items-center justify-between gap-0.5 rounded-sm border border-[#6f5124] bg-[#fff3d1]/95 px-1.5 py-0.5 text-[8px] font-black text-slate-950 shadow-md backdrop-blur-sm sm:min-w-[7.5rem] sm:max-w-none sm:gap-1 sm:border-2 sm:px-2 sm:text-[11px] lg:min-w-[9rem] lg:text-xs {{ $isSelected ? 'ring-2 ring-amber-200' : '' }}">
                                <span class="truncate whitespace-nowrap pr-0.5 sm:hidden">{{ $marker['short_label'] }}</span>
                                <span class="hidden truncate whitespace-nowrap pr-1 sm:inline">{{ $marker['label'] }}</span>
                                <span class="flex h-4 min-w-4 shrink-0 items-center justify-center rounded-sm bg-blue-900 px-0.5 text-[8px] font-black text-white shadow-inner sm:h-5 sm:min-w-5 sm:px-1 sm:text-[10px]">
                                    {{ number_format($marker['total']) }}
                                </span>
                                @if($marker['dungeon_total'] > 0)
                                    <span class="absolute -right-1.5 -top-1.5 h-3 w-3 rotate-45 rounded-sm border border-white/80 bg-purple-700 shadow-sm" title="探索中 {{ number_format($marker['dungeon_total']) }}人"></span>
                                @endif
                            </span>
                            @if($dotTotal > 0)
                                <span class="mt-1 hidden max-w-40 flex-wrap justify-center gap-0.5 sm:flex">
                                    @for($i = 0; $i < $dotTotal; $i++)
                                        @php
                                            $dotTone = $i < $activeDots ? 'bg-blue-600' : 'bg-emerald-600';
                                        @endphp
                                        <span class="inline-flex flex-col items-center drop-shadow-sm" aria-hidden="true">
                                            <span class="h-1.5 w-1.5 rounded-full border border-white/70 {{ $dotTone }}"></span>
                                            <span class="h-2 w-2 rounded-t-full border-x border-b border-white/70 {{ $dotTone }}"></span>
                                        </span>
                                    @endfor
                                    @if($marker['total'] > $dotLimit)
                                        <span class="ml-0.5 rounded-sm bg-slate-950/75 px-1 text-[9px] font-black text-white">+{{ number_format($marker['total'] - $dotLimit) }}</span>
                                    @endif
                                </span>
                            @endif
                        </button>
                    @endforeach
                </div>

                <div class="mt-4 grid grid-cols-2 gap-2 text-xs font-bold text-slate-500 sm:grid-cols-4">
                    <div class="rounded-md bg-slate-50 px-3 py-2">街人数: characters.current_city_id</div>
                    <div class="rounded-md bg-slate-50 px-3 py-2">活動: characters.last_seen_at</div>
                    <div class="rounded-md bg-slate-50 px-3 py-2">探索: character_exploration_states</div>
                    <div class="rounded-md bg-slate-50 px-3 py-2">個人名は表示しません</div>
                </div>
            </div>
        </section>

        <aside class="rounded-md border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <p class="text-xs font-black tracking-[0.18em] text-amber-600">DETAIL</p>
                <h2 class="mt-1 text-xl font-black text-slate-950">{{ $selectedDetail['name'] }}</h2>
            </div>

            <div class="space-y-5 p-5">
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-md bg-slate-50 p-4">
                        <div class="text-xs font-black text-slate-500">街の総人数</div>
                        <div class="mt-2 text-2xl font-black text-slate-950">{{ number_format($selectedDetail['total']) }}人</div>
                    </div>
                    <div class="rounded-md bg-slate-50 p-4">
                        <div class="text-xs font-black text-slate-500">直近アクティブ</div>
                        <div class="mt-2 text-2xl font-black text-emerald-700">{{ number_format($selectedDetail['active']) }}人</div>
                    </div>
                </div>

                <section>
                    <h3 class="text-sm font-black text-slate-900">ダンジョン別人数</h3>
                    <div class="mt-3 overflow-hidden rounded-md border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-100 text-sm">
                            <thead class="bg-slate-50 text-xs text-slate-500">
                                <tr>
                                    <th class="px-3 py-2 text-left font-black">ダンジョン</th>
                                    <th class="px-3 py-2 text-right font-black">人数</th>
                                    <th class="px-3 py-2 text-right font-black">活動</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse($selectedDetail['dungeons'] as $dungeon)
                                    <tr>
                                        <td class="px-3 py-2 font-bold text-slate-800">{{ $dungeon['name'] }}</td>
                                        <td class="px-3 py-2 text-right font-black text-slate-950">{{ number_format($dungeon['total']) }}</td>
                                        <td class="px-3 py-2 text-right font-bold text-emerald-700">{{ number_format($dungeon['active']) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="px-3 py-5 text-center text-xs font-bold text-slate-500">探索中の人数は0人、または取得不可です。</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                <section>
                    <h3 class="text-sm font-black text-slate-900">深度帯別人数</h3>
                    <div class="mt-3 space-y-2">
                        @forelse($selectedDetail['depths'] as $depth)
                            <div class="flex items-center justify-between rounded-md bg-slate-50 px-3 py-2 text-sm">
                                <span class="font-bold text-slate-700">{{ $depth['label'] }}</span>
                                <span class="font-black text-slate-950">{{ number_format($depth['total']) }}人</span>
                            </div>
                        @empty
                            <div class="rounded-md bg-slate-50 px-3 py-5 text-center text-xs font-bold text-slate-500">取得不可、または該当者なし</div>
                        @endforelse
                    </div>
                    <p class="mt-2 text-xs font-bold text-amber-700">深度帯は探索度と危険度からの仮判定です。セッション上の入場済み深度は管理画面からは正本化できません。</p>
                </section>

                @if(count($selectedDetail['notes']) > 0)
                    <section class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-bold text-amber-800">
                        @foreach($selectedDetail['notes'] as $note)
                            <div>{{ $note }}</div>
                        @endforeach
                    </section>
                @endif

                <section class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-xs font-bold text-slate-500">
                    <div>街: {{ $sourceStatus['city'] }}</div>
                    <div>活動: {{ $sourceStatus['active'] }}</div>
                    <div>探索: {{ $sourceStatus['dungeon'] }}</div>
                    <div>深度: {{ $sourceStatus['depth'] }}</div>
                </section>
            </div>
        </aside>
    </div>
</div>
