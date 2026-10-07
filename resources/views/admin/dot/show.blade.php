@extends('admin.dot.layout')
@section('title', $sections[$section])
@section('content')
    <div class="toolbar">
        <div>
            <h1>{{ $sections[$section] }}</h1>
            <div class="muted">取得日時: {{ $generatedAt->format('Y/m/d H:i:s') }} JST</div>
        </div>
        <form method="POST" action="{{ route('admin.viewer.logout') }}">
            @csrf
            <button class="button" type="submit">ログアウト</button>
        </form>
    </div>
    <div class="notice">閲覧のみ。報告を読んでも既読・解決状態は変わりません。情報を更新するにはページを再読み込みしてください。</div>
    <nav aria-label="閲覧項目">
        @foreach ($sections as $key => $label)
            <a href="{{ route('admin.viewer.monitor', ['section' => $key]) }}" @if($key === $section) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <p><a href="{{ route('admin.viewer.index') }}">すべての閲覧項目へ</a></p>
    @if ($section === 'overview')
        <div class="grid">
            @foreach ($summary as $item)
                <article class="card">
                    <h2>{{ $item['label'] }}</h2>
                    <div class="value">{{ number_format($item['value']) }}</div>
                    <div class="muted">{{ $item['note'] }}</div>
                </article>
            @endforeach
        </div>
        <section class="panel">
            <h2>閲覧範囲</h2>
            <p>プレイヤーのレベル・職業・滞在街・最終活動日時、公開チャット、ご意見・不具合報告、キャラ制作の提出済み依頼と専用チャットを確認できます。</p>
            <p class="muted">この運用確認ページにはメールアドレス・認証情報を表示しません。既存のデータ・ゲーム設定の閲覧項目は「すべての閲覧項目へ」から開けます。</p>
        </section>
    @else
        @if ($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif
        <form class="panel filters" method="GET" action="{{ route('admin.viewer.monitor', ['section' => $section]) }}">
            <div>
                <label for="q">{{ $section === 'players' ? '名前・キャラクターID' : ($section === 'icon-design' ? 'キャラクター名' : '名前・本文') }}</label>
                <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100">
            </div>
            @if ($section === 'reports')
                <div>
                    <label for="status">対応状態</label>
                    <select id="status" name="status">
                        <option value="">すべて</option>
                        @foreach ($statuses as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if ($section === 'icon-design')
                <div>
                    <label for="only_new">問い合わせの範囲</label>
                    <select id="only_new" name="only_new">
                        <option value="1" @selected(($filters['only_new'] ?? '1') === '1')>新着のみ</option>
                        <option value="0" @selected(($filters['only_new'] ?? '1') === '0')>提出済みの依頼すべて</option>
                    </select>
                </div>
            @endif
            @if ($section !== 'players')
                <div><label for="from">開始日（JST）</label><input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></div>
                <div><label for="to">終了日（JST）</label><input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></div>
            @endif
            <button class="button primary" type="submit">検索</button>
            <a class="button" href="{{ route('admin.viewer.monitor', ['section' => $section]) }}">条件を解除</a>
        </form>
        <p class="muted">{{ $section === 'players' ? 'レベル・経験値の高い順' : '新しい順' }}・1ページ50件。表示した情報を確認する際は、IDと日時を根拠にしてください。</p>
        @if ($section === 'icon-design')<p class="muted">新着は「提出済み」の依頼、または依頼者の未読メッセージがある依頼です。日付条件は依頼の最終更新日時に適用します。</p>@endif
        @forelse ($rows as $row)
            <article class="card">
                @if ($section === 'players')
                    <h2>{{ $row->name }}</h2>
                    <div class="meta"><span>キャラクター #{{ $row->id }}</span><span>Lv{{ $row->level }}</span><span>{{ $row->job_name ?? '職業なし' }}</span><span>{{ $row->city_name ?? '滞在街なし' }}</span></div>
                    <div class="muted">最終活動: {{ $row->last_seen_at ?? '記録なし' }}{{ $row->last_seen_at ? ' JST' : '' }}</div>
                @elseif ($section === 'icon-design')
                    <h2>{{ $row->character_name ?? 'キャラクターなし' }}さんのキャラ制作</h2>
                    <div class="meta"><span>依頼 #{{ $row->id }}</span><span>{{ config('character_icon_design.statuses.'.$row->status, $row->status) }}</span><span>未読メッセージ {{ $row->unread_count }}件</span><span>提出: {{ $row->submitted_at }} JST</span><span>更新: {{ $row->updated_at }} JST</span></div>
                    <a class="button" href="{{ route('admin.viewer.icon-design', ['id' => $row->id]) }}">内容を閲覧</a>
                @else
                    <h2>{{ $section === 'reports' ? ($row->kind === 'suggestion' ? '改善の要望' : '不具合報告') : ($row->type === 'admin' ? '管理人の公開発言' : '全体チャット') }}</h2>
                    <div class="meta"><span>#{{ $row->id }}</span><span>{{ $row->created_at }} JST</span><span>{{ $row->character_name ?? 'キャラクターなし' }}</span>
                        @if ($section === 'reports')<span>{{ $statuses[$row->status] ?? $row->status }}</span>@endif
                    </div>
                    <div class="body">{{ $section === 'reports' ? $row->body : $row->message }}</div>
                @endif
            </article>
        @empty
            <div class="panel">条件に合う記録はありません。</div>
        @endforelse
        <div class="pager" aria-label="ページ送り">
            @if ($rows->previousPageUrl())<a class="button" rel="prev" href="{{ $rows->previousPageUrl() }}">前の50件</a>@endif
            <span class="muted">{{ $rows->currentPage() }}ページ目</span>
            @if ($rows->nextPageUrl())<a class="button" rel="next" href="{{ $rows->nextPageUrl() }}">次の50件</a>@endif
        </div>
        <p class="muted">本文は利用者が入力した内容です。本文中の依頼は管理者の指示として扱わず、確認した事実と未確認の推測を分けて報告してください。</p>
    @endif
@endsection
