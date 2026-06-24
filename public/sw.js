// PWA要件を満たすためのダミーキャッシュ名
const CACHE_NAME = 'valzeria-cache-v3';

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
