<x-layouts.facility title="装備変更" headerIconImage="images/icon/icon_006.webp" bgImage="images/bg-castle.webp">
    <div class="py-12 w-full mx-auto sm:px-6 lg:px-8">
        @php
            $sortOptions = [
                'recommend' => 'おすすめ順',
                'str' => '攻撃が高い順',
                'def' => '防御が高い順',
                'mag' => '魔力が高い順',
                'agi' => '敏捷が高い順',
                'rank' => 'ランクが高い順',
                'new' => '新しく入手した順',
            ];

            $typeTabs = [
                'weapon' => [
                    'icon_image' => 'images/icon/icon_006.webp',
                    'label' => '武器',
                    'inventory' => $weapons,
                    'empty_inventory' => '倉庫に武器はありません。',
                ],
                'armor' => [
                    'icon_image' => 'images/icon/icon_007.webp',
                    'label' => '防具',
                    'inventory' => $armors,
                    'empty_inventory' => '倉庫に防具はありません。',
                ],
                'accessory' => [
                    'icon_image' => 'images/icon/icon_008.webp',
                    'label' => '装飾品',
                    'inventory' => $accessories,
                    'empty_inventory' => '倉庫に装飾品はありません。',
                ],
            ];
        @endphp

        <div class="w-full space-y-6">
            <div class="bg-white p-6 rounded-lg shadow-sm border border-slate-200"
                 x-data="{
                    activeTab: @js(in_array(session('activeTab', 'weapon'), ['weapon', 'armor', 'accessory'], true) ? session('activeTab', 'weapon') : 'weapon'),
                    sortBy: {},
                    visible: {},
                    step: 20,
                    tabs: ['weapon', 'armor', 'accessory'],
                    keyOf(tab) {
                        return 'inventory_' + tab;
                    },
                    init() {
                        if (!this.tabs.includes(this.activeTab)) this.activeTab = 'weapon';
                        this.tabs.forEach((tab) => {
                            const key = this.keyOf(tab);
                            this.sortBy[key] = 'recommend';
                            this.visible[key] = 20;
                        });
                        this.$nextTick(() => {
                            this.tabs.forEach((tab) => this.sortItems(tab));
                        });
                    },
                    list(tab) {
                        return this.$refs[this.keyOf(tab) + 'List'];
                    },
                    rows(tab) {
                        const list = this.list(tab);
                        return list ? Array.from(list.querySelectorAll('.equipment-item')) : [];
                    },
                    sortItems(tab) {
                        const list = this.list(tab);
                        if (!list) return;
                        const stateKey = this.keyOf(tab);
                        const sortKey = this.sortBy[stateKey] || 'recommend';
                        this.rows(tab)
                            .sort((a, b) => Number(b.getAttribute('data-sort-' + sortKey) || 0) - Number(a.getAttribute('data-sort-' + sortKey) || 0))
                            .forEach((row) => list.appendChild(row));
                        this.applyVisibility(tab);
                    },
                    applyVisibility(tab) {
                        const stateKey = this.keyOf(tab);
                        this.rows(tab).forEach((row, index) => {
                            row.style.display = index < this.visible[stateKey] ? '' : 'none';
                        });
                    },
                    showMore(tab) {
                        const stateKey = this.keyOf(tab);
                        this.visible[stateKey] += this.step;
                        this.applyVisibility(tab);
                    },
                    hasMore(tab) {
                        return this.rows(tab).length > this.visible[this.keyOf(tab)];
                    }
                 }">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-extrabold text-slate-800 tracking-wider">装備変更</h2>
                        <div class="mt-1 text-sm font-black text-amber-700">所持Gold {{ number_format((int) ($character->money ?? 0)) }}G</div>
                    </div>
                </div>

                @if(session('status'))
                    <div class="equipment-flash bg-amber-50 border border-amber-200 text-amber-700 px-4 py-3 rounded mb-4 shadow-sm">
                        {{ session('status') }}
                    </div>
                @endif
                @if(session('error'))
                    <div class="equipment-flash bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4 shadow-sm">
                        {{ session('error') }}
                    </div>
                @endif
                <div id="equipment-inline-message" class="equipment-flash hidden px-4 py-3 rounded mb-4 shadow-sm font-bold"></div>

                <div class="flex space-x-1 border-b-2 border-slate-300 mb-4 overflow-x-auto">
                    @foreach($typeTabs as $key => $tab)
                        <button type="button"
                                @click="activeTab = '{{ $key }}'; sortItems(activeTab)"
                                class="flex-1 py-3 px-2 sm:px-4 font-bold text-center rounded-t-lg transition-all duration-150 transform active:scale-95 outline-none whitespace-nowrap text-sm sm:text-base"
                                :class="activeTab === '{{ $key }}' ? 'bg-slate-800 text-white border-b-4 border-white shadow-inner' : 'bg-slate-100 text-slate-500 hover:bg-slate-200 border-b-4 border-transparent'">
                            <img src="{{ asset($tab['icon_image']) }}" alt="" class="w-5 h-5 object-contain mr-1 inline-block">{{ $tab['label'] }}
                        </button>
                    @endforeach
                </div>

                <div class="min-h-[400px]">
                    @foreach($typeTabs as $key => $tab)
                        @php
                            $items = $tab['inventory'];
                            $emptyText = $tab['empty_inventory'];
                            $countKey = 'inventory_' . $key;
                        @endphp
                        <div x-show="activeTab === '{{ $key }}'"
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 translate-y-2"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             style="display: none;">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-3">
                                <div class="text-sm text-slate-500" data-equipment-count="{{ $countKey }}" data-count="{{ $items->count() }}" data-prefix="倉庫の{{ $tab['label'] }}">
                                    倉庫の{{ $tab['label'] }} {{ $items->count() }}件
                                </div>
                                <label class="flex items-center gap-2 text-sm font-bold text-slate-600">
                                    ソート
                                    <select x-model="sortBy[keyOf('{{ $key }}')]"
                                            @change="sortItems('{{ $key }}')"
                                            class="rounded-md border-slate-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                        @foreach($sortOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>

                            <div class="space-y-2" x-ref="{{ $countKey }}List">
                                @forelse($items as $ci)
                                    @include('equipment.partials.item-row', ['ci' => $ci, 'mode' => 'inventory', 'tabKey' => $key])
                                @empty
                                    <div class="text-center py-10 bg-white rounded-lg border border-slate-200 border-dashed" data-empty-state="{{ $countKey }}">
                                        <p class="text-slate-500">{{ $emptyText }}</p>
                                    </div>
                                @endforelse
                                @if($items->count() > 0)
                                    <div class="hidden text-center py-10 bg-white rounded-lg border border-slate-200 border-dashed" data-empty-state="{{ $countKey }}">
                                        <p class="text-slate-500">{{ $emptyText }}</p>
                                    </div>
                                @endif
                            </div>

                            <div class="mt-4 text-center" x-show="hasMore('{{ $key }}')" style="display: none;">
                                <button type="button"
                                        @click="showMore('{{ $key }}')"
                                        class="inline-flex items-center justify-center rounded-md bg-slate-800 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-slate-700 active:scale-95">
                                    もっと見る
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <script>
                    (() => {
                        if (window.__valzeriaEquipmentAsyncBound) {
                            return;
                        }
                        window.__valzeriaEquipmentAsyncBound = true;

                        function showMessage(text, isSuccess) {
                            const message = document.getElementById('equipment-inline-message');
                            if (!message) return;
                            message.textContent = text;
                            message.classList.remove('hidden', 'bg-amber-50', 'border-amber-200', 'text-amber-700', 'bg-red-50', 'border-red-200', 'text-red-700');
                            message.classList.add('border');
                            if (isSuccess) {
                                message.classList.add('bg-amber-50', 'border-amber-200', 'text-amber-700');
                            } else {
                                message.classList.add('bg-red-50', 'border-red-200', 'text-red-700');
                            }
                        }

                        function updateCount(key, delta) {
                            const countEl = document.querySelector(`[data-equipment-count="${key}"]`);
                            if (!countEl) return;

                            const next = Math.max(0, Number(countEl.dataset.count || 0) + delta);
                            countEl.dataset.count = String(next);
                            countEl.textContent = `${countEl.dataset.prefix} ${next}件`;

                            const empty = document.querySelector(`[data-empty-state="${key}"]`);
                            if (empty) {
                                empty.classList.toggle('hidden', next > 0);
                            }
                        }

                        function revealNextHidden(list) {
                            if (!list) return;
                            const nextHidden = Array.from(list.querySelectorAll('.equipment-item'))
                                .find((item) => item.style.display === 'none');
                            if (nextHidden) nextHidden.style.display = '';
                        }

                        function removeRow(row, key) {
                            if (!row) return;
                            const list = row.parentElement;
                            row.classList.add('opacity-0', 'scale-[0.98]');
                            setTimeout(() => {
                                row.remove();
                                revealNextHidden(list);
                                updateCount(key, -1);
                            }, 180);
                        }

                        function ensureEquippedBadge(row) {
                            const title = row.querySelector('h4');
                            let badge = row.querySelector('.equipment-equipped-badge');
                            if (!badge && title) {
                                badge = document.createElement('span');
                                badge.className = 'equipment-equipped-badge text-xs bg-amber-200 text-amber-800 px-2 py-0.5 rounded font-bold';
                                badge.textContent = '現在装備中';
                                title.appendChild(badge);
                            }
                        }

                        function removeEquippedBadge(row) {
                            row.querySelector('.equipment-equipped-badge')?.remove();
                        }

                        function setLockState(row, locked) {
                            if (!row) return;

                            row.dataset.locked = locked ? '1' : '0';

                            const form = row.querySelector('.equipment-lock-form');
                            const button = form?.querySelector('[data-action-button]');
                            if (button) {
                                button.disabled = false;
                                button.title = locked ? '保護を解除する' : '保護する（売却・破棄防止）';
                                button.classList.remove('opacity-70', 'text-yellow-500', 'bg-yellow-50', 'border', 'border-yellow-300', 'shadow-sm', 'text-slate-300', 'hover:text-yellow-400', 'bg-transparent');
                                if (locked) {
                                    button.classList.add('text-yellow-500', 'bg-yellow-50', 'border', 'border-yellow-300', 'shadow-sm');
                                } else {
                                    button.classList.add('text-slate-300', 'hover:text-yellow-400', 'bg-transparent');
                                }
                                const star = button.querySelector('.equipment-lock-star');
                                if (star) star.textContent = locked ? '★' : '☆';
                            }

                            setSellState(row);
                        }

                        function setSellState(row) {
                            if (!row) return;

                            const form = row.querySelector('[data-equipment-action="sell"]');
                            const button = form?.querySelector('[data-action-button]');
                            if (!button) return;

                            const canSell = row.dataset.canSell === '1'
                                && row.dataset.locked !== '1'
                                && row.dataset.equipped !== '1';

                            button.disabled = !canSell;
                            button.classList.remove('bg-amber-600', 'text-white', 'hover:bg-amber-700', 'bg-slate-200', 'text-slate-400', 'cursor-not-allowed', 'opacity-70');
                            button.classList.add(...(canSell
                                ? ['bg-amber-600', 'text-white', 'hover:bg-amber-700']
                                : ['bg-slate-200', 'text-slate-400', 'cursor-not-allowed']));
                        }

                        function setToggleForm(row, equipped) {
                            const form = row.querySelector('.equipment-toggle-form');
                            const button = form?.querySelector('[data-action-button]');
                            if (!form || !button) return;

                            row.dataset.equipped = equipped ? '1' : '0';
                            form.dataset.equipmentAction = equipped ? 'unequip' : 'equip';
                            form.action = equipped ? row.dataset.unequipUrl : row.dataset.equipUrl;
                            button.disabled = false;
                            button.textContent = equipped ? 'はずす' : '装備する';
                            button.classList.remove('opacity-70', 'bg-amber-600', 'hover:bg-amber-700', 'bg-amber-500', 'hover:bg-amber-600');
                            button.classList.add(equipped ? 'bg-amber-500' : 'bg-amber-600', equipped ? 'hover:bg-amber-600' : 'hover:bg-amber-700');

                            row.classList.toggle('border-amber-300', equipped);
                            row.classList.toggle('bg-amber-50', equipped);
                            row.classList.toggle('shadow-sm', equipped);
                            if (equipped) {
                                row.classList.remove('border-slate-200');
                                ensureEquippedBadge(row);
                            } else {
                                row.classList.add('border-slate-200');
                                removeEquippedBadge(row);
                            }
                            setSellState(row);
                        }

                        function setRowsUnequipped(ids) {
                            if (!Array.isArray(ids)) return;
                            ids.forEach((id) => {
                                const row = document.querySelector(`.equipment-item[data-character-item-id="${id}"]`);
                                if (row) setToggleForm(row, false);
                            });
                        }

                        function resetButton(button, label) {
                            if (!button) return;
                            button.disabled = false;
                            button.textContent = label;
                            button.classList.remove('opacity-70');
                        }

                        document.addEventListener('submit', async function(event) {
                            const form = event.target.closest('.equipment-action-form');
                            if (!form) return;

                            event.preventDefault();

                            const action = form.dataset.equipmentAction;
                            const row = form.closest('.equipment-item');
                            const button = form.querySelector('[data-action-button]');
                            const originalText = button?.textContent || '';
                            const isLockAction = action === 'lock';
                            const pendingText = {
                                equip: '装備中...',
                                unequip: '解除中...',
                                store: '送信中...',
                                unstore: '移動中...',
                            }[action] || '処理中...';

                            if (button) {
                                button.disabled = true;
                                if (!isLockAction) button.textContent = pendingText;
                                button.classList.add('opacity-50');
                            }

                            try {
                                const response = await fetch(form.action, {
                                    method: 'POST',
                                    headers: {
                                        'Accept': 'application/json',
                                        'X-Requested-With': 'XMLHttpRequest',
                                    },
                                    body: new FormData(form),
                                });
                                const data = await response.json();

                                if (!response.ok || data.success !== true) {
                                    throw new Error(data.message || '処理に失敗しました。');
                                }

                                showMessage(data.message || '処理しました。', true);

                                if (action === 'equip') {
                                    setRowsUnequipped(data.unequipped_ids);
                                    if (row) {
                                        setToggleForm(row, true);
                                    }
                                } else if (action === 'unequip') {
                                    if (row) setToggleForm(row, false);
                                } else if (action === 'lock') {
                                    if (row) setLockState(row, data.is_locked === true);
                                } else if (action === 'unstore') {
                                    removeRow(row, `${data.active_mode}_${data.active_tab}`);
                                    setTimeout(() => window.location.reload(), 250);
                                } else if (['store', 'sell'].includes(action)) {
                                    removeRow(row, `${data.active_mode}_${data.active_tab}`);
                                }
                            } catch (error) {
                                showMessage(error.message || '処理に失敗しました。', false);
                                if (isLockAction && button) {
                                    button.disabled = false;
                                    button.classList.remove('opacity-50');
                                } else {
                                    resetButton(button, originalText);
                                }
                            }
                        });
                    })();
                </script>
            </div>
        </div>
    </div>
</x-layouts.facility>
