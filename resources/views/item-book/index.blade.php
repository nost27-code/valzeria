@php
    $materials = collect($book['materials'] ?? []);
    $summary = $book['summary'] ?? [];
    $filters = $book['filters'] ?? [];
@endphp

<x-layouts.facility title="アイテム図鑑" headerIconImage="images/icon/icon_241.webp" bgImage="images/facilities/item.webp">
    <div
        class="w-full mx-auto pb-10"
        x-data="{
            category: 'all',
            ownership: 'all',
            search: '',
            jumpToMaterial(anchorId) {
                this.category = 'all';
                this.ownership = 'all';
                this.search = '';
                this.$nextTick(() => {
                    const target = document.getElementById(anchorId);
                    if (!target) {
                        return;
                    }

                    target.dispatchEvent(new CustomEvent('item-book-open'));
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            },
        }"
    >
        <div class="mb-4 grid grid-cols-3 gap-2">
            <div class="rounded-lg border border-[#d4af37]/30 bg-white px-3 py-2.5">
                <div class="text-[10px] font-black tracking-wide text-slate-400">素材数</div>
                <div class="mt-1 text-lg font-black text-slate-900">{{ number_format($summary['total_count'] ?? 0) }}</div>
            </div>
            <div class="rounded-lg border border-[#d4af37]/30 bg-white px-3 py-2.5">
                <div class="text-[10px] font-black tracking-wide text-slate-400">所持済み</div>
                <div class="mt-1 text-lg font-black text-[#9c7a19]">{{ number_format($summary['owned_count'] ?? 0) }}</div>
            </div>
            <div class="rounded-lg border border-[#d4af37]/30 bg-white px-3 py-2.5">
                <div class="text-[10px] font-black tracking-wide text-slate-400">作り方あり</div>
                <div class="mt-1 text-lg font-black text-slate-900">{{ number_format($summary['craftable_count'] ?? 0) }}</div>
            </div>
        </div>

        <div class="mb-4 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <div class="flex items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 focus-within:border-[#d4af37]/60">
                <span class="text-sm text-slate-400">🔍</span>
                <input
                    type="search"
                    x-model.debounce.150ms="search"
                    placeholder="素材名・用途・作り方で検索"
                    class="min-w-0 flex-1 border-0 bg-transparent text-sm font-bold text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-0"
                >
                <button type="button" x-show="search !== ''" @click="search = ''" class="rounded bg-white px-2 py-1 text-xs font-bold text-slate-500">
                    クリア
                </button>
            </div>

            <div class="mt-3 flex flex-wrap gap-1.5">
                <button type="button" @click="category = 'all'" :class="category === 'all' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'" class="rounded-full px-3 py-1.5 text-xs font-black transition">全て</button>
                @foreach($filters as $key => $filter)
                    <button type="button" @click="category = @js($key)" :class="category === @js($key) ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'" class="rounded-full px-3 py-1.5 text-xs font-black transition">
                        {{ $filter['label'] }} {{ number_format($filter['count']) }}
                    </button>
                @endforeach
            </div>

            <div class="mt-3 grid grid-cols-3 gap-2">
                <button type="button" @click="ownership = 'all'" :class="ownership === 'all' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'" class="rounded-lg px-2 py-2 text-xs font-black transition">全素材</button>
                <button type="button" @click="ownership = 'owned'" :class="ownership === 'owned' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'" class="rounded-lg px-2 py-2 text-xs font-black transition">所持済み</button>
                <button type="button" @click="ownership = 'unowned'" :class="ownership === 'unowned' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'" class="rounded-lg px-2 py-2 text-xs font-black transition">未所持</button>
            </div>
        </div>

        <div class="space-y-3">
            @foreach($materials as $entry)
                @php
                    $searchText = $entry['search_text'] ?? '';
                    $ownedQuantity = (int) ($entry['owned_quantity'] ?? 0);
                    $craftRecipes = $entry['craft_recipes'] ?? [];
                    $dropSources = $entry['drop_sources'] ?? [];
                @endphp
                <article
                    id="{{ $entry['anchor_id'] }}"
                    data-item-book-card
                    data-category="{{ $entry['category_key'] }}"
                    data-owned="{{ $ownedQuantity > 0 ? '1' : '0' }}"
                    data-search="{{ e($searchText) }}"
                    x-data="{ open: false, init() { this.$el.addEventListener('item-book-open', () => { this.open = true; }); } }"
                    x-show="(category === 'all' || category === $el.dataset.category) && (ownership === 'all' || (ownership === 'owned' && $el.dataset.owned === '1') || (ownership === 'unowned' && $el.dataset.owned === '0')) && (!search.trim() || $el.dataset.search.toLowerCase().includes(search.trim().toLowerCase()))"
                    class="scroll-mt-24 rounded-xl border {{ $ownedQuantity > 0 ? 'border-[#d4af37]/50 bg-white' : 'border-slate-200 bg-slate-50/60' }} shadow-sm transition"
                >
                    <button
                        type="button"
                        @click="open = !open"
                        class="flex w-full items-center gap-3 p-3 text-left transition hover:bg-slate-50/80"
                        :aria-expanded="open.toString()"
                    >
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white p-1">
                            @if(!empty($entry['icon_image']))
                                <img src="{{ asset($entry['icon_image']) }}" alt="" class="h-full w-full object-contain">
                            @else
                                <span class="text-lg text-slate-300">◇</span>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-base font-black leading-tight text-slate-900">{{ $entry['name'] }}</h2>
                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <span class="rounded bg-slate-800 px-1.5 py-0.5 text-[10px] font-black leading-none text-white">{{ $entry['category'] }}</span>
                                <span class="rounded border border-[#d4af37]/50 bg-[#fdf8ec] px-1.5 py-0.5 text-[10px] font-black leading-none text-[#9c7a19]">{{ $entry['rarity'] ?: '-' }}</span>
                                @if($ownedQuantity > 0)
                                    <span class="rounded border border-[#d4af37]/50 bg-[#fdf8ec] px-1.5 py-0.5 text-[10px] font-black leading-none text-[#9c7a19]">所持 {{ number_format($ownedQuantity) }}</span>
                                @else
                                    <span class="rounded border border-slate-200 bg-slate-100 px-1.5 py-0.5 text-[10px] font-black leading-none text-slate-400">未所持</span>
                                @endif
                                @if(count($craftRecipes) > 0)
                                    <span class="rounded border border-slate-200 px-1.5 py-0.5 text-[10px] font-black leading-none text-slate-500">作成可</span>
                                @endif
                                @if(count($dropSources) > 0)
                                    <span class="rounded border border-slate-200 px-1.5 py-0.5 text-[10px] font-black leading-none text-slate-500">敵ドロップ</span>
                                @endif
                            </div>
                        </div>
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-sm font-black text-slate-400">
                            <span x-show="!open">＋</span>
                            <span x-show="open">−</span>
                        </div>
                    </button>

                    <div x-show="open" class="border-t border-slate-100 p-3">
                        @if(!empty($entry['main_use']))
                            <p class="mb-3 rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2 text-xs font-bold leading-relaxed text-slate-600">{{ $entry['main_use'] }}</p>
                        @endif

                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-2.5">
                        <section class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                            <h3 class="flex items-center gap-1 border-b border-[#d4af37]/30 pb-1.5 text-[10px] font-black tracking-wide text-[#9c7a19]">
                                <span aria-hidden="true">📥</span>入手方法
                            </h3>
                            <ul class="mt-1.5 space-y-1 text-xs font-bold leading-relaxed text-slate-700">
                                @foreach($entry['obtain_notes'] as $note)
                                    <li>{{ $note }}</li>
                                @endforeach
                            </ul>
                        </section>

                        <section class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                            <h3 class="flex items-center gap-1 border-b border-[#d4af37]/30 pb-1.5 text-[10px] font-black tracking-wide text-[#9c7a19]">
                                <span aria-hidden="true">🔨</span>作り方
                            </h3>
                            @if(count($craftRecipes) > 0)
                                <div class="mt-1.5 space-y-2">
                                    @foreach($craftRecipes as $recipe)
                                        <div class="rounded border border-slate-200 bg-slate-50/60 px-2 py-1.5">
                                            <div class="flex items-center justify-between gap-2 text-xs font-black text-slate-800">
                                                <span>{{ $recipe['label'] }}</span>
                                                <span class="text-[#9c7a19]">+{{ number_format($recipe['target_quantity']) }}</span>
                                            </div>
                                            <div class="mt-1 space-y-0.5">
                                                @foreach($recipe['sources'] as $source)
                                                    <div class="flex items-center justify-between gap-2 text-[11px] font-bold">
                                                        <span class="min-w-0 inline-flex items-center gap-1 text-slate-600">
                                                            @if(!empty($source['icon_image']))
                                                                <img src="{{ asset($source['icon_image']) }}" alt="" class="h-4 w-4 shrink-0 object-contain">
                                                            @endif
                                                            @if(!empty($source['anchor_id']))
                                                                <button
                                                                    type="button"
                                                                    @click.stop="jumpToMaterial(@js($source['anchor_id']))"
                                                                    class="min-w-0 truncate text-left font-black text-slate-700 underline decoration-[#d4af37] decoration-2 underline-offset-2"
                                                                >{{ $source['name'] }}</button>
                                                            @else
                                                                <span class="truncate">{{ $source['name'] }}</span>
                                                            @endif
                                                        </span>
                                                        <span class="shrink-0 font-mono {{ $source['owned'] >= $source['required'] ? 'text-emerald-700' : 'text-red-500' }}">
                                                            {{ number_format($source['owned']) }} / {{ number_format($source['required']) }}
                                                        </span>
                                                    </div>
                                                @endforeach
                                            </div>
                                            @if($recipe['gold_cost'] > 0)
                                                <div class="mt-1 text-[11px] font-black text-[#9c7a19]">費用 {{ number_format($recipe['gold_cost']) }}G</div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="mt-1.5 text-xs font-bold text-slate-400">素材交換所で作るレシピはありません。</p>
                            @endif
                        </section>

                        <section class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                            <h3 class="flex items-center gap-1 border-b border-[#d4af37]/30 pb-1.5 text-[10px] font-black tracking-wide text-[#9c7a19]">
                                <span aria-hidden="true">⚔️</span>用途
                            </h3>
                            <ul class="mt-1.5 space-y-1 text-xs font-bold leading-relaxed text-slate-700">
                                @foreach(($entry['linked_usage_notes'] ?? []) as $note)
                                    <li>
                                        @foreach(($note['segments'] ?? []) as $segment)
                                            @if(!empty($segment['anchor_id']))
                                                <button
                                                    type="button"
                                                    @click.stop="jumpToMaterial(@js($segment['anchor_id']))"
                                                    class="font-black text-slate-700 underline decoration-[#d4af37] decoration-2 underline-offset-2"
                                                >{{ $segment['text'] }}</button>
                                            @else
                                                {{ $segment['text'] }}
                                            @endif
                                        @endforeach
                                    </li>
                                @endforeach
                            </ul>
                            @if(count($dropSources) > 0)
                                <div class="mt-3 border-t border-slate-100 pt-2">
                                    <div class="text-[10px] font-black tracking-wide text-slate-400">主なドロップ</div>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @foreach($dropSources as $source)
                                            <span class="rounded bg-slate-50 px-1.5 py-0.5 text-[10px] font-bold text-slate-600">
                                                {{ $source['area_name'] }} / {{ $source['enemy_name'] }}
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </section>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</x-layouts.facility>
