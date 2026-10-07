@extends('admin.viewer.layout')
@section('content')
<h1 class="text-2xl font-bold">閲覧する情報を選択</h1>
<section class="mt-6 rounded border bg-white p-4">
    <h2 class="font-bold">dotの運用確認</h2>
    <p class="mt-1 text-sm text-slate-600">報告やキャラ制作の問い合わせを開いても既読にしません。</p>
    <nav aria-label="運用確認" class="mt-3 flex flex-wrap gap-3">
        @foreach(\App\Services\Admin\DotAdminReadService::SECTIONS as $section => $label)
            <a class="rounded border px-4 py-3" href="{{ route('admin.viewer.monitor', ['section' => $section]) }}">{{ $label }}</a>
        @endforeach
    </nav>
</section>
<p class="mt-2 text-sm text-slate-600">選んだページの情報だけを読み込みます。ユーザー情報・個人チャット・課金履歴を含みます。ログイン用パスワードや認証トークンは表示しません。</p>
<section class="mt-6 rounded border bg-white p-4">
    <h2 class="font-bold">管理ダッシュボードの分析</h2>
    <p class="mt-1 text-sm text-slate-600">登録・活動・継続率・装備・敗北分析などをまとめたレポートです。開いたときに集計するため、数秒かかる場合があります。</p>
    <div class="mt-3 flex flex-wrap gap-3">
        <a class="rounded border px-4 py-2" href="{{ route('admin.viewer.dashboard-report', ['format' => 'txt']) }}">分析TXTを取得</a>
        <a class="rounded border px-4 py-2" href="{{ route('admin.viewer.dashboard-report', ['format' => 'csv']) }}">分析CSVを取得</a>
    </div>
</section>
<h2 class="mt-6 text-lg font-bold">データ一覧</h2>
<nav aria-label="データ一覧" class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
    @foreach($tables as $table => $label)
        <a class="min-w-0 rounded border bg-white p-3 hover:bg-slate-50" href="{{ route('admin.viewer.table', ['table' => $table]) }}">
            <span class="block font-bold">{{ $label }}</span>
            @if($label !== $table)<span class="text-xs text-slate-500">{{ $table }}</span>@endif
        </a>
    @endforeach
</nav>
<h2 class="mt-8 text-lg font-bold">運営・ゲーム設定</h2>
<nav aria-label="設定一覧" class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
    @foreach($settings as $setting => $label)
        <a class="min-w-0 rounded border bg-white p-3" href="{{ route('admin.viewer.settings', ['setting' => $setting]) }}">{{ $label }}</a>
    @endforeach
</nav>
@endsection
