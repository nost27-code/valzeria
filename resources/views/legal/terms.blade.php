@extends('legal.layout')

@section('title', '利用規約')
@section('eyebrow', 'TERMS')

@section('content')
    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">第1条 目的</h2>
        <p class="leading-8 text-slate-700">本利用規約は、「ヴァルゼリアの冒険者」（以下「本サービス」といいます）の利用条件を定めるものです。プレイヤーは、本規約に同意したうえで本サービスを利用するものとします。</p>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">第2条 サービス内容</h2>
        <p class="leading-8 text-slate-700">本サービスは、ブラウザ上でキャラクター育成、ダンジョン探索、戦闘、装備収集、職業育成、ランキング等を楽しむFFA風ブラウザRPGです。サービス内容、ゲームバランス、報酬、機能は、運営上必要な範囲で変更、追加、停止される場合があります。</p>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">第3条 アカウントとデータ</h2>
        <ul class="list-disc space-y-2 pl-5 leading-8 text-slate-700">
            <li>Googleログインまたはゲストログインにより、本サービスを利用できます。</li>
            <li>ゲストログインのデータは、端末やブラウザの状態により継続利用できなくなる場合があります。</li>
            <li>プレイヤーは、自身のアカウントやログイン状態を適切に管理するものとします。</li>
            <li>不具合、メンテナンス、外部サービス障害等により、ゲームデータの一部が反映されない、または失われる場合があります。</li>
        </ul>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">第4条 禁止事項</h2>
        <p class="leading-8 text-slate-700">プレイヤーは、以下の行為を行ってはなりません。</p>
        <ul class="list-disc space-y-2 pl-5 leading-8 text-slate-700">
            <li>不正アクセス、チート、外部ツール、通信改ざん、自動操作など、通常のプレイ範囲を超える行為</li>
            <li>サーバーに過度な負荷をかける行為、または本サービスの運営を妨害する行為</li>
            <li>他プレイヤー、第三者、運営への誹謗中傷、なりすまし、迷惑行為</li>
            <li>ゲームデータ、アカウント、アイテム等を第三者と売買、譲渡、貸与する行為</li>
            <li>法令または公序良俗に反する行為</li>
            <li>その他、運営が不適切と判断する行為</li>
        </ul>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">第5条 利用停止等</h2>
        <p class="leading-8 text-slate-700">運営は、プレイヤーが本規約に違反した場合、または本サービスの安定運営に支障があると判断した場合、事前通知なくアカウント停止、データ修正、ランキング除外、アクセス制限等の措置を行うことがあります。</p>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">第6条 有償コンテンツ</h2>
        <ul class="list-disc space-y-2 pl-5 leading-8 text-slate-700">
            <li>本サービスでは、ゲーム内通貨「輝石」等の有償コンテンツを提供する場合があります。</li>
            <li>有償コンテンツとして購入した「有償輝石」の有効期限は、購入日から起算して180日間とします。期限を過ぎた有償輝石は失効し、利用できなくなります。</li>
            <li>無償で付与された「無償輝石」については、有効期限は設けておりませんが、本サービスが終了した場合等は失効します。</li>
            <li>有償輝石と無償輝石を両方所持している場合、無償輝石から優先して消費されます。</li>
            <li>購入後のキャンセル、返金、他アカウントへの移転は、法令上必要な場合を除きできません。</li>
            <li>決済処理は外部決済サービスを通じて行われます。</li>
            <li>未成年の方は、必ず保護者の同意を得たうえで購入してください。</li>
        </ul>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">第7条 免責事項</h2>
        <p class="leading-8 text-slate-700">運営は、本サービスが常に正常に動作すること、ゲームデータが永続的に保持されること、プレイヤーの期待する結果が得られることを保証しません。本サービスの利用により生じた損害について、運営の故意または重過失がある場合を除き、責任を負いません。</p>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-black text-slate-950">第8条 規約の変更</h2>
        <p class="leading-8 text-slate-700">本規約は、必要に応じて変更されることがあります。変更後の内容は、本ページまたはサービス内で告知した時点から適用されます。</p>
    </section>
@endsection
