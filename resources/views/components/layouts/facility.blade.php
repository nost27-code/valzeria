@props([
    'title' => '施設',
    'headerIcon' => null,
    'headerIconImage' => null,
    'backgroundSymbolImage' => null,
    'pageBackgroundClass' => 'bg-slate-50',
    'pageBackgroundStyle' => null,
    'bgImage' => null,
    'showExit' => true,
])

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
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{{ $title ?? '施設' }} - ヴァルゼリアの冒険者</title>
        <link rel="icon" href="{{ asset('images/favicon.webp') }}?v=2" type="image/webp">
        @include('partials.ogp', ['ogTitle' => ($title ?? '施設') . ' - ヴァルゼリアの冒険者'])

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        <!-- Styles / Scripts -->
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
        @livewireStyles
    </head>
    <body class="relative font-sans antialiased {{ $pageBackgroundClass }} text-slate-800 min-h-screen flex flex-col overflow-x-hidden" @if($pageBackgroundStyle) style="{{ $pageBackgroundStyle }}" @endif>
        @php
            $facilityBaseName = $title ?? 'ここ';
            if (($pos = strpos($facilityBaseName, ' (')) !== false) {
                $facilityBaseName = substr($facilityBaseName, 0, $pos);
            }
            $isBattleResult = in_array($facilityBaseName, ['戦闘結果', '戦闘開始！'], true);
            $exitLabel = $isBattleResult ? '戦利品を持って帰る' : $facilityBaseName . 'から出る';
        @endphp
        <x-pwa-install-banner />
        @if($backgroundSymbolImage)
            <div class="pointer-events-none fixed inset-0 z-0 flex items-center justify-center overflow-hidden lg:hidden" aria-hidden="true">
                <img
                    src="{{ asset($backgroundSymbolImage) }}"
                    alt=""
                    class="h-[82vh] w-[82vh] max-h-none max-w-none object-contain opacity-[0.14] saturate-90"
                >
            </div>
            <div class="pointer-events-none fixed inset-0 z-0 hidden overflow-hidden lg:block" aria-hidden="true">
                <img
                    src="{{ asset($backgroundSymbolImage) }}"
                    alt=""
                    class="absolute left-[-10rem] top-1/2 h-[92vh] w-[92vh] max-h-none max-w-none -translate-y-1/2 rotate-[-8deg] object-contain opacity-[0.16] saturate-90"
                >
                <img
                    src="{{ asset($backgroundSymbolImage) }}"
                    alt=""
                    class="absolute right-[-14rem] top-[54%] h-[86vh] w-[86vh] max-h-none max-w-none -translate-y-1/2 rotate-[10deg] object-contain opacity-[0.10] saturate-90"
                >
            </div>
        @endif
        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 {{ $isBattleResult ? 'pt-4' : 'pt-6' }} w-full">
            <!-- 施設ヘッダーブロック -->
            <div class="relative bg-white rounded-lg shadow-md border-t-4 {{ $isBattleResult ? 'border-red-600' : 'border-[#003366]' }} overflow-hidden {{ $isBattleResult ? 'mb-1' : 'mb-2' }}">
                @php
                    $resolvedBgImage = $bgImage ?? null;
                    if (!$resolvedBgImage && auth()->check()) {
                        $__char = auth()->user()->currentCharacter();
                        if ($__char) {
                            $resolvedBgImage = app(\App\Services\CityThemeService::class)->bgImageForCityId($__char->current_city_id);
                        }
                    }
                    if ($resolvedBgImage && !file_exists(public_path($resolvedBgImage))) {
                        $resolvedBgImage = 'images/bg-castle.webp';
                    }
                @endphp
                @if($resolvedBgImage)
                    <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('{{ asset($resolvedBgImage) }}');"></div>
                    <!-- テキスト視認性向上のためのオーバーレイ -->
                    <div class="absolute inset-0 bg-white/75 backdrop-blur-[1px]"></div>
                @endif
                <div class="relative z-10 px-4 sm:px-6 lg:px-8 {{ $isBattleResult ? 'py-4' : 'py-5' }} flex flex-col md:flex-row justify-between items-center gap-4">
                    
                    <!-- タイトルエリア -->
                    <div class="flex items-center gap-4">
                        @if($headerIconImage)
                            <div class="w-12 h-12 bg-white border border-slate-200 rounded-full flex items-center justify-center shadow-sm overflow-hidden">
                                <img src="{{ asset($headerIconImage) }}" alt="" class="h-9 w-9 object-contain">
                            </div>
                        @elseif(isset($headerIcon))
                            <div class="w-12 h-12 bg-white border {{ $isBattleResult ? 'border-red-200 text-red-600 shadow-red-100' : 'border-slate-200' }} rounded-full flex items-center justify-center text-2xl shadow-sm">
                                {{ $headerIcon }}
                            </div>
                        @endif
                        <div>
                            <h1 class="text-2xl sm:text-3xl font-extrabold tracking-widest {{ $isBattleResult ? 'text-red-700' : 'text-[#003366]' }} drop-shadow-sm">
                                {{ $title ?? '施設' }}
                            </h1>
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>

        <!-- フラッシュメッセージ -->
        @if (session('message'))
            <div x-data="{ show: true }" x-show="show" class="bg-blue-600 text-white px-4 py-3 shadow-md w-full relative z-50 flex justify-between items-center" x-init="setTimeout(() => show = false, 3000)">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>
                    <span class="font-bold text-sm">{!! session('message') !!}</span>
                </div>
                <button @click="show = false" class="text-blue-200 hover:text-white">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
        @endif

        <!-- メインコンテンツ -->
        <div class="relative z-10 flex-grow max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 {{ $isBattleResult ? 'py-2' : 'py-8' }} w-full">
            @if($showExit && !$isBattleResult)
                <!-- 退出リンク (左上) -->
                <div class="mb-4">
                    @if($isBattleResult)
                        <form action="{{ route('battle.return') }}" method="POST" class="inline">
                            @csrf
                            <button type="submit" class="inline-flex items-center text-slate-500 hover:text-[#d4af37] font-bold transition-colors group">
                                <svg class="w-5 h-5 mr-1 transform group-hover:-translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                                {{ $exitLabel }}
                            </button>
                        </form>
                    @else
                        <a href="{{ route('home') }}" class="inline-flex items-center text-slate-500 hover:text-[#d4af37] font-bold transition-colors group">
                            <svg class="w-5 h-5 mr-1 transform group-hover:-translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                            {{ $exitLabel }}
                        </a>
                    @endif
                </div>
            @endif

            {{ $slot }}
            
            @if($showExit)
                <!-- 共通の退出ボタン -->
                <div class="mt-8 flex justify-center w-full">
                    @if($isBattleResult)
                        <form action="{{ route('battle.return') }}" method="POST" x-data="{ loading: false }" @submit="loading = true">
                            @csrf
                            <button type="submit"
                                    :disabled="loading"
                                    :class="loading ? 'opacity-80' : ''"
                                    class="bg-slate-800 hover:bg-slate-700 disabled:cursor-wait text-white font-bold rounded-lg shadow-lg transition duration-200 text-sm flex items-center justify-center gap-3"
                                    style="padding: 14px 40px; min-width: 240px; letter-spacing: 0.05em;">
                                <img x-show="!loading" src="{{ asset('images/icon/icon_001.webp') }}" alt="" class="w-5 h-5 object-contain opacity-90">
                                <svg x-show="loading" style="display: none;" class="h-4 w-4 animate-spin text-white" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                                    <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
                                </svg>
                                <span>{{ $exitLabel }}</span>
                            </button>
                        </form>
                    @else
                        <x-back-button href="{{ route('home') }}" label="{{ $exitLabel }}" />
                    @endif
                </div>
            @endif
        </div>
        
        @livewireScripts
    </body>
</html>
