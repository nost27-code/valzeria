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
        <title>キャラクター選択 - ヴァルゼリアの冒険者</title>
        <link rel="icon" href="{{ asset('images/favicon.webp') }}?v=2" type="image/webp">
        @include('partials.ogp', ['ogTitle' => 'キャラクター選択 - ヴァルゼリアの冒険者'])

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        <!-- Styles / Scripts -->
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
        @livewireStyles
    </head>
    <body class="font-sans antialiased bg-slate-50 text-slate-800">
        <x-pwa-install-banner />
        @if (session('message'))
            <div x-data="{ show: true }" x-show="show"
                 class="w-full fixed top-0 z-50 flex justify-between items-center bg-amber-50 border-b border-amber-200 px-4 py-3 shadow-sm"
                 x-init="setTimeout(() => show = false, 3000)">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-amber-600 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>
                    <span class="font-bold text-sm text-slate-800">{!! session('message') !!}</span>
                </div>
                <button @click="show = false" class="text-slate-400 hover:text-slate-700">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="h-12"></div>
        @endif

        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12 min-h-screen flex flex-col justify-center">
            {{ $slot }}
        </div>
        
        @livewireScripts
    </body>
</html>
