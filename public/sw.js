// PWA要件を満たすためのダミーキャッシュ名
const CACHE_NAME = 'valzeria-cache-v4';

// インストール時に即座にアクティベート
self.addEventListener('install', (event) => {
    self.skipWaiting();
});

// アクティベート時、もし古いキャッシュがあればクリア
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cache) => {
                    if (cache !== CACHE_NAME) {
                        return caches.delete(cache);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// リクエストのフェッチ
// ゲームは常に最新のデータを取得する必要があるため、強力な「Network Only」戦略を採用します。
self.addEventListener('fetch', (event) => {
    // リクエストがGET以外（POST等）の場合は何もしない（Service Workerは関与しない）
    if (event.request.method !== 'GET') {
        return;
    }

    event.respondWith(
        fetch(event.request).catch(() => {
            // オフラインなどで取得失敗した場合のみ、ブラウザにエラーを返すか、
            // キャッシュがあれば（基本無いですが）それを返します。
            return caches.match(event.request);
        })
    );
});

const DEFAULT_PUSH_PAYLOAD = {
    title: 'ヴァルゼリアの冒険者',
    body: '通知ベルに新着があります。',
    tag: 'valzeria-bell',
    data: {
        url: '/home',
    },
};

self.addEventListener('push', (event) => {
    let payload = DEFAULT_PUSH_PAYLOAD;

    if (event.data) {
        try {
            payload = { ...DEFAULT_PUSH_PAYLOAD, ...event.data.json() };
        } catch (error) {
            payload = DEFAULT_PUSH_PAYLOAD;
        }
    }

    let targetUrl = '/home';
    try {
        const candidate = new URL(payload.data?.url || '/home', self.location.origin);
        if (candidate.origin === self.location.origin) {
            targetUrl = `${candidate.pathname}${candidate.search}${candidate.hash}`;
        }
    } catch (error) {
        targetUrl = '/home';
    }

    event.waitUntil(self.registration.showNotification(
        typeof payload.title === 'string' ? payload.title : DEFAULT_PUSH_PAYLOAD.title,
        {
            body: typeof payload.body === 'string' ? payload.body : DEFAULT_PUSH_PAYLOAD.body,
            icon: '/images/icon-192x192.png',
            badge: '/images/icon-192x192.png',
            tag: typeof payload.tag === 'string' ? payload.tag : DEFAULT_PUSH_PAYLOAD.tag,
            renotify: false,
            data: {
                url: targetUrl,
            },
        }
    ));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const targetUrl = event.notification.data?.url || '/home';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async (windowClients) => {
            const existingClient = windowClients.find((client) => {
                try {
                    return new URL(client.url).origin === self.location.origin;
                } catch (error) {
                    return false;
                }
            });

            if (existingClient) {
                if ('navigate' in existingClient) {
                    await existingClient.navigate(targetUrl);
                }

                return existingClient.focus();
            }

            return self.clients.openWindow(targetUrl);
        })
    );
});
