@props(['title', 'logs', 'empty'])

<div class="overflow-hidden rounded-md bg-white shadow-sm ring-1 ring-slate-200">
    <div class="border-b border-slate-200 px-4 py-3">
        <h2 class="text-lg font-black text-slate-950">{{ $title }}</h2>
    </div>
    <div class="divide-y divide-slate-100">
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
</div>
