@props([
    'href',
    'label' => '街へ戻る',
    'icon' => null,
    'iconImage' => 'images/icon/icon_001.webp',
])

<a href="{{ $href }}" wire:navigate
   x-data="{ loading: false }"
   @click="if (!$event.defaultPrevented && !$event.metaKey && !$event.ctrlKey && !$event.shiftKey && $event.button === 0) loading = true"
   :class="loading ? 'pointer-events-none opacity-80' : ''"
   class="bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-lg shadow-lg transition duration-200 text-sm flex items-center justify-center gap-3"
   style="padding: 14px 40px; min-width: 240px; letter-spacing: 0.05em;">
    @if($iconImage)
        <img x-show="!loading" src="{{ asset($iconImage) }}" alt="" class="w-5 h-5 object-contain opacity-90">
    @elseif($icon)
        <span x-show="!loading" class="text-lg opacity-80">{{ $icon }}</span>
    @endif
    <svg x-show="loading" style="display: none;" class="h-4 w-4 animate-spin text-white" viewBox="0 0 24 24" aria-hidden="true">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
        <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
    </svg>
    <span>{{ $label }}</span>
</a>
