<div class="mx-auto max-w-5xl p-4 sm:p-6 lg:p-8">
    <div class="mb-6">
        <p class="text-xs font-black tracking-[0.24em] text-amber-600">ADMIN TOOLS</p>
        <h1 class="mt-2 text-3xl font-black text-slate-950">ツール集</h1>
        <p class="mt-2 text-sm font-bold text-slate-500">制作・運用で使う補助ツールへのショートカットです。</p>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach($tools as $tool)
            <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="inline-flex rounded bg-slate-950 px-2 py-1 text-[10px] font-black tracking-[0.14em] text-amber-200">
                            {{ $tool['badge'] }}
                        </div>
                        <h2 class="mt-3 text-lg font-black text-slate-950">{{ $tool['name'] }}</h2>
                        <p class="mt-2 text-sm font-bold leading-relaxed text-slate-500">{{ $tool['description'] }}</p>
                    </div>
                </div>

                <div class="mt-5">
                    <a href="{{ $tool['href'] }}"
                       target="_blank"
                       rel="noopener"
                       class="inline-flex min-h-11 items-center justify-center rounded-md bg-amber-400 px-4 text-sm font-black text-slate-950 shadow-sm transition hover:bg-amber-300">
                        {{ $tool['openLabel'] }}
                    </a>
                    <div class="mt-2 break-all text-xs font-bold text-slate-400">{{ $tool['href'] }}</div>
                </div>
            </article>
        @endforeach
    </div>
</div>
