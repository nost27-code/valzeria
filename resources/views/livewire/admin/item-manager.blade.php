<div class="w-full px-4 sm:px-6 lg:px-8 py-8">
    @php
        $fieldClass = 'w-full rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-900 shadow-inner placeholder:text-slate-400 focus:border-[#d4af37] focus:bg-white focus:ring-2 focus:ring-[#d4af37]/30';
        $compactFieldClass = 'w-full rounded-md border border-slate-300 bg-slate-50 px-2 py-1.5 text-sm text-slate-900 shadow-inner placeholder:text-slate-400 focus:border-[#d4af37] focus:bg-white focus:ring-2 focus:ring-[#d4af37]/30';
        $checkboxClass = 'rounded border-slate-400 bg-white text-[#1e40af] focus:ring-[#d4af37]';
        $weaponCategories = [
            '' => '未設定',
            'sword' => '剣',
            'axe' => '斧',
            'dagger' => '短剣',
            'bow' => '弓',
            'staff' => '杖',
            'magic_device' => '魔導具',
            'gun' => '銃',
            'spear' => '槍',
            'fist' => '拳甲',
            'katana' => '刀',
        ];
        $armorCategories = [
            '' => '未設定',
            'clothes' => '服・旅装',
            'robe' => 'ローブ・法衣',
            'cloak' => '外套・マント',
            'light_armor' => '革鎧・軽鎧',
            'heavy_armor' => '鎧・重鎧',
        ];
    @endphp

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-bold text-[#1e293b]">アイテム一覧</h1>
            <p class="text-sm text-gray-500 mt-1">ラフ管理版。保存すると稼働中ゲームのショップ・装備一覧に即反映されます。</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button wire:click="createNew('weapon')" class="px-3 py-2 rounded bg-blue-600 text-white text-sm font-bold shadow hover:bg-blue-700">武器を追加</button>
            <button wire:click="createNew('armor')" class="px-3 py-2 rounded bg-slate-700 text-white text-sm font-bold shadow hover:bg-slate-800">防具を追加</button>
            <button wire:click="createNew('accessory')" class="px-3 py-2 rounded bg-purple-600 text-white text-sm font-bold shadow hover:bg-purple-700">装飾/印を追加</button>
        </div>
    </div>

    @if (session()->has('message'))
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded shadow-sm">
            {{ session('message') }}
        </div>
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
        <div class="xl:col-span-4 bg-white p-5 rounded-lg shadow border-t-4 border-[#d4af37] h-fit">
            <h2 class="text-xl font-bold text-gray-800 mb-4">{{ $editingItemId ? 'アイテム編集' : '新しく装備を追加する' }}</h2>
            <form wire:submit.prevent="save" class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">名前</label>
                    <input type="text" wire:model="form.name" class="{{ $fieldClass }}">
                    @error('form.name') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">種別</label>
                        <select wire:model="form.type" class="{{ $compactFieldClass }}">
                            <option value="weapon">武器</option>
                            <option value="armor">防具</option>
                            <option value="accessory">装飾/印</option>
                            <option value="consumable">消耗品</option>
                            <option value="material">素材</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">サブ種別</label>
                        <input type="text" wire:model="form.sub_type" placeholder="剣 / 鎧 / 印 など" class="{{ $compactFieldClass }}">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">レアリティ</label>
                        <select wire:model="form.rarity" class="{{ $compactFieldClass }}">
                            <option value="normal">normal</option>
                            <option value="rare">rare</option>
                            <option value="epic">epic</option>
                            <option value="legend">legend</option>
                            <option value="S">S</option>
                            <option value="A">A</option>
                            <option value="B">B</option>
                            <option value="C">C</option>
                            <option value="D">D</option>
                            <option value="E">E</option>
                            <option value="F">F</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">属性</label>
                        <input type="text" wire:model="form.element" class="{{ $compactFieldClass }}">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">説明</label>
                    <textarea wire:model="form.description" rows="2" class="{{ $fieldClass }}"></textarea>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">武器カテゴリ</label>
                        <select wire:model="form.weapon_category" class="{{ $compactFieldClass }}">
                            @foreach($weaponCategories as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">防具カテゴリ</label>
                        <select wire:model="form.armor_category" class="{{ $compactFieldClass }}">
                            @foreach($armorCategories as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">価格</label>
                        <input type="number" wire:model="form.price" class="{{ $compactFieldClass }}">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">売却</label>
                        <input type="number" wire:model="form.sell_price" class="{{ $compactFieldClass }}">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">必要Lv</label>
                        <input type="number" wire:model="form.required_level" class="{{ $compactFieldClass }}">
                    </div>
                </div>

                <div class="grid grid-cols-4 gap-2">
                    @foreach(['hp_bonus' => 'HP', 'mp_bonus' => 'SP', 'str_bonus' => '攻撃', 'def_bonus' => '防御', 'agi_bonus' => '敏捷', 'mag_bonus' => '魔力', 'spr_bonus' => '精神', 'luk_bonus' => '運'] as $field => $label)
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">{{ $label }}</label>
                            <input type="number" wire:model="form.{{ $field }}" class="{{ $compactFieldClass }}">
                        </div>
                    @endforeach
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">販売する街</label>
                        <select wire:model="form.unlock_city_id" class="{{ $compactFieldClass }}">
                            <option value="">全街/指定なし</option>
                            @foreach($cities as $city)
                                <option value="{{ $city->id }}">{{ $city->id }}: {{ $city->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">並び順</label>
                        <input type="number" wire:model="form.sort_order" class="{{ $compactFieldClass }}">
                    </div>
                </div>

                <div class="flex flex-wrap gap-4 text-sm font-bold">
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" wire:model="form.is_shop_item" class="{{ $checkboxClass }}">
                        店売り
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" wire:model="form.is_active" class="{{ $checkboxClass }}">
                        公開する
                    </label>
                </div>

                <div class="flex gap-2 pt-2">
                    <button type="submit" class="flex-1 bg-[#1e40af] hover:bg-[#1e3a8a] text-white font-bold py-2 rounded shadow">
                        {{ $editingItemId ? '更新する' : '追加する' }}
                    </button>
                    <button type="button" wire:click="resetForm" class="px-4 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-2 rounded border">
                        クリア
                    </button>
                </div>
            </form>
        </div>

        <div class="xl:col-span-8 bg-white p-5 rounded-lg shadow">
            <div class="flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between mb-4">
                <h2 class="text-xl font-bold text-gray-800">登録済みアイテム</h2>
                <div class="flex gap-2">
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="名前で検索" class="rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-900 shadow-inner placeholder:text-slate-400 focus:border-[#d4af37] focus:bg-white focus:ring-2 focus:ring-[#d4af37]/30">
                    <select wire:model.live="typeFilter" class="rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-900 shadow-inner focus:border-[#d4af37] focus:bg-white focus:ring-2 focus:ring-[#d4af37]/30">
                        <option value="all">全種別</option>
                        <option value="weapon">武器</option>
                        <option value="armor">防具</option>
                        <option value="accessory">装飾/印</option>
                        <option value="consumable">消耗品</option>
                        <option value="material">素材</option>
                    </select>
                    <select wire:model.live="perPage" class="rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-900 shadow-inner focus:border-[#d4af37] focus:bg-white focus:ring-2 focus:ring-[#d4af37]/30">
                        <option value="30">30件</option>
                        <option value="60">60件</option>
                        <option value="100">100件</option>
                    </select>
                </div>
            </div>

            <div class="mb-3 text-xs font-bold text-slate-500">
                {{ number_format($items->total()) }} 件中 {{ number_format($items->firstItem() ?? 0) }} - {{ number_format($items->lastItem() ?? 0) }} 件を表示
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-500">
                        <tr>
                            <th class="px-3 py-2 text-left">ID</th>
                            <th class="px-3 py-2 text-left">名前</th>
                            <th class="px-3 py-2 text-left">種別</th>
                            <th class="px-3 py-2 text-left">能力</th>
                            <th class="px-3 py-2 text-left">店</th>
                            <th class="px-3 py-2 text-left">状態</th>
                            <th class="px-3 py-2 text-right">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($items as $item)
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-2 text-gray-400">{{ $item->id }}</td>
                                <td class="px-3 py-2">
                                    <div class="font-bold text-gray-800">{{ $item->name }}</div>
                                    <div class="text-xs text-gray-400">{{ $item->rarity }} / Lv{{ $item->required_level }}</div>
                                </td>
                                <td class="px-3 py-2">
                                    <span class="px-2 py-1 rounded bg-slate-100 text-slate-700 text-xs font-bold">{{ $item->type }}</span>
                                    @if($item->sub_type)
                                        <span class="text-xs text-gray-500 ml-1">{{ $item->sub_type }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs text-blue-700 font-bold">
                                    @foreach(['hp_bonus' => 'HP', 'mp_bonus' => 'SP', 'str_bonus' => '攻', 'def_bonus' => '防', 'agi_bonus' => '敏', 'mag_bonus' => '魔', 'spr_bonus' => '精', 'luk_bonus' => '運'] as $field => $label)
                                        @if((int) $item->{$field} !== 0)
                                            <span class="mr-1">{{ $label }}{{ $item->{$field} > 0 ? '+' : '' }}{{ $item->{$field} }}</span>
                                        @endif
                                    @endforeach
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    @if($item->is_shop_item)
                                        <span class="text-green-700 font-bold">店売り</span>
                                        <div class="text-gray-400">{{ $item->unlock_city_id ? '街ID ' . $item->unlock_city_id : '全街' }}</div>
                                    @else
                                        <span class="text-gray-400">非売品</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <button wire:click="toggleActive({{ $item->id }})" class="px-2 py-1 rounded text-xs font-bold {{ $item->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                                        {{ $item->is_active ? '公開中' : '非公開' }}
                                    </button>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button wire:click="edit({{ $item->id }})" class="px-3 py-1 rounded bg-white border border-gray-300 text-gray-700 font-bold hover:bg-gray-100">
                                        編集
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $items->links() }}
            </div>
        </div>
    </div>
</div>
