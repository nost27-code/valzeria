<x-layouts.facility title="印図鑑" headerIconImage="images/icon/icon_013.webp" bgImage="images/bg-castle.webp">
    <div class="py-8 w-full mx-auto sm:px-6 lg:px-8">
        <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-[#d4af37]/50">
            <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-2xl font-extrabold text-slate-800 tracking-wider">印図鑑</h2>
                    <p class="mt-2 text-sm text-slate-600 leading-relaxed">
                        魔物の印を集めると、1個・3個・7個・15個で永続効果が解放されます。攻撃などはステージ1〜3が+1、4〜6が+2、7〜10が+3/段階。HP/SPはそれぞれ+5、+10、+15/段階です。
                    </p>
                </div>
                <a href="{{ route('inventory.index') }}" class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-slate-50 px-4 py-2 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-100">
                    倉庫を見る
                </a>
            </div>

            <div class="mb-5 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <div class="rounded-lg border border-fuchsia-100 bg-fuchsia-50 px-3 py-3">
                    <div class="text-[11px] font-bold text-fuchsia-700">発見</div>
                    <div class="mt-1 text-lg font-extrabold text-slate-800">{{ number_format($summary['discovered_count']) }} / {{ number_format($summary['total_count']) }}</div>
                </div>
                <div class="rounded-lg border border-fuchsia-100 bg-fuchsia-50 px-3 py-3">
                    <div class="text-[11px] font-bold text-fuchsia-700">印合計</div>
                    <div class="mt-1 text-lg font-extrabold text-slate-800">{{ number_format($summary['total_marks']) }} 個</div>
                </div>
                <div class="rounded-lg border border-fuchsia-100 bg-fuchsia-50 px-3 py-3">
                    <div class="text-[11px] font-bold text-fuchsia-700">解放Lv</div>
                    <div class="mt-1 text-lg font-extrabold text-slate-800">{{ number_format($summary['unlocked_levels']) }}</div>
                </div>
                <div class="rounded-lg border border-fuchsia-100 bg-fuchsia-50 px-3 py-3">
                    <div class="text-[11px] font-bold text-fuchsia-700">主な効果</div>
                    <div class="mt-1 text-sm font-extrabold text-slate-800">
                        攻撃 +{{ number_format($summary['bonuses']['str'] ?? 0) }}
                    </div>
                </div>
            </div>

            <div class="mb-5 rounded-lg border border-slate-200 bg-slate-50 p-3">
                <div class="mb-2 text-sm font-extrabold text-slate-700">永続効果合計</div>
                <div class="grid grid-cols-4 gap-2 text-center text-xs font-bold sm:grid-cols-8">
                    @php
                        $labels = ['hp' => 'HP', 'mp' => 'SP', 'str' => '攻撃', 'def' => '防御', 'agi' => '敏捷', 'mag' => '魔力', 'spr' => '精神', 'luk' => '運'];
                    @endphp
                    @foreach($labels as $key => $label)
                        <div class="rounded border border-slate-200 bg-white px-2 py-2">
                            <div class="text-slate-500">{{ $label }}</div>
                            <div class="mt-1 text-fuchsia-700">+{{ number_format($summary['bonuses'][$key] ?? 0) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="space-y-3">
                @foreach($collection as $entry)
                    @php
                        $mark = $entry['mark'];
                        $enemy = $entry['enemy'];
                        $quantity = (int) $entry['quantity'];
                        $isDiscovered = $entry['is_discovered'];
                        $level = (int) $entry['unlocked_level'];
                        $nextRequired = $entry['next_required'];
                        $maxLevel = (int) $entry['max_level'];
                    @endphp
                    <div class="rounded-lg border {{ $isDiscovered ? 'border-fuchsia-200 bg-white' : 'border-slate-200 bg-slate-50 opacity-80' }} p-4 shadow-sm">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex rounded bg-slate-800 px-2 py-0.5 text-xs font-bold text-white">
                                        {{ $enemy?->area?->name ?? '不明な地域' }}
                                    </span>
                                    <span class="inline-flex rounded bg-fuchsia-50 px-2 py-0.5 text-xs font-bold text-fuchsia-700 border border-fuchsia-100">
                                        段階 {{ $level }} / {{ $maxLevel }}
                                    </span>
                                </div>
                                <h3 class="mt-2 text-lg font-extrabold {{ $isDiscovered ? 'text-slate-900' : 'text-slate-500' }}">
                                    {{ $isDiscovered ? $mark->mark_name : '未発見の印' }}
                                </h3>
                                <div class="mt-1 text-sm font-bold text-slate-500">
                                    {{ $enemy?->name ?? '不明な魔物' }}
                                </div>
                            </div>
                            <div class="shrink-0 rounded border border-fuchsia-100 bg-fuchsia-50 px-3 py-2 text-sm font-extrabold text-fuchsia-700">
                                {{ $entry['bonus_label'] }} +{{ number_format($entry['total_bonus']) }}
                            </div>
                        </div>

                        <div class="mt-3">
                            <div class="mb-1 flex items-center justify-between text-xs font-bold text-slate-600">
                                <span>所持 {{ number_format($quantity) }} 個</span>
                                <span>
                                    @if($nextRequired === null)
                                        最大解放
                                    @else
                                        次まで {{ number_format($nextRequired) }} 個
                                    @endif
                                </span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-slate-200">
                                <div class="h-full rounded-full bg-fuchsia-500 transition-all" style="width: {{ $entry['progress_percent'] }}%;"></div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-layouts.facility>
