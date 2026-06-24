<div id="pwa-install-banner" class="hidden relative z-[100] w-full flex items-center gap-2.5 bg-slate-900 px-3 py-2 shadow-[0_2px_12px_rgba(0,0,0,0.4)]">
    {{-- アプリアイコン風 --}}
    <div class="shrink-0 rounded-xl overflow-hidden">
        <img src="{{ asset('images/install.webp') }}" alt="" class="h-10 w-10 object-contain">
    </div>
    {{-- テキスト --}}
    <div class="min-w-0 flex-1">
        <p class="truncate text-xs font-bold text-white">アプリとしてインストール</p>
        <p class="truncate text-[10px] text-slate-400" id="pwa-install-desc">ホーム画面に追加してフルスクリーンで遊ぼう！</p>
    </div>
    {{-- 追加ボタン --}}
    <button id="pwa-install-btn" class="shrink-0 whitespace-nowrap rounded-lg bg-[#d4af37] px-3 py-1.5 text-xs font-black text-slate-900 shadow-sm transition hover:bg-[#c9a227] active:scale-95">追加</button>
    {{-- 閉じるボタン --}}
    <button id="pwa-dismiss-btn" class="shrink-0 flex h-7 w-7 items-center justify-center rounded-full text-slate-500 transition hover:bg-white/10 hover:text-white">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
    </button>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const banner = document.getElementById('pwa-install-banner');
    const installBtn = document.getElementById('pwa-install-btn');
    const dismissBtn = document.getElementById('pwa-dismiss-btn');
    const desc = document.getElementById('pwa-install-desc');
    let deferredPrompt;

    // 既にPWAとして起動しているかチェック
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (isStandalone) {
        return; // すでにアプリ化されている場合は表示しない
    }

    // 今日の日付を取得 (YYYY-MM-DD)
    const getTodayStr = () => {
        const today = new Date();
        return `${today.getFullYear()}-${today.getMonth() + 1}-${today.getDate()}`;
    };

    // 却下履歴のチェック
    const dismissedDate = localStorage.getItem('pwa_banner_dismissed_date');
    if (dismissedDate === getTodayStr()) {
        return; // 今日すでに「いいえ」を押している場合は表示しない
    }

    // iOSかどうかの判定
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    // Safariかどうかの判定（Chrome for iOSなども考慮）
    const isSafari = isIOS && /WebKit/.test(navigator.userAgent) && !/CriOS/.test(navigator.userAgent) && !/FxiOS/.test(navigator.userAgent);

    if (isSafari) {
        // iOS Safari の場合
        banner.classList.remove('hidden');
        installBtn.classList.add('hidden'); // iOS Safariはボタンで自動インストールできない
        desc.innerHTML = '共有メニュー<span class="text-lg leading-none mx-1">↑</span>から「ホーム画面に追加」を選択';
    } else {
        // Android / PC Chrome 等の場合
        window.addEventListener('beforeinstallprompt', (e) => {
            // デフォルトのプロンプトを防止
            e.preventDefault();
            // 後でトリガーできるようにイベントを保存
            deferredPrompt = e;
            // バナーを表示
            banner.classList.remove('hidden');
        });
    }

    // インストールボタンのクリック処理
    installBtn.addEventListener('click', async () => {
        if (deferredPrompt) {
            // プロンプトを表示
            deferredPrompt.prompt();
            // ユーザーの応答を待つ
            const { outcome } = await deferredPrompt.userChoice;
            if (outcome === 'accepted') {
                banner.classList.add('hidden');
            }
            deferredPrompt = null;
        } else if (isIOS && !isSafari) {
             // iOSでSafari以外のブラウザを使っている場合はSafariを開くよう促す等の代替処理が必要だが、一旦非表示
             alert('ホーム画面への追加はSafariブラウザからのみ可能です。');
        }
    });

    // 「いいえ」ボタンのクリック処理
    dismissBtn.addEventListener('click', () => {
        // 本日の日付をLocalStorageに保存
        localStorage.setItem('pwa_banner_dismissed_date', getTodayStr());
        banner.classList.add('hidden');
    });
});
</script>
