@props(['title'])

<div class="overflow-hidden rounded-md bg-white shadow-sm ring-1 ring-slate-200">
    <div class="border-b border-slate-200 px-4 py-3">
        <h2 class="text-lg font-black text-slate-950">{{ $title }}</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-xs font-black text-slate-700">
                <tr>{{ $head }}</tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
                {{ $slot }}
            </tbody>
        </table>
    </div>
</div>
