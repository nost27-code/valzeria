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
        <link rel="manifest" href="{{ asset('manifest.json') }}?v=3">
        <meta name="theme-color" content="#2d6a1a">
        <link rel="apple-touch-icon" href="{{ asset('images/icon-192x192.png') }}?v=3">
        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => {
                    navigator.serviceWorker.register('/sw.js?v=3');
                });
            }
        </script>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
        <title>ヴァルモン牧場 - ヴァルゼリアの冒険者</title>
        <link rel="icon" href="{{ asset('images/favicon.webp') }}?v=2" type="image/webp">
        @include('partials.ogp', ['ogTitle' => 'ヴァルモン牧場 - ヴァルゼリアの冒険者'])
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
        @livewireStyles
        <style>
            /* iOS safe area 対応 */
            .pt-safe { padding-top: max(1rem, env(safe-area-inset-top)); }
            .pb-safe { padding-bottom: max(1.5rem, env(safe-area-inset-bottom)); }
        </style>
    </head>
    <body class="font-sans antialiased text-slate-800 overflow-x-hidden bg-slate-50">

        <x-pwa-install-banner />

        {{-- デスクトップ: 通常の施設ヘッダー --}}
        <div class="hidden md:block relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-6 w-full">
            <div class="relative bg-white rounded-lg shadow-md border-t-4 border-[#003366] overflow-hidden mb-2">
                <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('{{ asset('images/valmon/ranch_bg.webp') }}');"></div>
                <div class="absolute inset-0 bg-white/70 backdrop-blur-[1px]"></div>
                <div class="relative z-10 px-4 sm:px-6 py-5 flex items-center gap-4">
                    <div class="w-12 h-12 bg-white border border-slate-200 rounded-full flex items-center justify-center shadow-sm"><img src="{{ asset('images/icon/icon_038.webp') }}" alt="" class="w-8 h-8 object-contain"></div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold tracking-widest text-[#003366] drop-shadow-sm">ヴァルモン牧場</h1>
                </div>
            </div>
        </div>

        {{-- フラッシュメッセージ --}}
        @if (session('message'))
            <div x-data="{ show: true }" x-show="show" class="relative z-50 bg-blue-600 text-white px-4 py-3 shadow-md w-full flex justify-between items-center" x-init="setTimeout(() => show = false, 3000)">
                <span class="font-bold text-sm">{!! session('message') !!}</span>
                <button @click="show = false" class="text-blue-200 hover:text-white ml-4">✕</button>
            </div>
        @endif

        {{-- メインコンテンツ --}}
        <div class="relative z-10 w-full md:max-w-7xl md:mx-auto md:px-4 md:sm:px-6 md:lg:px-8 md:py-8">
            {{-- デスクトップ: 戻るリンク --}}
            <div class="hidden md:block mb-4">
                <a href="{{ route('home') }}" class="inline-flex items-center text-slate-500 hover:text-[#d4af37] font-bold transition-colors group">
                    <svg class="w-5 h-5 mr-1 transform group-hover:-translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    ヴァルモン牧場から出る
                </a>
            </div>

            {{ $slot }}

            {{-- デスクトップ: 退出ボタン --}}
            <div class="hidden md:flex mt-8 justify-center">
                <x-back-button href="{{ route('home') }}" label="ヴァルモン牧場から出る" icon="🚪" />
            </div>
        </div>

        @livewireScripts
    </body>
</html>
