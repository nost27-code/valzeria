<p class="text-xs font-bold text-slate-500 leading-relaxed">
    {{ $helpContent['instruction'] }}
</p>

<div class="space-y-2">
    @foreach($helpContent['sections'] as $i => $section)
        <div x-data="{ open: false }" class="rounded-lg border border-slate-200 bg-white overflow-hidden shadow-sm">
            <button
                type="button"
                @click="open = !open"
                class="flex w-full items-center justify-between gap-3 px-4 py-3.5 text-left transition-colors"
                :class="open ? 'bg-amber-50' : 'bg-white hover:bg-slate-50'"
            >
                <div class="flex items-center gap-3 min-w-0">
                    @if(!empty($section['icon_image']))
                        <img src="{{ asset($section['icon_image']) }}" alt="" class="shrink-0 w-5 h-5 object-contain">
                    @else
                        <span class="shrink-0 text-lg leading-none">{{ $section['icon'] ?? '' }}</span>
                    @endif
                    <span class="text-sm font-black text-slate-900">{{ $i + 1 }}. {{ $section['title'] }}</span>
                </div>
                <svg
                    class="shrink-0 h-4 w-4 text-slate-400 transition-transform duration-200"
                    :class="open ? 'rotate-180' : ''"
                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <div
                x-show="open"
                x-transition:enter="transition-all duration-200 ease-out"
                x-transition:enter-start="opacity-0 -translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition-all duration-150 ease-in"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-1"
                class="border-t border-slate-100 bg-white px-4 py-4 help-body"
            >
                {!! $section['body'] !!}
            </div>
        </div>
    @endforeach
</div>

<div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-4 text-sm font-bold text-amber-800">
    {{ $helpContent['footer'] }}
</div>

<style>
    .help-body { font-size: 13px; line-height: 1.85; color: #374151; }
    .help-body p { margin-bottom: 0.75rem; }
    .help-body p:last-child { margin-bottom: 0; }
    .help-body ul, .help-body ol { padding-left: 1.4rem; margin-bottom: 0.75rem; }
    .help-body li { margin-bottom: 0.3rem; }
    .help-body strong { font-weight: 800; color: #1e293b; }
    .help-body dl { margin-bottom: 0.75rem; }
    .help-body dt { font-weight: 800; color: #1e293b; margin-top: 0.6rem; margin-bottom: 0.15rem; }
    .help-body dd { margin-left: 1rem; margin-bottom: 0.4rem; color: #4b5563; }
</style>
