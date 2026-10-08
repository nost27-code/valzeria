<x-layouts.admin>
<div class="mx-auto max-w-7xl space-y-5">
    <header>
        <h1 class="text-2xl font-black text-slate-950">処理負荷・DB分析</h1>
        <p class="mt-2 text-sm text-slate-600">② 重複取得の削減　③ 表示用キャッシュ　④ 遅いSQL・索引　⑤ ロック競合の改善対象を調べます。</p>
    </header>
    <form method="GET" action="{{ route('admin.request-performance') }}" class="grid gap-3 rounded-md bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-5">
        <label class="min-w-0 text-sm font-bold">期間<select name="minutes" class="mt-1 w-full rounded-md border-slate-300">@foreach($periods as $value => $label)<option value="{{ $value }}" @selected($minutes === $value)>{{ $label }}</option>@endforeach</select></label>
        <label class="min-w-0 text-sm font-bold">並び順<select name="sort" class="mt-1 w-full rounded-md border-slate-300">@foreach($sorts as $value => $label)<option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>@endforeach</select></label>
        <label class="min-w-0 text-sm font-bold">終了時刻（日本時間）<input type="datetime-local" name="until" value="{{ $filters['until'] ?? '' }}" class="mt-1 w-full min-w-0 rounded-md border-slate-300"><span class="text-xs font-normal text-slate-500">空欄なら現在。改善前後の時刻を指定できます。</span></label>
        <label class="min-w-0 text-sm font-bold">公開版<select name="release" class="mt-1 w-full rounded-md border-slate-300"><option value="">すべて</option>@foreach($releases as $sha)<option value="{{ $sha }}" @selected(($filters['release'] ?? '') === $sha)>{{ $sha === 'local' ? 'ローカル' : substr($sha, 0, 12) }}</option>@endforeach</select></label>
        <div class="flex flex-wrap items-center gap-3"><button class="rounded-md bg-slate-900 px-5 py-3 font-bold text-white" type="submit">集計を更新</button><a class="text-sm underline" href="{{ route('admin.request-performance') }}">条件をリセット</a></div>
    </form>
    <div class="rounded-md border border-slate-200 bg-white p-4 text-sm leading-relaxed">
        <p>{{ \Illuminate\Support\Carbon::createFromTimestamp($from, 'Asia/Tokyo')->format('m/d H:i:s') }} ～ {{ \Illuminate\Support\Carbon::createFromTimestamp($until, 'Asia/Tokyo')->format('m/d H:i:s') }}（日本時間）／直前の同じ長さの期間と比較</p>
        <p class="mt-2">保存済み {{ number_format($totalRequests) }}リクエスト ／ DB合計 {{ number_format($totalDbMs / 1000, 2) }}秒 ／ SQL詳細 {{ number_format($totalSamples) }}サンプル</p>
        <p class="mt-2 text-xs text-slate-600">概要は対象HTTPリクエストを計測。SQL詳細は通常{{ round(config('request_performance.detail_sample_rate') * 100) }}%を抽出し、失敗SQLは別途記録します。管理画面・ヘルスチェック・CLIジョブは対象外です。Livewireの複合通信はまとめて計測します。</p>
        <p class="mt-1 text-xs text-slate-600">応答時間はサーバー内の処理時間です。SQL数は成功したSQLの本数で、物理接続数ではありません。SQL時間には待機が含まれますが、純粋なロック待ち時間は分離できません。</p>
    </div>
    @if(!$enabled)<p class="rounded-md bg-amber-50 p-4 font-bold text-amber-900">計測は停止中です。既存の記録だけを表示しています。</p>@endif
    @foreach($warnings as $warning)<p class="rounded-md bg-amber-50 p-3 text-sm text-amber-900">{{ $warning }}</p>@endforeach
    @if(!$totalRequests)
        <p class="rounded-md bg-white p-8 text-center text-slate-600">この条件の計測データはまだありません。公開・計測開始後の対象操作から蓄積します。未観測を「負荷なし」とは判断しません。</p>
    @endif
    <section class="space-y-3">
        <h2 class="text-lg font-black">画面・操作別</h2>
        @foreach($rows as $row)
            <article class="min-w-0 rounded-md border {{ ($selected['id'] ?? '') === $row['id'] ? 'border-amber-400' : 'border-slate-200' }} bg-white p-4 shadow-sm">
                <a class="break-all font-mono text-sm font-bold text-sky-800 underline" href="{{ route('admin.request-performance', array_merge($filters, ['minutes' => $minutes, 'sort' => $sort, 'op' => $row['id']])) }}#operation-detail">{{ $row['operation'] }}</a>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 xl:grid-cols-6">
                    <div><dt class="text-xs text-slate-500">保存済み回数</dt><dd class="font-bold">{{ number_format($row['requests']) }}</dd></div>
                    <div><dt class="text-xs text-slate-500">SQL平均／回</dt><dd class="font-bold">{{ number_format($row['query_avg'], 1) }}本</dd></div>
                    <div><dt class="text-xs text-slate-500">DB平均／合計</dt><dd class="font-bold">{{ number_format($row['db_avg'], 1) }}ms ／ {{ number_format($row['db_ms'] / 1000, 2) }}秒</dd></div>
                    <div><dt class="text-xs text-slate-500">応答 中央値／p95</dt><dd class="font-bold">{{ number_format($row['median'], 1) }} ／ {{ number_format($row['p95'], 1) }}ms</dd></div>
                    <div><dt class="text-xs text-slate-500">同条件の重複読取／標本</dt><dd class="font-bold">{{ $row['duplicate_avg'] === null ? '未観測' : number_format($row['duplicate_avg'], 1).'本' }}（{{ $row['samples'] }}標本）</dd></div>
                    <div><dt class="text-xs text-slate-500">DB競合／HTTP 5xx</dt><dd class="font-bold">{{ $row['contention'] }}件 ／ {{ $row['http_errors'] }}回</dd></div>
                </dl>
                <p class="mt-3 text-xs text-slate-600">@if($row['previous'])直前期間：{{ $row['previous']['requests'] }}回 ／ DB平均{{ number_format($row['previous']['db_avg'], 1) }}ms ／ 応答p95 {{ number_format($row['previous']['p95'], 1) }}ms。頻度・標本数・公開版の差も確認してください。@else直前期間の比較データなし。@endif</p>
                @if($row['requests'] < 20)<p class="mt-1 text-xs text-amber-800">少数の観測です。p95や改善効果の断定は避けてください。</p>@endif
                @foreach($row['hints'] as $hint)<p class="mt-2 text-xs font-bold text-sky-800">{{ $hint }}（調査候補）</p>@endforeach
            </article>
        @endforeach
    </section>
    @if($selected)
    <section id="operation-detail" class="min-w-0 space-y-3 rounded-md border border-slate-200 bg-white p-4">
        <h2 class="text-lg font-black">操作の詳細</h2>
        <p class="break-all font-mono text-sm">{{ $selected['operation'] }}</p>
        <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <div><dt class="text-slate-500">読取・構造確認の割合</dt><dd>{{ number_format($selected['read_ratio'], 1) }}%</dd></div>
            <div><dt class="text-slate-500">読取SQL／書込SQL</dt><dd>{{ $selected['read_queries'] }} ／ {{ $selected['write_queries'] }}本</dd></div>
            <div><dt class="text-slate-500">構造確認SQL</dt><dd>{{ $selected['categories']['metadata'] ?? 0 }}本</dd></div>
            <div><dt class="text-slate-500">セッション・キャッシュSQL</dt><dd>{{ $selected['categories']['session_cache'] ?? 0 }}本</dd></div>
            <div><dt class="text-slate-500">最長SQL</dt><dd>{{ number_format($selected['max_sql_ms'], 1) }}ms</dd></div>
            <div><dt class="text-slate-500">最長トランザクション</dt><dd>{{ number_format($selected['max_transaction_ms'], 1) }}ms</dd></div>
            <div><dt class="text-slate-500">明示ロックSQLの合計時間</dt><dd>{{ number_format($selected['locking_ms'], 1) }}ms</dd></div>
            <div><dt class="text-slate-500">ロック／チャンプ状態変更／接続上限</dt><dd>{{ $selected['errors']['database_lock'] ?? 0 }} ／ {{ $selected['errors']['state_changed'] ?? 0 }} ／ {{ $selected['errors']['connection_limit'] ?? 0 }}件</dd></div>
            <div><dt class="text-slate-500">終了時に未終了のトランザクション</dt><dd>{{ $selected['open_transactions'] }}件</dd></div>
        </dl>
        <p class="text-xs text-slate-600">競合件数は検出した例外数です。チャンプ戦は再試行中の競合も含みます。トランザクション時間と明示ロックSQL時間は、実際のロック保持・待機時間そのものではありません。</p>
        @foreach($selected['phases'] as $phase => $count)<p class="break-all text-sm">競合段階：{{ $phase }} {{ $count }}件</p>@endforeach
        <p class="break-all text-xs text-slate-500">公開版：{{ implode(' / ', array_keys($selected['releases'])) }}</p>
        <h3 class="font-bold">SQLの種類・代表呼出元</h3>
        <p class="text-xs text-slate-600">以下は抽出したリクエストと失敗SQLの記録です。同条件の重複と、条件が異なる同形式SQLを区別します。値・ID・URLパラメータは保存していません。呼出元は代表例です。失敗SQLの所要時間は未取得で、DB時間に含みません。</p>
        @forelse($details as $query)
            <article class="min-w-0 rounded-md bg-slate-50 p-3">
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs font-bold"><span>{{ $query['category'] }}</span><span>成功{{ $query['count'] }}本／失敗{{ $query['failed'] }}本</span><span>同条件の重複{{ $query['duplicates'] }}本</span><span>合計{{ number_format($query['ms'], 1) }}ms／最大{{ number_format($query['max_ms'], 1) }}ms</span></div>
                <code class="mt-2 block whitespace-pre-wrap break-all text-xs">{{ $query['sql'] }}</code>
                @foreach($query['callers'] as $caller)<p class="mt-1 break-all font-mono text-xs text-slate-500">{{ $caller }}</p>@endforeach
            </article>
        @empty<p class="text-sm text-slate-600">SQL詳細の標本はまだありません。概要の件数と詳細の標本数は別です。</p>@endforelse
        <div class="rounded-md bg-amber-50 p-3 text-xs leading-relaxed text-amber-900">
            <p>②：同条件の重複と呼出元を確認。③：表示・マスタに限り、更新時の無効化と再利用可能性を確認。④：遅いSQLを元コードの条件でEXPLAINし、索引の効果を検証。⑤：競合段階とトランザクション時間を確認し、報酬・資産の原子性を保って短縮します。</p>
            <p class="mt-1">この画面はSQL実行・索引追加・設定変更を行いません。表示だけでキャッシュ可能性や索引不足、ロック原因を確定しません。</p>
        </div>
    </section>
    @endif
</div>
</x-layouts.admin>
