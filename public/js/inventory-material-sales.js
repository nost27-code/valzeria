(() => {
    window.createMaterialSalesStore = (requestUuid) => ({
        items: {},
        requestUuid,
        submitting: false,
        uncertain: false,
        discarding: 0,
        pending: null,
        message: '',
        messageType: 'success',
        get busy() { return this.submitting || this.uncertain || this.discarding > 0; },
        set(id, qty, price) { if (!this.submitting && !this.uncertain) this.items[id] = { qty, price }; },
        remove(id) { if (!this.submitting && !this.uncertain) delete this.items[id]; },
        get total() { return Object.values(this.items).reduce((sum, item) => sum + item.qty * item.price, 0); },
        get count() { return Object.keys(this.items).length; },
        clear() { if (!this.busy) this.items = {}; },
    });

    window.bulkSellMaterials = async (csrfToken, sellUrl) => {
        const store = Alpine.store('matSales');
        if (store.submitting || store.discarding > 0) return;
        if (!store.pending) {
            const sales = Object.entries(store.items).map(([id, item]) => ({
                character_material_id: Number(id), quantity: Number(item.qty),
            }));
            if (sales.length < 2) return;
            store.pending = { request_uuid: store.requestUuid, sales };
        }

        store.submitting = true;
        store.message = '';
        try {
            const response = await fetch(sellUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify(store.pending),
            });
            const data = await response.json().catch(() => null);
            if (!response.ok || data?.success !== true) {
                if (!response.redirected && response.status === 422 && data) {
                    store.uncertain = false;
                    store.pending = null;
                    store.message = Object.values(data.errors || {}).flat()[0] || data.message || '素材をまとめて売却できませんでした。';
                } else {
                    throw new Error('unknown sale result');
                }
                store.messageType = 'error';
                return;
            }
            if (!Array.isArray(data.sales) || typeof data.next_request_uuid !== 'string') {
                throw new Error('incomplete sale result');
            }

            store.items = {};
            store.pending = null;
            store.uncertain = false;
            store.requestUuid = data.next_request_uuid;
            store.messageType = 'success';
            store.message = `${data.message} 所持Gold: ${Number(data.money).toLocaleString()}G`;
            window.dispatchEvent(new CustomEvent('materials-sold', { detail: data }));
        } catch {
            // 確定後の通信切断もあり得るため、同じ操作番号・内容を保持して結果を再確認する。
            store.uncertain = true;
            store.messageType = 'error';
            store.message = '売却結果を確認できませんでした。「結果を確認する」を押してください。続く場合は倉庫を開き直してください。';
        } finally {
            store.submitting = false;
        }
    };
})();
