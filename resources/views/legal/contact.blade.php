@extends('legal.layout')

@section('title', 'お問い合わせ')
@section('eyebrow', 'CONTACT')

@section('content')
    @if(session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-black text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">お問い合わせ前にご確認ください</h2>
        <p class="leading-8 text-slate-700">不具合、決済、アカウント、迷惑行為などに関するお問い合わせを受け付けています。ゲームバランスや攻略情報に関する個別回答は行えない場合があります。</p>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">連絡先</h2>
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
            <p class="text-sm font-bold text-slate-600">メール</p>
            <a href="mailto:info@valzeria.com" class="mt-1 inline-block break-all text-lg font-black text-amber-700 hover:text-amber-800">info@valzeria.com</a>
        </div>
        <p class="text-sm leading-7 text-slate-500">返信が必要な内容は、受信可能なメールアドレスからご連絡ください。返信には数日かかる場合があります。</p>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">お問い合わせフォーム</h2>
        @if(isset($errors) && $errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700">
                入力内容を確認してください。
            </div>
        @endif
        <form method="POST" action="{{ route('legal.contact.store') }}" enctype="multipart/form-data" class="space-y-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="sender_name" class="block text-sm font-black text-slate-700">お名前</label>
                    <input id="sender_name" name="sender_name" value="{{ old('sender_name', $character?->name ?? $user?->name ?? '') }}" maxlength="100" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-bold shadow-sm focus:border-amber-500 focus:ring-amber-500">
                    @error('sender_name')<div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label for="sender_email" class="block text-sm font-black text-slate-700">返信先メールアドレス</label>
                    <input id="sender_email" type="email" name="sender_email" value="{{ old('sender_email', $user?->email ?? '') }}" required maxlength="255" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-bold shadow-sm focus:border-amber-500 focus:ring-amber-500">
                    @error('sender_email')<div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-[180px_minmax(0,1fr)]">
                <div>
                    <label for="category" class="block text-sm font-black text-slate-700">種類</label>
                    <select id="category" name="category" required class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-bold shadow-sm focus:border-amber-500 focus:ring-amber-500">
                        @foreach(['bug' => '不具合', 'payment' => '決済', 'account' => 'アカウント', 'report' => '迷惑行為', 'other' => 'その他'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('category') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('category')<div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label for="subject" class="block text-sm font-black text-slate-700">件名</label>
                    <input id="subject" name="subject" value="{{ old('subject') }}" required maxlength="160" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-bold shadow-sm focus:border-amber-500 focus:ring-amber-500">
                    @error('subject')<div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>@enderror
                </div>
            </div>
            <div>
                <label for="body" class="block text-sm font-black text-slate-700">本文</label>
                <textarea id="body" name="body" rows="8" required maxlength="5000" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-bold leading-7 shadow-sm focus:border-amber-500 focus:ring-amber-500">{{ old('body') }}</textarea>
                @error('body')<div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="attachment" class="block text-sm font-black text-slate-700">画像添付 <span class="font-bold text-slate-400">（任意・PNG/JPG/WEBP/GIF・5MB以内）</span></label>
                <input id="attachment" type="file" name="attachment" accept="image/png,image/jpeg,image/webp,image/gif"
                       class="mt-1 block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-sm file:font-black file:text-slate-700 hover:file:bg-slate-200">
                @error('attachment')<div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-md bg-slate-950 px-5 text-sm font-black text-white shadow hover:bg-slate-800">
                info@valzeria.com 宛に送信
            </button>
        </form>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">記載いただきたい内容</h2>
        <ul class="list-disc space-y-2 pl-5 leading-8 text-slate-700">
            <li>キャラクター名</li>
            <li>発生日時</li>
            <li>利用端末、ブラウザ名</li>
            <li>発生した画面や操作内容</li>
            <li>エラーメッセージやスクリーンショットがある場合はその内容</li>
            <li>決済に関するお問い合わせの場合は、購入日時と購入内容</li>
        </ul>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">対応できない場合があるもの</h2>
        <ul class="list-disc space-y-2 pl-5 leading-8 text-slate-700">
            <li>攻略方法、育成方針、ドロップ確率に関する個別回答</li>
            <li>本人確認ができないアカウント情報の照会</li>
            <li>規約違反や不正利用につながる内容</li>
            <li>外部サービス側の障害や仕様に関する詳細調査</li>
        </ul>
    </section>
@endsection
