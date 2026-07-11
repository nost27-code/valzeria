<div class="mx-auto max-w-6xl p-4 sm:p-6 lg:p-8">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">SYSTEM HEALTH</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">正常性チェック</h1>
            <p class="mt-2 text-sm font-bold leading-relaxed text-slate-500">安全なGET URLを本番URL経由で確認し、500エラーを検知します。</p>
        </div>
        <button type="button" wire:click="runCheck" wire:loading.attr="disabled" class="min-h-11 rounded-md bg-amber-400 px-5 text-sm font-black text-slate-950 shadow-sm transition hover:bg-amber-300 disabled:cursor-wait disabled:opacity-60">
            <span wire:loading.remove>全URLを確認する（{{ $routeCount }}件）</span><span wire:loading>確認中…</span>
        </button>
    </div>
    <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm font-bold leading-relaxed text-amber-950">URLパラメータが必要な画面、外部認証、メール取込、開発用URLは実行せず対象外として表示します。ログインが必要な画面はリダイレクト（3xx）までを正常として確認します。</div>
    @if ($checkedAt)
        <div class="mb-5 grid gap-3 sm:grid-cols-4">
            @foreach ([['正常', $summary['ok'] ?? 0, 'emerald'], ['要確認', $summary['warning'] ?? 0, 'amber'], ['障害', $summary['failed'] ?? 0, 'rose'], ['対象外', $summary['excluded'] ?? 0, 'slate']] as [$label, $value, $color])
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"><div class="text-xs font-black text-{{ $color }}-700">{{ $label }}</div><div class="mt-1 text-2xl font-black text-slate-950">{{ $value }}件</div></div>
            @endforeach
        </div>
        <p class="mb-4 text-xs font-bold text-slate-500">最終確認: {{ $checkedAt }}</p>
        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm"><div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="bg-slate-950 text-slate-100"><tr><th class="px-4 py-3">状態</th><th class="px-4 py-3">URL</th><th class="px-4 py-3">HTTP</th><th class="px-4 py-3">応答</th></tr></thead><tbody class="divide-y divide-slate-100">@foreach ($results as $row)<tr class="{{ $row['state'] === 'failed' ? 'bg-rose-50' : '' }}"><td class="px-4 py-3 font-black {{ $row['state'] === 'failed' ? 'text-rose-700' : ($row['state'] === 'warning' ? 'text-amber-700' : 'text-slate-600') }}">{{ ['ok' => '正常', 'warning' => '要確認', 'failed' => '障害', 'excluded' => '対象外'][$row['state']] }}</td><td class="px-4 py-3"><div class="font-bold text-slate-900">{{ $row['uri'] }}</div><div class="text-xs text-slate-500">{{ $row['name'] }}{{ $row['reason'] ? ' — ' . $row['reason'] : '' }}{{ ($row['error'] ?? null) ? ' — ' . $row['error'] : '' }}</div></td><td class="px-4 py-3 font-mono text-xs text-slate-700">{{ $row['status'] ?? '—' }}</td><td class="px-4 py-3 text-xs text-slate-500">{{ $row['milliseconds'] !== null ? $row['milliseconds'] . 'ms' : '—' }}</td></tr>@endforeach</tbody></table></div></div>
    @else
        <div class="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center text-sm font-bold text-slate-500">まだ確認を実行していません。</div>
    @endif
</div>
