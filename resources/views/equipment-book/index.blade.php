@php
    $summary = $book['summary'] ?? [];
    $selectedChart = $book['selected_chart'] ?? null;
    $type = $book['type'] ?? 'weapon';
@endphp

<x-layouts.facility title="装備図鑑" headerIconImage="images/icon/icon_277.webp" bgImage="images/facilities/item.webp">
    <div class="w-full pb-10" x-data="{ detail: null }">
        <section class="rounded-xl border border-[#d4af37]/50 bg-white/95 p-4 shadow-sm sm:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-black tracking-[0.2em] text-amber-700">EQUIPMENT ARCHIVE</p>
                    <h2 class="mt-1 text-xl font-black text-slate-900">装備の進化系譜</h2>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">
                        手にした装備の記録と、その先へ続く進化の枝を確認できます。未発見の装備は姿と名前が伏せられます。
                    </p>
                </div>
                <div class="grid grid-cols-3 gap-2 text-center text-xs">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                        <div class="font-black text-slate-900">{{ number_format((int) ($summary['discovered_count'] ?? 0)) }}</div>
                        <div class="mt-0.5 text-slate-500">発見</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                        <div class="font-black text-slate-900">{{ number_format((int) ($summary['total_count'] ?? 0)) }}</div>
                        <div class="mt-0.5 text-slate-500">総数</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                        <div class="font-black text-slate-900">{{ number_format((int) ($summary['chart_count'] ?? 0)) }}</div>
                        <div class="mt-0.5 text-slate-500">系統</div>
                    </div>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-2 gap-2 rounded-xl bg-slate-100 p-1">
                <a
                    href="{{ route('equipment-book.index', ['type' => 'weapon']) }}"
                    class="rounded-lg px-3 py-3 text-center text-sm font-black transition {{ $type === 'weapon' ? 'bg-slate-900 text-white shadow' : 'text-slate-600 hover:bg-white' }}"
                >
                    武器図鑑
                </a>
                <div
                    aria-disabled="true"
                    class="flex cursor-not-allowed items-center justify-center gap-2 rounded-lg px-3 py-3 text-center text-sm font-black text-slate-400"
                >
                    防具図鑑
                    <span class="rounded-full border border-slate-300 bg-white px-2 py-0.5 text-[10px] font-bold text-slate-500">準備中</span>
                </div>
            </div>

            @if(($book['chart_options'] ?? []) !== [])
                <form method="GET" action="{{ route('equipment-book.index') }}" class="mt-4">
                    <input type="hidden" name="type" value="{{ $type }}">
                    <label for="equipment-chart" class="mb-1 block text-xs font-black text-slate-600">表示する進化系統</label>
                    <div class="flex gap-2">
                        <select id="equipment-chart" name="chart" class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-3 text-sm font-bold text-slate-800">
                            @foreach($book['chart_options'] as $option)
                                <option value="{{ $option['key'] }}" @selected(($selectedChart['key'] ?? '') === $option['key'])>
                                    {{ $option['title'] }}（{{ $option['discovered_count'] }}/{{ $option['node_count'] }}）
                                </option>
                            @endforeach
                        </select>
                        <button type="submit" class="shrink-0 rounded-lg bg-amber-600 px-4 py-3 text-sm font-black text-white shadow-sm transition hover:bg-amber-700">
                            表示
                        </button>
                    </div>
                </form>
            @endif
        </section>

        @if($selectedChart)
            <section class="mt-4 overflow-hidden rounded-xl border border-amber-300/70 bg-[#f7f3e8] shadow-lg">
                <div class="border-b border-amber-400/30 bg-gradient-to-r from-slate-950 via-slate-900 to-slate-950 px-4 py-4 text-white">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="text-xs font-black tracking-[0.18em] text-amber-300">{{ $selectedChart['category'] }}</p>
                            <h3 class="mt-1 text-lg font-black">{{ $selectedChart['title'] }}</h3>
                        </div>
                        <span class="rounded-full border border-amber-300/40 bg-amber-300/10 px-3 py-1 text-xs font-bold text-amber-100">
                            発見 {{ $selectedChart['discovered_count'] }}/{{ $selectedChart['node_count'] }}
                        </span>
                    </div>
                    <p class="mt-2 text-xs text-slate-300 sm:hidden">装備をタップすると性能を確認できます。</p>
                </div>

                <div
                    class="equipment-tree-scroll overflow-x-auto px-2 py-4 sm:px-4 sm:py-6"
                    x-init="$nextTick(() => { $el.scrollLeft = Math.max(0, ($el.scrollWidth - $el.clientWidth) / 2) })"
                >
                    <ul class="equipment-tree mx-auto w-max min-w-full">
                        @include('equipment-book.partials.tree-node', ['node' => $selectedChart['tree']])
                    </ul>
                </div>
            </section>
        @else
            <section class="mt-4 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">
                表示できる進化系統がありません。
            </section>
        @endif

        <div
            x-cloak
            x-show="detail"
            x-transition.opacity
            data-equipment-detail-modal
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4"
            @keydown.escape.window="detail = null"
            @click.self="detail = null"
        >
            <div x-show="detail" x-transition class="relative max-h-[88vh] w-full max-w-md overflow-y-auto rounded-2xl border border-amber-300/60 bg-white p-4 shadow-2xl sm:p-6">
                <button type="button" @click="detail = null" aria-label="閉じる" class="absolute right-3 top-3 z-10 flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-sm font-black text-slate-600 transition hover:bg-slate-200">×</button>

                <div class="flex flex-col items-center text-center">
                    <div class="flex h-36 w-36 items-center justify-center rounded-2xl border border-amber-200 bg-gradient-to-br from-amber-50 via-white to-slate-100 shadow-inner sm:h-40 sm:w-40">
                        <img :src="detail?.image" :alt="detail?.name ?? ''" class="h-28 w-28 object-contain drop-shadow-md sm:h-32 sm:w-32">
                    </div>
                    <div class="mt-3 rounded-full border border-amber-300 bg-amber-50 px-3 py-1 text-xs font-black text-amber-800" x-text="detail?.rank"></div>
                    <h4 class="mt-2 break-words text-xl font-black leading-tight text-slate-900" x-text="detail?.name"></h4>
                    <p class="mt-1 text-xs font-bold text-slate-500">
                        <span x-text="detail?.type"></span>
                        <span aria-hidden="true">・</span>
                        <span x-text="detail?.family"></span>
                    </p>
                </div>

                <template x-if="detail?.discovered">
                    <div class="mt-4">
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-center text-sm font-bold text-emerald-800">
                            発見済み
                            <span x-show="detail?.owned_count > 0">・現在 <span x-text="detail?.owned_count"></span>個所持</span>
                        </div>
                        <div class="mt-4">
                            <h5 class="text-sm font-black text-slate-800">{{ $type === 'weapon' ? '武器性能' : '防具性能' }}</h5>
                            <div class="mt-2 flex flex-wrap justify-center gap-2">
                                <template x-for="stat in detail?.stats ?? []" :key="stat">
                                    <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-bold text-slate-700" x-text="stat"></span>
                                </template>
                                <span x-show="(detail?.stats ?? []).length === 0" class="text-xs text-slate-500">能力補正なし</span>
                            </div>
                        </div>
                        <p class="mt-4 text-sm leading-relaxed text-slate-600" x-text="detail?.description"></p>
                    </div>
                </template>

                <template x-if="detail && !detail.discovered">
                    <div class="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm leading-relaxed text-slate-600">
                        この装備はまだ図鑑に記録されていません。探索や店、進化合成で手に入れると詳細が明らかになります。
                    </div>
                </template>
            </div>
        </div>
    </div>

    <style>
        .equipment-tree,
        .equipment-tree ul {
            position: relative;
            display: flex;
            justify-content: center;
            gap: .2rem;
            margin: 0;
            padding-top: 1rem;
            padding-left: 0;
        }

        .equipment-tree {
            padding-top: 0;
        }

        .equipment-tree li {
            position: relative;
            list-style: none;
            padding: 1rem .1rem 0;
            text-align: center;
        }

        .equipment-tree > li {
            padding-top: 0;
        }

        .equipment-tree li::before,
        .equipment-tree li::after {
            position: absolute;
            top: 0;
            right: 50%;
            width: 50%;
            height: 1rem;
            border-top: 2px solid rgba(251, 191, 36, .75);
            content: "";
        }

        .equipment-tree li::after {
            right: auto;
            left: 50%;
            border-left: 2px solid rgba(251, 191, 36, .75);
        }

        .equipment-tree li:only-child::before,
        .equipment-tree li:only-child::after,
        .equipment-tree > li::before,
        .equipment-tree > li::after {
            display: none;
        }

        .equipment-tree li:first-child::before,
        .equipment-tree li:last-child::after {
            border: 0;
        }

        .equipment-tree li:last-child::before {
            border-right: 2px solid rgba(251, 191, 36, .75);
            border-radius: 0 .75rem 0 0;
        }

        .equipment-tree li:first-child::after {
            border-radius: .75rem 0 0 0;
        }

        .equipment-tree ul::before {
            position: absolute;
            top: 0;
            left: 50%;
            height: 1rem;
            border-left: 2px solid rgba(251, 191, 36, .75);
            content: "";
        }
    </style>
</x-layouts.facility>
