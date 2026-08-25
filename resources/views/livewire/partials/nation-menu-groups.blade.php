<div class="space-y-3" data-nation-menu-groups>
    @foreach($menuGroups as $group)
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" data-nation-menu-group="{{ $group['key'] }}">
            <h3 class="bg-slate-50 px-4 py-2.5 text-xs font-bold text-slate-500">{{ $group['label'] }}</h3>
            <div class="divide-y divide-slate-100">
                @foreach($group['items'] as $item)
                    @php($isDanger = ($item['tone'] ?? null) === 'danger')
                    <button
                        type="button"
                        wire:key="nation-menu-{{ $group['key'] }}-{{ $item['key'] }}"
                        wire:click="{{ $item['action'] }}"
                        data-nation-menu-item="{{ $item['key'] }}"
                        class="flex min-h-16 w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-slate-50 focus-visible:bg-slate-50"
                    >
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-xl {{ $isDanger ? 'bg-rose-50' : 'bg-amber-50' }}" aria-hidden="true">{{ $item['icon'] }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-base font-black {{ $isDanger ? 'text-rose-800' : 'text-slate-950' }}">{{ $item['title'] }}</span>
                            <span class="block truncate text-xs font-bold text-slate-500 sm:text-sm">{{ $item['description'] }}</span>
                        </span>
                        @if(!empty($item['badge']))
                            <span class="shrink-0 rounded-full bg-rose-600 px-2 py-0.5 text-[11px] font-black text-white">{{ $item['badge'] }}</span>
                        @endif
                        <svg class="h-5 w-5 shrink-0 text-slate-300" aria-hidden="true" viewBox="0 0 20 20" fill="none">
                            <path d="m7.5 5 5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
