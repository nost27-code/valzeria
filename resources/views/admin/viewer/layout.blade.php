<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', '閲覧専用管理画面') - Valzeria</title>
    @vite(['resources/css/app.css'])
    <style>
        html,body { max-width:100%; overflow-wrap:anywhere; }
        pre { white-space:pre-wrap; overflow-wrap:anywhere; word-break:break-word; }
    </style>
</head>
<body class="bg-slate-100 text-slate-900">
    <header class="border-b border-slate-200 bg-white px-4 py-4">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3">
            <div>
                <a href="{{ route('admin.viewer.index') }}" class="text-lg font-bold">Valzeria 閲覧専用管理画面</a>
                <p class="text-sm text-slate-500">dot用 · 閲覧と検索のみ</p>
            </div>
            @if(session()->has('admin_viewer.fingerprint'))
                <form method="POST" action="{{ route('admin.viewer.logout') }}">
                    @csrf
                    <button class="rounded border px-4 py-2">ログアウト</button>
                </form>
            @endif
        </div>
    </header>
    <main class="mx-auto w-full max-w-6xl px-4 py-6">@yield('content')</main>
</body>
</html>
