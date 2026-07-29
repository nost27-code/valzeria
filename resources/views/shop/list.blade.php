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

                @if($type === 'weapon' && !empty($jobCombatGuide))
                    <div class="mb-5 rounded-xl border border-indigo-200 bg-indigo-50/70 p-3 sm:p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-extrabold text-slate-900">
                                現在職：{{ $jobCombatGuide['job_name'] }}
                            </span>
                            <span class="rounded-full border border-indigo-200 bg-white px-2.5 py-1 text-xs font-extrabold text-indigo-700">
                                通常攻撃：{{ $jobCombatGuide['normal_attack_reference'] }}
                            </span>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                            <span class="font-extrabold text-slate-700">適正武器：</span>
                            @forelse($jobCombatGuide['weapon_labels'] as $weaponLabel)
                                <span class="rounded-full border border-emerald-200 bg-white px-2.5 py-1 font-extrabold text-emerald-700">
                                    {{ $weaponLabel }}
                                </span>
                            @empty
                                <span class="font-bold text-slate-500">未設定</span>
                            @endforelse
                        </div>
                        <p class="mt-2 text-[11px] font-bold leading-relaxed text-slate-600">
                            @if($jobCombatGuide['non_proficient_enabled'])
                                適正武器は装備効果100%。適性外は武器種ごとに効果が下がります。
                            @else
                                現在は適正武器のみ装備できます。
                            @endif
                            必殺技・奥義の参照先は職業詳細で個別に確認できます。
                        </p>
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
                                $shopStatLabels = [
                                    'hp' => 'HP',
                                    'mp' => 'SP',
                                    'str' => '攻撃',
                                    'def' => '防御',
                                    'agi' => '敏捷',
                                    'mag' => '魔力',
                                    'spr' => '精神',
                                    'luk' => '運',
                                ];
                            @endphp

                            @foreach($displaySlots as $slot)
                                @php 
                                    $equip = $equippedItems[$slot] ?? null;
                                    $currentPerformance = $equippedPerformanceStats[$slot] ?? [];
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
                                            <span>{{ $equip->displayName(false) }}</span>
                                            @if($equip->item->element) <span class="text-[10px] bg-purple-100 text-purple-600 px-1 py-0.5 rounded ml-1 font-normal">{{ $equip->item->element }}属性</span> @endif
                                        </div>
                                        @if(collect($currentPerformance)->contains(fn ($value) => (int) $value !== 0))
                                            <div class="mt-1 flex flex-wrap items-center gap-x-2 text-[10px] font-semibold leading-tight text-amber-700 sm:text-xs">
                                                <span class="text-amber-900">{{ $slotName }}性能：</span>
                                                @foreach($shopStatLabels as $statKey => $statLabel)
                                                    @php $performanceValue = (int) ($currentPerformance[$statKey] ?? 0); @endphp
                                                    @if($performanceValue !== 0)
                                                        <span>{{ $statLabel }} {{ $performanceValue > 0 ? '+' : '' }}{{ number_format($performanceValue) }}</span>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif
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

                <div class="space-y-4" id="item-list">
                    @php
                        $character = $character ?? Auth::user()->currentCharacter();
                        $ownedItemCounts = $ownedItemCounts ?? [];
                        $equipmentGuides = $equipmentGuides ?? [];
                        $permissionService = app(\App\Services\EquipmentPermissionService::class);
                        $shopService = app(\App\Services\ShopService::class);
                    @endphp
                    @forelse($items as $item)
                        @php
                            $equipmentGuide = $equipmentGuides[(int) $item->id] ?? null;
                            $equipmentPreview = $equipmentGuide['preview'] ?? null;
                            $categoryLabel = $permissionService->categoryLabel($item);
                            $canEquipByJob = $equipmentGuide['can_equip'] ?? true;
                            $restrictionJobs = $equipmentGuide['restriction_jobs'] ?? [];
                            $displayPrice = $character ? $shopService->priceFor($character, $item) : (int) $item->price;
                            $ownedCount = $ownedItemCounts[$item->id] ?? 0;
                            $equipmentIcon = $type !== 'consumable' ? $item->iconImagePath() : null;
                            $showCategoryLabel = $categoryLabel && $categoryLabel !== (string) $item->sub_type;
                        @endphp
                        <div class="item-card border border-[#d4af37]/50 rounded-lg p-4 flex flex-col sm:flex-row justify-between items-start sm:items-center hover:border-[#d4af37] transition-colors" data-subtype="{{ $item->sub_type }}">
                            <div class="mb-4 sm:mb-0">
                                <h3 class="flex flex-wrap items-center gap-1.5 font-bold text-lg text-slate-800">
                                    @if($equipmentIcon)
                                        <img src="{{ asset($equipmentIcon) }}" alt="" class="h-7 w-7 shrink-0 object-contain">
                                    @endif
                                    @if($type !== 'consumable')
                                        @include('equipment.partials.rank-label', ['item' => $item])
                                    @endif
                                    <span>{{ $item->name }}</span>
                                    @if($item->sub_type) <span class="text-xs bg-slate-200 text-slate-600 px-2 py-1 rounded ml-2">{{ $item->sub_type }}</span> @endif
                                    @if($item->element) <span class="text-xs bg-purple-100 text-purple-600 px-2 py-1 rounded ml-1">{{ $item->element }}属性</span> @endif
                                    @if($showCategoryLabel) <span class="text-xs bg-slate-100 text-slate-600 border border-slate-200 px-2 py-1 rounded ml-1">{{ $categoryLabel }}</span> @endif
                                </h3>
                                <p class="mt-1 text-xs font-semibold leading-relaxed text-slate-500">{{ $item->description }}</p>
                                @if($equipmentGuide && $canEquipByJob)
                                    <div class="mt-2">
                                        @if($equipmentGuide['native_proficiency'])
                                            <span class="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[11px] font-extrabold text-emerald-700">
                                                {{ $item->type === 'weapon' ? '適正武器' : '適正防具' }}・装備効果100%
                                            </span>
                                        @else
                                            <span class="inline-flex rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] font-extrabold text-amber-700">
                                                適性外・装備効果{{ $equipmentGuide['performance_percent'] }}%
                                            </span>
                                        @endif
                                    </div>
                                @endif
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
                                        @if($equipmentPreview)
                                            @if($canEquipByJob)
                                                <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50/80 p-2.5">
                                                    <div class="flex flex-wrap items-baseline justify-between gap-1">
                                                        <p class="text-xs font-extrabold text-slate-700">装備後の能力</p>
                                                        <p class="text-[10px] font-semibold text-slate-500">現在装備から交換した場合</p>
                                                    </div>
                                                    <div class="mt-2 grid grid-cols-2 gap-1.5 sm:grid-cols-3 xl:grid-cols-4">
                                                        @foreach($equipmentPreview['visible_stats'] as $statKey)
                                                            @php
                                                                $statLabel = $shopStatLabels[$statKey];
                                                                $afterValue = (int) $equipmentPreview['after_stats'][$statKey];
                                                                $delta = (int) $equipmentPreview['deltas'][$statKey];
                                                                $deltaClass = $delta > 0
                                                                    ? 'text-emerald-700 bg-emerald-100'
                                                                    : ($delta < 0 ? 'text-rose-700 bg-rose-100' : 'text-slate-500 bg-slate-100');
                                                                $deltaText = $delta > 0
                                                                    ? number_format($delta) . '上昇'
                                                                    : ($delta < 0 ? number_format(abs($delta)) . '低下' : '変化なし');
                                                            @endphp
                                                            <div class="rounded border border-slate-200 bg-white p-1.5" data-shop-after-stat="{{ $statKey }}">
                                                                <div class="text-[11px] font-semibold text-slate-500">{{ $statLabel }}</div>
                                                                <div class="mt-0.5 flex flex-wrap items-center gap-1">
                                                                    <span class="text-sm font-extrabold text-slate-800">{{ number_format($afterValue) }}</span>
                                                                    <span class="rounded px-1 py-0.5 text-[10px] font-bold {{ $deltaClass }}">{{ $deltaText }}</span>
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif

                                            <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50/70 p-2.5">
                                                <div class="flex flex-wrap items-baseline justify-between gap-1">
                                                    <p class="text-xs font-extrabold text-amber-800">
                                                        {{ $item->type === 'weapon' ? '武器性能' : '防具性能' }}
                                                    </p>
                                                    <p class="text-[10px] font-semibold text-amber-700">
                                                        {{ $canEquipByJob ? '現在職で実際に反映される値' : '強化前の基本値' }}
                                                    </p>
                                                </div>
                                                <div class="mt-2 grid grid-cols-2 gap-1.5 sm:grid-cols-3 xl:grid-cols-4">
                                                    @foreach($equipmentPreview['visible_stats'] as $statKey)
                                                        @php
                                                            $statLabel = $shopStatLabels[$statKey];
                                                            $effectiveValue = (int) $equipmentPreview['effective_stats'][$statKey];
                                                            $rawValue = (int) $equipmentPreview['raw_stats'][$statKey];
                                                        @endphp
                                                        <div class="rounded border border-amber-200 bg-white px-2 py-1.5" data-shop-effective-stat="{{ $statKey }}">
                                                            <div class="flex items-baseline justify-between gap-1">
                                                                <span class="text-[11px] font-semibold text-slate-500">{{ $statLabel }}</span>
                                                                <span class="text-sm font-extrabold text-slate-800">{{ $effectiveValue > 0 ? '+' : '' }}{{ number_format($effectiveValue) }}</span>
                                                            </div>
                                                            @if($rawValue !== $effectiveValue)
                                                                <div class="mt-0.5 text-right text-[10px] font-semibold text-amber-700">
                                                                    補正前 {{ $rawValue > 0 ? '+' : '' }}{{ number_format($rawValue) }}
                                                                </div>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
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
