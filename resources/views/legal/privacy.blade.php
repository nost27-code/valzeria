@extends('legal.layout')

@section('title', 'プライバシーポリシー')
@section('eyebrow', 'PRIVACY')

@section('content')
    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">1. 基本方針</h2>
        <p class="leading-8 text-slate-700">ヴァルゼリアの冒険者 運営（以下「運営」といいます）は、本サービスを安心して利用できるよう、取得する情報を必要な範囲に限定し、適切に取り扱います。</p>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">2. 取得する情報</h2>
        <p class="leading-8 text-slate-700">本サービスでは、以下の情報を取得する場合があります。</p>
        <ul class="list-disc space-y-2 pl-5 leading-8 text-slate-700">
            <li>Googleログイン時に連携されるユーザー名、メールアドレス、Google ID、アイコン画像URL</li>
            <li>ゲストログイン、キャラクター名、ゲーム進行、所持アイテム、戦闘結果、ランキング等のゲームデータ</li>
            <li>お問い合わせ時に入力または送信された情報</li>
            <li>アクセス日時、IPアドレス、ブラウザ情報、Cookie、セッション情報、エラーログ等の技術情報</li>
            <li>有償コンテンツ購入時に外部決済サービスから連携される決済識別子、購入内容、購入日時、決済状態等</li>
        </ul>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">3. 利用目的</h2>
        <ul class="list-disc space-y-2 pl-5 leading-8 text-slate-700">
            <li>アカウント管理、ログイン、キャラクターデータ保存のため</li>
            <li>ゲーム進行、戦闘、報酬、ランキング、称号等の機能提供のため</li>
            <li>不具合調査、不正利用対策、セキュリティ確保のため</li>
            <li>お問い合わせ対応、重要なお知らせの連絡のため</li>
            <li>決済処理、購入履歴確認、有償コンテンツ付与のため</li>
            <li>サービス改善、利用状況の把握のため</li>
        </ul>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">4. 第三者提供・外部サービス</h2>
        <p class="leading-8 text-slate-700">運営は、法令に基づく場合、プレイヤーの同意がある場合、またはサービス提供に必要な範囲で外部サービスを利用する場合を除き、個人情報を第三者に提供しません。本サービスでは、ログイン、決済、メール送信、アクセス解析等の目的で外部サービスを利用する場合があります。</p>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">5. Cookie等の利用</h2>
        <p class="leading-8 text-slate-700">本サービスでは、ログイン状態の維持、セキュリティ、表示改善、不具合調査のためCookieや類似技術を使用します。ブラウザ設定によりCookieを無効化できますが、一部機能が利用できなくなる場合があります。</p>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">6. 情報の管理</h2>
        <p class="leading-8 text-slate-700">運営は、取得した情報について、不正アクセス、紛失、改ざん、漏えい等を防ぐため、合理的な安全管理措置を講じます。ただし、インターネット上の通信やシステム運用に絶対的な安全性を保証するものではありません。</p>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">7. 開示・訂正・削除</h2>
        <p class="leading-8 text-slate-700">本人から個人情報の開示、訂正、削除等の申し出があった場合、法令に従い、本人確認のうえ合理的な範囲で対応します。アカウント削除を行った場合でも、不正対策、決済記録、法令対応等に必要な情報は一定期間保持される場合があります。</p>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">8. ポリシーの変更</h2>
        <p class="leading-8 text-slate-700">本ポリシーは、法令やサービス内容の変更に応じて改定されることがあります。重要な変更がある場合は、本ページまたはサービス内で告知します。</p>
    </section>
@endsection
