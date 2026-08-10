<div wire:ignore
     data-web-push-control
     data-vapid-public-key="{{ $vapidPublicKey }}"
     data-store-url="{{ route('web-push.subscription.store') }}"
     data-destroy-url="{{ route('web-push.subscription.destroy') }}"
     data-csrf-token="{{ csrf_token() }}"
     class="border-b border-slate-100 bg-sky-50/70 px-3 py-2">
    <div class="flex items-center justify-between gap-2">
        <div class="min-w-0">
            <div class="text-[11px] font-black text-slate-700">スマホ通知</div>
            <div data-web-push-status class="mt-0.5 text-[10px] font-bold leading-snug text-slate-500">設定を確認しています…</div>
        </div>
        <button type="button"
                data-web-push-enable
                hidden
                class="shrink-0 rounded-md bg-sky-600 px-2.5 py-1.5 text-[10px] font-black text-white shadow-sm transition active:scale-95 disabled:opacity-60">
            有効にする
        </button>
        <button type="button"
                data-web-push-disable
                hidden
                class="shrink-0 rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-[10px] font-black text-slate-600 transition active:scale-95 disabled:opacity-60">
            解除
        </button>
    </div>
</div>

@once
    <script>
        (() => {
            const selector = '[data-web-push-control]';

            const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true;

            const applicationServerKey = (value) => {
                const padding = '='.repeat((4 - value.length % 4) % 4);
                const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
                const raw = window.atob(base64);

                return Uint8Array.from([...raw].map((character) => character.charCodeAt(0)));
            };

            const subscriptionFingerprint = (endpoint) => {
                let hash = 2166136261;
                for (let index = 0; index < endpoint.length; index++) {
                    hash ^= endpoint.charCodeAt(index);
                    hash = Math.imul(hash, 16777619);
                }

                return (hash >>> 0).toString(16);
            };

            const postSubscription = async (root, subscription) => {
                const fingerprint = subscriptionFingerprint(subscription.endpoint);
                if (window.sessionStorage.getItem('valzeria_web_push_synced') === fingerprint) {
                    return;
                }

                const payload = subscription.toJSON();
                payload.contentEncoding = 'aes128gcm';

                const response = await fetch(root.dataset.storeUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': root.dataset.csrfToken,
                    },
                    body: JSON.stringify(payload),
                });

                if (!response.ok) {
                    throw new Error('subscription_store_failed');
                }

                window.sessionStorage.setItem('valzeria_web_push_synced', fingerprint);
            };

            const initialize = async (root) => {
                if (root.dataset.webPushReady === '1') {
                    return;
                }
                root.dataset.webPushReady = '1';

                const status = root.querySelector('[data-web-push-status]');
                const enableButton = root.querySelector('[data-web-push-enable]');
                const disableButton = root.querySelector('[data-web-push-disable]');

                const show = (message, state = 'off') => {
                    status.textContent = message;
                    enableButton.hidden = state !== 'off';
                    disableButton.hidden = state !== 'on';
                };

                if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
                    show('この端末はスマホ通知に対応していません。', 'unavailable');
                    return;
                }

                if (!isStandalone()) {
                    show('PWAをホーム画面から開くと設定できます。', 'unavailable');
                    return;
                }

                let registration;
                let currentSubscription;

                try {
                    registration = await navigator.serviceWorker.ready;
                    currentSubscription = await registration.pushManager.getSubscription();

                    if (currentSubscription) {
                        await postSubscription(root, currentSubscription);
                        show('新着を端末へ通知します。', 'on');
                    } else if (Notification.permission === 'denied') {
                        show('端末の設定で通知が拒否されています。', 'unavailable');
                    } else {
                        show('スマホ通知はオフです。', 'off');
                    }
                } catch (error) {
                    show('通知設定を確認できませんでした。再読み込みしてください。', 'unavailable');
                    return;
                }

                enableButton.addEventListener('click', async () => {
                    enableButton.disabled = true;
                    status.textContent = '通知を設定しています…';
                    let createdSubscription = false;

                    try {
                        const permission = Notification.permission === 'default'
                            ? await Notification.requestPermission()
                            : Notification.permission;

                        if (permission !== 'granted') {
                            show('通知が許可されませんでした。', 'unavailable');
                            return;
                        }

                        currentSubscription = await registration.pushManager.getSubscription();
                        if (!currentSubscription) {
                            currentSubscription = await registration.pushManager.subscribe({
                                userVisibleOnly: true,
                                applicationServerKey: applicationServerKey(root.dataset.vapidPublicKey),
                            });
                            createdSubscription = true;
                        }

                        await postSubscription(root, currentSubscription);
                        show('新着を端末へ通知します。', 'on');
                    } catch (error) {
                        if (createdSubscription && currentSubscription) {
                            await currentSubscription.unsubscribe().catch(() => false);
                            currentSubscription = null;
                        }
                        show('通知を有効にできませんでした。もう一度お試しください。', 'off');
                    } finally {
                        enableButton.disabled = false;
                    }
                });

                disableButton.addEventListener('click', async () => {
                    disableButton.disabled = true;
                    status.textContent = '通知を解除しています…';

                    try {
                        currentSubscription = await registration.pushManager.getSubscription();
                        if (currentSubscription) {
                            const response = await fetch(root.dataset.destroyUrl, {
                                method: 'DELETE',
                                credentials: 'same-origin',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': root.dataset.csrfToken,
                                },
                                body: JSON.stringify({ endpoint: currentSubscription.endpoint }),
                            });

                            if (!response.ok) {
                                throw new Error('subscription_destroy_failed');
                            }

                            await currentSubscription.unsubscribe();
                            currentSubscription = null;
                        }

                        window.sessionStorage.removeItem('valzeria_web_push_synced');
                        show('スマホ通知はオフです。', 'off');
                    } catch (error) {
                        show('通知を解除できませんでした。もう一度お試しください。', 'on');
                    } finally {
                        disableButton.disabled = false;
                    }
                });
            };

            document.querySelectorAll(selector).forEach((root) => initialize(root));
        })();
    </script>
@endonce
