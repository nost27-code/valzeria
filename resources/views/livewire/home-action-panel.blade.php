<div>
    @if(!empty($homeActions))
        <section class="overflow-hidden rounded-xl border border-amber-300 bg-white shadow-[0_4px_14px_rgba(126,96,28,0.14)]">
        <div class="flex items-center justify-between border-b border-amber-100 bg-amber-50/70 px-4 py-2">
            <h4 class="text-sm font-black text-slate-900">次やること</h4>
            <span class="text-[11px] font-bold text-amber-700">現在の状況</span>
        </div>
        <div class="divide-y divide-slate-100">
            @foreach($homeActions as $action)
                @php
                    $actionIcon = $action['icon'] ?? '•';
                    $actionIconImage = $action['icon_image'] ?? null;
                    $actionLabel = $action['action_label'] ?? '開く';
                @endphp
                <div class="flex items-center gap-3 px-4 py-2.5">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-50">
                        @if($actionIconImage)
                            <img src="{{ asset('images/' . $actionIconImage) }}" alt="" class="w-6 h-6 object-contain">
                        @else
                            <span class="text-lg">{{ $actionIcon }}</span>
                        @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-black leading-tight text-slate-900">{{ $action['title'] }}</div>
                        @if(!empty($action['body']))
                            <div class="mt-0.5 truncate text-[11px] font-bold text-slate-500">{{ $action['body'] }}</div>
                        @endif
                    </div>
                    @if(!empty($action['action_url']))
                        <a href="{{ $action['action_url'] }}"
                           class="shrink-0 rounded-lg bg-slate-900 px-3 py-2 text-[11px] font-black text-white shadow-sm transition hover:bg-slate-700 active:scale-95">
                            {{ $actionLabel }}
                        </a>
                    @elseif(!empty($action['tab']))
                        <button type="button"
                                wire:click="$dispatchTo('nav-menu', 'tabSelectedFromOutside', { location: '{{ $action['tab'] }}' })"
                                @click="window.dispatchEvent(new CustomEvent('main-tab-selected', { detail: { location: '{{ $action['tab'] }}' } }))"
                                class="shrink-0 rounded-lg bg-slate-900 px-3 py-2 text-[11px] font-black text-white shadow-sm transition hover:bg-slate-700 active:scale-95">
                            {{ $actionLabel }}
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
        </section>
    @endif
</div>
