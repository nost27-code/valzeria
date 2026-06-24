<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>メールログイン - ヴァルゼリアの冒険者</title>
    @include('partials.ogp', ['ogTitle' => 'メールログイン - ヴァルゼリアの冒険者'])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#0a1628] text-slate-950">
    <main class="flex min-h-screen items-center justify-center px-4 py-10">
        <div class="w-full max-w-md rounded-md bg-white p-6 shadow-2xl ring-1 ring-amber-200">
            <div class="mb-6 text-center">
                <p class="text-xs font-black tracking-[0.24em] text-amber-600">VALZERIA LOGIN</p>
                <h1 class="mt-2 text-2xl font-black">メールでログイン</h1>
                <p class="mt-2 text-sm font-bold text-slate-500">Googleアカウントなしでもプレイできます。</p>
            </div>

            @if(isset($errors) && $errors->any())
                <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('auth.email.login.submit') }}" class="space-y-4">
                @csrf
                <div>
                    <label for="email" class="block text-sm font-black text-slate-700">メールアドレス</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" class="mt-1 w-full rounded-md border border-slate-300 px-4 py-3 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500">
                </div>
                <div>
                    <label for="password" class="block text-sm font-black text-slate-700">パスワード</label>
                    <input id="password" type="password" name="password" required autocomplete="current-password" class="mt-1 w-full rounded-md border border-slate-300 px-4 py-3 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500">
                </div>
                <label class="flex items-center gap-2 text-sm font-bold text-slate-600">
                    <input type="checkbox" name="remember" value="1" class="rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                    ログイン状態を保持する
                </label>
                <button type="submit" class="w-full rounded-md bg-amber-500 px-4 py-3 text-sm font-black text-slate-950 shadow hover:bg-amber-400">
                    ログイン
                </button>
            </form>

            <div class="mt-5 space-y-3 text-center text-sm font-bold">
                <a href="{{ route('auth.email.register') }}" class="text-amber-700 hover:underline">メールアドレスで新規登録</a>
                <div><a href="{{ route('top') }}" class="text-slate-500 hover:text-slate-800">TOPへ戻る</a></div>
            </div>
        </div>
    </main>
</body>
</html>
