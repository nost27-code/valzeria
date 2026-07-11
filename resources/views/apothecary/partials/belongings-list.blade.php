@php
    $hasOtherActive = collect($belongings)->contains('is_active', true);
@endphp
<div data-belongings-container data-csrf="{{ csrf_token() }}" data-activate-url="{{ route('apothecary.activate') }}" data-auto-renew-url="{{ route('apothecary.auto-renew') }}">
    <div class="space-y-2">
        @forelse($belongings as $belonging)
            <div class="border {{ $belonging['is_active'] ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-white' }} rounded-lg px-3 py-2.5">
                <div class="flex flex-wrap items-center gap-1.5">
                    <h4 class="text-sm font-bold text-slate-900 leading-snug">{{ $belonging['name'] }}</h4>
                    @if($belonging['is_active'])
                        <span class="shrink-0 text-[10px] bg-amber-200 text-amber-800 px-1.5 py-0.5 rounded font-bold">使用中</span>
                    @endif
                </div>
                <p class="mt-1 text-xs leading-relaxed text-slate-600">{{ $belonging['description'] }}</p>
                <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xs font-bold text-slate-500">所持数 {{ number_format($belonging['owned']) }}個</span>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            data-belonging-auto-toggle
                            data-item-key="{{ $belonging['item_key'] }}"
                            data-auto-renew="{{ $belonging['auto_renew'] ? '1' : '0' }}"
                            title="30戦使い切った時、同じ品を自動で補充して使い続けます"
                            class="h-7 rounded border px-2 text-[11px] font-bold shadow-sm transition active:scale-95 {{ $belonging['auto_renew'] ? 'border-emerald-300 bg-emerald-600 text-white' : 'border-slate-300 bg-white text-slate-600' }}"
                        >自動補充: {{ $belonging['auto_renew'] ? 'ON' : 'OFF' }}</button>
                        @if($belonging['owned'] > 0 && !$belonging['is_active'])
                            <button
                                type="button"
                                data-belonging-activate
                                data-item-key="{{ $belonging['item_key'] }}"
                                data-conflict="{{ $hasOtherActive ? '1' : '0' }}"
                                data-armed="0"
                                data-default-label="使用する"
                                class="h-7 rounded bg-amber-600 px-3 text-[11px] font-bold text-white shadow-sm transition hover:bg-amber-700 active:scale-95"
                            >使用する</button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="text-center py-10 bg-white rounded-lg border border-slate-200 border-dashed">
                <p class="text-slate-500">所持している補助品はありません。</p>
                <a href="{{ route('apothecary.index') }}" wire:navigate class="mt-2 inline-block text-sm text-blue-600 hover:underline">薬屋で調合する</a>
            </div>
        @endforelse
    </div>
</div>

<script>
    (() => {
        if (window.__valzeriaBelongingsAsyncBound) {
            return;
        }
        window.__valzeriaBelongingsAsyncBound = true;

        let armedResetTimer = null;

        function disarmAll() {
            document.querySelectorAll('[data-belonging-activate][data-armed="1"]').forEach((button) => {
                button.dataset.armed = '0';
                button.textContent = button.dataset.defaultLabel || '使用する';
                button.classList.remove('bg-red-600', 'hover:bg-red-700');
                button.classList.add('bg-amber-600', 'hover:bg-amber-700');
            });
        }

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

        document.addEventListener('click', async (event) => {
            const activateButton = event.target.closest('[data-belonging-activate]');
            if (activateButton) {
                const container = activateButton.closest('[data-belongings-container]');
                if (!container) return;

                if (activateButton.dataset.conflict === '1' && activateButton.dataset.armed !== '1') {
                    disarmAll();
                    activateButton.dataset.armed = '1';
                    activateButton.textContent = '本当に使用する？';
                    activateButton.classList.remove('bg-amber-600', 'hover:bg-amber-700');
                    activateButton.classList.add('bg-red-600', 'hover:bg-red-700');
                    clearTimeout(armedResetTimer);
                    armedResetTimer = setTimeout(disarmAll, 4000);
                    return;
                }

                clearTimeout(armedResetTimer);
                const originalText = activateButton.textContent;
                activateButton.disabled = true;
                activateButton.textContent = '使用中...';
                try {
                    const data = await postJson(container.dataset.activateUrl, container.dataset.csrf, {
                        item_key: activateButton.dataset.itemKey,
                    });
                    replaceContainer(container, data.belongings_html);
                } catch (error) {
                    alert(error.message || '使用に失敗しました。');
                    activateButton.disabled = false;
                    activateButton.textContent = originalText;
                }
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
                    });
                    replaceContainer(container, data.belongings_html);
                } catch (error) {
                    alert(error.message || '自動補充の変更に失敗しました。');
                    toggleButton.disabled = false;
                }
            }
        });
    })();
</script>
