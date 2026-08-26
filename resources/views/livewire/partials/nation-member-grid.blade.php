@php
    $visibleMemberships = isset($limit) ? $memberships->take($limit) : $memberships;
    $showJoinedAt = $showJoinedAt ?? false;
@endphp

<div class="grid grid-cols-3 gap-2 sm:gap-3" data-nation-member-grid>
    @foreach($visibleMemberships as $nationMember)
        @php($memberCharacter = $nationMember->character)
        @if($memberCharacter)
            <button
                type="button"
                x-on:click="Livewire.dispatch('open-adventurer-card', { characterId: {{ (int) $memberCharacter->id }} })"
                wire:key="nation-member-{{ $nationMember->id }}"
                class="group min-w-0 rounded-xl border border-amber-100 bg-gradient-to-b from-amber-50 to-white px-1.5 pb-2 pt-1.5 text-center shadow-sm transition hover:border-amber-300 hover:bg-amber-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 sm:px-2"
                aria-label="{{ $memberCharacter->name }}の冒険者カードを見る"
                data-nation-member-card="{{ $memberCharacter->id }}"
            >
                <span class="mx-auto flex h-16 w-16 items-end justify-center overflow-hidden rounded-full border border-amber-200 bg-white sm:h-20 sm:w-20">
                    <img
                        src="{{ \App\Support\CharacterIconCatalog::versionedAsset($memberCharacter->icon_path) }}"
                        alt="{{ $memberCharacter->name }}"
                        width="80"
                        height="80"
                        loading="lazy"
                        class="h-full w-full object-contain drop-shadow-sm transition group-hover:scale-105"
                    >
                </span>
                <span class="mt-1 block truncate text-xs font-black text-stone-900 sm:text-sm">{{ $memberCharacter->name }}</span>
                <span class="mt-0.5 block truncate text-[10px] font-bold text-amber-800 sm:text-[11px]">{{ $nationMember->roleLabel($nation) }}</span>
                <span class="block text-[10px] font-bold text-stone-500 sm:text-[11px]">Lv{{ $memberCharacter->level ?? 1 }}</span>
                @if($showJoinedAt)
                    <span class="block text-[9px] font-bold text-stone-400 sm:text-[10px]">{{ $nationMember->joined_at?->format('Y/m/d') }}加入</span>
                @endif
            </button>
        @else
            <div wire:key="nation-member-{{ $nationMember->id }}" class="min-w-0 rounded-xl border border-stone-200 bg-stone-50 px-1.5 py-3 text-center" data-nation-member-card-missing>
                <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-stone-200 text-2xl text-stone-400 sm:h-20 sm:w-20" aria-hidden="true">?</span>
                <span class="mt-1 block text-xs font-black text-stone-500">不明</span>
                <span class="mt-0.5 block text-[10px] font-bold text-stone-400">{{ $nationMember->roleLabel($nation) }}</span>
            </div>
        @endif
    @endforeach
</div>
