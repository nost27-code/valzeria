@extends('admin.viewer.layout')
@section('title', $label)
@section('content')
<a href="{{ route('admin.viewer.index') }}" class="text-sm underline">一覧へ戻る</a>
<h1 class="mt-3 text-2xl font-bold">{{ $label }}</h1>
<pre class="mt-4 min-w-0 rounded border bg-white p-4 text-sm">{{ json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
@endsection
