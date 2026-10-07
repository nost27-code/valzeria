@extends('admin.viewer.layout')
@section('title', $label)
@section('content')
<a href="{{ route('admin.viewer.index') }}" class="text-sm underline">一覧へ戻る</a>
<h1 class="mt-3 text-2xl font-bold">{{ $label }}</h1>
<p class="mt-1 text-sm text-slate-500">{{ $table }} · 1ページ25件 · 完全一致で検索</p>
<form method="GET" class="mt-4 flex flex-col gap-3 rounded border bg-white p-4 sm:flex-row sm:items-end">
    <div class="min-w-0"><label for="field" class="block text-sm">検索項目</label>
        <select name="field" id="field" class="mt-1 w-full rounded border p-2">
            <option value="">指定なし</option>
            @foreach($columns as $column)<option value="{{ $column }}" @selected(($filters['field'] ?? '') === $column)>{{ $column }}</option>@endforeach
        </select></div>
    <div class="min-w-0 flex-1"><label for="value" class="block text-sm">検索値</label>
        <input id="value" name="value" value="{{ $filters['value'] ?? '' }}" maxlength="500" class="mt-1 w-full rounded border p-2"></div>
    <button class="rounded bg-slate-900 px-4 py-2 text-white">検索</button>
    <a href="{{ route('admin.viewer.table', ['table' => $table]) }}" class="p-2 underline">クリア</a>
</form>
<div class="mt-4">{{ $rows->links() }}</div>
<div class="mt-4 space-y-3">
@forelse($rows as $row)
    <article class="min-w-0 rounded border bg-white p-4">
        <h2 class="font-bold">レコード {{ is_scalar($row['id'] ?? null) ? $row['id'] : '' }}</h2>
        @if(in_array($table, ['bug_report_attachments', 'character_icon_design_message_attachments'], true))
            <a class="text-sm underline" href="{{ route('admin.viewer.attachment', ['kind' => $table === 'bug_report_attachments' ? 'bug-report' : 'icon-design', 'id' => $row['id']]) }}">添付ファイルを開く</a>
        @endif
        <dl class="mt-3 grid min-w-0 grid-cols-1 gap-2 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]">
            @foreach($row as $column => $value)
                <dt class="min-w-0 text-sm font-bold text-slate-500">{{ $column }}</dt>
                <dd class="min-w-0 text-sm"><pre>{{ is_array($value) ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ($value === null ? '—' : $value) }}</pre></dd>
            @endforeach
        </dl>
    </article>
@empty
    <p class="rounded border bg-white p-6">該当するデータはありません。</p>
@endforelse
</div>
<div class="mt-4">{{ $rows->links() }}</div>
@endsection
