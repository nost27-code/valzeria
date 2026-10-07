@extends('admin.viewer.layout')
@section('title', '閲覧専用ログイン')
@section('content')
<p class="mb-4 text-sm text-slate-600">dotの専用ブラウザーで利用してください。閲覧用ログインに切り替えると、このブラウザーの通常のゲーム・管理者ログインは終了します。</p>
<section class="mx-auto max-w-md rounded border bg-white p-6">
    <h1 class="text-xl font-bold">閲覧専用ログイン</h1>
    <p class="mt-2 text-sm text-slate-600">dotにはこの画面からログインしてください。</p>
    @if($errors->any())<p role="alert" class="mt-4 text-red-700">{{ $errors->first() }}</p>@endif
    <form method="POST" action="{{ route('admin.viewer.login.submit') }}" class="mt-6 space-y-4">
        @csrf
        <div><label for="email">閲覧用ID（メールアドレス）</label>
            <input id="email" name="email" type="email" autocomplete="username" required value="{{ old('email') }}" class="mt-1 w-full rounded border p-3"></div>
        <div><label for="password">パスワード</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required class="mt-1 w-full rounded border p-3"></div>
        <button class="w-full rounded bg-slate-900 p-3 font-bold text-white">ログイン</button>
    </form>
</section>
@endsection
