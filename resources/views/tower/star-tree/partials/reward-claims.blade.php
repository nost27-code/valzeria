@php
    $pendingTowerRewards = collect($pendingTowerRewards ?? []);
@endphp

@if($pendingTowerRewards->isNotEmpty())
    <section class="rounded-lg border border-amber-200 bg-amber-50/95 p-4 shadow-lg">
        <div class="flex items-start gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-amber-200 bg-white shadow-sm">
                <img src="{{ asset('images/icon/icon_012.webp') }}" alt="" class="h-7 w-7 object-contain">
            </div>
            <div class="min-w-0">
                <div class="text-xs font-black text-amber-700">初回到達報酬</div>
                <h2 class="mt-0.5 text-lg font-black text-slate-950">星樹の宝箱</h2>
                <p class="mt-1 text-xs font-bold leading-5 text-slate-600">
                    枝葉の奥で星樹の宝箱を発見した！ 中には特別な武器が眠っている。どれか一つを選んで受け取れそうだ。
                </p>
            </div>
        </div>

        <div class="mt-3 space-y-3">
            @foreach($pendingTowerRewards as $reward)
                @php
                    $isWeaponReward = ($reward['reward_type'] ?? '') === \App\Services\StarTreeTowerRewardService::TYPE_WEAPON;
                    $options = collect($reward['options'] ?? []);
                    $defaultCategory = (string) ($options->first()['category'] ?? '');
                @endphp
                <div class="rounded-lg border border-amber-100 bg-white p-3 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <div class="text-[11px] font-black text-amber-700">{{ number_format((int) ($reward['floor'] ?? 0)) }}階報酬</div>
                            <div class="text-base font-black text-slate-950">{{ $reward['name'] }}</div>
                        </div>
                        @unless($isWeaponReward)
                            <form method="POST" action="{{ route('tower.star-tree.rewards.claim', ['reward' => $reward['id']]) }}" data-tower-submit-form data-loading-text="受取中...">
                                @csrf
                                <button type="submit" class="rounded-lg bg-amber-600 px-4 py-2 text-xs font-black text-white shadow-sm transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-80" data-tower-submit-button>
                                    <span class="inline-flex items-center gap-1.5">
                                        <x-loading-spinner class="hidden" data-tower-submit-spinner size="h-3.5 w-3.5" />
                                        <span data-tower-submit-text>受け取る</span>
                                    </span>
                                </button>
                            </form>
                        @endunless
                    </div>

                    @if($isWeaponReward)
                        <form id="tower-weapon-claim-form-{{ $reward['id'] }}" method="POST" action="{{ route('tower.star-tree.rewards.claim', ['reward' => $reward['id']]) }}" class="mt-3 space-y-3" data-tower-submit-form data-tower-weapon-claim-form data-loading-text="受取中...">
                            @csrf
                            <div class="grid gap-2 sm:grid-cols-3">
                                @foreach($options as $option)
                                    @php
                                        $stats = collect($option['stats'] ?? [])->filter(fn ($value) => (int) $value !== 0);
                                    @endphp
                                    <label class="flex cursor-pointer flex-col gap-2 rounded-lg border border-slate-200 bg-slate-50 p-2 text-left transition has-[:checked]:border-amber-400 has-[:checked]:bg-amber-50" data-tower-weapon-option-label>
                                        <input type="radio" name="weapon_category" value="{{ $option['category'] }}" class="sr-only" @checked((string) $option['category'] === $defaultCategory)>
                                        <span class="flex items-center justify-between gap-2">
                                            <span class="min-w-0 text-sm font-black text-slate-950" data-tower-weapon-option-name>{{ $option['name'] }}</span>
                                            <span class="shrink-0 rounded bg-emerald-600 px-2 py-0.5 text-[10px] font-black text-white">{{ $option['killer_label'] }}</span>
                                        </span>
                                        <span class="text-[11px] font-bold text-slate-500" data-tower-weapon-option-category>{{ $option['category_label'] }}</span>
                                        <span class="text-[11px] font-bold leading-4 text-slate-600">{{ $option['category_description'] }}</span>
                                        <span class="flex flex-wrap gap-1 text-[11px] font-black text-slate-700">
                                            @foreach($stats as $label => $value)
                                                <span class="rounded bg-white px-1.5 py-0.5 ring-1 ring-slate-200">{{ $label }}{{ (int) $value > 0 ? '+' : '' }}{{ $value }}</span>
                                            @endforeach
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <button type="submit" class="w-full rounded-lg bg-amber-600 px-4 py-2 text-sm font-black text-white shadow-sm transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-80" data-tower-submit-button>
                                <span class="inline-flex items-center justify-center gap-1.5">
                                    <x-loading-spinner class="hidden" data-tower-submit-spinner size="h-4 w-4" />
                                    <span data-tower-submit-text>選んだ武器種を受け取る</span>
                                </span>
                            </button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <div class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 px-4 py-6" data-tower-weapon-claim-modal aria-hidden="true">
        <div class="w-full max-w-sm rounded-lg border border-amber-200 bg-white p-4 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="tower-weapon-claim-modal-title">
            <div class="text-xs font-black text-amber-700">星樹の宝箱</div>
            <h2 id="tower-weapon-claim-modal-title" class="mt-1 text-lg font-black text-slate-950">この武器種を受け取りますか？</h2>
            <p class="mt-2 text-sm font-bold leading-6 text-slate-700">
                <span class="font-black text-amber-700" data-tower-weapon-claim-modal-name>選択した武器</span>
                を宝箱から取り出します。受け取った後は選び直せません。
            </p>
            <div class="mt-4 grid grid-cols-2 gap-2">
                <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-black text-slate-700 shadow-sm transition hover:bg-slate-50" data-tower-weapon-claim-cancel>
                    戻る
                </button>
                <button type="button" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-black text-white shadow-sm transition hover:bg-amber-700" data-tower-weapon-claim-confirm>
                    受け取る
                </button>
            </div>
        </div>
    </div>

    @once
        <script>
            (function() {
                const getWeaponClaimModal = () => document.querySelector('[data-tower-weapon-claim-modal]');

                const closeWeaponClaimModal = () => {
                    const modal = getWeaponClaimModal();
                    if (!modal) {
                        return;
                    }

                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                    modal.setAttribute('aria-hidden', 'true');
                    delete modal.dataset.targetFormId;
                };

                document.addEventListener('submit', function(event) {
                    const form = event.target.closest('[data-tower-weapon-claim-form]');

                    if (!form || form.dataset.towerWeaponClaimConfirmed === '1') {
                        return;
                    }

                    event.preventDefault();
                    event.stopImmediatePropagation();

                    const selected = form.querySelector('input[name="weapon_category"]:checked');
                    const selectedLabel = selected ? selected.closest('[data-tower-weapon-option-label]') : null;
                    const weaponName = selectedLabel?.querySelector('[data-tower-weapon-option-name]')?.textContent?.trim() || '選択した武器';
                    const weaponCategory = selectedLabel?.querySelector('[data-tower-weapon-option-category]')?.textContent?.trim() || '';
                    const modal = getWeaponClaimModal();

                    if (!modal) {
                        form.dataset.towerWeaponClaimConfirmed = '1';
                        form.requestSubmit();
                        return;
                    }

                    modal.dataset.targetFormId = form.id;
                    modal.querySelector('[data-tower-weapon-claim-modal-name]').textContent = weaponCategory
                        ? `${weaponName}（${weaponCategory}）`
                        : weaponName;
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                    modal.setAttribute('aria-hidden', 'false');
                    modal.querySelector('[data-tower-weapon-claim-confirm]')?.focus();
                }, true);

                document.addEventListener('click', function(event) {
                    if (event.target.closest('[data-tower-weapon-claim-cancel]')) {
                        closeWeaponClaimModal();
                        return;
                    }

                    const confirmButton = event.target.closest('[data-tower-weapon-claim-confirm]');

                    if (confirmButton) {
                        const modal = getWeaponClaimModal();
                        const form = modal?.dataset.targetFormId ? document.getElementById(modal.dataset.targetFormId) : null;

                        if (!form) {
                            closeWeaponClaimModal();
                            return;
                        }

                        form.dataset.towerWeaponClaimConfirmed = '1';
                        closeWeaponClaimModal();
                        form.requestSubmit();
                        return;
                    }

                    const modal = event.target.closest('[data-tower-weapon-claim-modal]');

                    if (modal && event.target === modal) {
                        closeWeaponClaimModal();
                    }
                });

                document.addEventListener('keydown', function(event) {
                    if (event.key === 'Escape') {
                        closeWeaponClaimModal();
                    }
                });
            })();
        </script>
    @endonce
@endif
