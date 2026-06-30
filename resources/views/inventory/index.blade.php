<x-layouts.facility title="倉庫" headerIconImage="images/icon/icon_025.webp" bgImage="images/bg-castle.webp">
    <div class="py-12 w-full mx-auto sm:px-6 lg:px-8">
        @php
            $materialTabs = [
                'material' => ['label' => '素材', 'icon_image' => 'images/icon/icon_011.webp', 'count' => $storageSummary['categories']['material']['count'] ?? 0],
            ];

            $equipmentTabs = [
                'weapon' => ['label' => '武器', 'icon_image' => 'images/icon/icon_006.webp', 'count' => $storageSummary['categories']['weapon']['count'] ?? 0],
                'armor' => ['label' => '防具', 'icon_image' => 'images/icon/icon_007.webp', 'count' => $storageSummary['categories']['armor']['count'] ?? 0],
                'accessory' => ['label' => '装飾品', 'icon_image' => 'images/icon/icon_008.webp', 'count' => $storageSummary['categories']['accessory']['count'] ?? 0],
            ];

            $rankLabel = function ($item) {
                return $item?->weapon_rank
                    ?? $item?->armor_rank
                    ?? $item?->accessory_rank
                    ?? $item?->rarity
                    ?? '-';
            };

            $typeMeta = [
                'weapon' => ['title' => '武器', 'empty' => '所持している武器はありません。'],
                'armor' => ['title' => '防具', 'empty' => '所持している防具はありません。'],
                'accessory' => ['title' => '装飾品', 'empty' => '所持している装飾品はありません。'],
            ];

            $storageExpandItems = config('adventure_support.items');
            $materialExpandItem = $storageExpandItems['material_storage_expand'] ?? [
                'name' => '素材倉庫拡張',
                'price' => 50,
                'effect_value' => 500,
            ];
            $equipmentExpandItem = $storageExpandItems['equipment_storage_expand'] ?? [
                'name' => '装備倉庫拡張',
                'price' => 50,
                'effect_value' => 100,
            ];
        @endphp

        <div class="w-full space-y-6">
            <div
                class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-slate-200"
                x-data="{
                    storageTab: 'material',
                    activeMaterialTab: 'material',
                    activeEquipmentTab: 'weapon',
                    materialStorageTotal: {{ (int) ($storageSummary['material_storage_total'] ?? 0) }},
                    materialStorageTypes: {{ (int) ($storageSummary['material_storage_types'] ?? 0) }},
                    expandConfirm: null,
                    submittingExpand: false,
                    supportConfirm: null,
                    submittingSupport: false
                }"
                @material-discarded="materialStorageTotal = Math.max(0, materialStorageTotal - Number($event.detail.quantity || 0)); if ($event.detail.removed) materialStorageTypes = Math.max(0, materialStorageTypes - 1);"
            >
                <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="text-xs font-bold text-slate-500">帰還後に安全保管された資産</div>
                        <h2 class="text-2xl font-extrabold text-slate-800 tracking-wider">保有資産 合計 {{ number_format($storageSummary['total']) }} 個</h2>
                        <div class="mt-1 text-sm font-black text-amber-700">所持Gold {{ number_format((int) ($character->money ?? 0)) }}G</div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('material-exchange.index') }}" class="inline-flex items-center justify-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition-colors hover:bg-emerald-700">
                            素材交換所へ
                        </a>
                    </div>
                </div>

                @if(session('status'))
                    <div class="bg-amber-50 border border-amber-200 text-amber-700 px-4 py-3 rounded mb-4 shadow-sm">
                        {{ session('status') }}
                    </div>
                @endif
                @if(session('error'))
                    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4 shadow-sm">
                        {{ session('error') }}
                    </div>
                @endif

                <div class="mb-5 grid grid-cols-1 gap-3 lg:grid-cols-3">
                    <div
                        role="button"
                        tabindex="0"
                        @click="storageTab = 'material'"
                        @keydown.enter.prevent="storageTab = 'material'"
                        class="rounded-lg border p-4 text-left transition active:scale-[0.99]"
                        :class="storageTab === 'material' ? 'border-emerald-500 bg-emerald-50 shadow-md' : 'border-slate-200 bg-slate-50 hover:bg-white'"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs font-extrabold text-emerald-700 flex items-center gap-1"><img src="{{ asset('images/icon/icon_011.webp') }}" alt="" class="w-4 h-4 object-contain"> 素材倉庫</div>
                                <div class="mt-1 flex flex-wrap items-end gap-x-2 gap-y-1">
                                    <div class="text-2xl font-black text-slate-900"><span x-text="materialStorageTotal.toLocaleString()"></span> / {{ number_format($storageSummary['material_storage_limit'] ?? 500) }}</div>
                                    <button
                                        type="button"
                                        @click.stop="expandConfirm = {
                                            key: 'material_storage_expand',
                                            title: '素材倉庫',
                                            name: @js($materialExpandItem['name'] ?? '素材倉庫拡張'),
                                            price: {{ (int) ($materialExpandItem['price'] ?? 50) }},
                                            effect: {{ (int) ($materialExpandItem['effect_value'] ?? 50) }}
                                        }"
                                        class="mb-1 rounded-full border border-emerald-200 bg-white/90 px-2.5 py-1 text-[11px] font-extrabold text-emerald-700 shadow-sm hover:bg-emerald-100">
                                        枠拡張
                                    </button>
                                </div>
                            </div>
                            <div class="rounded bg-white/80 px-2 py-1 text-xs font-bold text-slate-600">
                                <span x-text="materialStorageTypes.toLocaleString()"></span> 種
                            </div>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-2 text-xs font-bold text-slate-600 sm:grid-cols-3">
                            @foreach($materialTabs as $tab)
                                <div class="rounded border border-emerald-100 bg-white px-2 py-1">
                                    <img src="{{ asset($tab['icon_image'] ?? 'images/icon/icon_011.webp') }}" alt="" class="w-4 h-4 object-contain inline-block mr-0.5"> {{ $tab['label'] }} <span x-text="materialStorageTotal.toLocaleString()"></span>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div
                        role="button"
                        tabindex="0"
                        @click="storageTab = 'equipment'"
                        @keydown.enter.prevent="storageTab = 'equipment'"
                        class="rounded-lg border p-4 text-left transition active:scale-[0.99]"
                        :class="storageTab === 'equipment' ? 'border-amber-500 bg-amber-50 shadow-md' : 'border-slate-200 bg-slate-50 hover:bg-white'"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs font-extrabold text-amber-700 flex items-center gap-1"><img src="{{ asset('images/icon/icon_006.webp') }}" alt="" class="w-4 h-4 object-contain"> 装備一覧</div>
                                <div class="mt-1 flex flex-wrap items-end gap-x-2 gap-y-1">
                                    <div class="text-2xl font-black text-slate-900">{{ number_format($storageSummary['equipment_storage_total']) }} / {{ number_format($storageSummary['equipment_storage_limit'] ?? 200) }}</div>
                                    <button
                                        type="button"
                                        @click.stop="expandConfirm = {
                                            key: 'equipment_storage_expand',
                                            title: '装備倉庫',
                                            name: @js($equipmentExpandItem['name'] ?? '装備倉庫拡張'),
                                            price: {{ (int) ($equipmentExpandItem['price'] ?? 50) }},
                                            effect: {{ (int) ($equipmentExpandItem['effect_value'] ?? 50) }}
                                        }"
                                        class="mb-1 rounded-full border border-amber-200 bg-white/90 px-2.5 py-1 text-[11px] font-extrabold text-amber-700 shadow-sm hover:bg-amber-100">
                                        枠拡張
                                    </button>
                                </div>
                            </div>
                            <div class="rounded bg-white/80 px-2 py-1 text-xs font-bold text-slate-600">
                                3 種別
                            </div>
                        </div>
                        <div class="mt-3 grid grid-cols-3 gap-2 text-xs font-bold text-slate-600">
                            @foreach($equipmentTabs as $tab)
                                <div class="rounded border border-amber-100 bg-white px-2 py-1">
                                    <img src="{{ asset('images/' . $tab['icon_image']) }}" alt="" class="w-4 h-4 object-contain inline-block align-middle"> {{ $tab['label'] }} {{ number_format($tab['count']) }}
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <button
                        type="button"
                        @click="storageTab = 'key'"
                        class="rounded-lg border p-4 text-left transition active:scale-[0.99]"
                        :class="storageTab === 'key' ? 'border-sky-500 bg-sky-50 shadow-md' : 'border-slate-200 bg-slate-50 hover:bg-white'"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs font-extrabold text-sky-700 flex items-center gap-1"><img src="{{ asset('images/icon/icon_087.webp') }}" alt="" class="w-4 h-4 object-contain"> 所持品</div>
                                <div class="mt-1 text-2xl font-black text-slate-900">{{ number_format($storageSummary['key_item_total']) }} 個</div>
                            </div>
                            <div class="rounded bg-white/80 px-2 py-1 text-xs font-bold text-slate-600">
                                {{ number_format($storageSummary['key_item_types']) }} 種
                            </div>
                        </div>
                        <div class="mt-3 rounded border border-sky-100 bg-white px-2 py-1 text-xs font-bold text-slate-600">
                            探索力回復や救助支援アイテム
                        </div>
                    </button>
                </div>

                <div x-show="storageTab === 'material'" x-transition>
                    <div class="mb-4 flex space-x-1 overflow-x-auto border-b-2 border-emerald-200">
                        @foreach($materialTabs as $key => $tab)
                            <button
                                type="button"
                                @click="activeMaterialTab = @js($key)"
                                class="shrink-0 py-3 px-3 sm:px-4 font-bold text-center rounded-t-lg transition-all duration-150 active:scale-95 outline-none whitespace-nowrap text-sm"
                                :class="activeMaterialTab === @js($key) ? 'bg-emerald-700 text-white border-b-4 border-white shadow-inner' : 'bg-emerald-50 text-emerald-800 hover:bg-emerald-100 border-b-4 border-transparent'"
                            >
                                <img src="{{ asset($tab['icon_image'] ?? 'images/icon/icon_011.webp') }}" alt="" class="w-5 h-5 object-contain inline-block mr-1">{{ $tab['label'] }}
                                <span class="ml-1 rounded bg-white/80 px-1.5 py-0.5 text-[10px] text-slate-700">{{ number_format($tab['count']) }}</span>
                            </button>
                        @endforeach
                    </div>

                    <div class="min-h-[320px]">
                        <div x-show="activeMaterialTab === 'material'" x-transition>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                @forelse($materials as $cm)
                                    @php
                                        $unitSalePrice = max(0, (int) ($cm->material?->npc_sale_price ?? 0));
                                        $canSellMaterial = $unitSalePrice > 0;
                                        $usageTags = $cm->material?->usage_tags ?? [];
                                        $matBadges = [];
                                        foreach ($usageTags as $tag) {
                                            if (str_contains($tag, '進化') || str_contains($tag, '合成')) {
                                                $matBadges['合成'] = ['label' => '合成', 'color' => 'bg-emerald-100 text-emerald-700 border-emerald-300'];
                                            }
                                            if (str_contains($tag, '鍛冶')) {
                                                $matBadges['鍛冶'] = ['label' => '鍛冶', 'color' => 'bg-orange-100 text-orange-700 border-orange-300'];
                                            }
                                            if (str_contains($tag, '交換所')) {
                                                $matBadges['交換所'] = ['label' => '交換所', 'color' => 'bg-purple-100 text-purple-700 border-purple-300'];
                                            }
                                        }
                                        if ($cm->material?->is_cash_item) {
                                            $matBadges['換金用'] = ['label' => '換金用', 'color' => 'bg-amber-100 text-amber-700 border-amber-300'];
                                        }
                                        if ($cm->material?->is_tradable && ($cm->material?->trade_policy ?? '') === 'marketable') {
                                            $matBadges['市場'] = ['label' => '市場', 'color' => 'bg-sky-100 text-sky-700 border-sky-300'];
                                        }
                                    @endphp
                                    <div
                                        class="bg-white border border-slate-200 rounded-lg p-2.5 shadow-sm"
                                        x-show="remainingQty > 0"
                                        x-transition
                                        x-data="{
                                            discardOpen: false,
                                            confirmOpen: false,
                                            submitting: false,
                                            inlineMessage: '',
                                            discardQty: 1,
                                            saleQty: 0,
                                            remainingQty: {{ (int) $cm->quantity }},
                                            maxQty: {{ max(1, (int) $cm->quantity) }},
                                            unitPrice: {{ $unitSalePrice }},
                                            setSaleQty(v) { this.saleQty = Math.max(0, Math.min(this.remainingQty, parseInt(v) || 0)); if (this.saleQty > 0) this.$store.matSales.set({{ $cm->id }}, this.saleQty, this.unitPrice); else this.$store.matSales.remove({{ $cm->id }}); },
                                            decrease() { this.discardQty = Math.max(1, this.discardQty - 1); },
                                            increase() { this.discardQty = Math.min(this.maxQty, this.discardQty + 1); },
                                            setQty(v) { this.discardQty = Math.max(1, Math.min(this.maxQty, parseInt(v) || 1)); },
                                            async submitDiscard() {
                                                if (this.submitting) return;
                                                this.submitting = true;
                                                this.inlineMessage = '';
                                                const formData = new FormData(this.$refs.discardForm);
                                                formData.set('quantity', this.discardQty);
                                                try {
                                                    const response = await fetch(this.$refs.discardForm.action, {
                                                        method: 'POST',
                                                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                                        body: formData,
                                                    });
                                                    const data = await response.json().catch(() => ({}));
                                                    if (!response.ok) { this.inlineMessage = data.message || '素材を捨てられませんでした。'; return; }
                                                    const discarded = Number(data.discarded_quantity || this.discardQty);
                                                    const remaining = Number(data.remaining_quantity || 0);
                                                    this.remainingQty = remaining;
                                                    this.maxQty = Math.max(1, remaining);
                                                    this.discardQty = 1;
                                                    this.saleQty = Math.min(this.saleQty, Math.max(1, remaining));
                                                    if (remaining <= 0) this.$store.matSales.remove({{ $cm->id }});
                                                    else if (this.$store.matSales.items[{{ $cm->id }}]) this.$store.matSales.set({{ $cm->id }}, this.saleQty, this.unitPrice);
                                                    this.confirmOpen = false;
                                                    this.discardOpen = remaining > 0 ? this.discardOpen : false;
                                                    this.inlineMessage = data.message || '素材を捨てました。';
                                                    this.$dispatch('material-discarded', { quantity: discarded, removed: remaining <= 0 });
                                                } catch { this.inlineMessage = '通信に失敗しました。もう一度お試しください。'; }
                                                finally { this.submitting = false; }
                                            }
                                        }"
                                    >
                                        {{-- ヘッダー行: アイコン + 名前 + 所持数 --}}
                                        @php
                                            $materialIconImage = $cm->material?->iconImagePath();
                                            $fixedMaterialIconImage = null;
                                            if ($cm->material?->category === '印') {
                                                $fixedMaterialIconImage = 'images/icon/icon_065.webp';
                                            } elseif (str_contains($cm->material?->name ?? '', '討伐証')) {
                                                $fixedMaterialIconImage = 'images/icon/icon_009.webp';
                                            }
                                            $displayMaterialIconImage = $fixedMaterialIconImage ?: $materialIconImage;
                                        @endphp
                                        <div class="flex items-center gap-2">
                                            @if($displayMaterialIconImage)
                                                <div class="w-8 h-8 shrink-0 bg-slate-100 rounded border border-slate-200 flex items-center justify-center text-base leading-none">
                                                    <img src="{{ asset($displayMaterialIconImage) }}" alt="" class="w-5 h-5 object-contain">
                                                </div>
                                            @endif
                                            <div class="min-w-0 flex-1 text-sm font-bold text-slate-800 truncate" title="{{ $cm->material?->displayName() }}">{{ $cm->material?->displayName() }}</div>
                                            <div class="shrink-0 text-right leading-none">
                                                <div class="text-[10px] text-slate-400">所持</div>
                                                <div class="text-base font-black text-slate-800 tabular-nums" x-text="remainingQty.toLocaleString()"></div>
                                            </div>
                                        </div>

                                        {{-- 用途バッジ --}}
                                        @if(!empty($matBadges))
                                            <div class="mt-1.5 flex flex-wrap gap-1">
                                                @foreach($matBadges as $badge)
                                                    <span class="inline-block border rounded px-1.5 py-0.5 text-[10px] font-bold {{ $badge['color'] }}">{{ $badge['label'] }}</span>
                                                @endforeach
                                            </div>
                                        @endif

                                        {{-- インラインメッセージ --}}
                                        <div x-show="inlineMessage" x-transition style="display:none;"
                                            class="mt-2 rounded border border-emerald-100 bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-700"
                                            x-text="inlineMessage"></div>

                                        {{-- 売却セクション --}}
                                        @if($canSellMaterial)
                                            <div class="mt-2 pt-2 border-t border-slate-100">
                                                <div class="flex items-center justify-between mb-1.5">
                                                    <span class="text-[11px] text-slate-400">
                                                        {{ number_format($unitSalePrice) }}G/個
                                                        <template x-if="saleQty > 0">
                                                            <span> → 合計 <span class="font-bold text-amber-700" x-text="(saleQty * unitPrice).toLocaleString()"></span>G</span>
                                                        </template>
                                                    </span>
                                                    <div class="flex gap-1">
                                                        <button type="button" @click="setSaleQty(1)" class="text-[10px] text-slate-400 hover:text-slate-600 px-1">1</button>
                                                        <button type="button" @click="setSaleQty(Math.ceil(remainingQty / 2))" class="text-[10px] text-slate-400 hover:text-slate-600 px-1">半</button>
                                                        <button type="button" @click="setSaleQty(remainingQty)" class="text-[10px] text-slate-400 hover:text-slate-600 px-1">全</button>
                                                    </div>
                                                </div>
                                                <div class="flex items-center gap-1.5">
                                                    <button type="button" @click="setSaleQty(saleQty - 1)"
                                                        :disabled="saleQty <= 0"
                                                        class="w-7 h-7 shrink-0 rounded border border-slate-300 bg-slate-50 text-sm font-bold text-slate-600 hover:bg-slate-100 disabled:opacity-30 flex items-center justify-center">−</button>
                                                    <input type="range" min="0" :max="remainingQty" :value="saleQty"
                                                        @input="setSaleQty($event.target.value)"
                                                        class="flex-1 h-2 accent-amber-500 cursor-pointer">
                                                    <button type="button" @click="setSaleQty(saleQty + 1)"
                                                        :disabled="saleQty >= remainingQty"
                                                        class="w-7 h-7 shrink-0 rounded border border-slate-300 bg-slate-50 text-sm font-bold text-slate-600 hover:bg-slate-100 disabled:opacity-30 flex items-center justify-center">+</button>
                                                    <form method="POST" action="{{ route('inventory.sell') }}" class="shrink-0">
                                                        @csrf
                                                        <input type="hidden" name="character_material_id" value="{{ $cm->id }}">
                                                        <input type="hidden" name="quantity" :value="saleQty">
                                                        <button type="submit"
                                                            :disabled="saleQty <= 0"
                                                            class="rounded bg-amber-600 px-2.5 py-1.5 text-xs font-extrabold text-white shadow-sm hover:bg-amber-700 disabled:opacity-30 disabled:cursor-not-allowed">売る</button>
                                                    </form>
                                                </div>
                                            </div>
                                        @else
                                            <div class="mt-2 pt-2 border-t border-slate-100 text-[11px] text-slate-400">売却不可</div>
                                        @endif

                                        {{-- 捨てるボタン + パネル --}}
                                        <div class="mt-2 flex items-center justify-between">
                                            <button type="button" @click="discardOpen = !discardOpen; discardQty = Math.min(discardQty, maxQty)"
                                                class="text-[11px] font-semibold text-red-400 hover:text-red-600 transition">
                                                捨てる
                                            </button>
                                            <span x-show="discardOpen" class="text-[10px] text-slate-400" x-text="`${discardQty} 個`"></span>
                                        </div>

                                        <form x-ref="discardForm" x-show="discardOpen" x-transition style="display:none;"
                                            method="POST" action="{{ route('inventory.materials.discard', $cm) }}"
                                            class="mt-1.5 rounded-lg border border-red-100 bg-red-50/50 p-2"
                                            @submit.prevent="confirmOpen = true">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="quantity" :value="discardQty">
                                            <div class="flex items-center gap-1.5">
                                                <button type="button" @click="decrease()"
                                                    class="w-7 h-7 shrink-0 rounded border border-red-200 bg-white text-sm font-bold text-red-600 hover:bg-red-50 flex items-center justify-center disabled:opacity-30"
                                                    :disabled="discardQty <= 1">−</button>
                                                <input type="range" min="1" :max="maxQty" step="1" x-model.number="discardQty"
                                                    class="flex-1 h-2 accent-red-500 cursor-pointer">
                                                <button type="button" @click="increase()"
                                                    class="w-7 h-7 shrink-0 rounded border border-red-200 bg-white text-sm font-bold text-red-600 hover:bg-red-50 flex items-center justify-center disabled:opacity-30"
                                                    :disabled="discardQty >= maxQty">+</button>
                                                <button type="submit" class="shrink-0 rounded bg-red-600 px-2.5 py-1.5 text-xs font-extrabold text-white hover:bg-red-700">破棄</button>
                                            </div>
                                        </form>

                                        {{-- 破棄確認モーダル --}}
                                        <div x-show="confirmOpen" style="display:none;" class="fixed inset-0 z-50 flex items-center justify-center p-4" aria-modal="true" role="dialog">
                                            <div x-show="confirmOpen" x-transition.opacity class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" @click="confirmOpen = false"></div>
                                            <div x-show="confirmOpen" x-transition.scale.origin.center
                                                class="relative w-full max-w-sm overflow-hidden rounded-xl border-2 border-red-300 bg-white shadow-2xl" @click.stop>
                                                <div class="bg-red-600 px-4 py-3">
                                                    <h3 class="text-sm font-extrabold text-white">素材破棄の確認</h3>
                                                </div>
                                                <div class="p-4">
                                                    <div class="rounded-lg border border-red-100 bg-red-50 p-3 text-sm">
                                                        <span class="font-bold text-slate-800">{{ $cm->material?->displayName() }}</span> を
                                                        <span class="font-black text-red-700" x-text="discardQty"></span> 個破棄します。
                                                        <div class="mt-1 text-xs text-slate-500">破棄後: <span x-text="Math.max(0, remainingQty - discardQty).toLocaleString()"></span> 個</div>
                                                    </div>
                                                    <p class="mt-2 text-xs text-slate-400">捨てた素材は戻せません。</p>
                                                    <div class="mt-4 grid grid-cols-2 gap-2">
                                                        <button type="button" @click="confirmOpen = false"
                                                            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50">キャンセル</button>
                                                        <button type="button" @click="submitDiscard()" :disabled="submitting"
                                                            class="rounded-lg bg-red-600 px-3 py-2 text-sm font-extrabold text-white hover:bg-red-700 disabled:opacity-60">
                                                            <span x-show="!submitting">捨てる</span>
                                                            <span x-show="submitting" style="display:none;">処理中...</span>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                @empty
                                    <div class="col-span-1 sm:col-span-2 text-center py-10 bg-white rounded-lg border border-slate-200 border-dashed">
                                        <p class="text-slate-500">倉庫に保管されている素材はありません。</p>
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>

                <div x-show="storageTab === 'key'" x-transition style="display: none;">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @foreach($supportItems as $entry)
                            @php
                                $isStaminaRecovery = ($entry['effect_type'] ?? null) === 'explore_stamina_recovery';
                                $effectValue = (int) ($entry['effect_value'] ?? 0);
                                $supportUsePayload = [
                                    ...$entry,
                                    'use_url' => route('inventory.support-items.use', $entry['key']),
                                    'current_stamina' => (int) ($staminaSummary['current'] ?? 0),
                                    'max_stamina' => (int) ($staminaSummary['max'] ?? 0),
                                    'stamina_enabled' => (bool) ($staminaSummary['enabled'] ?? false),
                                ];
                            @endphp
                            <div class="bg-white border border-sky-100 rounded p-4 shadow-sm flex items-center">
                                <div class="w-12 h-12 shrink-0 bg-sky-50 rounded border border-sky-200 flex items-center justify-center mr-4">
                                    @if(!empty($entry['icon_image']))
                                        <img src="{{ asset($entry['icon_image']) }}" alt="" class="w-9 h-9 object-contain">
                                    @else
                                        <span class="text-2xl">🎒</span>
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <div class="font-bold text-slate-800 truncate" title="{{ $entry['name'] }}">{{ $entry['name'] }}</div>
                                        @if($isStaminaRecovery && $effectValue > 0)
                                            <span class="rounded-full border border-sky-200 bg-sky-50 px-2 py-0.5 text-[11px] font-black text-sky-700">探索力 +{{ number_format($effectValue) }}</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-slate-500">{{ $entry['category'] }}</div>
                                    @if($isStaminaRecovery && $effectValue > 0)
                                        <div class="text-xs font-bold text-sky-700">使うと探索力が {{ number_format($effectValue) }} 回復</div>
                                    @elseif(!empty($entry['use_note']))
                                        <div class="text-xs text-slate-500 truncate" title="{{ $entry['use_note'] }}">{{ $entry['use_note'] }}</div>
                                    @else
                                        <div class="text-xs text-slate-500 truncate" title="{{ $entry['description'] }}">{{ $entry['description'] }}</div>
                                    @endif
                                </div>
                                <div class="ml-2 shrink-0 text-right">
                                    <div class="text-sm text-slate-500">所持数</div>
                                    <div class="text-lg font-black text-slate-800">{{ number_format($entry['quantity']) }}</div>
                                    @if($entry['can_use'] && !empty($entry['use_label']))
                                        <button
                                            type="button"
                                            @click="supportConfirm = @js($supportUsePayload)"
                                            class="mt-2 rounded bg-sky-700 px-3 py-1.5 text-xs font-extrabold text-white shadow-sm transition hover:bg-sky-800 active:scale-95">
                                            {{ $entry['use_label'] }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endforeach

                        @forelse($keyItems as $entry)
                            <div class="bg-white border border-amber-100 rounded p-4 shadow-sm flex items-center">
                                <div class="w-12 h-12 shrink-0 bg-amber-50 rounded border border-amber-200 flex items-center justify-center mr-4">
                                    @if(!empty($entry['icon_image']))
                                        <img src="{{ asset($entry['icon_image']) }}" alt="" class="w-8 h-8 object-contain">
                                    @else
                                        <span class="text-2xl">{{ $entry['icon'] ?? '🔑' }}</span>
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="font-bold text-slate-800 truncate" title="{{ $entry['name'] }}">{{ $entry['name'] }}</div>
                                    <div class="text-xs text-slate-500">{{ $entry['category'] }} / レア度: {{ $entry['rarity'] }}</div>
                                    <div class="text-xs text-slate-500 truncate" title="{{ $entry['description'] }}">{{ $entry['description'] }}</div>
                                </div>
                                <div class="ml-2 text-right">
                                    <div class="text-sm text-slate-500">所持数</div>
                                    <div class="text-lg font-black text-slate-800">{{ number_format($entry['quantity']) }}</div>
                                </div>
                            </div>
                        @empty
                            @if($supportItems->isEmpty())
                                <div class="text-center py-10 bg-white rounded-lg border border-slate-200 border-dashed">
                                    <p class="text-slate-500">所持している支援アイテムはありません。</p>
                                    <a href="{{ route('kiseki.support') }}" class="mt-3 inline-flex items-center justify-center rounded-md bg-sky-700 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-sky-800">
                                        補給商会へ
                                    </a>
                                </div>
                            @endif
                        @endforelse
                    </div>
                </div>

                <div x-show="storageTab === 'equipment'" x-transition style="display: none;">
                    <div class="mb-4 flex space-x-1 overflow-x-auto border-b-2 border-amber-200">
                        @foreach($equipmentTabs as $key => $tab)
                            <button
                                type="button"
                                @click="activeEquipmentTab = @js($key)"
                                class="shrink-0 py-3 px-3 sm:px-4 font-bold text-center rounded-t-lg transition-all duration-150 active:scale-95 outline-none whitespace-nowrap text-sm"
                                :class="activeEquipmentTab === @js($key) ? 'bg-amber-700 text-white border-b-4 border-white shadow-inner' : 'bg-amber-50 text-amber-800 hover:bg-amber-100 border-b-4 border-transparent'"
                            >
                                <img src="{{ asset($tab['icon_image'] ?? 'images/icon/icon_011.webp') }}" alt="" class="w-5 h-5 object-contain inline-block mr-1">{{ $tab['label'] }}
                                <span class="ml-1 rounded bg-white/80 px-1.5 py-0.5 text-[10px] text-slate-700">{{ number_format($tab['count']) }}</span>
                            </button>
                        @endforeach
                    </div>

                    <div class="min-h-[320px]">
                        @foreach(['weapon', 'armor', 'accessory'] as $type)
                            <div x-show="activeEquipmentTab === @js($type)" x-transition @if($type !== 'weapon') style="display: none;" @endif>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    @forelse($equipmentGroups[$type] as $characterItem)
                                        @php($item = $characterItem->item)
                                        <div class="bg-white border border-slate-200 rounded p-4 shadow-sm flex items-center">
                                            <div class="w-12 h-12 shrink-0 bg-slate-100 rounded border border-slate-300 flex items-center justify-center mr-4 text-2xl">
                                                <img src="{{ asset($type === 'weapon' ? 'images/icon/icon_006.webp' : ($type === 'armor' ? 'images/icon/icon_007.webp' : 'images/icon/icon_008.webp')) }}" alt="" class="w-7 h-7 object-contain">
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div class="font-bold text-slate-800 truncate" title="{{ $characterItem->displayName() }}">{{ $characterItem->displayName() }}</div>
                                                <div class="text-xs text-slate-500">
                                                    Rank {{ $rankLabel($item) }} / {{ $item?->sub_type ?? $typeMeta[$type]['title'] }}
                                                </div>
                                                <div class="text-xs font-bold {{ $characterItem->is_equipped ? 'text-amber-700' : 'text-emerald-700' }}">
                                                    @if($characterItem->is_equipped)
                                                        装備中
                                                    @else
                                                        装備変更で装備可
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="ml-2 text-right">
                                                <div class="text-sm text-slate-500">個数</div>
                                                <div class="text-lg font-black text-slate-800">1</div>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="col-span-1 sm:col-span-2 text-center py-10 bg-white rounded-lg border border-slate-200 border-dashed">
                                            <p class="text-slate-500">{{ $typeMeta[$type]['empty'] }}</p>
                                        </div>
                                    @endforelse
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div
                    x-show="supportConfirm"
                    x-cloak
                    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 px-4"
                    @keydown.escape.window="supportConfirm = null; submittingSupport = false"
                >
                    <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl" @click.outside="supportConfirm = null; submittingSupport = false">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg border border-sky-100 bg-sky-50 p-1.5">
                                    <img :src="supportConfirm?.icon_image ? '{{ url('/') }}/' + supportConfirm.icon_image : ''" alt="" class="h-full w-full object-contain">
                                </div>
                                <div class="min-w-0">
                                    <div class="text-xs font-black tracking-wide text-sky-700">USE ITEM</div>
                                    <h3 class="mt-1 truncate text-xl font-black text-slate-950" x-text="supportConfirm?.name ?? 'アイテム'"></h3>
                                </div>
                            </div>
                            <button type="button" @click="supportConfirm = null; submittingSupport = false" class="rounded-full bg-slate-100 px-2.5 py-1 text-sm font-black text-slate-500 hover:bg-slate-200">×</button>
                        </div>

                        <p class="mt-4 text-sm font-bold leading-relaxed text-slate-600">
                            このアイテムを使用しますか？
                        </p>

                        <div class="mt-4 rounded-lg border border-sky-100 bg-sky-50 px-3 py-3">
                            <div class="flex items-center justify-between gap-3 text-sm font-black text-slate-800">
                                <span>現在の探索力</span>
                                <span class="tabular-nums">
                                    <span x-text="Number(supportConfirm?.current_stamina ?? 0).toLocaleString()"></span>
                                    /
                                    <span x-text="Number(supportConfirm?.max_stamina ?? 0).toLocaleString()"></span>
                                </span>
                            </div>
                            <template x-if="supportConfirm?.effect_type === 'explore_stamina_recovery'">
                                <div class="mt-2 flex items-center justify-between gap-3 text-sm font-black text-sky-800">
                                    <span>使用後の探索力</span>
                                    <span class="tabular-nums">
                                        <span x-text="(Number(supportConfirm?.current_stamina ?? 0) + Number(supportConfirm?.effect_value ?? 0)).toLocaleString()"></span>
                                        /
                                        <span x-text="Number(supportConfirm?.max_stamina ?? 0).toLocaleString()"></span>
                                        <span class="ml-1 text-xs text-sky-600" x-text="`(+${Number(supportConfirm?.effect_value ?? 0).toLocaleString()})`"></span>
                                    </span>
                                </div>
                            </template>
                        </div>

                        <div class="mt-5 grid grid-cols-2 gap-3">
                            <button type="button" @click="supportConfirm = null; submittingSupport = false" class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-black text-slate-600 hover:bg-slate-50">
                                キャンセル
                            </button>
                            <form method="POST" :action="supportConfirm?.use_url ?? '#'" @submit="if (submittingSupport) { $event.preventDefault(); return; } submittingSupport = true">
                                @csrf
                                <button type="submit" :disabled="submittingSupport" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-sky-700 px-4 py-3 text-sm font-black text-white shadow-sm hover:bg-sky-800 disabled:cursor-wait disabled:opacity-60">
                                    <x-loading-spinner x-show="submittingSupport" style="display: none;" />
                                    <span x-show="!submittingSupport">使用する</span>
                                    <span x-show="submittingSupport" style="display: none;">処理中...</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div
                    x-show="expandConfirm"
                    x-cloak
                    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 px-4"
                    @keydown.escape.window="expandConfirm = null"
                >
                    <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl" @click.outside="expandConfirm = null">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs font-black tracking-wide text-amber-700">STORAGE EXPAND</div>
                                <h3 class="mt-1 text-xl font-black text-slate-950" x-text="`${expandConfirm?.title ?? '倉庫'}の枠を拡張しますか？`"></h3>
                            </div>
                            <button type="button" @click="expandConfirm = null" class="rounded-full bg-slate-100 px-2.5 py-1 text-sm font-black text-slate-500 hover:bg-slate-200">×</button>
                        </div>

                        <p class="mt-4 text-sm font-bold leading-relaxed text-slate-600">
                            輝石を
                            <span class="font-black text-slate-950" x-text="Number(expandConfirm?.price ?? 0).toLocaleString()"></span>
                            消費して、
                            <span class="font-black text-slate-950" x-text="expandConfirm?.title ?? '倉庫'"></span>
                            の上限を
                            <span class="font-black text-emerald-700" x-text="`+${Number(expandConfirm?.effect ?? 0).toLocaleString()}`"></span>
                            拡張します。
                        </p>

                        <div class="mt-4 flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                            <img src="{{ asset('images/icon/kiseki.webp') }}" alt="輝石" class="h-6 w-6 object-contain">
                            <div class="text-sm font-black text-slate-800">
                                消費輝石:
                                <span x-text="Number(expandConfirm?.price ?? 0).toLocaleString()"></span>
                            </div>
                        </div>

                        <div class="mt-5 grid grid-cols-2 gap-3">
                            <button type="button" @click="expandConfirm = null" class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-black text-slate-600 hover:bg-slate-50">
                                キャンセル
                            </button>
                            <form method="POST" action="{{ route('kiseki.support.purchase') }}" @submit="if (submittingExpand) { $event.preventDefault(); return; } submittingExpand = true">
                                @csrf
                                <input type="hidden" name="item_key" :value="expandConfirm?.key">
                                <button type="submit" :disabled="submittingExpand" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-amber-600 px-4 py-3 text-sm font-black text-white shadow-sm hover:bg-amber-700 disabled:cursor-wait disabled:opacity-60">
                                    <x-loading-spinner x-show="submittingExpand" style="display: none;" />
                                    <span x-show="!submittingExpand">拡張する</span>
                                    <span x-show="submittingExpand" style="display: none;">処理中...</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
{{-- まとめて売るフローティングボタン（2種以上調整時に出現） --}}
<div
    x-data
    x-show="$store.matSales.count >= 2"
    x-cloak
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 translate-y-3"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-3"
    class="fixed bottom-6 inset-x-0 z-50 flex justify-center pointer-events-none px-4"
>
    <button
        type="button"
        @click="bulkSellMaterials('{{ csrf_token() }}', '{{ route('inventory.sell') }}')"
        class="pointer-events-auto flex items-center gap-3 rounded-full bg-amber-600 px-5 py-3 text-sm font-extrabold text-white shadow-2xl ring-2 ring-amber-400/40 hover:bg-amber-700 active:scale-95 transition-transform"
    >
        <span>まとめて売る</span>
        <span class="rounded-full bg-amber-800/30 px-2 py-0.5 text-xs tabular-nums">
            <span x-text="$store.matSales.count"></span>種
        </span>
        <span class="text-amber-100 text-xs tabular-nums">
            合計 <span x-text="$store.matSales.total.toLocaleString()"></span>G
        </span>
    </button>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.store('matSales', {
        items: {},
        set(id, qty, price) { this.items[id] = { qty, price }; },
        remove(id) { delete this.items[id]; },
        get total() { return Object.values(this.items).reduce((s, i) => s + i.qty * i.price, 0); },
        get count() { return Object.keys(this.items).length; },
        clear() { this.items = {}; }
    });
});

async function bulkSellMaterials(csrfToken, sellUrl) {
    const items = { ...Alpine.store('matSales').items };
    const ids = Object.keys(items);
    if (ids.length < 2) return;

    for (const id of ids) {
        const fd = new FormData();
        fd.append('_token', csrfToken);
        fd.append('character_material_id', id);
        fd.append('quantity', items[id].qty);
        try {
            await fetch(sellUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            });
        } catch(e) {}
    }
    Alpine.store('matSales').clear();
    window.location.reload();
}
</script>
</x-layouts.facility>
