<!DOCTYPE html>
<html lang="ja">
<head>
    <!-- PWA -->
    <link rel="manifest" href="{{ asset('manifest.json') }}?v=3">
    <meta name="theme-color" content="#0f172a">
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理者ログイン - Valzeria</title>
    @include('partials.ogp', ['ogTitle' => '管理者ログイン - Valzeria'])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body
    class="flex min-h-screen items-center justify-center bg-slate-100 bg-cover bg-center px-4"
    style="background-image: linear-gradient(120deg, rgba(248,250,252,.82), rgba(248,250,252,.38) 48%, rgba(15,23,42,.24)), url('{{ asset('images/admin-login-bg.webp') }}');"
>

<div class="w-full max-w-md rounded-lg border border-white/70 border-t-4 border-t-[#d4af37] bg-white/88 p-8 shadow-2xl shadow-slate-900/20 backdrop-blur-[2px]">
    <h1 class="text-2xl font-bold text-center text-[#1e293b] mb-6">Valzeria 管理者ログイン</h1>

    @if ($errors->any())
        <div class="bg-red-50 text-red-500 p-3 rounded mb-4 text-sm">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('admin.login') }}">
        @csrf
        <div class="mb-4">
            <label for="email" class="block text-sm font-medium text-gray-700">メールアドレス (Admin ID)</label>
            <input type="email" name="email" id="email" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37] focus:ring-opacity-50" required autofocus>
        </div>

        <div class="mb-6">
            <label for="password" class="block text-sm font-medium text-gray-700">パスワード</label>
            <input type="password" name="password" id="password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37] focus:ring-opacity-50" required>
        </div>

        <button type="submit" class="w-full bg-[#1e293b] hover:bg-gray-800 text-white font-bold py-2 px-4 rounded-md shadow focus:outline-none focus:ring-2 focus:ring-[#d4af37] transition-colors">
            ログイン
        </button>
    </form>
</div>

</body>
</html>
