@php
    $entries = $book['entries'] ?? [];
    $summary = $book['summary'] ?? [];
@endphp

<x-layouts.facility
    title="エネミー図鑑"
    headerIconImage="images/icon/icon_240.webp"
    bgImage="images/facilities/item.webp"
    :showExit="false"
    :showFacilityHeader="false"
    :compactHeader="true"
    :lockViewport="true"
    mainContentClass="flex min-h-0 flex-col overflow-hidden py-2 sm:py-3"
>
    <div
        data-enemy-book-shell
        class="flex min-h-0 w-full flex-1 flex-col overflow-hidden rounded-2xl bg-[#f7f9fc] pt-1"
        x-data="{
            filter: 'all',
            search: '',
            selected: @js($initialDetail),
            loading: false,
            detailBaseUrl: @js(url('/enemy-book')),
            applyFilters() {
                const query = this.search.trim().toLocaleLowerCase();
                this.$refs.grid?.querySelectorAll('[data-enemy-state]').forEach((card) => {
                    const filterMatches = this.filter === 'all' || this.filter === card.dataset.enemyState;
                    const searchMatches = query === '' || (card.dataset.enemySearch ?? '').includes(query);
                    card.hidden = !(filterMatches && searchMatches);
                });
            },
            format(value) {
                return Number(value ?? 0).toLocaleString('ja-JP');
            },
            statIcon(label) {
                return {
                    HP: '♥',
                    攻撃: '⚔',
                    防御: '◆',
                    魔力: '✦',
                    精神: '✚',
                    敏捷: '≫',
                    運: '♣',
                }[label] ?? '•';
            },
            async selectEnemy(id) {
                this.loading = true;
                try {
                    const response = await fetch(`${this.detailBaseUrl}/${id}`, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (!response.ok) throw new Error('detail request failed');
                    this.selected = await response.json();
                } catch (error) {
                    this.selected = {
                        id,
                        state: 'error',
                        name: '記録を読み込めませんでした',
                        message: '時間をおいて、もう一度選択してください。',
                        details_unlocked: false,
                        image_url: null,
                    };
                } finally {
                    this.loading = false;
                }
            },
        }"
    >
        <div class="flex h-[47%] min-h-0 shrink-0 flex-col gap-2">
            <section class="shrink-0 rounded-xl border border-[#d8a928]/55 bg-white px-3 py-2 shadow-sm sm:px-4">
                <div class="relative border-b border-[#d8a928]/20 pb-1.5">
                    <a href="{{ route('home') }}" class="absolute left-0 top-0 text-xs font-black text-slate-500 hover:text-amber-700">← 戻る</a>
                    <div class="mx-auto min-w-0 px-14 text-center">
                        <p class="text-[7px] font-black tracking-[0.24em] text-[#b78316]">ENEMY ARCHIVE</p>
                        <h2 class="truncate text-lg font-black leading-tight text-slate-950">エネミー図鑑</h2>
                        <p class="mt-0.5 truncate text-[10px] text-slate-500">冒険で出会った魔物の記録</p>
                    </div>
                </div>
                <div class="mt-1.5 grid grid-cols-3 gap-1.5 text-center text-[10px]">
                    <div class="rounded-md bg-emerald-50 px-1.5 py-1">
                        <span class="font-black text-emerald-900">{{ number_format((int) ($summary['defeated_count'] ?? 0)) }}</span>
                        <span class="ml-1 text-emerald-700">討伐</span>
                    </div>
                    <div class="rounded-md bg-sky-50 px-1.5 py-1">
                        <span class="font-black text-sky-900">{{ number_format((int) ($summary['encountered_count'] ?? 0)) }}</span>
                        <span class="ml-1 text-sky-700">発見</span>
                    </div>
                    <div class="rounded-md bg-[#f4f7fa] px-1.5 py-1">
                        <span class="font-black text-slate-900">{{ number_format((int) ($summary['total_count'] ?? 0)) }}</span>
                        <span class="ml-1 text-slate-500">総数</span>
                    </div>
                </div>
            </section>

            <section x-ref="detail" data-enemy-book-detail-pane class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-[#d8a928]/70 bg-[#fff9e8] shadow-md">
            <div class="shrink-0 px-3 pb-1 pt-2 sm:px-4">
                <div class="flex items-center justify-between gap-3 border-b border-[#d8a928]/20 pb-1.5">
                    <div>
                        <p class="text-[7px] font-black tracking-[0.24em] text-[#b78316]">ENEMY RECORD</p>
                    </div>
                    <span x-show="loading" x-cloak class="text-[10px] font-bold text-amber-700">確認中...</span>
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 pb-3 pt-1 sm:px-4">
                <template x-if="!selected">
                    <div class="py-5 text-center text-xs font-bold text-slate-500">下の一覧から魔物を選択してください。</div>
                </template>

                <template x-if="selected">
                    <div>
                        <div class="flex items-center gap-3">
                            <template x-if="selected.image_url">
                                <div class="relative flex h-40 w-40 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-white shadow-[0_8px_18px_rgba(132,91,19,0.14)] ring-1 ring-inset ring-[#d8a928]/45">
                                    <template x-if="selected.area_card_background_url">
                                        <img :src="selected.area_card_background_url" alt="" class="absolute inset-0 h-full w-full object-cover opacity-[0.14]">
                                    </template>
                                    <span class="absolute inset-2 rounded-full border border-[#ead8ad]"></span>
                                    <img :src="selected.image_url" :alt="selected.name" class="relative z-10 h-full w-full object-contain p-0.5 drop-shadow-[0_6px_4px_rgba(69,45,10,0.22)]">
                                </div>
                            </template>
                            <template x-if="!selected.image_url">
                                <div class="flex h-40 w-40 shrink-0 items-center justify-center rounded-2xl bg-[#eef2f7] text-6xl font-black text-slate-400 shadow-inner ring-1 ring-inset ring-slate-300/60">?</div>
                            </template>

                            <div class="min-w-0 flex-1">
                                <div class="inline-flex rounded-full px-2 py-0.5 text-[9px] font-black"
                                    :class="selected.state === 'defeated' ? 'bg-emerald-100 text-emerald-800' : (selected.state === 'encountered' ? 'bg-sky-100 text-sky-800' : 'bg-slate-200 text-slate-600')"
                                    x-text="selected.state === 'defeated' ? '討伐済み' : (selected.state === 'encountered' ? '発見済み・未討伐' : '未発見')"
                                ></div>
                                <h4 class="mt-1 break-words text-2xl font-black leading-tight tracking-tight text-slate-950" x-text="selected.name"></h4>
                                <div x-show="selected.details_unlocked" x-cloak class="mt-1 space-y-0.5 text-[10px] font-bold text-slate-600">
                                    <p><span class="text-emerald-700">◆</span> <span x-text="selected.element"></span><span class="px-1 text-slate-300">/</span><span x-text="selected.species"></span></p>
                                    <p class="truncate text-slate-500"><span class="text-[#a27b42]">●</span> <span x-text="selected.area_name"></span></p>
                                </div>
                                <div x-show="selected.state === 'defeated'" x-cloak class="mt-1 inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-black text-emerald-800">
                                    討伐回数 <span class="ml-1" x-text="format(selected.defeat_count)"></span>回
                                </div>
                            </div>
                        </div>

                        <p x-show="!selected.details_unlocked" x-cloak class="mt-4 px-2 text-center text-[10px] font-bold leading-relaxed text-slate-500" x-text="selected.message"></p>

                        <template x-if="selected.details_unlocked">
                            <div class="mt-2 space-y-2.5">
                                <div>
                                    <h5 class="text-[10px] font-black tracking-wide text-slate-600">基本能力</h5>
                                    <div class="mt-1 grid grid-cols-7 gap-1">
                                        <template x-for="(stat, index) in selected.stats ?? []" :key="stat.label">
                                            <div class="min-w-0 rounded-md bg-white/85 px-0.5 py-1 text-center shadow-sm ring-1 ring-inset ring-[#ead8ad]">
                                                <div class="flex items-center justify-center gap-0.5 truncate text-[7px] font-black text-slate-600">
                                                    <span class="text-[9px] leading-none"
                                                        :class="{
                                                            'text-rose-500': stat.label === 'HP',
                                                            'text-orange-500': stat.label === '攻撃',
                                                            'text-sky-600': stat.label === '防御',
                                                            'text-violet-500': stat.label === '魔力',
                                                            'text-teal-500': stat.label === '精神',
                                                            'text-amber-500': stat.label === '敏捷',
                                                            'text-lime-600': stat.label === '運',
                                                        }"
                                                        x-text="statIcon(stat.label)"></span>
                                                    <span class="truncate" x-text="stat.label"></span>
                                                </div>
                                                <div class="truncate text-xs font-black leading-tight text-slate-950" x-text="format(stat.value)"></div>
                                            </div>
                                        </template>
                                    </div>
                                    <p class="mt-0.5 text-[9px] leading-tight text-slate-500">危険度や特殊な戦場では、実戦時の能力が変化します。</p>
                                </div>

                                <div class="min-w-0 rounded-lg bg-white/70 p-2 shadow-sm ring-1 ring-inset ring-[#ead8ad]">
                                    <h5 class="flex items-center gap-1 border-b border-[#ead8ad] pb-1 text-[10px] font-black tracking-wide text-slate-700">
                                        <span class="text-[#b78316]">◆</span>ドロップ記録
                                    </h5>
                                    <div x-show="(selected.drops ?? []).length > 0" class="mt-1 grid grid-cols-2 gap-x-3 gap-y-1">
                                        <template x-for="drop in selected.drops ?? []" :key="drop.key">
                                            <template x-if="drop.item_book_url">
                                                <a :href="drop.item_book_url" :aria-label="`${drop.name}をアイテム図鑑で確認`" class="group flex min-w-0 items-center gap-1.5 rounded-sm py-0.5 transition hover:bg-amber-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-300">
                                                    <template x-if="drop.image_url">
                                                        <img :src="drop.image_url" alt="" class="h-5 w-5 shrink-0 object-contain">
                                                    </template>
                                                    <span class="line-clamp-1 text-[10px] font-black text-slate-800 underline decoration-[#d8a928] decoration-1 underline-offset-2 group-hover:text-[#a26b12]" x-text="drop.name"></span>
                                                </a>
                                            </template>
                                            <template x-if="!drop.item_book_url">
                                                <div class="flex min-w-0 items-center gap-1.5 py-0.5">
                                                    <template x-if="drop.image_url">
                                                        <img :src="drop.image_url" alt="" class="h-5 w-5 shrink-0 object-contain">
                                                    </template>
                                                    <span class="line-clamp-1 text-[10px] font-black text-slate-800" x-text="drop.name"></span>
                                                </div>
                                            </template>
                                        </template>
                                    </div>
                                    <p x-show="(selected.drops ?? []).length === 0" class="mt-1 text-[10px] text-slate-500">固有ドロップはありません。</p>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
            </section>
        </div>

        <div class="mt-2 flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-[#dde4ec] bg-[#f4f7fa] p-2 shadow-sm">
            <section class="shrink-0">
                <div class="grid grid-cols-4 gap-1 rounded-lg bg-slate-100 p-1 text-[10px] font-black sm:text-xs">
                    <button type="button" @click="filter = 'all'; applyFilters()" :class="filter === 'all' ? 'bg-slate-900 text-white shadow' : 'text-slate-600'" class="rounded-md px-1 py-1.5">すべて</button>
                    <button type="button" @click="filter = 'defeated'; applyFilters()" :class="filter === 'defeated' ? 'bg-emerald-700 text-white shadow' : 'text-slate-600'" class="rounded-md px-1 py-1.5">討伐済み</button>
                    <button type="button" @click="filter = 'encountered'; applyFilters()" :class="filter === 'encountered' ? 'bg-sky-700 text-white shadow' : 'text-slate-600'" class="rounded-md px-1 py-1.5">未討伐</button>
                    <button type="button" @click="filter = 'undiscovered'; applyFilters()" :class="filter === 'undiscovered' ? 'bg-slate-600 text-white shadow' : 'text-slate-600'" class="rounded-md px-1 py-1.5">未発見</button>
                </div>
                <label class="mt-1.5 block">
                    <span class="sr-only">発見済みの敵名を検索</span>
                    <input :value="search" @input.debounce.150ms="search = $event.target.value; applyFilters()" type="search" placeholder="敵名を検索" class="w-full rounded-md border-0 bg-white px-2.5 py-1.5 text-xs font-bold text-slate-800 shadow-sm ring-1 ring-inset ring-[#dde4ec] placeholder:text-slate-400">
                </label>
            </section>

            <section data-enemy-book-list-pane class="mt-2 min-h-0 flex-1 overflow-y-auto overscroll-contain pr-1">
            <div x-ref="grid" data-enemy-book-grid class="grid grid-cols-2 gap-1.5 sm:gap-2">
                @foreach($entries as $entry)
                    <button
                        type="button"
                        data-enemy-book-card
                        data-enemy-state="{{ $entry['state'] }}"
                        data-enemy-search="{{ $entry['search_name'] }}"
                        @click="selectEnemy({{ $entry['id'] }})"
                        :class="selected?.id === {{ $entry['id'] }} ? 'border-2 border-[#c58b19] bg-[#fff4d7] shadow-md' : 'border-[#d8dee8] bg-white'"
                        class="relative flex min-h-[4.25rem] min-w-0 overflow-hidden rounded-lg border text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                    >
                        <span x-show="selected?.id === {{ $entry['id'] }}" x-cloak class="absolute inset-y-1 left-0 z-10 w-1 rounded-r-full bg-[#d8a928]"></span>
                        <span x-show="selected?.id === {{ $entry['id'] }}" x-cloak class="absolute right-1 top-1 z-10 rounded-full bg-[#b78316] px-1.5 py-0.5 text-[7px] font-black text-white">選択中</span>
                        <div class="relative h-[4.25rem] w-16 shrink-0 {{ $entry['state'] === 'undiscovered' ? 'bg-[#e9eef4]' : 'bg-gradient-to-br from-amber-50 to-white' }}">
                            @if($entry['image_url'])
                                <img src="{{ $entry['image_url'] }}" alt="{{ $entry['name'] }}" loading="lazy" decoding="async" class="h-full w-full object-contain p-1.5">
                            @else
                                <div class="flex h-full w-full items-center justify-center text-2xl font-black text-slate-400">?</div>
                            @endif
                            @if($entry['is_boss'] && $entry['state'] !== 'undiscovered')
                                <span class="absolute bottom-1 left-1 rounded-full bg-rose-700 px-1.5 py-0.5 text-[8px] font-black text-white">ボス</span>
                            @endif
                        </div>
                        <div class="flex min-w-0 flex-1 flex-col justify-center px-2 py-1">
                            <div class="line-clamp-2 break-words text-sm font-black leading-tight text-slate-950">{{ $entry['name'] }}</div>
                            <div class="mt-0.5 truncate text-[7px] font-black {{ $entry['state'] === 'defeated' ? 'text-emerald-700' : ($entry['state'] === 'encountered' ? 'text-sky-700' : 'text-slate-500') }}">{{ $entry['state_label'] }}</div>
                            <div class="mt-0.5 text-[6px] font-bold tracking-wide text-slate-300">No.{{ str_pad((string) $entry['number'], 3, '0', STR_PAD_LEFT) }}</div>
                        </div>
                    </button>
                @endforeach
            </div>
            </section>
        </div>
    </div>
</x-layouts.facility>
