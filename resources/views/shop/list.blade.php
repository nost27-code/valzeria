@php
    $headerIconImage = 'images/icon/icon_027.webp';
    $bgImage = 'images/bg-town.png';
    $equipPrompt = session('equipPrompt');
    if(str_contains($categoryName, '装備')) {
        $headerIconImage = 'images/icon/icon_007.webp';
        $bgImage = 'images/bg-castle.webp';
    } elseif(str_contains($categoryName, '武器')) {
        $headerIconImage = 'images/icon/icon_006.webp';
        $bgImage = 'images/bg-castle.webp';
    } elseif(str_contains($categoryName, '防具')) {
        $headerIconImage = 'images/icon/icon_007.webp';
        $bgImage = 'images/bg-castle.webp';
    } elseif(str_contains($categoryName, '装飾')) {
        $headerIconImage = 'images/icon/icon_008.webp';
        $bgImage = 'images/bg-town.png';
    } elseif(str_contains($categoryName, '道具')) {
        $headerIconImage = 'images/icon/icon_028.webp';
        $bgImage = 'images/bg-town.png';
    }
@endphp
<x-layouts.facility :title="$categoryName" :headerIconImage="$headerIconImage" :bgImage="$bgImage">
    <div class="w-full mx-auto">
        
        {{-- ショップ一覧エリア --}}
        <div class="w-full space-y-6">
            <div class="bg-white p-6 rounded-lg shadow-sm border border-[#d4af37]/50"
                 x-data="{
                    buyModal: false,
                    buyItemName: '',
                    buyFormId: '',
                    buyQuantity: 1,
                    buyMaxQuantity: 1,
                    buyIsConsumable: false,
                    isStarterSupply: @js($isStarterSupply ?? false),
                    equipPromptModal: @js((bool) $equipPrompt),
                    openBuyModal(name, formId, isConsumable = false, maxQuantity = 1) {
                        this.buyItemName = name;
                        this.buyFormId = formId;
                        this.buyIsConsumable = isConsumable;
                        this.buyMaxQuantity = Math.max(1, Number(maxQuantity || 1));
                        this.buyQuantity = 1;
                        this.buyModal = true;
                    },
                    increaseBuyQuantity() {
                        this.buyQuantity = Math.min(this.buyMaxQuantity, this.buyQuantity + 1);
                    },
                    decreaseBuyQuantity() {
                        this.buyQuantity = Math.max(1, this.buyQuantity - 1);
                    },
                    confirmBuy() {
                        const form = document.getElementById(this.buyFormId);
                        const quantityInput = form ? form.querySelector('[name=quantity]') : null;
                        if (quantityInput) quantityInput.value = this.buyQuantity;
                        if (form) form.submit();
                        this.buyModal = false;
                    }
                 }">
                <div class="mb-5">
                    <h2 class="text-xl font-bold text-slate-800">{{ $categoryName }}</h2>
                    <p class="mt-2 text-sm font-bold text-slate-600">
                        @if($type !== 'consumable')
                            {{ $cityName ?? '現在の街' }}で販売中の店売り装備です。Goldで購入でき、合成元になる装備や安定した通常装備を揃えられます。
                        @elseif($isStarterSupply ?? false)
                            Gランクの武器・防具を無料で受け取れます。所持していない装備のみ、同じ装備は1日1個までです。
                        @endif
                    </p>
                </div>

                @if(session('status'))
                    <div class="bg-amber-50 border border-amber-200 text-amber-700 px-4 py-3 rounded mb-4">
                        {{ session('status') }}
                    </div>
                @endif
                @if(session('error'))
                    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">
                        {{ session('error') }}
                    </div>
                @endif

                {{-- 現在の装備表示 (スクロール追従・コンパクト版) --}}
                @if($type !== 'consumable' && isset($equippedItems) && is_array($equippedItems))
                    <div class="sticky top-2 z-30 mb-6 p-2 sm:p-3 bg-amber-50/95 backdrop-blur-sm border border-amber-200 rounded-lg shadow-md shadow-amber-200/50">
                        <h3 class="font-bold text-amber-800 mb-1 border-b border-amber-200 pb-1 flex items-center text-xs">
                            <span class="mr-1">👤</span> 現在の装備
                        </h3>
                        <div class="flex flex-col gap-1.5 mt-1.5">
                            @php
                                $displaySlots = [];
                                if ($type === 'weapon') $displaySlots = ['weapon'];
                                elseif ($type === 'armor') $displaySlots = ['armor'];
                                elseif ($type === 'accessory') $displaySlots = ['accessory'];
                            @endphp

                            @foreach($displaySlots as $slot)
                                @php 
                                    $equip = $equippedItems[$slot] ?? null;
                                    $slotName = '';
                                    if ($slot === 'weapon') $slotName = '武器';
                                    elseif ($slot === 'armor') $slotName = '防具';
                                    elseif ($slot === 'accessory') $slotName = '装飾';
                                @endphp
                                
                                <div class="flex flex-col {{ $loop->last ? '' : 'border-b border-amber-100 pb-1.5' }}">
                                    @if($equip && $equip->item)
                                        <div class="flex flex-wrap items-center gap-1.5 font-bold text-slate-800 text-xs">
                                            <span>{{ $slotName }}：</span>
                                            @include('equipment.partials.rank-label', ['item' => $equip->item])
                                            <span>{{ $equip->displayName() }}</span>
                                            @if($equip->item->element) <span class="text-[10px] bg-purple-100 text-purple-600 px-1 py-0.5 rounded ml-1 font-normal">{{ $equip->item->element }}属性</span> @endif
                                        </div>
                                        <div class="text-[10px] sm:text-xs text-amber-600 font-semibold leading-tight mt-0.5 flex flex-wrap gap-x-2">
                                            @if($equip->item->hp_bonus > 0) <span>HP+{{ $equip->item->hp_bonus }}</span> @endif
                                            @if($equip->item->mp_bonus > 0) <span>SP+{{ $equip->item->mp_bonus }}</span> @endif
                                            @if($equip->item->str_bonus > 0) <span>攻+{{ $equip->item->str_bonus }}</span> @endif
                                            @if($equip->item->def_bonus > 0) <span>防+{{ $equip->item->def_bonus }}</span> @endif
                                            @if($equip->item->agi_bonus > 0) <span>敏+{{ $equip->item->agi_bonus }}</span> @endif
                                            @if($equip->item->mag_bonus > 0) <span>魔+{{ $equip->item->mag_bonus }}</span> @endif
                                            @if($equip->item->spr_bonus > 0) <span>精+{{ $equip->item->spr_bonus }}</span> @endif
                                            @if($equip->item->luk_bonus > 0) <span>運+{{ $equip->item->luk_bonus }}</span> @endif
                                            @if($equip->item->agi_bonus < 0) <span>敏{{ $equip->item->agi_bonus }}</span> @endif
                                        </div>
                                    @else
                                        <div class="font-bold text-slate-400 text-xs">
                                            {{ $slotName }}：装備なし
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if($type !== 'consumable')
                    @php
                        $equipmentTabs = [
                            'weapon' => ['label' => '武器', 'icon_image' => 'images/icon/icon_005.webp'],
                            'armor' => ['label' => '防具', 'icon_image' => 'images/icon/icon_007.webp'],
                        ];
                        $sortOptions = [
                            'recommended' => 'おすすめ順',
                            'attack_desc' => '攻撃が高い順',
                            'defense_desc' => '防御が高い順',
                            'magic_desc' => '魔力が高い順',
                            'speed_desc' => '敏捷が高い順',
                            'luck_desc' => '運が高い順',
                            'rarity_desc' => 'ランクが高い順',
                            'level_asc' => '必要Lvが低い順',
                        ];
                    @endphp

                    <div class="mb-5 grid grid-cols-2 gap-2 rounded-xl bg-slate-100 p-1 border border-slate-200">
                        @foreach($equipmentTabs as $tabType => $tab)
                            <a href="{{ route('shop.equipment', ['type' => $tabType, 'sort' => $sort ?? 'recommended']) }}"
                               class="min-h-11 rounded-lg flex items-center justify-center gap-1.5 text-sm font-extrabold transition {{ $type === $tabType ? 'bg-slate-900 text-white shadow' : 'text-slate-600 hover:bg-white' }}">
                                <img src="{{ asset($tab['icon_image']) }}" alt="" class="w-5 h-5 object-contain">
                                <span>{{ $tab['label'] }}</span>
                            </a>
                        @endforeach
                    </div>

                    <form method="GET" action="{{ route('shop.equipment') }}" class="mb-5 flex items-center gap-2">
                        <input type="hidden" name="type" value="{{ $type }}">
                        <label for="shop-sort" class="text-sm font-bold text-slate-600 shrink-0">ソート</label>
                        <select id="shop-sort" name="sort" onchange="this.form.submit()" class="w-full sm:w-64 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            @foreach($sortOptions as $value => $label)
                                <option value="{{ $value }}" @selected(($sort ?? 'recommended') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif

                {{-- 細分類フィルタリングUI --}}
                @php
                    $subTypes = $items->pluck('sub_type')->filter()->unique()->values();
                @endphp
                
                @if($subTypes->count() > 0)
                    <div class="flex flex-wrap gap-2 mb-6" id="shop-tabs">
                        <button type="button" data-filter="all" class="subtype-tab-btn px-4 py-2 rounded-full text-sm font-bold bg-amber-600 text-white shadow-sm transition">すべて</button>
                        @foreach($subTypes as $subType)
                            <button type="button" data-filter="{{ $subType }}" class="subtype-tab-btn px-4 py-2 rounded-full text-sm font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 transition">{{ $subType }}</button>
                        @endforeach
                    </div>
                @endif

                @php
                    $compareItem = null;
                    if (isset($equippedItems) && is_array($equippedItems)) {
                        if ($type === 'weapon' && isset($equippedItems['weapon'])) {
                            $compareItem = $equippedItems['weapon']->item;
                        } elseif ($type === 'armor' && isset($equippedItems['armor'])) {
                            $compareItem = $equippedItems['armor']->item;
                        }
                        // 装飾品は比較対象が3つあり曖昧なため比較差分を表示しない
                    }

                    $getDiffHtml = function($statName, $itemValue, $compareItem, $propName) use ($type) {
                        if ($itemValue == 0 && (!$compareItem || $compareItem->$propName == 0)) {
                            return ''; // 両方0なら表示しない
                        }
                        
                        $itemValueStr = $itemValue > 0 ? '+' . $itemValue : $itemValue;
                        
                        // 装飾品の場合は比較差分を非表示にする
                        if ($type === 'accessory') {
                            return '
                            <div class="flex items-center text-xs sm:text-sm bg-slate-50 p-1 sm:p-1.5 rounded border border-slate-200">
                                <span class="text-slate-500 font-medium w-8 sm:w-10 shrink-0">' . $statName . '</span>
                                <span class="font-bold text-slate-800 ml-0.5 sm:ml-1">' . $itemValueStr . '</span>
                            </div>';
                        }

                        $compareValue = $compareItem ? $compareItem->$propName : 0;
                        $diff = $itemValue - $compareValue;
                        
                        $diffHtml = '';
                        if ($diff > 0) {
                            $diffHtml = '<span class="text-[10px] sm:text-xs text-emerald-600 font-bold ml-auto bg-emerald-100 px-1 py-0.5 rounded whitespace-nowrap">▲' . $diff . '</span>';
                        } elseif ($diff < 0) {
                            $diffHtml = '<span class="text-[10px] sm:text-xs text-rose-600 font-bold ml-auto bg-rose-100 px-1 py-0.5 rounded whitespace-nowrap">▼' . abs($diff) . '</span>';
                        } else {
                            $diffHtml = '<span class="text-[10px] sm:text-xs text-slate-400 font-medium ml-auto px-1 py-0.5">—</span>';
                        }
                        
                        return '
                        <div class="flex items-center text-xs sm:text-sm bg-slate-50 p-1 sm:p-1.5 rounded border border-slate-200">
                            <span class="text-slate-500 font-medium w-8 sm:w-10 shrink-0">' . $statName . '</span>
                            <span class="font-bold text-slate-800 ml-0.5 sm:ml-1">' . $itemValueStr . '</span>
                            ' . $diffHtml . '
                        </div>';
                    };
                @endphp

                <div class="space-y-4" id="item-list">
                    @php
                        $character = $character ?? Auth::user()->currentCharacter();
                        $ownedItemCounts = $ownedItemCounts ?? [];
                        $permissionService = app(\App\Services\EquipmentPermissionService::class);
                        $shopService = app(\App\Services\ShopService::class);
                    @endphp
                    @forelse($items as $item)
                        @php
                            $categoryLabel = $permissionService->categoryLabel($item);
                            $canEquipByJob = !$character || $permissionService->canEquip($character, $item);
                            $restrictionJobs = $canEquipByJob ? [] : $permissionService->representativeJobNames($item);
                            $displayPrice = $character ? $shopService->priceFor($character, $item) : (int) $item->price;
                            $ownedCount = $ownedItemCounts[$item->id] ?? 0;
                        @endphp
                        <div class="item-card border border-[#d4af37]/50 rounded-lg p-4 flex flex-col sm:flex-row justify-between items-start sm:items-center hover:border-[#d4af37] transition-colors" data-subtype="{{ $item->sub_type }}">
                            <div class="mb-4 sm:mb-0">
                                <h3 class="flex flex-wrap items-center gap-1.5 font-bold text-lg text-slate-800">
                                    @if($type !== 'consumable')
                                        @include('equipment.partials.rank-label', ['item' => $item])
                                    @endif
                                    <span>{{ $item->name }}</span>
                                    @if($item->sub_type) <span class="text-xs bg-slate-200 text-slate-600 px-2 py-1 rounded ml-2">{{ $item->sub_type }}</span> @endif
                                    @if($item->element) <span class="text-xs bg-purple-100 text-purple-600 px-2 py-1 rounded ml-1">{{ $item->element }}属性</span> @endif
                                    @if($categoryLabel) <span class="text-xs bg-slate-100 text-slate-600 border border-slate-200 px-2 py-1 rounded ml-1">カテゴリ：{{ $categoryLabel }}</span> @endif
                                </h3>
                                <p class="mt-1 text-xs font-semibold leading-relaxed text-slate-500">{{ $item->description }}</p>
                                <div class="text-sm text-slate-500 mt-2">
                                    <span class="mr-3">
                                        <strong class="text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-0.5">
                                            {{ $type === 'consumable' ? '補給品' : '店売り' }}
                                        </strong>
                                    </span>
                                    @if($type !== 'consumable')
                                        <span class="text-[11px] bg-slate-50 text-slate-700 border border-slate-200 rounded px-2 py-0.5 font-bold">{{ number_format($displayPrice) }}G</span>
                                        <span class="text-[11px] bg-emerald-50 text-emerald-700 border border-emerald-200 rounded px-2 py-0.5 font-bold">+5強化可</span>
                                        <span class="text-[11px] bg-slate-50 text-slate-600 border border-slate-200 rounded px-2 py-0.5 font-bold">所持数: {{ $ownedCount }}</span>
                                    @endif
                                    @if($type === 'consumable' && in_array($item->name, ['薬草', '回復薬', '魔力水'], true))
                                        <span class="text-[11px] bg-amber-50 text-amber-700 border border-amber-200 rounded px-2 py-0.5 font-bold">Lv連動</span>
                                    @endif
                                    
                                    @if($type === 'consumable')
                                        @php
                                            $effectText = match($item->name) {
                                                '薬草' => '探索中にHPを30%回復',
                                                '回復薬' => '探索中にHPを60%回復',
                                                '魔力水' => '探索中にSPを30%回復',
                                                default => '探索中に使用可能',
                                            };
                                        @endphp
                                        <div class="mt-3 flex flex-wrap gap-2 text-xs font-bold">
                                            <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 rounded px-2 py-1">{{ $effectText }}</span>
                                            <span class="bg-slate-50 text-slate-600 border border-slate-200 rounded px-2 py-1">所持数: {{ $ownedCount }}</span>
                                        </div>
                                    @else
                                        <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-2 mt-3">
                                            {!! $getDiffHtml('HP', $item->hp_bonus, $compareItem, 'hp_bonus') !!}
                                            {!! $getDiffHtml('SP', $item->mp_bonus, $compareItem, 'mp_bonus') !!}
                                            {!! $getDiffHtml('攻撃', $item->str_bonus, $compareItem, 'str_bonus') !!}
                                            {!! $getDiffHtml('防御', $item->def_bonus, $compareItem, 'def_bonus') !!}
                                            {!! $getDiffHtml('敏捷', $item->agi_bonus, $compareItem, 'agi_bonus') !!}
                                            {!! $getDiffHtml('魔力', $item->mag_bonus, $compareItem, 'mag_bonus') !!}
                                            {!! $getDiffHtml('精神', $item->spr_bonus, $compareItem, 'spr_bonus') !!}
                                            {!! $getDiffHtml('運', $item->luk_bonus, $compareItem, 'luk_bonus') !!}
                                        </div>
                                        @if(!$canEquipByJob)
                                            <div class="mt-3 text-xs font-bold text-rose-600 bg-rose-50 border border-rose-100 rounded px-2 py-1">
                                                現在の職業では装備できません
                                                @if(!empty($restrictionJobs))
                                                    <span class="text-slate-500 font-medium">（例：{{ implode('、', $restrictionJobs) }}）</span>
                                                @endif
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            </div>
                            
                            <div>
                                @php
                                    // レベル制限撤廃により常に購入可能にする
                                    $levelOk = true;
                                    $maxBuyQuantity = 1;
                                    $goldOk = !$character || (int) ($character->money ?? 0) >= $displayPrice;
                                @endphp

                                @if(!$levelOk)
                                    <button disabled class="bg-slate-300 text-slate-600 px-4 py-2 rounded cursor-not-allowed">Lv不足</button>
                                @elseif($type !== 'consumable' && !$goldOk)
                                    <button disabled class="bg-slate-300 text-slate-600 px-4 py-2 rounded cursor-not-allowed">Gold不足</button>
                                @else
                                    <form action="{{ route('shop.buy', $item) }}" method="POST" id="buyForm_{{ $item->id }}">
                                        @csrf
                                        @if($type === 'consumable')
                                            <input type="hidden" name="quantity" value="1">
                                        @endif
                                        <button type="button"
                                                @click="openBuyModal(@js($item->name), 'buyForm_{{ $item->id }}', @js($type === 'consumable'), @js($maxBuyQuantity))"
                                                class="bg-emerald-600 text-white px-4 py-2 rounded hover:bg-emerald-700 font-bold shadow-sm">
                                            {{ $type === 'consumable' ? '受け取る' : '購入する' }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-8 text-center">
                            <div class="text-sm font-bold text-slate-600">この街では対象の装備を販売していません。</div>
                        </div>
                    @endforelse
                </div>
                
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const tabBtns = document.querySelectorAll('.subtype-tab-btn');
                        const itemCards = document.querySelectorAll('.item-card');
                        
                        if(tabBtns.length === 0) return;
                        
                        tabBtns.forEach(btn => {
                            btn.addEventListener('click', function() {
                                // アクティブなタブのスタイル更新
                                tabBtns.forEach(b => {
                                    b.classList.remove('bg-amber-600', 'text-white', 'shadow-sm', 'hover:bg-amber-700');
                                    b.classList.add('bg-slate-100', 'text-slate-700', 'hover:bg-slate-200');
                                });
                                this.classList.remove('bg-slate-100', 'text-slate-700', 'hover:bg-slate-200');
                                this.classList.add('bg-amber-600', 'text-white', 'shadow-sm', 'hover:bg-amber-700');
                                
                                const filter = this.getAttribute('data-filter');
                                
                                // アイテムの表示・非表示切り替え
                                itemCards.forEach(card => {
                                    if (filter === 'all') {
                                        card.style.display = 'flex';
                                    } else {
                                        if (card.getAttribute('data-subtype') === filter) {
                                            card.style.display = 'flex';
                                        } else {
                                            card.style.display = 'none';
                                        }
                                    }
                                });
                            });
                        });
                    });
                </script>

                <div x-show="buyModal" style="display: none;" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div x-show="buyModal"
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0"
                         x-transition:enter-end="opacity-100"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0"
                         @click="buyModal = false"
                         class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm"></div>

                    <div x-show="buyModal"
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 scale-95"
                         x-transition:enter-end="opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100 scale-100"
                         x-transition:leave-end="opacity-0 scale-95"
                         class="relative bg-white border-2 border-[#d4af37] rounded-xl shadow-2xl w-full max-w-sm p-6 z-10">
                        <p class="text-slate-700 font-bold text-base mb-1">
                            <span class="text-[#d4af37]">🛒</span> <span x-text="buyIsConsumable ? '支給確認' : '購入確認'"></span>
                        </p>
                        <p class="text-slate-600 text-sm mt-2 mb-2">
                            <span class="font-bold text-slate-800" x-text="buyItemName"></span><span x-text="buyIsConsumable ? 'を受け取ります。' : 'を購入します。'"></span>
                        </p>
                        <div x-show="buyIsConsumable" class="my-4 rounded-lg border border-amber-200 bg-amber-50 p-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm font-bold text-slate-700">受け取り数</span>
                                <div class="flex items-center gap-2">
                                    <button type="button"
                                            @click="decreaseBuyQuantity()"
                                            class="h-10 w-10 rounded-lg bg-white border border-amber-300 text-amber-700 font-extrabold text-xl shadow-sm hover:bg-amber-100 transition">
                                        −
                                    </button>
                                    <div class="h-10 min-w-14 px-4 rounded-lg bg-white border border-amber-300 flex items-center justify-center text-lg font-extrabold text-slate-800" x-text="buyQuantity"></div>
                                    <button type="button"
                                            @click="increaseBuyQuantity()"
                                            class="h-10 w-10 rounded-lg bg-white border border-amber-300 text-amber-700 font-extrabold text-xl shadow-sm hover:bg-amber-100 transition">
                                        ＋
                                    </button>
                                </div>
                            </div>
                            <div class="mt-2 text-right text-xs font-bold text-slate-500">
                                最大 <span x-text="buyMaxQuantity"></span> 個まで受け取り可能
                            </div>
                        </div>
                        <div class="flex justify-end gap-3">
                            <button type="button"
                                    @click="buyModal = false"
                                    class="px-4 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold text-sm transition-colors">
                                キャンセル
                            </button>
                            <button type="button"
                                    @click="confirmBuy()"
                                    class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm shadow transition-colors">
                                <span x-text="buyIsConsumable ? '受け取る' : '購入する'"></span>
                            </button>
                        </div>
                    </div>
                </div>

                @if($equipPrompt)
                    <div x-show="equipPromptModal" style="display: none;" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                        <div x-show="equipPromptModal"
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0"
                             x-transition:enter-end="opacity-100"
                             x-transition:leave="transition ease-in duration-150"
                             x-transition:leave-start="opacity-100"
                             x-transition:leave-end="opacity-0"
                             @click="equipPromptModal = false"
                             class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm"></div>

                        <div x-show="equipPromptModal"
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 scale-95"
                             x-transition:enter-end="opacity-100 scale-100"
                             x-transition:leave="transition ease-in duration-150"
                             x-transition:leave-start="opacity-100 scale-100"
                             x-transition:leave-end="opacity-0 scale-95"
                             class="relative bg-white border-2 border-[#d4af37] rounded-xl shadow-2xl w-full max-w-sm p-6 z-10">
                            <p class="text-slate-700 font-bold text-base mb-1">
                                <img src="{{ asset('images/icon/icon_042.webp') }}" alt="" class="w-5 h-5 object-contain inline-block align-middle"> 装備確認
                            </p>
                            <p class="text-slate-600 text-sm mt-2 mb-2">
                                <span class="font-bold text-slate-800">{{ $equipPrompt['item_name'] ?? '受け取った装備' }}</span> を受け取りました。
                            </p>
                            @if($equipPrompt['can_equip'] ?? true)
                                <p class="text-slate-700 text-sm font-bold mb-6">
                                    このまま装備しますか？
                                </p>
                            @else
                                <p class="text-rose-600 text-sm font-bold mb-6 bg-rose-50 border border-rose-100 rounded px-3 py-2">
                                    {{ $equipPrompt['restriction_message'] ?? '現在の職業では装備できません。' }}
                                </p>
                            @endif
                            <div class="flex justify-end gap-3">
                                <button type="button"
                                        @click="equipPromptModal = false"
                                        class="px-4 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold text-sm transition-colors">
                                    あとで
                                </button>
                                @if($equipPrompt['can_equip'] ?? true)
                                    <form action="{{ route('equipment.equip', $equipPrompt['character_item_id']) }}" method="POST">
                                        @csrf
                                        <input type="hidden" name="return_to_shop" value="1">
                                        <button type="submit"
                                                class="px-4 py-2 rounded-lg bg-amber-600 hover:bg-amber-700 text-white font-bold text-sm shadow transition-colors">
                                            装備する
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

            </div>
        </div>
    </div>
</x-layouts.facility>
