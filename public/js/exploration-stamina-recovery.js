(function () {
    if (window.ValzeriaStaminaRecovery) return;
    const formatNumber = new Intl.NumberFormat("ja-JP");
    let activeCallbacks = {};
    let busy = false;
    function notify(text, type = "info") {
        const message = batchStaminaModal()?.querySelector("[data-stamina-recovery-message]");
        if (message) { message.textContent = text; message.classList.toggle("text-red-700", type === "warning"); }
        if (activeCallbacks.notify) activeCallbacks.notify(text, type);
    }
    function batchStaminaModal() {
        return document.getElementById('batch-stamina-modal');
    }

    function bindBatchStaminaModal(modal) {
        if (!modal || modal.dataset.interactionBound === '1') return;

        modal.dataset.interactionBound = '1';
        const closeModalFromControl = function(event) {
            event.preventDefault();
            event.stopPropagation();
            closeBatchStaminaModal();
        };

        // iOS Safari can consume a bubbled click after the scrollable
        // modal has handled a touch. Bind the close controls directly as
        // well as keeping the delegated fallback below.
        modal.querySelectorAll('[data-batch-stamina-modal-close]').forEach((button) => {
            button.addEventListener('click', closeModalFromControl);
            button.addEventListener('touchend', closeModalFromControl, { passive: false });
        });

        modal.addEventListener('click', function(event) {
            const closeButton = event.target.closest('[data-batch-stamina-modal-close]');
            if (closeButton) {
                closeModalFromControl(event);
                return;
            }

            const staminaItemButton = event.target.closest('[data-batch-stamina-item]');
            if (staminaItemButton) {
                event.preventDefault();
                event.stopPropagation();
                if (!staminaItemButton.disabled) {
                    useBatchStaminaItem(staminaItemButton);
                }
                return;
            }

            const staminaBuyButton = event.target.closest('[data-batch-stamina-buy]');
            if (staminaBuyButton) {
                event.preventDefault();
                event.stopPropagation();
                if (!staminaBuyButton.disabled) {
                    purchaseAndUseBatchStaminaItem(staminaBuyButton);
                }
            }
        });

        modal.addEventListener('touchmove', function(event) {
            event.stopPropagation();
        }, { passive: true });
    }

    function openBatchStaminaModal(form, current, required, callbacks = {}) {
        if (busy) return;
        activeCallbacks = callbacks;
        const modal = batchStaminaModal();
        if (!modal) {
            notify(form?.dataset.staminaWarning || '探索力が足りません。探索回数を減らすか、探索力を回復してください。', 'warning');
            return;
        }

        if (modal.parentElement !== document.body) {
            modal.__batchStaminaModalHome = {
                parent: modal.parentElement,
                nextSibling: modal.nextSibling,
            };
            document.body.appendChild(modal);
        }

        bindBatchStaminaModal(modal);

        modal.dataset.formId = form?.id || '';
        modal.querySelector('[data-stamina-recovery-message]').textContent = '';
        const currentText = modal.querySelector('[data-batch-stamina-current]');
        const requiredText = modal.querySelector('[data-batch-stamina-required]');
        if (currentText) currentText.textContent = formatNumber.format(Math.max(0, Number(current || 0)));
        if (requiredText) requiredText.textContent = formatNumber.format(Math.max(1, Number(required || 10)));

        modal.style.position = 'fixed';
        modal.style.inset = '0';
        modal.style.display = 'flex';
        modal.style.alignItems = 'center';
        modal.style.justifyContent = 'center';
        modal.style.minHeight = '100dvh';
        modal.style.zIndex = '10000';
        modal.style.pointerEvents = 'auto';
        if (!modal.classList.contains('hidden')) return;
        modal.dataset.previousHtmlOverflow = document.documentElement.style.overflow;
        modal.dataset.previousBodyOverflow = document.body.style.overflow;
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeBatchStaminaModal(completed = false) {
        if (busy && !completed) return;
        const modal = batchStaminaModal();
        if (!modal || modal.classList.contains('hidden')) return;
        modal.style.display = '';
        document.documentElement.style.overflow = modal.dataset.previousHtmlOverflow || '';
        document.body.style.overflow = modal.dataset.previousBodyOverflow || '';
        modal.classList.add('hidden');
        modal.classList.remove('flex');

        const home = modal.__batchStaminaModalHome;
        if (home?.parent?.isConnected) {
            const nextSibling = home.nextSibling?.parentNode === home.parent
                ? home.nextSibling
                : null;
            home.parent.insertBefore(modal, nextSibling);
        }
        delete modal.__batchStaminaModalHome;
    }

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            || document.querySelector('input[name="_token"]')?.value
            || '';
    }

    function updateBatchStaminaItemChoices(items, selectedKey = null) {
        const modal = batchStaminaModal();
        if (!modal) return;

        const quantities = new Map();
        if (Array.isArray(items)) {
            items.forEach((item) => {
                if (item?.key) {
                    quantities.set(String(item.key), Number(item.quantity || 0));
                }
            });
        }

        modal.querySelectorAll('[data-batch-stamina-item]').forEach((button) => {
            const key = button.dataset.itemKey || '';
            const nextQuantity = quantities.has(key)
                ? Math.max(0, Number(quantities.get(key) || 0))
                : (key === selectedKey ? Math.max(0, Number(button.dataset.quantity || 0) - 1) : Number(button.dataset.quantity || 0));
            button.dataset.quantity = String(nextQuantity);
            button.disabled = nextQuantity <= 0;

            const count = button.querySelector('[data-batch-stamina-item-count]');
            if (count) {
                count.textContent = formatNumber.format(nextQuantity);
            }
        });
    }

    function updateBatchStaminaKiseki(value) {
        const kisekiText = batchStaminaModal()?.querySelector('[data-batch-stamina-kiseki]');
        if (kisekiText && value !== null && value !== undefined) {
            kisekiText.textContent = formatNumber.format(Math.max(0, Number(value || 0)));
        }
    }

    async function useBatchStaminaItem(button, purchased = false) {
        const modal = batchStaminaModal();
        if (!modal || !button?.dataset.useUrl || (busy && !purchased)) return;
        if (!purchased) busy = true;

        const buttons = modal.querySelectorAll('button');
        buttons.forEach((modalButton) => {
            modalButton.disabled = true;
        });
        notify('使用中...', 'info');

        try {
            const formData = new FormData();
            const token = csrfToken();
            if (token) {
                formData.append('_token', token);
            }

            const response = await fetch(button.dataset.useUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
                credentials: 'same-origin',
            });
            const data = await response.json();

            if (!response.ok || data.success !== true) {
                notify(data.message || '探索力を回復できませんでした。', 'warning');
                return;
            }

            if (data.stamina) {
                if (activeCallbacks.sync) {
                    activeCallbacks.sync(data.stamina.current, data.stamina.max, data.stamina.recovery_seconds, data.stamina.next_recovery_seconds);
                } else {
                    window.dispatchEvent(new CustomEvent("valzeria-stamina-sync", { detail: {
                        current: data.stamina.current, max: data.stamina.max,
                        recoverySeconds: data.stamina.recovery_seconds, nextRecoverySeconds: data.stamina.next_recovery_seconds,
                    } }));
                }
            }
            updateBatchStaminaItemChoices(data.support_items, button.dataset.itemKey || null);
            notify(data.message || '探索力を回復しました。', 'info');
            closeBatchStaminaModal(true);

            if (activeCallbacks.resume) {
                await activeCallbacks.resume();
            }
        } catch (error) {
            notify('探索力回復アイテムの使用に失敗しました。通信状態を確認してください。', 'warning');
        } finally {
            if (!purchased) busy = false;
            buttons.forEach((modalButton) => {
                const quantity = modalButton.hasAttribute('data-batch-stamina-item')
                    ? Number(modalButton.dataset.quantity || 0)
                    : 1;
                modalButton.disabled = quantity <= 0;
            });
        }
    }

    async function purchaseAndUseBatchStaminaItem(button) {
        const modal = batchStaminaModal();
        if (!modal || !button?.dataset.purchaseUrl || !button?.dataset.itemKey || busy) return;
        busy = true;

        const buttons = modal.querySelectorAll('button');
        buttons.forEach((modalButton) => {
            modalButton.disabled = true;
        });
        notify('購入中...', 'info');

        try {
            const formData = new FormData();
            const token = csrfToken();
            if (token) {
                formData.append('_token', token);
            }
            formData.append('item_key', button.dataset.itemKey);

            const response = await fetch(button.dataset.purchaseUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
                credentials: 'same-origin',
            });
            const data = await response.json();

            if (!response.ok || data.success !== true) {
                notify(data.message || '探索力回復アイテムを購入できませんでした。', 'warning');
                return;
            }

            updateBatchStaminaKiseki(data.kiseki);
            updateBatchStaminaItemChoices(data.support_items);
            notify(data.message || '探索力回復アイテムを購入しました。', 'info');

            const itemButton = modal.querySelector(`[data-batch-stamina-item][data-item-key="${CSS.escape(button.dataset.itemKey)}"]`);
            if (itemButton) {
                await useBatchStaminaItem(itemButton, true);
            }
        } catch (error) {
            notify('探索力回復アイテムの購入に失敗しました。通信状態を確認してください。', 'warning');
        } finally {
            busy = false;
            buttons.forEach((modalButton) => {
                const quantity = modalButton.hasAttribute('data-batch-stamina-item')
                    ? Number(modalButton.dataset.quantity || 0)
                    : 1;
                modalButton.disabled = quantity <= 0;
            });
        }
    }


    window.ValzeriaStaminaRecovery = { open: openBatchStaminaModal, close: closeBatchStaminaModal };
    window.addEventListener('valzeria-stamina-recovery-open', function (event) {
        openBatchStaminaModal(null, event.detail?.current, event.detail?.required);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeBatchStaminaModal();
    });
})();
