@php
    $status = (string) ($diagnosis['status'] ?? 'warning');
    $tone = match ($status) {
        'ready' => [
            'border' => 'border-emerald-200',
            'bg' => 'bg-emerald-50/70',
            'badge' => 'bg-emerald-600 text-white',
            'icon' => '✓',
        ],
        'invalid' => [
            'border' => 'border-rose-200',
            'bg' => 'bg-rose-50/70',
            'badge' => 'bg-rose-600 text-white',
            'icon' => '!',
        ],
        default => [
            'border' => 'border-amber-200',
            'bg' => 'bg-amber-50/70',
            'badge' => 'bg-amber-500 text-white',
            'icon' => '!',
        ],
    };
@endphp
<details class="rounded-lg border {{ $tone['border'] }} {{ $tone['bg'] }}" data-job-art-loadout-diagnosis-details>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 [&::-webkit-details-marker]:hidden">
        <span class="flex min-w-0 items-center gap-2">
            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-black {{ $tone['badge'] }}">{{ $tone['icon'] }}</span>
            <span class="min-w-0">
                <span class="block text-xs font-black text-slate-900">構成診断：{{ $diagnosis['status_label'] ?? '要確認' }}</span>
                <span class="block text-[10px] font-bold leading-relaxed text-slate-600">{{ $diagnosis['summary'] ?? '' }}</span>
            </span>
        </span>
        <span class="shrink-0 text-[10px] font-black text-slate-500">詳細 ▾</span>
    </summary>
    <div class="space-y-1.5 border-t {{ $tone['border'] }} px-3 py-2.5">
        @foreach($diagnosis['checks'] ?? [] as $check)
            @php
                $checkTone = match ($check['level'] ?? 'warning') {
                    'ok' => 'border-emerald-200 bg-white/80 text-emerald-800',
                    'error' => 'border-rose-200 bg-white/80 text-rose-800',
                    default => 'border-amber-200 bg-white/80 text-amber-900',
                };
            @endphp
            <div class="rounded-md border px-2.5 py-2 {{ $checkTone }}">
                <div class="text-[11px] font-black">{{ $check['title'] ?? '' }}</div>
                <div class="mt-0.5 text-[10px] font-bold leading-relaxed text-slate-600">{{ $check['detail'] ?? '' }}</div>
            </div>
        @endforeach
        <p class="pt-1 text-[9px] font-bold leading-relaxed text-slate-400">この診断は資源・Cost・SP・セット順の機械的な成立を確認します。相手との相性や勝率は判定しません。</p>
    </div>
</details>
