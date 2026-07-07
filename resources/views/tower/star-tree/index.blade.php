@php
    $towerUi = $towerUi ?? [];
    $towerAssets = $towerUi['assets'] ?? [];
    $towerName = (string) ($towerUi['name'] ?? '星樹の塔');
    $towerExitLabel = (string) ($towerUi['exit_label'] ?? ($towerName.'から出る'));
    $towerLocationName = (string) ($towerUi['location_name'] ?? '精霊の森エルフィア');
    $towerSymbolImage = (string) ($towerAssets['symbol'] ?? 'images/tower/01_tower_symbol.webp');
    $towerBackgroundImage = (string) ($towerAssets['background'] ?? 'images/tower/01_tower.webp');
    $towerLogoImage = (string) ($towerAssets['logo'] ?? 'images/tower/01_tower_logo.webp');
    $title = $activeRun
        ? $towerName . ' ' . number_format((int) $activeRun->current_floor) . '階'
        : $towerName;
    $bestClearedFloor = (int) ($characterRecord->best_cleared_floor ?? 0);
    $weeklyBestClearedFloor = (int) ($weeklyRecord->best_cleared_floor ?? 0);
    $currentFloorNumber = (int) ($activeRun->current_floor ?? 1);
    $checkpointStartFloor = (int) ($checkpointStartFloor ?? 1);
    $isMerchantPending = $activeRun?->pending_event === \App\Services\TowerMerchantService::PENDING_EVENT;
    $continueRoute = $isMerchantPending
        ? route('tower.star-tree.merchant.resume')
        : route('tower.star-tree.challenge');
    $continueLoadingText = $isMerchantPending ? '戻り中...' : '挑戦中...';
    $continueText = $isMerchantPending ? '行商人のところへ戻る' : 'つづきから';
    $entryStaminaCost = (int) ($entryFloor?->stamina_cost ?? $currentFloor?->stamina_cost ?? 0);
    $restartStaminaCost = (int) ($restartFloor?->stamina_cost ?? 1);
    $supportItemCounts = $supportItemCounts ?? [];
    $hasScoutedEntryFloor = (bool) ($hasScoutedEntryFloor ?? false);
    $towerActionStrategies = collect($towerActionStrategies ?? [])
        ->reject(fn ($strategy) => $hasScoutedEntryFloor && (string) ($strategy['key'] ?? '') === 'scout')
        ->values();
    $staminaRecoveryChoices = collect(['explore_stamina_small_bottle', 'explore_stamina_potion'])
        ->map(function (string $itemKey) use ($supportItemCounts) {
            $item = config("adventure_support.items.{$itemKey}");
            if (!$item) {
                return null;
            }

            return [
                'key' => $itemKey,
                'name' => (string) ($item['name'] ?? $itemKey),
                'icon_image' => $item['icon_image'] ?? null,
                'effect_value' => (int) ($item['effect_value'] ?? 0),
                'price' => (int) ($item['price'] ?? 0),
                'quantity' => (int) ($supportItemCounts[$itemKey] ?? 0),
                'use_url' => route('inventory.support-items.use', ['itemKey' => $itemKey]),
                'purchase_url' => route('kiseki.support.purchase'),
            ];
        })
        ->filter()
        ->values();
    $currentKiseki = (int) ($character->free_kiseki ?? 0) + (int) ($character->paid_kiseki ?? 0);
@endphp

<x-layouts.facility
    :title="$title"
    :headerIconImage="$towerSymbolImage"
    headerOverlayClass="bg-transparent"
    headerTitleClass="text-white"
    headerBorderClass="border-transparent"
    headerShellStyle="display: none;"
    :showExit="false"
    :pageBgImage="$towerBackgroundImage"
    pageBgOverlay="bg-slate-950/28"
    :showGameHeader="true"
    :gameHeaderShowCityPanel="false"
>
    <div class="mx-auto w-full max-w-xl px-1 pt-3">
        <a href="{{ route('home') }}"
           x-data="{ loading: false }"
           @click="if (loading) { $event.preventDefault(); return; } if (!$event.defaultPrevented && !$event.metaKey && !$event.ctrlKey && !$event.shiftKey && $event.button === 0) { $event.preventDefault(); loading = true; setTimeout(() => { window.location.href = $el.href }, 80); }"
           :class="loading ? 'pointer-events-none opacity-80' : ''"
           :aria-busy="loading ? 'true' : 'false'"
           class="inline-flex items-center gap-1.5 rounded-full bg-slate-950/35 px-3 py-1.5 text-xs font-black text-white shadow-lg backdrop-blur transition hover:bg-slate-950/55">
            <svg x-show="loading" style="display: none;" class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
            </svg>
            <span x-text="loading ? '移動中...' : @js('← '.$towerExitLabel)">{{ '← '.$towerExitLabel }}</span>
        </a>
    </div>

    <div class="mx-auto flex min-h-[74vh] w-full max-w-xl flex-col justify-between pb-8">
        @if(session('error'))
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700 shadow-sm">
                {{ session('error') }}
            </div>
        @endif

        @if(session('message'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700 shadow-sm">
                {{ session('message') }}
            </div>
        @endif

        @if(!empty($unlockedTitleNames))
            <div class="rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm font-bold text-yellow-800 shadow-sm">
                <div class="text-xs font-black text-yellow-700">称号獲得</div>
                <div class="mt-1">称号を獲得しました：{{ implode('、', $unlockedTitleNames) }}</div>
            </div>
        @endif

        <div class="pt-[12vh] text-center">
            <img
                src="{{ asset($towerLogoImage) }}"
                alt="{{ $towerName }}"
                loading="eager"
                fetchpriority="high"
                decoding="async"
                x-data="{ bgReady: window.__facilityBgReady || false, logoReady: false }"
                x-init="if ($el.complete) logoReady = true"
                @facility-bg-loaded.window="bgReady = true"
                x-bind:class="(bgReady && logoReady) ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-1'"
                @load="logoReady = true"
                class="mx-auto w-[min(92vw,28rem)] object-contain drop-shadow-[0_5px_16px_rgba(0,0,0,0.78)] transition-all duration-700 ease-out"
            >
        </div>

        <div class="space-y-4">
            @if(!$canAccess)
                <section class="rounded-lg bg-white/88 p-5 text-center shadow-xl backdrop-blur">
                    <h2 class="text-xl font-black text-slate-950">{{ $towerUi['empty_state_title'] ?? ($towerName.'は静まり返っている') }}</h2>
                    <p class="mt-2 text-sm font-bold leading-relaxed text-slate-600">
                        {{ $towerLocationName }}で、まだ入口が見つかっていません。
                    </p>
                </section>
            @else
                <div class="flex flex-wrap items-center justify-center gap-2 text-[11px] font-black">
                    <span class="rounded-full border border-violet-200 bg-white/85 px-3 py-1 text-violet-700 shadow-sm backdrop-blur">
                        今週 {{ number_format($weeklyBestClearedFloor) }}階突破
                    </span>
                    <span class="rounded-full border border-emerald-200 bg-white/85 px-3 py-1 text-emerald-700 shadow-sm backdrop-blur">
                        最高 {{ number_format($bestClearedFloor) }}階突破
                    </span>
                </div>
                <div class="mt-6 text-center">
                    @if($activeRun)
                        <div class="text-xs font-black text-emerald-100 drop-shadow">現在</div>
                        <div class="mt-1 text-4xl font-black text-white drop-shadow-[0_3px_8px_rgba(0,0,0,0.75)]">{{ number_format($currentFloorNumber) }}階</div>
                    @else
                        <div class="text-xs font-black text-emerald-100 drop-shadow">再開地点</div>
                        <div class="mt-1 text-4xl font-black text-white drop-shadow-[0_3px_8px_rgba(0,0,0,0.75)]">{{ number_format($checkpointStartFloor) }}階</div>
                    @endif
                </div>

                @if($towerActionStrategies->isNotEmpty() && !$isMerchantPending)
                    <section class="mt-6 rounded-lg border border-emerald-100 bg-white/88 px-4 py-3 text-left shadow-lg backdrop-blur">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <div>
                                <div class="text-xs font-black text-emerald-700">挑戦前の方針</div>
                                <div class="text-sm font-bold text-slate-700">この階へ挑む前に戦い方を選べます。</div>
                            </div>
                            <span class="shrink-0 rounded-full bg-emerald-600 px-3 py-1 text-[11px] font-black text-white">
                                探索力 -<span data-tower-entry-strategy-stamina>{{ number_format($entryStaminaCost) }}</span>
                            </span>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2" data-tower-entry-strategy-panel data-base-stamina-cost="{{ $entryStaminaCost }}">
                            @foreach($towerActionStrategies as $strategy)
                                <label class="flex cursor-pointer gap-2 rounded-lg border border-emerald-100 bg-white px-3 py-2 text-sm shadow-sm transition has-[:checked]:border-emerald-400 has-[:checked]:bg-emerald-50">
                                    <input
                                        type="radio"
                                        name="tower_entry_strategy_choice"
                                        value="{{ (string) ($strategy['key'] ?? 'normal') }}"
                                        class="mt-1 h-4 w-4 border-emerald-300 text-emerald-600 focus:ring-emerald-500"
                                        data-tower-entry-strategy-option
                                        data-strategy-key="{{ (string) ($strategy['key'] ?? 'normal') }}"
                                        data-stamina-extra="{{ (int) ($strategy['stamina_extra'] ?? 0) }}"
                                        data-fixed-stamina-cost="{{ isset($strategy['fixed_stamina_cost']) ? (int) $strategy['fixed_stamina_cost'] : '' }}"
                                        data-battle="{{ ($strategy['battle'] ?? true) ? '1' : '0' }}"
                                        @checked((string) ($strategy['key'] ?? 'normal') === 'normal')
                                    >
                                    <span class="min-w-0">
                                        <span class="block font-black text-slate-950">{{ $strategy['name'] }}</span>
                                        <span class="mt-0.5 block text-xs font-bold leading-5 text-slate-500">{{ $strategy['summary'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </section>
                @endif

                <div class="mt-6 space-y-2">
                    @if($activeRun)
                        <form method="POST" action="{{ $continueRoute }}" data-tower-submit-form data-tower-entry-form data-tower-strategy-form="{{ $isMerchantPending ? '0' : '1' }}" data-loading-text="{{ $continueLoadingText }}" data-current-stamina="{{ (int) ($stamina['current'] ?? 0) }}" data-required-stamina="{{ $entryStaminaCost }}" data-base-stamina-cost="{{ $entryStaminaCost }}" data-ready-text="{{ $continueText }}">
                            @csrf
                            @unless($isMerchantPending)
                                <input type="hidden" name="strategy" value="normal" data-tower-entry-strategy-input>
                            @endunless
                            <button type="submit" class="w-full rounded-lg bg-emerald-600 px-5 py-3 text-sm font-black text-white shadow-md transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-80" data-tower-submit-button>
                                <span class="inline-flex items-center justify-center gap-1.5">
                                    <x-loading-spinner class="hidden" data-tower-submit-spinner size="h-4 w-4" />
                                    <span data-tower-submit-text>{{ $continueText }}</span>
                                </span>
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('tower.star-tree.start') }}" data-tower-submit-form data-tower-entry-form data-tower-strategy-form="1" data-loading-text="開始中..." data-current-stamina="{{ (int) ($stamina['current'] ?? 0) }}" data-required-stamina="{{ $entryStaminaCost }}" data-base-stamina-cost="{{ $entryStaminaCost }}" data-ready-text="{{ number_format($checkpointStartFloor) }}階から挑戦を始める">
                            @csrf
                            <input type="hidden" name="strategy" value="normal" data-tower-entry-strategy-input>
                            <button type="submit" class="w-full rounded-lg bg-emerald-600 px-5 py-3 text-sm font-black text-white shadow-md transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-80" data-tower-submit-button>
                                    <span class="inline-flex items-center justify-center gap-1.5">
                                        <x-loading-spinner class="hidden" data-tower-submit-spinner size="h-4 w-4" />
                                        <span data-tower-submit-text>{{ number_format($checkpointStartFloor) }}階から挑戦を始める</span>
                                    </span>
                                </button>
                            </form>
                    @endif

                    <a href="{{ route('tower.star-tree.ranking') }}" class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-5 py-3 text-sm font-black text-slate-800 shadow-sm transition hover:bg-slate-50">
                        ランキングを見る
                    </a>

                    @if($activeRun || $checkpointStartFloor > 1 || $bestClearedFloor > 0)
                        <form method="POST"
                              action="{{ route('tower.star-tree.restart') }}"
                              data-tower-submit-form
                              data-tower-entry-form
                              data-loading-text="登り直し中..."
                              data-current-stamina="{{ (int) ($stamina['current'] ?? 0) }}"
                              data-required-stamina="{{ $restartStaminaCost }}">
                            @csrf
                            <button type="submit" class="w-full rounded-lg border border-white/40 bg-white/85 px-5 py-3 text-sm font-black text-slate-800 shadow-sm backdrop-blur transition hover:bg-white disabled:cursor-not-allowed disabled:opacity-80" data-tower-submit-button>
                                <span class="inline-flex items-center justify-center gap-1.5">
                                    <x-loading-spinner class="hidden" data-tower-submit-spinner size="h-4 w-4" />
                                    <span data-tower-submit-text>1階から登り直す（探索力 -{{ number_format($restartStaminaCost) }}）</span>
                                </span>
                            </button>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    </div>

    @if($canAccess && max($entryStaminaCost, $restartStaminaCost) > 0)
        <div id="tower-entry-stamina-modal" class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-950/45 px-4 py-6" role="dialog" aria-modal="true" aria-labelledby="tower-entry-stamina-modal-title">
            <div class="w-full max-w-sm rounded-lg bg-white p-4 shadow-2xl">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 id="tower-entry-stamina-modal-title" class="text-base font-black text-slate-900">探索力を回復して探索を続けますか？</h3>
                        <p class="mt-1 text-xs font-bold leading-5 text-slate-500">探索力が足りません。使うアイテムを選んでください。</p>
                    </div>
                    <button type="button" data-tower-entry-stamina-modal-close class="rounded-full p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" aria-label="閉じる">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18" />
                        </svg>
                    </button>
                </div>
                <div class="mt-3 rounded border border-sky-100 bg-sky-50 px-3 py-2 text-xs font-extrabold text-sky-800">
                    探索力 <span data-tower-entry-modal-stamina-current>{{ number_format((int) ($stamina['current'] ?? 0)) }}</span> / 必要 <span data-tower-entry-modal-stamina-required>{{ number_format($entryStaminaCost) }}</span>
                </div>
                <div class="mt-2 rounded border border-amber-100 bg-amber-50 px-3 py-2 text-xs font-extrabold text-amber-800">
                    所持輝石 <span data-tower-entry-modal-kiseki>{{ number_format($currentKiseki) }}</span>
                </div>
                <div class="mt-3 flex flex-col gap-2">
                    @foreach($staminaRecoveryChoices as $choice)
                        @php($hasOwnedStaminaItem = (int) $choice['quantity'] > 0)
                        <div class="rounded-lg border {{ $hasOwnedStaminaItem ? 'border-emerald-200 bg-emerald-50/60' : 'border-slate-200 bg-white' }} p-2">
                            <button type="button"
                                    data-tower-entry-stamina-item
                                    data-item-key="{{ $choice['key'] }}"
                                    data-use-url="{{ $choice['use_url'] }}"
                                    data-quantity="{{ $choice['quantity'] }}"
                                    @disabled($choice['quantity'] <= 0)
                                    class="flex w-full items-center justify-between rounded-md px-2 py-2 text-left transition {{ $hasOwnedStaminaItem ? 'bg-white text-emerald-900 shadow-sm ring-1 ring-emerald-200 hover:bg-emerald-50' : 'bg-slate-50 text-slate-500 disabled:cursor-not-allowed disabled:opacity-75' }}">
                                <span class="flex min-w-0 items-center gap-2">
                                    @if($choice['icon_image'])
                                        <img src="{{ asset($choice['icon_image']) }}" alt="" class="h-5 w-5 object-contain">
                                    @endif
                                    <span class="min-w-0">
                                        <span class="block text-sm font-black text-slate-800">{{ $choice['name'] }}</span>
                                        <span class="block text-[11px] font-bold text-slate-500">探索力 +{{ number_format($choice['effect_value']) }}</span>
                                    </span>
                                </span>
                                <span class="flex shrink-0 flex-col items-end gap-1">
                                    <span class="rounded bg-white px-2 py-0.5 text-[11px] font-black {{ $hasOwnedStaminaItem ? 'text-emerald-700 ring-1 ring-emerald-100' : 'text-slate-500 ring-1 ring-slate-100' }}">
                                        所持 <span data-tower-entry-stamina-item-count>{{ number_format($choice['quantity']) }}</span>
                                    </span>
                                    <span class="rounded px-2 py-0.5 text-[11px] font-black {{ $hasOwnedStaminaItem ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-500' }}">
                                        {{ $hasOwnedStaminaItem ? '所持分を使う' : '所持なし' }}
                                    </span>
                                </span>
                            </button>
                            @unless($hasOwnedStaminaItem)
                                <div class="mt-2 rounded-md border border-amber-200 bg-amber-50 p-2">
                                    <div class="text-[11px] font-bold text-amber-800">所持していないため、輝石を消費して購入後に使用します。</div>
                                    <button type="button"
                                            data-tower-entry-stamina-buy
                                            data-item-key="{{ $choice['key'] }}"
                                            data-purchase-url="{{ $choice['purchase_url'] }}"
                                            class="mt-1.5 flex w-full items-center justify-center gap-1 rounded-md border border-amber-400 bg-white px-3 py-1.5 text-xs font-black text-amber-900 transition hover:bg-amber-100 active:scale-95">
                                        <span>輝石で購入して使う</span>
                                        <img src="{{ asset('images/icon/kiseki.webp') }}" alt="" class="h-3.5 w-3.5 object-contain">
                                        <span>{{ number_format($choice['price']) }}</span>
                                    </button>
                                </div>
                            @endunless
                        </div>
                    @endforeach
                    <button type="button" data-tower-entry-stamina-modal-close class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-black text-slate-700 transition hover:bg-slate-50">
                        閉じる
                    </button>
                </div>
            </div>
        </div>
    @endif

    @once
        <script>
            let pendingTowerEntryForm = null;

            function towerEntryNumber(value) {
                return new Intl.NumberFormat('ja-JP').format(Number(value || 0));
            }

            function towerEntryCsrfToken() {
                const token = document.querySelector('input[name="_token"]');
                return token ? token.value : '';
            }

            function towerEntryStaminaModal() {
                return document.getElementById('tower-entry-stamina-modal');
            }

            function selectedTowerEntryStrategy() {
                return document.querySelector('[data-tower-entry-strategy-option]:checked');
            }

            function towerEntryStrategyRequiredStamina(form, option) {
                const base = Number(form.dataset.baseStaminaCost || form.dataset.requiredStamina || 0);
                const fixed = option && option.dataset.fixedStaminaCost !== ''
                    ? Number(option.dataset.fixedStaminaCost || 0)
                    : null;
                const extra = option ? Number(option.dataset.staminaExtra || 0) : 0;

                return Math.max(1, fixed !== null && fixed > 0 ? fixed : base + extra);
            }

            function updateTowerEntryStrategyForms() {
                const option = selectedTowerEntryStrategy();
                const key = option ? option.dataset.strategyKey || 'normal' : 'normal';
                const isScout = option ? option.dataset.battle === '0' : false;
                let displayedRequired = null;

                document.querySelectorAll('[data-tower-entry-form][data-tower-strategy-form="1"]').forEach(function(form) {
                    const required = towerEntryStrategyRequiredStamina(form, option);
                    const input = form.querySelector('[data-tower-entry-strategy-input]');
                    const text = form.querySelector('[data-tower-submit-text]');
                    const baseReadyText = form.dataset.readyText || '挑戦する';
                    const nextText = isScout
                        ? '様子を見る（探索力 -' + towerEntryNumber(required) + '）'
                        : baseReadyText;

                    displayedRequired = required;
                    form.dataset.requiredStamina = String(required);
                    if (input) {
                        input.value = key;
                    }
                    if (text && form.dataset.submitted !== '1') {
                        text.textContent = nextText;
                    }
                });

                if (displayedRequired !== null) {
                    document.querySelectorAll('[data-tower-entry-strategy-stamina]').forEach(function(el) {
                        el.textContent = towerEntryNumber(displayedRequired);
                    });
                }
            }

            function openTowerEntryStaminaModal(form) {
                const modal = towerEntryStaminaModal();
                if (!modal) {
                    alert('探索力が足りません。探索力の小瓶や薬で回復してから進んでください。');
                    return;
                }

                pendingTowerEntryForm = form;

                if (modal.parentElement !== document.body) {
                    document.body.appendChild(modal);
                }

                const current = Number(form.dataset.currentStamina || 0);
                const required = Math.max(1, Number(form.dataset.requiredStamina || 1));
                const currentText = modal.querySelector('[data-tower-entry-modal-stamina-current]');
                const requiredText = modal.querySelector('[data-tower-entry-modal-stamina-required]');
                if (currentText) currentText.textContent = towerEntryNumber(current);
                if (requiredText) requiredText.textContent = towerEntryNumber(required);

                modal.style.position = 'fixed';
                modal.style.inset = '0';
                modal.style.display = 'flex';
                modal.style.alignItems = 'center';
                modal.style.justifyContent = 'center';
                modal.style.minHeight = '100dvh';
                document.documentElement.style.overflow = 'hidden';
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }

            function closeTowerEntryStaminaModal() {
                const modal = towerEntryStaminaModal();
                if (!modal) return;
                modal.style.display = '';
                document.documentElement.style.overflow = '';
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }

            function updateTowerEntryStamina(stamina) {
                if (!stamina) return;

                const current = Number(stamina.current || 0);
                const max = Number(stamina.max || 0);
                document.querySelectorAll('[data-tower-entry-form]').forEach(function(form) {
                    form.dataset.currentStamina = String(current);
                });

                const currentText = towerEntryStaminaModal()?.querySelector('[data-tower-entry-modal-stamina-current]');
                if (currentText) currentText.textContent = towerEntryNumber(current);

                document.querySelectorAll('[data-tower-stamina-current]').forEach(function(el) {
                    el.textContent = towerEntryNumber(current);
                });
                document.querySelectorAll('[data-tower-stamina-max]').forEach(function(el) {
                    el.textContent = towerEntryNumber(max);
                });
            }

            function updateTowerEntryStaminaItems(supportItems) {
                if (!Array.isArray(supportItems)) return;

                const quantities = {};
                supportItems.forEach(function(item) {
                    if (item && item.key) {
                        quantities[item.key] = Number(item.quantity || 0);
                    }
                });

                document.querySelectorAll('[data-tower-entry-stamina-item]').forEach(function(button) {
                    const key = button.dataset.itemKey;
                    const quantity = quantities[key] ?? 0;
                    const count = button.querySelector('[data-tower-entry-stamina-item-count]');
                    if (count) count.textContent = towerEntryNumber(quantity);
                    button.dataset.quantity = String(quantity);
                    button.disabled = quantity <= 0;
                });
            }

            function updateTowerEntryKiseki(value) {
                const kisekiText = towerEntryStaminaModal()?.querySelector('[data-tower-entry-modal-kiseki]');
                if (kisekiText && value !== null && value !== undefined) {
                    kisekiText.textContent = towerEntryNumber(value);
                }
            }

            function submitTowerEntryForm(form) {
                if (!form) return;
                form.dataset.submitted = '1';
                const button = form.querySelector('[data-tower-submit-button]');
                const spinner = form.querySelector('[data-tower-submit-spinner]');
                const text = form.querySelector('[data-tower-submit-text]');
                const loadingText = form.dataset.loadingText || '処理中...';
                if (button) button.disabled = true;
                if (spinner) spinner.classList.remove('hidden');
                if (text) text.textContent = loadingText;
                form.submit();
            }

            async function useTowerEntryStaminaItem(button) {
                const modal = towerEntryStaminaModal();
                if (!modal || !button?.dataset.useUrl) return;

                const buttons = modal.querySelectorAll('button');
                buttons.forEach(function(modalButton) {
                    modalButton.disabled = true;
                });

                try {
                    const formData = new FormData();
                    const token = towerEntryCsrfToken();
                    if (token) formData.append('_token', token);

                    const response = await fetch(button.dataset.useUrl, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                    });
                    const data = await response.json();
                    if (!response.ok || data.success !== true) {
                        throw new Error(data.message || '探索力を回復できませんでした。');
                    }

                    updateTowerEntryStamina(data.stamina);
                    updateTowerEntryStaminaItems(data.support_items);
                    closeTowerEntryStaminaModal();
                    if (pendingTowerEntryForm) {
                        submitTowerEntryForm(pendingTowerEntryForm);
                    }
                } catch (error) {
                    alert(error.message || '探索力回復アイテムの使用に失敗しました。');
                } finally {
                    buttons.forEach(function(modalButton) {
                        const quantity = modalButton.hasAttribute('data-tower-entry-stamina-item')
                            ? Number(modalButton.dataset.quantity || 0)
                            : 1;
                        modalButton.disabled = quantity <= 0;
                    });
                }
            }

            async function purchaseAndUseTowerEntryStaminaItem(button) {
                const modal = towerEntryStaminaModal();
                if (!modal || !button?.dataset.purchaseUrl || !button?.dataset.itemKey) return;

                const buttons = modal.querySelectorAll('button');
                buttons.forEach(function(modalButton) {
                    modalButton.disabled = true;
                });

                try {
                    const formData = new FormData();
                    const token = towerEntryCsrfToken();
                    if (token) formData.append('_token', token);
                    formData.append('item_key', button.dataset.itemKey);

                    const response = await fetch(button.dataset.purchaseUrl, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                    });
                    const data = await response.json();
                    if (!response.ok || data.success !== true) {
                        throw new Error(data.message || '探索力回復アイテムを購入できませんでした。');
                    }

                    updateTowerEntryKiseki(data.kiseki);
                    updateTowerEntryStaminaItems(data.support_items);
                    const itemButton = modal.querySelector('[data-tower-entry-stamina-item][data-item-key="' + CSS.escape(button.dataset.itemKey) + '"]');
                    if (itemButton) {
                        await useTowerEntryStaminaItem(itemButton);
                    }
                } catch (error) {
                    alert(error.message || '探索力回復アイテムの購入に失敗しました。');
                } finally {
                    buttons.forEach(function(modalButton) {
                        const quantity = modalButton.hasAttribute('data-tower-entry-stamina-item')
                            ? Number(modalButton.dataset.quantity || 0)
                            : 1;
                        modalButton.disabled = quantity <= 0;
                    });
                }
            }

            document.addEventListener('submit', function(event) {
                const form = event.target.closest('[data-tower-submit-form]');
                if (!form) return;

                if (form.dataset.submitted === '1') {
                    event.preventDefault();
                    return;
                }

                if (form.matches('[data-tower-entry-form]')) {
                    updateTowerEntryStrategyForms();
                    const current = Number(form.dataset.currentStamina || 0);
                    const required = Math.max(0, Number(form.dataset.requiredStamina || 0));
                    if (required > 0 && current < required) {
                        event.preventDefault();
                        openTowerEntryStaminaModal(form);
                        return;
                    }
                }

                form.dataset.submitted = '1';
                const button = form.querySelector('[data-tower-submit-button]');
                const spinner = form.querySelector('[data-tower-submit-spinner]');
                const text = form.querySelector('[data-tower-submit-text]');
                const loadingText = form.dataset.loadingText || '処理中...';

                if (button) {
                    button.disabled = true;
                }

                if (spinner) {
                    spinner.classList.remove('hidden');
                }

                if (text) {
                    text.textContent = loadingText;
                }
            }, true);

            document.addEventListener('click', function(event) {
                const closeButton = event.target.closest('[data-tower-entry-stamina-modal-close]');
                if (closeButton) {
                    closeTowerEntryStaminaModal();
                    return;
                }

                const staminaItemButton = event.target.closest('[data-tower-entry-stamina-item]');
                if (staminaItemButton) {
                    event.preventDefault();
                    useTowerEntryStaminaItem(staminaItemButton);
                    return;
                }

                const staminaBuyButton = event.target.closest('[data-tower-entry-stamina-buy]');
                if (staminaBuyButton) {
                    event.preventDefault();
                    purchaseAndUseTowerEntryStaminaItem(staminaBuyButton);
                }
            });

            document.addEventListener('change', function(event) {
                if (event.target.closest('[data-tower-entry-strategy-option]')) {
                    updateTowerEntryStrategyForms();
                }
            });

            updateTowerEntryStrategyForms();
        </script>
    @endonce
</x-layouts.facility>
