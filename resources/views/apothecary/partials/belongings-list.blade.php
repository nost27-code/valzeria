@php
    $activeBelonging = collect($belongings)->first(fn (array $belonging): bool => $belonging['is_active']);
    $speciesLuresEligible = $speciesLuresEligible ?? true;
@endphp
<div
    data-belongings-container
    data-csrf="{{ csrf_token() }}"
    data-activate-url="{{ route('apothecary.activate') }}"
    data-auto-renew-url="{{ route('apothecary.auto-renew') }}"
    data-active-item-key="{{ $activeBelonging['item_key'] ?? '' }}"
    data-active-item-name="{{ $activeBelonging['name'] ?? '' }}"
    data-active-item-remaining="{{ $activeBelonging['remaining'] ?? 0 }}"
    data-species-lures-eligible="{{ $speciesLuresEligible ? '1' : '0' }}"
>
    <div class="space-y-2">
        @forelse($belongings as $belonging)
            <div class="rounded-lg border px-3 py-2.5 {{ $belonging['is_active'] ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-white' }}">
                <div class="flex flex-wrap items-center gap-1.5">
                    <h4 class="text-sm font-bold leading-snug text-slate-900">{{ $belonging['name'] }}</h4>
                    @if($belonging['is_active'])
                        <span class="shrink-0 rounded bg-amber-200 px-1.5 py-0.5 text-[10px] font-bold text-amber-800">使用中</span>
                    @endif
                    @if($belonging['is_lure'] && !$belonging['is_effective_here'])
                        <span class="shrink-0 rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-bold text-slate-600">対象外</span>
                    @endif
                </div>
                <p class="mt-1 text-xs leading-relaxed text-slate-600">{{ $belonging['description'] }}</p>
                @if($belonging['effectiveness_note'])
                    <p class="mt-1 text-[11px] font-bold {{ $belonging['is_effective_here'] ? 'text-emerald-700' : 'text-slate-500' }}">
                        {{ $belonging['effectiveness_note'] }}
                    </p>
                @endif
                <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                    <div class="text-xs font-bold text-slate-500">
                        @if($belonging['is_open'])
                            残り {{ number_format($belonging['remaining']) }}/{{ number_format($belonging['max_battles']) }}戦
                            <span class="mx-1 text-slate-300">|</span>
                        @endif
                        予備 {{ number_format($belonging['owned']) }}個
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            data-belonging-auto-toggle
                            data-item-key="{{ $belonging['item_key'] }}"
                            data-auto-renew="{{ $belonging['auto_renew'] ? '1' : '0' }}"
                            title="残り戦数を使い切った時、予備を1個使って50戦へ戻します"
                            class="h-7 rounded border px-2 text-[11px] font-bold shadow-sm transition active:scale-95 {{ $belonging['auto_renew'] ? 'border-emerald-300 bg-emerald-600 text-white' : 'border-slate-300 bg-white text-slate-600' }}"
                        >自動補充: {{ $belonging['auto_renew'] ? 'ON' : 'OFF' }}</button>
                        @if($belonging['can_activate'])
                            <button
                                type="button"
                                data-belonging-activate
                                data-item-key="{{ $belonging['item_key'] }}"
                                data-item-name="{{ $belonging['name'] }}"
                                data-remaining="{{ $belonging['remaining'] }}"
                                data-max-battles="{{ $belonging['max_battles'] }}"
                                data-consumes-reserve="{{ $belonging['remaining'] > 0 ? '0' : '1' }}"
                                class="h-7 rounded bg-amber-600 px-3 text-[11px] font-bold text-white shadow-sm transition hover:bg-amber-700 active:scale-95"
                            >{{ $belonging['activate_label'] }}</button>
                        @elseif(!$belonging['is_active'] && !$belonging['is_effective_here'])
                            <button type="button" disabled class="h-7 cursor-not-allowed rounded bg-slate-200 px-3 text-[11px] font-bold text-slate-500">対象外</button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-dashed border-slate-200 bg-white py-10 text-center">
                <p class="text-slate-500">所持している補助品はありません。</p>
                <a href="{{ route('apothecary.index') }}" wire:navigate class="mt-2 inline-block text-sm text-blue-600 hover:underline">薬屋で調合する</a>
            </div>
        @endforelse
    </div>
</div>

<div data-belonging-switch-confirm-modal hidden style="position:fixed;inset:0;z-index:12000;">
    <button
        type="button"
        data-belonging-switch-cancel
        aria-label="もちもの切り替え確認を閉じる"
        style="position:absolute;inset:0;width:100%;height:100%;border:0;background:rgba(15,23,42,.58);"
    ></button>
    <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="belonging-switch-confirm-title"
        style="position:absolute;left:50%;top:50%;width:min(calc(100% - 32px),420px);transform:translate(-50%,-50%);border:1px solid #fcd34d;border-radius:14px;background:#fff;box-shadow:0 20px 50px rgba(15,23,42,.28);overflow:hidden;"
    >
        <div style="padding:15px 16px 11px;border-bottom:1px solid #fef3c7;background:#fffbeb;">
            <h3 id="belonging-switch-confirm-title" style="margin:0;font-size:15px;font-weight:900;color:#78350f;">もちものを切り替えますか？</h3>
            <p style="margin:4px 0 0;font-size:11px;line-height:1.6;color:#92400e;">現在のもちものは消えず、残り戦数が保存されます。</p>
        </div>
        <div style="padding:14px 16px;">
            <div style="display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);align-items:center;gap:8px;">
                <div style="min-width:0;border:1px solid #e2e8f0;border-radius:9px;background:#f8fafc;padding:9px;">
                    <div style="font-size:9px;font-weight:800;color:#94a3b8;">現在</div>
                    <div data-belonging-switch-current-name style="margin-top:2px;font-size:12px;font-weight:900;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
                    <div data-belonging-switch-current-remaining style="margin-top:2px;font-size:10px;font-weight:700;color:#64748b;"></div>
                </div>
                <div aria-hidden="true" style="font-size:16px;font-weight:900;color:#d97706;">→</div>
                <div style="min-width:0;border:1px solid #fcd34d;border-radius:9px;background:#fffbeb;padding:9px;">
                    <div style="font-size:9px;font-weight:800;color:#d97706;">変更後</div>
                    <div data-belonging-switch-next-name style="margin-top:2px;font-size:12px;font-weight:900;color:#78350f;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
                    <div data-belonging-switch-next-remaining style="margin-top:2px;font-size:10px;font-weight:700;color:#92400e;"></div>
                </div>
            </div>
            <p data-belonging-switch-consumption style="margin:11px 0 0;border-radius:8px;padding:8px 10px;font-size:11px;font-weight:800;line-height:1.6;"></p>
            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">
                <button type="button" data-belonging-switch-cancel style="border:1px solid #cbd5e1;border-radius:8px;background:#fff;padding:7px 13px;font-size:12px;font-weight:800;color:#475569;">やめる</button>
                <button type="button" data-belonging-switch-confirm style="border:0;border-radius:8px;background:#d97706;padding:7px 14px;font-size:12px;font-weight:900;color:#fff;box-shadow:0 2px 6px rgba(146,64,14,.22);">切り替える</button>
            </div>
        </div>
    </section>
</div>

<script>
    (() => {
        if (window.__valzeriaBelongingsAsyncBound) {
            return;
        }
        window.__valzeriaBelongingsAsyncBound = true;

        async function postJson(url, csrfToken, body) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: new URLSearchParams(body),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.success !== true) {
                throw new Error(data.message || '処理に失敗しました。');
            }
            return data;
        }

        function replaceContainer(container, html) {
            const wrapper = document.createElement('div');
            wrapper.innerHTML = html;
            const newContainer = wrapper.querySelector('[data-belongings-container]');
            if (newContainer && container.parentElement) {
                container.parentElement.replaceChild(newContainer, container);
            }
        }

        function notifyUpdated(data) {
            window.dispatchEvent(new CustomEvent('exploration-support-updated', {
                detail: {
                    active: data.active || null,
                    message: data.message || '',
                },
            }));
        }

        let pendingActivateButton = null;
        let pendingSwitchModal = null;
        let switchConfirmPreviousBodyOverflow = '';

        function switchConfirmModal(container = null) {
            if (container) {
                const sibling = container.nextElementSibling;
                if (sibling?.matches('[data-belonging-switch-confirm-modal]')) {
                    return sibling;
                }

                return container.parentElement?.querySelector('[data-belonging-switch-confirm-modal]') || null;
            }

            return pendingSwitchModal;
        }

        function closeSwitchConfirmModal(restoreFocus = true) {
            const modal = switchConfirmModal();
            if (!modal) return;

            modal.hidden = true;
            document.body.style.overflow = switchConfirmPreviousBodyOverflow;
            if (restoreFocus && pendingActivateButton?.isConnected) {
                pendingActivateButton.focus();
            }
            pendingActivateButton = null;
            pendingSwitchModal = null;
        }

        function openSwitchConfirmModal(activateButton, container) {
            const modal = switchConfirmModal(container);
            const confirmButton = modal?.querySelector('[data-belonging-switch-confirm]');
            if (!modal || !confirmButton) return;

            const currentName = container.dataset.activeItemName || '現在のもちもの';
            const currentRemaining = Number.parseInt(container.dataset.activeItemRemaining || '0', 10);
            const nextName = activateButton.dataset.itemName || '選択したもちもの';
            const nextRemaining = Number.parseInt(activateButton.dataset.remaining || '0', 10);
            const maxBattles = Number.parseInt(activateButton.dataset.maxBattles || '50', 10);
            const consumesReserve = activateButton.dataset.consumesReserve === '1';

            modal.querySelector('[data-belonging-switch-current-name]').textContent = currentName;
            modal.querySelector('[data-belonging-switch-current-remaining]').textContent = `残り${Math.max(0, currentRemaining)}戦を保存`;
            modal.querySelector('[data-belonging-switch-next-name]').textContent = nextName;
            modal.querySelector('[data-belonging-switch-next-remaining]').textContent = consumesReserve
                ? `${Math.max(1, maxBattles)}戦分を開始`
                : `残り${Math.max(0, nextRemaining)}戦から再開`;

            const consumption = modal.querySelector('[data-belonging-switch-consumption]');
            consumption.textContent = consumesReserve
                ? `${nextName}の予備を1個消費します。`
                : '予備は消費しません。';
            consumption.style.background = consumesReserve ? '#fff7ed' : '#ecfdf5';
            consumption.style.color = consumesReserve ? '#c2410c' : '#047857';

            pendingActivateButton = activateButton;
            pendingSwitchModal = modal;
            switchConfirmPreviousBodyOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            modal.hidden = false;
            confirmButton.focus();
        }

        async function activateBelonging(activateButton) {
            const container = activateButton.closest('[data-belongings-container]');
            if (!container) return;

            const originalText = activateButton.textContent.trim();
            activateButton.disabled = true;
            activateButton.textContent = '変更中...';
            try {
                const data = await postJson(container.dataset.activateUrl, container.dataset.csrf, {
                    item_key: activateButton.dataset.itemKey,
                    species_lures_eligible: container.dataset.speciesLuresEligible === '0' ? '0' : '1',
                });
                notifyUpdated(data);
                replaceContainer(container, data.belongings_html);
            } catch (error) {
                alert(error.message || '装備変更に失敗しました。');
                activateButton.disabled = false;
                activateButton.textContent = originalText;
            }
        }

        document.addEventListener('click', async (event) => {
            const confirmButton = event.target.closest('[data-belonging-switch-confirm]');
            if (confirmButton) {
                event.preventDefault();
                const activateButton = pendingActivateButton;
                closeSwitchConfirmModal(false);
                if (activateButton) {
                    await activateBelonging(activateButton);
                }
                return;
            }

            const cancelButton = event.target.closest('[data-belonging-switch-cancel]');
            if (cancelButton) {
                event.preventDefault();
                closeSwitchConfirmModal();
                return;
            }

            const activateButton = event.target.closest('[data-belonging-activate]');
            if (activateButton) {
                const container = activateButton.closest('[data-belongings-container]');
                if (!container) return;

                if (container.dataset.activeItemKey
                    && container.dataset.activeItemKey !== activateButton.dataset.itemKey) {
                    openSwitchConfirmModal(activateButton, container);
                    return;
                }

                await activateBelonging(activateButton);
                return;
            }

            const toggleButton = event.target.closest('[data-belonging-auto-toggle]');
            if (toggleButton) {
                const container = toggleButton.closest('[data-belongings-container]');
                if (!container) return;

                const next = toggleButton.dataset.autoRenew !== '1';
                toggleButton.disabled = true;
                try {
                    const data = await postJson(container.dataset.autoRenewUrl, container.dataset.csrf, {
                        item_key: toggleButton.dataset.itemKey,
                        auto_renew: next ? '1' : '0',
                        species_lures_eligible: container.dataset.speciesLuresEligible === '0' ? '0' : '1',
                    });
                    notifyUpdated(data);
                    replaceContainer(container, data.belongings_html);
                } catch (error) {
                    alert(error.message || '自動補充の変更に失敗しました。');
                    toggleButton.disabled = false;
                }
            }
        });

        document.addEventListener('keydown', (event) => {
            const modal = switchConfirmModal();
            if (event.key === 'Escape' && modal && !modal.hidden) {
                event.preventDefault();
                event.stopImmediatePropagation();
                closeSwitchConfirmModal();
            }
        });
    })();
</script>
