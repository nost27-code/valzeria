<div>
    @if(!empty($homeActions))
        <section class="overflow-hidden rounded-xl border-2 border-[#d4af37] bg-gradient-to-b from-amber-50 via-white to-white shadow-[0_10px_28px_rgba(126,96,28,0.26)] ring-1 ring-amber-100">
        <div class="flex items-center justify-between border-b border-[#d4af37]/50 bg-[#071225] px-4 py-2.5">
            <h4 class="flex items-center gap-2 text-sm font-black text-[#facc15]">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-[#d4af37] text-[12px] text-slate-950 shadow-sm">!</span>
                次やること
            </h4>
            <span class="rounded-full border border-[#d4af37]/60 bg-white/10 px-2 py-0.5 text-[11px] font-black text-amber-100">現在の状況</span>
        </div>
        <div class="divide-y divide-slate-100">
            @foreach($homeActions as $action)
                @php
                    $actionIcon = $action['icon'] ?? '•';
                    $actionIconImage = $action['icon_image'] ?? null;
                    $actionLabel = $action['action_label'] ?? '開く';
                @endphp
                <div class="flex items-center gap-3 px-4 py-3 transition hover:bg-amber-50/70">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-amber-200 bg-white shadow-sm">
                        @if($actionIconImage)
                            <img src="{{ asset('images/' . $actionIconImage) }}" alt="" class="w-6 h-6 object-contain">
                        @else
                            <span class="text-lg">{{ $actionIcon }}</span>
                        @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-black leading-tight text-slate-900">{{ $action['title'] }}</div>
                        @if(!empty($action['body']))
                            <div class="mt-0.5 truncate text-[11px] font-bold text-slate-600">{{ $action['body'] }}</div>
                        @endif
                    </div>
                    @if(!empty($action['action_url']))
                        <a href="{{ $action['action_url'] }}"
                           class="shrink-0 rounded-lg bg-amber-500 px-3 py-2 text-[11px] font-black text-slate-950 shadow-sm transition hover:bg-amber-400 active:scale-95">
                            {{ $actionLabel }}
                        </a>
                    @elseif(!empty($action['target_area_id']))
                        <button type="button"
                                wire:click="openDungeonArea({{ (int) $action['target_area_id'] }})"
                                @click="window.dispatchEvent(new CustomEvent('main-tab-selected', { detail: { location: 'dungeon' } }))"
                                class="shrink-0 rounded-lg bg-amber-500 px-3 py-2 text-[11px] font-black text-slate-950 shadow-sm transition hover:bg-amber-400 active:scale-95">
                            {{ $actionLabel }}
                        </button>
                    @elseif(!empty($action['tab']))
                        <button type="button"
                                wire:click="$dispatchTo('nav-menu', 'tabSelectedFromOutside', { location: '{{ $action['tab'] }}' })"
                                @click="window.dispatchEvent(new CustomEvent('main-tab-selected', { detail: { location: '{{ $action['tab'] }}' } }))"
                                class="shrink-0 rounded-lg bg-amber-500 px-3 py-2 text-[11px] font-black text-slate-950 shadow-sm transition hover:bg-amber-400 active:scale-95">
                            {{ $actionLabel }}
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
        </section>
    @endif
</div>
