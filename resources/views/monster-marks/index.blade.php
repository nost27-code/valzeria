<x-layouts.facility title="印図鑑" headerIconImage="images/icon/icon_240.webp" bgImage="images/bg-castle.webp">

    {{-- サマリーバー --}}
    <div class="mb-3 grid grid-cols-4 divide-x divide-amber-100 overflow-hidden rounded-xl border border-[#d4af37]/40 bg-amber-50/60">
        <div class="px-3 py-2.5">
            <div class="text-[10px] font-black tracking-wide text-amber-600 uppercase">印発見</div>
            <div class="mt-0.5 leading-none">
                <span class="text-base font-black tabular-nums text-slate-900">{{ number_format($summary['discovered_count']) }}</span>
                <span class="text-xs font-bold text-slate-400">/{{ number_format($summary['total_count']) }}</span>
            </div>
        </div>
        <div class="px-3 py-2.5">
            <div class="text-[10px] font-black tracking-wide text-amber-600 uppercase">印合計</div>
            <div class="mt-0.5 leading-none">
                <span class="text-base font-black tabular-nums text-slate-900">{{ number_format($summary['total_marks']) }}</span>
                <span class="text-xs font-bold text-slate-400">個</span>
            </div>
        </div>
        <div class="px-3 py-2.5">
            <div class="text-[10px] font-black tracking-wide text-amber-600 uppercase">解放Lv</div>
            <div class="mt-0.5 text-base font-black tabular-nums leading-none text-slate-900">{{ number_format($summary['unlocked_levels']) }}</div>
        </div>
        <div class="px-3 py-2.5">
            <div class="text-[10px] font-black tracking-wide text-amber-600 uppercase">攻撃ボーナス</div>
            <div class="mt-0.5 text-base font-black leading-none text-amber-700">+{{ number_format($summary['bonuses']['str'] ?? 0) }}</div>
        </div>
    </div>

    {{-- 永続効果 + 倉庫リンク --}}
    <div class="mb-4 rounded-xl border border-[#d4af37]/40 bg-white px-3 py-2.5">
        <div class="mb-2 flex items-center gap-2">
            <span class="text-[10px] font-black tracking-widest text-amber-600 uppercase">永続効果合計</span>
            <div class="h-px flex-1 bg-amber-100"></div>
            <a href="{{ route('inventory.index') }}" class="text-[10px] font-black text-slate-400 underline underline-offset-2 hover:text-slate-600">倉庫を見る</a>
        </div>
        @php $statLabels = ['hp' => 'HP', 'mp' => 'SP', 'str' => '攻撃', 'def' => '防御', 'agi' => '敏捷', 'mag' => '魔力', 'spr' => '精神', 'luk' => '運']; @endphp
        <div class="flex flex-wrap gap-1.5">
            @foreach($statLabels as $key => $label)
                @php $val = (int) ($summary['bonuses'][$key] ?? 0); @endphp
                <div class="flex items-baseline gap-0.5 rounded-md border {{ $val > 0 ? 'border-[#d4af37]/40 bg-amber-50' : 'border-slate-100 bg-slate-50' }} px-2 py-1 leading-none">
                    <span class="text-[10px] font-bold {{ $val > 0 ? 'text-amber-600' : 'text-slate-400' }}">{{ $label }}</span>
                    <span class="text-xs font-black {{ $val > 0 ? 'text-amber-700' : 'text-slate-400' }}">+{{ number_format($val) }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- 解放ルール（折りたたみ） --}}
    <details class="mb-4 rounded-lg border border-slate-100 bg-slate-50 text-[11px] font-bold text-slate-500">
        <summary class="cursor-pointer select-none px-3 py-2 font-black text-slate-500">印の解放ルールを見る</summary>
        <p class="px-3 pb-2.5 leading-relaxed">
            1個・3個・7個・15個で永続効果が解放されます。攻撃などはステージ1〜3が+1、4〜6が+2、7〜10が+3/段階。HP/SPはそれぞれ+5、+10、+15/段階です。
        </p>
    </details>

    {{-- 印リスト --}}
    <div class="space-y-2">
        @foreach($groups as $cityGroup)
            @php
                $cityImageNo = str_pad((string) ((int) ($cityGroup['city']?->id ?? $loop->iteration)), 2, '0', STR_PAD_LEFT);
                $cityBgImage = asset("images/cities/city{$cityImageNo}_side.webp");
                $citySymbolImages = [
                    '01' => '01.royal-capital-arclea.webp',
                    '02' => '02.port-town-marines.webp',
                    '03' => '03.spirit-city-elphia.webp',
                    '04' => '04.steel-city-granberg.webp',
                    '05' => '05.snow-city-frostria.webp',
                    '06' => '06.sand-city-sandra.png.webp',
                    '07' => '07.arcane-city-luminous.webp',
                    '08' => '08.demon-realm-city-necrom.webp',
                    '09' => '09.sky-city-celestia.webp',
                    '10' => '10.demon-king-castle-valzeria.webp',
                ];
                $citySymbolImage = asset('images/symbol/' . ($citySymbolImages[$cityImageNo] ?? $citySymbolImages['01']));
            @endphp
            <details class="group overflow-hidden rounded-xl border border-[#d4af37]/35 bg-white shadow-sm" {{ $loop->first ? 'open' : '' }}>
                <summary class="relative flex min-h-[72px] cursor-pointer select-none items-center gap-2 overflow-hidden bg-cover bg-center px-3 py-3" style="background-image: linear-gradient(90deg, rgba(15, 23, 42, 0.82), rgba(15, 23, 42, 0.48), rgba(15, 23, 42, 0.18)), url('{{ $cityBgImage }}');">
                    <div class="relative z-10 flex min-w-0 flex-1 items-center gap-2.5">
                        <img src="{{ $citySymbolImage }}" alt="" class="h-10 w-10 shrink-0 rounded-full border border-white/60 bg-white/80 object-contain p-1 shadow-sm">
                        <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-black text-white drop-shadow">{{ $cityGroup['city_name'] }}</div>
                        <div class="mt-0.5 text-[10px] font-bold text-white/80 drop-shadow">
                            印発見 {{ number_format($cityGroup['discovered_count']) }}/{{ number_format($cityGroup['total_count']) }}
                            @if((int) $cityGroup['total_quantity'] > 0)
                                ・印所持 {{ number_format($cityGroup['total_quantity']) }}個
                            @endif
                        </div>
                        </div>
                    </div>
                    <span class="relative z-10 rounded-full border border-white/50 bg-white/85 px-2 py-1 text-[10px] font-black text-amber-700 shadow-sm">{{ number_format($cityGroup['areas']->count()) }}エリア</span>
                    <span class="relative z-10 text-xs font-black text-white/80 drop-shadow transition group-open:rotate-180">▼</span>
                </summary>

                <div class="border-t border-amber-100 bg-amber-50/30 px-2.5 py-2.5">
                    <div class="space-y-3">
                        @foreach($cityGroup['areas'] as $areaGroup)
                            <details class="group/area overflow-hidden rounded-lg border border-slate-100 bg-white">
                                <summary class="flex cursor-pointer select-none items-center gap-2 px-2.5 py-2.5">
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-xs font-black {{ $areaGroup['is_area_discovered'] ? 'text-slate-800' : 'text-slate-400' }}">
                                            {{ $areaGroup['display_name'] }}
                                        </div>
                                        <div class="mt-0.5 text-[10px] font-bold text-slate-400">
                                            印発見 {{ number_format($areaGroup['discovered_count']) }}/{{ number_format($areaGroup['total_count']) }}
                                            @if((int) $areaGroup['total_quantity'] > 0)
                                                ・印所持 {{ number_format($areaGroup['total_quantity']) }}個
                                            @endif
                                        </div>
                                    </div>
                                    @if($areaGroup['is_area_discovered'])
                                        <span class="rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-black leading-none text-emerald-700">到達済み</span>
                                    @else
                                        <span class="rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-[10px] font-black leading-none text-slate-400">未到達</span>
                                    @endif
                                    <span class="text-[10px] font-black text-slate-400 transition group-open/area:rotate-180">▼</span>
                                </summary>

                                <div class="space-y-2 border-t border-slate-100 bg-slate-50/50 px-2.5 py-2.5">
                                    @foreach($areaGroup['entries'] as $entry)
                                        @php
                                            $mark = $entry['mark'];
                                            $enemy = $entry['enemy'];
                                            $quantity = (int) $entry['quantity'];
                                            $isDiscovered = $entry['is_discovered'];
                                            $isAreaDiscovered = $entry['is_area_discovered'];
                                            $level = (int) $entry['unlocked_level'];
                                            $nextRequired = $entry['next_required'];
                                            $maxLevel = (int) $entry['max_level'];
                                            $isMaxed = $nextRequired === null;
                                        @endphp
                                        <div class="rounded-lg border {{ $isDiscovered ? 'border-[#d4af37]/50 bg-white' : 'border-slate-100 bg-slate-50/60' }} px-3 py-2.5 shadow-sm">
                                            <div class="flex items-center gap-1.5">
                                                <span class="rounded border border-[#d4af37]/40 bg-amber-50 px-1.5 py-0.5 text-[10px] font-black leading-none text-amber-700">段階 {{ $level }}/{{ $maxLevel }}</span>
                                                @if($isMaxed)
                                                    <span class="rounded border border-amber-300 bg-amber-100 px-1.5 py-0.5 text-[10px] font-black leading-none text-amber-800">MAX</span>
                                                @endif
                                                <div class="ml-auto shrink-0 text-xs font-black text-amber-700">{{ $entry['bonus_label'] }} +{{ number_format($entry['total_bonus']) }}</div>
                                            </div>

                                            <div class="mt-1.5 flex items-baseline gap-2">
                                                <span class="text-sm font-black leading-tight {{ $isDiscovered ? 'text-slate-900' : 'text-slate-400' }}">
                                                    {{ $isDiscovered ? $mark->mark_name : '未発見の印' }}
                                                </span>
                                                <span class="text-[11px] font-bold text-slate-400">{{ $isAreaDiscovered ? ($enemy?->name ?? '不明な魔物') : '？？？' }}</span>
                                            </div>

                                            <div class="mt-2">
                                                <div class="mb-1 flex items-center justify-between text-[10px] font-bold text-slate-400">
                                                    <span>印所持 {{ number_format($quantity) }} 個</span>
                                                    <span>{{ $isMaxed ? '最大解放' : '次まで ' . number_format($nextRequired) . ' 個' }}</span>
                                                </div>
                                                <div class="h-1.5 overflow-hidden rounded-full bg-amber-100">
                                                    <div class="h-full rounded-full transition-all {{ $isMaxed ? 'bg-[#d4af37]' : ($isDiscovered ? 'bg-amber-500' : 'bg-slate-300') }}" style="width: {{ $entry['progress_percent'] }}%;"></div>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endforeach
                    </div>
                </div>
            </details>
        @endforeach
    </div>

</x-layouts.facility>
