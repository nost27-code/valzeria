<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @if(!auth()->check() || auth()->user()->role !== 'admin')
        <!-- Google tag (gtag.js) -->
        <script async src="https://www.googletagmanager.com/gtag/js?id=G-XGYVC4YYP2"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', 'G-XGYVC4YYP2');
        </script>
        @endif
        <!-- PWA -->
        <link rel="manifest" href="{{ asset('manifest.json') }}?v=3">
        <meta name="theme-color" content="#ffffff">
        <link rel="apple-touch-icon" href="{{ asset('images/icon-192x192.png') }}?v=3">
        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => {
                    navigator.serviceWorker.register('/sw.js?v=3').then(registration => {
                        console.log('ServiceWorker registration successful with scope: ', registration.scope);
                    }).catch(err => {
                        console.log('ServiceWorker registration failed: ', err);
                    });
                });
            }
        </script>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title>ヴァルゼリアの冒険者</title>
        <link rel="icon" href="{{ asset('images/favicon.webp') }}?v=2" type="image/webp">
        @include('partials.ogp')


        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        <!-- Styles / Scripts -->
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
        @livewireStyles
        @php
            $cityId = null;
            if (auth()->check()) {
                $character = auth()->user()->currentCharacter();
                if ($character && $character->currentCity) {
                    $cityId = $character->currentCity->id;
                }
            }

            $bgColor = app(\App\Services\CityThemeService::class)->backgroundColorForCityId($cityId);
            $currentLocation = session('current_location', 'home');
            $currentLocation = $currentLocation === 'job' ? 'town' : $currentLocation;
            if (auth()->check()) {
                $character = auth()->user()->currentCharacter();
                if ($character
                    && $currentLocation === 'home'
                    && app(\App\Services\ExplorationStateService::class)->hasActiveExploration($character)) {
                    $currentLocation = 'dungeon';
                    session(['current_location' => 'dungeon']);
                }
            }
        @endphp
    </head>
    <body class="font-sans antialiased text-[#1E293B]" style="background-color: #0f172a;">
        <div class="fixed inset-x-0 bottom-0 h-32 bg-[#0f172a]" aria-hidden="true"></div>
        <div class="relative z-10 min-h-screen" style="background-color: {{ $bgColor }};">
        <x-pwa-install-banner />
        @if (session('toast_success') || session('toast_error'))
            @php
                $isToastSuccess = (bool) session('toast_success');
                $toastText = session('toast_success') ?? session('toast_error');
            @endphp
            <div
                x-data="{ show: true }"
                x-show="show"
                x-init="setTimeout(() => show = false, 3200)"
                x-transition:enter="transition-all duration-200"
                x-transition:enter-start="opacity-0 -translate-y-3"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition-all duration-200"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-3"
                class="pointer-events-none fixed left-3 right-3 top-3 z-[70] rounded-xl border px-4 py-3 text-sm font-black shadow-2xl sm:left-1/2 sm:right-auto sm:w-full sm:max-w-md sm:-translate-x-1/2 {{ $isToastSuccess ? 'bg-emerald-600 border-emerald-400 text-white' : 'bg-red-600 border-red-400 text-white' }}"
            >
                {{ $toastText }}
            </div>
        @endif
        @if (session('message'))
            <div x-data="{ show: true }" x-show="show" class="bg-blue-600 text-white px-4 py-3 shadow-md w-full fixed top-0 z-50 flex justify-between items-center" x-init="setTimeout(() => show = false, 3000)">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>
                    <span class="font-bold text-sm">{!! session('message') !!}</span>
                </div>
                <button @click="show = false" class="text-blue-200 hover:text-white">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
        @endif
        @if (session('error'))
            <div x-data="{ show: true }" x-show="show" class="bg-red-600 text-white px-4 py-3 shadow-md w-full fixed top-0 z-50 flex justify-between items-center" x-init="setTimeout(() => show = false, 5000)">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-11.5a.75.75 0 00-1.5 0v4a.75.75 0 001.5 0v-4zM10 14a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"></path></svg>
                    <span class="font-bold text-sm">{{ session('error') }}</span>
                </div>
                <button @click="show = false" class="text-red-200 hover:text-white">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
        @endif
        <div class="mx-auto flex min-h-screen w-full max-w-screen-2xl flex-col gap-4 px-2 py-4 pb-24 sm:px-4 sm:py-6 sm:pb-24 lg:px-6"
             x-data="{ currentLocation: @js($currentLocation) }"
             @main-tab-selected.window="currentLocation = ($event.detail.location === 'job' ? 'town' : $event.detail.location)">
            <!-- 全幅ヘッダー -->
            <livewire:city-header />
            <livewire:adventurer-departure-set-banner />

            <div x-show="currentLocation === 'home'"
                 style="{{ $currentLocation === 'home' ? '' : 'display: none;' }}">
                <livewire:home-action-panel />
            </div>

            <div class="flex flex-col lg:flex-row gap-6 flex-grow">
                <!-- 左カラム (ステータス) -->
                <div x-show="currentLocation === 'home'"
                     style="{{ $currentLocation === 'home' ? '' : 'display: none;' }}"
                     class="w-full lg:w-[28rem] xl:w-[30rem] flex-shrink-0">
                    <livewire:left-sidebar />
                </div>

                <!-- 右カラム: チャンプ戦カード + メインコンテンツ -->
                <div class="flex-1 min-w-0 flex flex-col gap-4">
                    <livewire:champ-card />
                    <livewire:star-tree-tower-ranking-widget />

                    <div class="min-w-0 flex flex-col gap-0 rounded-xl shadow-[0_8px_22px_rgba(126,96,28,0.18)]" data-main-content>
                        <div class="bg-white border border-[#d4af37] rounded-xl p-0 flex-grow min-h-0 overflow-hidden">
                            {{ $slot }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- 全幅チャット -->
            <livewire:chat-log />
        </div>
        </div>

        @livewireScripts
        <script>
        // タブ切り替え時のスクロールジャンプを防ぐグローバルロック機構
        document.addEventListener('DOMContentLoaded', function() {
            let lockedScrollY = null;
            let scrollLockActive = false;
            let releaseTimer = null;

            function lockScroll() {
                lockedScrollY = window.scrollY;
                scrollLockActive = true;
                // bodyに固定高さを設定してレイアウトシフトを防ぐ
                document.body.style.overflow = 'hidden';
            }

            function releaseScroll() {
                if (!scrollLockActive) return;
                document.body.style.overflow = '';
                if (lockedScrollY !== null) {
                    window.scrollTo({ top: lockedScrollY, behavior: 'instant' });
                }
                scrollLockActive = false;
                lockedScrollY = null;
            }

            // changeTab イベントでスクロールをロック
            document.addEventListener('livewire:event', function(e) {
                if (e.detail && e.detail.name === 'changeTab') {
                    lockScroll();
                    // 念のため一定時間後に必ず解除するフォールバック
                    clearTimeout(releaseTimer);
                    releaseTimer = setTimeout(releaseScroll, 1000);
                }
            });

            // 全Livewireコミット完了後にスクロールを解除
            Livewire.hook('commit', ({ component, succeed }) => {
                if (scrollLockActive) {
                    succeed(() => {
                        // 少し待ってから解除（全コンポーネントの更新を待つ）
                        clearTimeout(releaseTimer);
                        releaseTimer = setTimeout(releaseScroll, 80);
                    });
                }
            });
        });
        </script>
    </body>
</html>
