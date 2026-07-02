@extends('legal.layout')

@section('title', '特定商取引法に基づく表記')
@section('eyebrow', 'OPERATOR / 特商法表記')

@section('content')
    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">運営者</h2>
        <dl class="divide-y divide-slate-100 rounded-lg border border-slate-200">
            <div class="grid gap-1 p-4 sm:grid-cols-[10rem_1fr] sm:gap-4">
                <dt class="text-sm font-black text-slate-500">サービス名</dt>
                <dd class="font-bold text-slate-800">ヴァルゼリアの冒険者</dd>
            </div>
            <div class="grid gap-1 p-4 sm:grid-cols-[10rem_1fr] sm:gap-4">
                <dt class="text-sm font-black text-slate-500">運営統括責任者（氏名）</dt>
                <dd class="font-bold text-slate-800">個人事業主として運営しております。ご請求をいただいた場合、法令に基づき遅滞なく開示します。</dd>
            </div>
            <div class="grid gap-1 p-4 sm:grid-cols-[10rem_1fr] sm:gap-4">
                <dt class="text-sm font-black text-slate-500">所在地</dt>
                <dd class="font-bold text-slate-800">請求があった場合、法令に基づき遅滞なく開示します。</dd>
            </div>
            <div class="grid gap-1 p-4 sm:grid-cols-[10rem_1fr] sm:gap-4">
                <dt class="text-sm font-black text-slate-500">電話番号</dt>
                <dd class="font-bold text-slate-800">請求があった場合、法令に基づき遅滞なく開示します。</dd>
            </div>
            <div class="grid gap-1 p-4 sm:grid-cols-[10rem_1fr] sm:gap-4">
                <dt class="text-sm font-black text-slate-500">メールアドレス</dt>
                <dd class="font-bold text-slate-800"><a href="{{ route('legal.contact') }}" class="text-amber-700 hover:text-amber-800">お問い合わせフォーム</a>よりご連絡ください。氏名・所在地・電話番号の開示請求もこちらで受け付けます。</dd>
            </div>
            <div class="grid gap-1 p-4 sm:grid-cols-[10rem_1fr] sm:gap-4">
                <dt class="text-sm font-black text-slate-500">提供URL</dt>
                <dd class="font-bold text-slate-800"><a href="https://valzeria.com/" class="break-all text-amber-700 hover:text-amber-800">https://valzeria.com/</a></dd>
            </div>
        </dl>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">有償コンテンツに関する表示</h2>
        <dl class="divide-y divide-slate-100 rounded-lg border border-slate-200">
            <div class="grid gap-1 p-4 sm:grid-cols-[10rem_1fr] sm:gap-4">
                <dt class="text-sm font-black text-slate-500">販売価格</dt>
                <dd class="font-bold leading-7 text-slate-800">購入画面に表示された価格に従います。</dd>
            </div>
            <div class="grid gap-1 p-4 sm:grid-cols-[10rem_1fr] sm:gap-4">
                <dt class="text-sm font-black text-slate-500">代金以外の費用</dt>
                <dd class="font-bold leading-7 text-slate-800">インターネット接続料金、通信料金等はプレイヤーの負担となります。</dd>
            </div>
            <div class="grid gap-1 p-4 sm:grid-cols-[10rem_1fr] sm:gap-4">
                <dt class="text-sm font-black text-slate-500">支払方法</dt>
                <dd class="font-bold leading-7 text-slate-800">クレジットカード、PayPayなど、決済代行サービス（Stripe）が提供する決済方法をご利用いただけます。</dd>
            </div>
            <div class="grid gap-1 p-4 sm:grid-cols-[10rem_1fr] sm:gap-4">
                <dt class="text-sm font-black text-slate-500">支払時期</dt>
                <dd class="font-bold leading-7 text-slate-800">購入手続き完了時に、選択した決済方法に応じて即時課金されます。</dd>
            </div>
            <div class="grid gap-1 p-4 sm:grid-cols-[10rem_1fr] sm:gap-4">
                <dt class="text-sm font-black text-slate-500">提供時期</dt>
                <dd class="font-bold leading-7 text-slate-800">決済完了後、通常は直ちにゲーム内へ反映されます。通信状況や外部サービスの状態により遅延する場合があります。</dd>
            </div>
            <div class="grid gap-1 p-4 sm:grid-cols-[10rem_1fr] sm:gap-4">
                <dt class="text-sm font-black text-slate-500">返品・返金</dt>
                <dd class="font-bold leading-7 text-slate-800">デジタルコンテンツの性質上、購入後のキャンセル、返品、返金は、法令上必要な場合を除きできません。</dd>
            </div>
            <div class="grid gap-1 p-4 sm:grid-cols-[10rem_1fr] sm:gap-4">
                <dt class="text-sm font-black text-slate-500">動作環境</dt>
                <dd class="font-bold leading-7 text-slate-800">最新版に近い主要ブラウザでの利用を推奨します。端末やブラウザの状態により、一部表示や機能が正常に動作しない場合があります。</dd>
            </div>
        </dl>
    </section>
@endsection
