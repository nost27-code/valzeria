<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>メール新規登録 - ヴァルゼリアの冒険者</title>
    @include('partials.ogp', ['ogTitle' => 'メール新規登録 - ヴァルゼリアの冒険者'])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#0a1628] text-slate-950">
    <main class="flex min-h-screen items-center justify-center px-4 py-10">
        <div class="w-full max-w-md rounded-md bg-white p-6 shadow-2xl ring-1 ring-amber-200">
            <div class="mb-6 text-center">
                <p class="text-xs font-black tracking-[0.24em] text-amber-600">VALZERIA REGISTER</p>
                <h1 class="mt-2 text-2xl font-black">メールで新規登録</h1>
                <p class="mt-2 text-sm font-bold text-slate-500">
                    {{ ($registrationOpen ?? true) ? '登録後、冒険者作成へ進みます。' : '現在、新規登録の受付を停止しています。' }}
                </p>
            </div>

            @unless($registrationOpen ?? true)
                <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold leading-relaxed text-amber-900">
                    リリース準備中のため、新規登録は一時停止中です。すでにアカウントをお持ちの場合はログインできます。
                </div>
            @endunless

            @if(isset($errors) && $errors->any())
                <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('auth.email.register.submit') }}" class="space-y-4">
                @csrf
                <div>
                    <label for="email" class="block text-sm font-black text-slate-700">メールアドレス</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" @disabled(!($registrationOpen ?? true)) class="mt-1 w-full rounded-md border border-slate-300 px-4 py-3 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500 disabled:bg-slate-100 disabled:text-slate-400">
                </div>
                <div>
                    <label for="password" class="block text-sm font-black text-slate-700">パスワード</label>
                    <input id="password" type="password" name="password" required autocomplete="new-password" @disabled(!($registrationOpen ?? true)) class="mt-1 w-full rounded-md border border-slate-300 px-4 py-3 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500 disabled:bg-slate-100 disabled:text-slate-400">
                    <p class="mt-1 text-xs font-bold text-slate-500">8文字以上、英字と数字を含めてください。</p>
                </div>
                <div>
                    <label for="password_confirmation" class="block text-sm font-black text-slate-700">パスワード確認</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" @disabled(!($registrationOpen ?? true)) class="mt-1 w-full rounded-md border border-slate-300 px-4 py-3 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500 disabled:bg-slate-100 disabled:text-slate-400">
                </div>
                <button type="submit" @disabled(!($registrationOpen ?? true)) class="w-full rounded-md bg-amber-500 px-4 py-3 text-sm font-black text-slate-950 shadow hover:bg-amber-400 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500">
                    {{ ($registrationOpen ?? true) ? '登録して冒険を始める' : '新規登録停止中' }}
                </button>
            </form>

            <div class="mt-5 space-y-3 text-center text-sm font-bold">
                <a href="{{ route('auth.email.login') }}" class="text-amber-700 hover:underline">すでに登録済みの方はこちら</a>
                <div><a href="{{ route('top') }}" class="text-slate-500 hover:text-slate-800">TOPへ戻る</a></div>
            </div>
        </div>
    </main>
</body>
</html>
