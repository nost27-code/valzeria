<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') - ヴァルゼリアの冒険者</title>
    <link rel="manifest" href="{{ asset('manifest.json') }}?v=3">
    <meta name="theme-color" content="#0a1628">
    <link rel="apple-touch-icon" href="{{ asset('images/icon-192x192.png') }}?v=3">
    <link rel="icon" href="{{ asset('images/favicon.webp') }}?v=2" type="image/webp">
    @include('partials.ogp', ['ogTitle' => trim($__env->yieldContent('title') . ' - ヴァルゼリアの冒険者')])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-[#f8f6ef] text-slate-900 antialiased">
    <x-pwa-install-banner />

    <header class="border-b-2 border-[#e7d98e] bg-white">
        <div class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-4">
            <a href="{{ route('top') }}" class="text-sm font-black tracking-[0.18em] text-amber-700">VALZERIA</a>
            <a href="{{ route('top') }}" class="rounded-full border border-[#e7d98e] px-4 py-2 text-sm font-bold text-slate-600 hover:bg-amber-50">TOPへ戻る</a>
        </div>
    </header>

    <main class="mx-auto max-w-3xl px-4 py-8 sm:py-12">
        <div class="mb-7">
            <p class="text-xs font-black tracking-[0.24em] text-amber-700">@yield('eyebrow')</p>
            <h1 class="mt-2 text-2xl font-black leading-tight text-slate-950 sm:text-3xl">@yield('title')</h1>
            <p class="mt-3 text-sm font-bold text-slate-500">最終更新日：2026年6月17日</p>
        </div>

        <article class="space-y-7 rounded-lg border border-[#e7d98e] bg-white p-5 shadow-sm sm:p-7">
            @yield('content')
        </article>
    </main>

    <footer class="px-4 pb-8 text-center text-xs font-bold text-slate-400">
        &copy; 2026 ヴァルゼリアの冒険者 Project. All rights reserved.
    </footer>
</body>
</html>
