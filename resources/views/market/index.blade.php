@php
    $tabs = [
        'demand'   => '需要板',
        'buy'      => '買う',
        'sell'     => '売る',
        'listings' => '出品中',
        'history'  => '履歴',
    ];
    $statusLabels = [
        'active' => '出品中',
        'sold_out' => '完売',
        'cancelled' => '取消済み',
        'expired' => '期限切れ',
    ];
@endphp

<x-layouts.facility title="冒険者市場" headerIconImage="images/icon/icon_032.webp" bgImage="images/facilities/item.webp">
    <div class="w-full mx-auto pb-10">
        <div class="mb-2 flex justify-end px-1 text-sm font-black text-slate-950 sm:text-base">
            所持：{{ number_format((int) ($character->money ?? 0)) }}G
        </div>

        <div class="rounded-lg border border-[#d4af37]/50 bg-white p-4 shadow-sm sm:p-6" x-data="{ tab: '{{ $tab }}' }">
            <div class="mb-5">
                <div>
                    <div class="text-xs font-black tracking-wide text-amber-700">ADVENTURER MARKET</div>
                    <h2 class="mt-1 text-2xl font-black text-slate-900">素材を売買できます</h2>
                    <p class="mt-1 text-sm font-bold leading-relaxed text-slate-500">
                        通常素材と地域素材を、冒険者同士で匿名売買します。売る場合、出品手数料として3%引かれます。
                    </p>
                </div>
            </div>

            @if(session('status'))
                <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif
            @if(session('error'))
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
                    {{ session('error') }}
                </div>
            @endif
            @if($errors->any())
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
                    {{ $errors->first() }}
                </div>
            @endif

            @if(session('market_result'))
                @php
                    $marketResult = session('market_result');
                @endphp
                <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-xs font-bold text-blue-800">
                    <div class="mb-1 text-sm font-black">購入内訳</div>
                    @foreach($marketResult['lines'] ?? [] as $line)
                        <div>{{ number_format($line['unit_price']) }}G x {{ number_format($line['quantity']) }} = {{ number_format($line['total_price']) }}G</div>
                    @endforeach
                </div>
            @endif

            <div class="mb-5 grid grid-cols-5 gap-1 rounded-lg bg-slate-100 p-1">
                @foreach($tabs as $key => $label)
                    <button type="button"
                            @click="tab = '{{ $key }}'"
                            :class="tab === '{{ $key }}' ? 'bg-white text-amber-700 shadow-sm' : 'text-slate-500 hover:bg-white/70'"
                            class="rounded-md px-1 py-2.5 text-center text-xs font-black transition">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <div x-show="tab === 'demand'" @if($tab !== 'demand') style="display:none" @endif>
                @include('market.partials.demand-board', ['demandItems' => $demandItems])
            </div>

            <div x-show="tab === 'buy'" @if($tab !== 'buy') style="display:none" @endif>
                <div class="space-y-2">
                    @forelse($materials as $material)
                        @php
                            $stats = $marketStats[$material->id] ?? null;
                            $stock = (int) ($stats->stock ?? 0);
                            $lowestPrice = (int) ($stats->lowest_price ?? 0);
                            $owned = (int) ($ownedByMaterial[$material->id] ?? 0);
                            $maxQty = max(1, $stock);
                            $info = $materialInfos[$material->id] ?? null;
                        @endphp
                        <div class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 shadow-sm">
                            <div class="flex min-w-0 items-center gap-1.5">
                                <span class="min-w-0 flex-1 truncate text-sm font-black text-slate-900">{{ $material->displayName() }}</span>
                                <span class="shrink-0 text-xs font-bold text-slate-400">所持{{ number_format($owned) }}個</span>
                                <span class="shrink-0 text-xs font-bold text-slate-500">在庫{{ number_format($stock) }}</span>
                                <span class="shrink-0 text-xs font-bold {{ $lowestPrice > 0 ? 'text-amber-600' : 'text-slate-400' }}">最安{{ $lowestPrice > 0 ? number_format($lowestPrice).'G' : '-' }}</span>
                                <div class="relative shrink-0" x-data="{ tip: false }">
                                    <button type="button" @click="tip = !tip"
                                            class="flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-[10px] font-black text-slate-500 transition hover:bg-slate-200">?</button>
                                    <div x-show="tip" @click.outside="tip = false"
                                         class="absolute right-0 top-6 z-20 w-44 rounded-lg border border-slate-200 bg-white p-2.5 shadow-xl text-[11px] font-bold text-slate-500"
                                         style="display: none;">
                                        <div>相場：{{ number_format($material->marketMinPrice()) }}〜{{ number_format($material->marketMaxPrice()) }}G</div>
                                    </div>
                                </div>
                            </div>
                            @if($info)
                                <x-market.partials.material-meta :info="$info" compact class="mt-2 border-t border-slate-100 pt-2" />
                            @endif
                            <form method="POST" action="{{ route('market.materials.buy') }}"
                                  class="mt-2 flex gap-1.5"
                                  x-data="{ qty: 1, submitting: false }"
                                  @submit="submitting = true">
                                @csrf
                                <input type="hidden" name="material_id" value="{{ $material->id }}">
                                <input type="hidden" name="quantity" x-model="qty">
                                <div class="flex overflow-hidden rounded-md border border-slate-300 {{ $stock <= 0 ? 'opacity-50' : '' }}">
                                    <button type="button"
                                            class="flex h-9 w-9 items-center justify-center bg-slate-50 text-base font-black text-slate-600 transition hover:bg-slate-100 active:scale-95 disabled:opacity-40"
                                            @click="qty = Math.max(1, qty - 1)"
                                            :disabled="qty <= 1 || {{ $stock <= 0 ? 'true' : 'false' }}">−</button>
                                    <input type="number" x-model.number="qty" min="1" max="{{ $maxQty }}"
                                           class="w-14 border-x border-slate-300 text-center text-sm font-bold focus:ring-0 focus:border-amber-400"
                                        {{ $stock <= 0 ? 'disabled' : '' }}>
                                    <button type="button"
                                            class="flex h-9 w-9 items-center justify-center bg-slate-50 text-base font-black text-slate-600 transition hover:bg-slate-100 active:scale-95 disabled:opacity-40"
                                            @click="qty = Math.min({{ $maxQty }}, qty + 1)"
                                            :disabled="qty >= {{ $maxQty }} || {{ $stock <= 0 ? 'true' : 'false' }}">＋</button>
                                </div>
                                <a href="{{ route('market.materials.show', $material) }}" wire:navigate
                                   class="inline-flex w-14 items-center justify-center rounded-md border border-slate-200 bg-white px-2 py-2 text-xs font-black text-slate-600 shadow-sm transition hover:bg-slate-50">
                                    詳細
                                </a>
                                <button type="submit"
                                        class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-md bg-amber-600 px-3 py-2 text-sm font-black text-white shadow-sm transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                                        :disabled="submitting || {{ $stock <= 0 ? 'true' : 'false' }}">
                                    <x-loading-spinner x-show="submitting" style="display: none;" size="h-4 w-4" />
                                    <span x-text="submitting ? '購入中' : '購入'">購入</span>
                                </button>
                            </form>
                        </div>
                    @empty
                        <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm font-bold text-slate-500">
                            市場対象の素材がありません。
                        </div>
                    @endforelse
                </div>
            </div>

            <div x-show="tab === 'sell'" @if($tab !== 'sell') style="display:none" @endif>
                <div class="space-y-2">
                    @forelse($ownedMaterials as $row)
                        @php
                            $material = $row->material;
                            $isSelected = (int) $selectedMaterialId === (int) $material->id;
                            $stats = $marketStats[$material->id] ?? null;
                            $lowestPrice = (int) ($stats->lowest_price ?? 0);
                            $suggestedPrice = $lowestPrice > 0 ? $lowestPrice : $material->marketMinPrice();
                            $npcPrice = (int) ($material->npc_sell_price ?? $material->npc_sale_price ?? 0);
                            $maxQty = max(1, (int) $row->quantity);
                            $priceRange = $material->marketMaxPrice() - $material->marketMinPrice();
                            $priceStep = $priceRange <= 100 ? 1 : ($priceRange <= 500 ? 5 : ($priceRange <= 2000 ? 10 : 50));
                            $info = $ownedMaterialInfos[$material->id] ?? $materialInfos[$material->id] ?? null;
                        @endphp
                        <div class="rounded-lg border px-3 py-2.5 shadow-sm {{ $isSelected ? 'border-amber-400 bg-amber-50/60 ring-2 ring-amber-100' : 'border-slate-200 bg-white' }}">
                            <div class="flex min-w-0 items-center gap-1.5">
                                <span class="min-w-0 flex-1 truncate text-sm font-black text-slate-900">{{ $material->displayName() }}</span>
                                <span class="shrink-0 text-xs font-bold text-slate-400">所持{{ number_format((int) $row->quantity) }}個</span>
                                <span class="shrink-0 text-xs font-bold text-slate-500">NPC {{ number_format($npcPrice) }}G</span>
                                <div class="relative shrink-0" x-data="{ tip: false }">
                                    <button type="button" @click="tip = !tip"
                                            class="flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-[10px] font-black text-slate-500 transition hover:bg-slate-200">?</button>
                                    <div x-show="tip" @click.outside="tip = false"
                                         class="absolute right-0 top-6 z-20 w-44 space-y-1 rounded-lg border border-slate-200 bg-white p-2.5 shadow-xl text-[11px] font-bold text-slate-500"
                                         style="display: none;">
                                        <div>範囲：{{ number_format($material->marketMinPrice()) }}〜{{ number_format($material->marketMaxPrice()) }}G</div>
                                        @if($lowestPrice > 0)
                                            <div>最安：<span class="text-amber-600">{{ number_format($lowestPrice) }}G</span></div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            @if($info)
                                <x-market.partials.material-meta :info="$info" compact class="mt-2 border-t border-slate-100 pt-2" />
                                <div class="mt-2 rounded-md border border-amber-100 bg-amber-50 px-2.5 py-1.5 text-[11px] font-bold leading-relaxed text-amber-800">
                                    {{ $info['market_hint'] }}
                                </div>
                            @endif
                            <form method="POST" action="{{ route('market.materials.list') }}"
                                  class="mt-2 flex items-center gap-1.5"
                                  x-data="{
                                      qty: 1,
                                      maxQty: {{ $maxQty }},
                                      price: {{ $suggestedPrice }},
                                      submitting: false,
                                      normalizeQty() {
                                          this.qty = Math.min(this.maxQty, Math.max(1, parseInt(this.qty || 1, 10)));
                                      }
                                  }"
                                  @submit="normalizeQty(); submitting = true">
                                @csrf
                                <input type="hidden" name="material_id" value="{{ $material->id }}">
                                <div class="flex h-8 shrink-0 overflow-hidden rounded border border-slate-300">
                                    <button type="button"
                                            class="flex w-7 items-center justify-center bg-slate-50 text-sm font-black text-slate-600 transition hover:bg-slate-100 active:scale-95 disabled:opacity-40"
                                            @click="normalizeQty(); qty = Math.max(1, qty - 1)"
                                            :disabled="qty <= 1">−</button>
                                    <input type="number" name="quantity"
                                           x-model.number="qty"
                                           min="1"
                                           max="{{ $maxQty }}"
                                           required
                                           @blur="normalizeQty()"
                                           class="w-12 border-x border-slate-300 text-center text-sm font-bold text-slate-900 focus:border-amber-400 focus:ring-0 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none">
                                    <button type="button"
                                            class="flex w-7 items-center justify-center bg-slate-50 text-sm font-black text-slate-600 transition hover:bg-slate-100 active:scale-95 disabled:opacity-40"
                                            @click="normalizeQty(); qty = Math.min(maxQty, qty + 1)"
                                            :disabled="qty >= maxQty">＋</button>
                                </div>
                                {{-- 単価ステッパー（直接入力も可） --}}
                                <div class="flex h-8 flex-1 overflow-hidden rounded border border-slate-300">
                                    <button type="button"
                                            class="flex w-7 shrink-0 items-center justify-center bg-slate-50 text-sm font-black text-slate-600 transition hover:bg-slate-100 active:scale-95 disabled:opacity-40"
                                            @click="price = Math.max({{ $material->marketMinPrice() }}, price - {{ $priceStep }})"
                                            :disabled="price <= {{ $material->marketMinPrice() }}">−</button>
                                    <div class="flex flex-1 items-center border-x border-slate-300 px-1">
                                        <input type="number" name="unit_price"
                                               x-model.number="price"
                                               min="{{ $material->marketMinPrice() }}"
                                               max="{{ $material->marketMaxPrice() }}"
                                               class="w-full border-0 bg-transparent p-0 text-center text-sm font-bold text-slate-900 focus:ring-0 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none">
                                        <span class="shrink-0 text-[10px] font-bold text-slate-400">G</span>
                                    </div>
                                    <button type="button"
                                            class="flex w-7 shrink-0 items-center justify-center bg-slate-50 text-sm font-black text-slate-600 transition hover:bg-slate-100 active:scale-95 disabled:opacity-40"
                                            @click="price = Math.min({{ $material->marketMaxPrice() }}, price + {{ $priceStep }})"
                                            :disabled="price >= {{ $material->marketMaxPrice() }}">＋</button>
                                </div>
                                {{-- 出品ボタン --}}
                                <button type="submit"
                                        class="inline-flex h-8 shrink-0 items-center justify-center gap-1 rounded bg-emerald-600 px-3 text-sm font-black text-white shadow-sm transition hover:bg-emerald-700 disabled:cursor-wait disabled:opacity-70"
                                        :disabled="submitting">
                                    <x-loading-spinner x-show="submitting" style="display: none;" size="h-3.5 w-3.5" />
                                    <span x-text="submitting ? '出品中' : '出品'">出品</span>
                                </button>
                            </form>
                            <div class="mt-2 text-right">
                                <a href="{{ route('market.materials.show', $material) }}" wire:navigate class="text-xs font-black text-slate-500 underline decoration-slate-300 underline-offset-2 hover:text-amber-700">
                                    素材詳細を見る
                                </a>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm font-bold text-slate-500">
                            出品できる素材を所持していません。
                        </div>
                    @endforelse
                </div>
            </div>

            <div x-show="tab === 'listings'" @if($tab !== 'listings') style="display:none" @endif>
                <div class="space-y-3">
                    @forelse($ownListings as $listing)
                        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <div class="truncate text-base font-black text-slate-900">{{ $listing->material?->displayName() ?? '不明な素材' }}</div>
                                    <div class="mt-1 text-xs font-bold text-slate-500">
                                        残り {{ number_format((int) $listing->remaining_quantity) }} / {{ number_format((int) $listing->quantity) }} 個
                                        ・単価 {{ number_format((int) $listing->unit_price) }}G
                                    </div>
                                    <div class="mt-1 text-xs font-bold text-slate-400">
                                        期限 {{ $listing->expires_at ? $listing->expires_at->format('m/d H:i') : '-' }}
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="rounded bg-slate-100 px-2 py-1 text-xs font-black text-slate-600">
                                        {{ $statusLabels[$listing->status] ?? $listing->status }}
                                    </span>
                                    @if($listing->status === 'active' && (int) $listing->remaining_quantity > 0)
                                        <form method="POST" action="{{ route('market.listings.cancel', $listing) }}" x-data="{ submitting: false }" @submit="submitting = true">
                                            @csrf
                                            <button type="submit" :disabled="submitting" class="rounded-md border border-red-200 bg-white px-3 py-1.5 text-xs font-black text-red-700 transition hover:bg-red-50 disabled:cursor-wait disabled:opacity-60">
                                                <span x-text="submitting ? '処理中' : '取消'">取消</span>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm font-bold text-slate-500">
                            自分の出品はありません。
                        </div>
                    @endforelse
                </div>
            </div>

            <div x-show="tab === 'history'" @if($tab !== 'history') style="display:none" @endif>
                <div class="space-y-3">
                    @forelse($history as $transaction)
                        @php
                            $isBuyer = (int) $transaction->buyer_character_id === (int) $character->id;
                            $sellerName = ($transaction->seller_type ?? 'character') === 'npc'
                                ? ($transaction->sellerNpc?->npc_name ?? '旅の冒険者')
                                : ($transaction->seller?->name ?? '冒険者');
                        @endphp
                        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-sm font-black {{ $isBuyer ? 'text-blue-700' : 'text-emerald-700' }}">
                                        {{ $isBuyer ? '購入' : '売却' }}: {{ $transaction->material?->displayName() ?? '不明な素材' }} x{{ number_format((int) $transaction->quantity) }}
                                    </div>
                                    <div class="mt-1 text-xs font-bold text-slate-500">
                                        単価 {{ number_format((int) $transaction->unit_price) }}G / 合計 {{ number_format((int) $transaction->total_price) }}G
                                        @if($isBuyer)
                                            / 出品者 {{ $sellerName }}
                                        @endif
                                        @if(! $isBuyer)
                                            / 手数料 {{ number_format((int) $transaction->sale_fee) }}G / 受取 {{ number_format((int) $transaction->seller_received) }}G
                                        @endif
                                    </div>
                                </div>
                                <div class="shrink-0 text-xs font-bold text-slate-400">
                                    {{ $transaction->created_at?->format('m/d H:i') }}
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm font-bold text-slate-500">
                            市場の取引履歴はまだありません。
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-layouts.facility>
