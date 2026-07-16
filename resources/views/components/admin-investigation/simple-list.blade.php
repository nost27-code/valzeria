@props(['title', 'logs', 'empty', 'summary' => null])

<details data-admin-investigation-accordion class="group min-w-0 self-start overflow-hidden rounded-md bg-white shadow-sm ring-1 ring-slate-200">
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 [&::-webkit-details-marker]:hidden">
        <h2 class="min-w-0 text-base font-black text-slate-950 sm:text-lg">{{ $title }}</h2>
        <span class="ml-auto flex shrink-0 items-center gap-2">
            @if($summary)
                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-black text-slate-600">{{ $summary }}</span>
            @endif
            <svg aria-hidden="true" class="h-5 w-5 shrink-0 text-slate-400 transition-transform duration-200 group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
            </svg>
        </span>
    </summary>
    <div class="divide-y divide-slate-100 border-t border-slate-200">
        @forelse($logs as $log)
            <div class="p-4">
                <div class="text-xs font-bold text-slate-500">
                    {{ $log['occurred_at'] ? \Carbon\Carbon::parse($log['occurred_at'])->format('Y/m/d H:i:s') : '-' }}
                </div>
                <div class="mt-1 font-black text-slate-950">{{ $log['summary'] }}</div>
                <div class="mt-1 max-h-20 overflow-hidden text-xs font-bold text-slate-500">{{ $log['detail'] }}</div>
            </div>
        @empty
            <div class="p-6 text-center text-sm font-bold text-slate-500">{{ $empty }}</div>
        @endforelse
    </div>
</details>
