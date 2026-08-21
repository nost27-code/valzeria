<div
    class="overflow-hidden rounded-xl border border-slate-300 bg-white shadow-xl transition-opacity"
    wire:loading.class="opacity-60"
    wire:target="gotoPage, previousPage, nextPage"
    data-six-hero-room-ranking
>
    <div class="border-b border-slate-300 px-4 py-4 sm:px-5">
        <h3 class="text-base font-black text-slate-950">{{ $roomLabel }} 現在ランキング</h3>
        <p class="mt-1 text-xs font-bold text-slate-500">20人ずつ表示しています。</p>
        <div
            class="mt-2 hidden items-center gap-2 text-[10px] font-black text-amber-800"
            wire:loading.flex
            wire:target="gotoPage, previousPage, nextPage"
            role="status"
            data-ranking-loading
        >
            <span class="inline-block h-3 w-3 animate-spin rounded-full border-2 border-amber-300 border-t-transparent"></span>
            ランキングを更新中…
        </div>
    </div>

    <div class="divide-y divide-slate-200" data-ranking-entries>
        @forelse($rankings as $ranking)
            @php
                $isMe = (int) $ranking->character_id === $currentCharacterId;
            @endphp
            <div class="flex min-w-0 flex-col gap-3 px-3 py-3 sm:flex-row sm:items-center sm:px-5 {{ $isMe ? 'bg-amber-50 shadow-[inset_3px_0_0_#fbbf24]' : 'bg-white' }}" data-ranking-character-id="{{ $ranking->character_id }}">
                <div class="flex min-w-0 items-center gap-3 sm:flex-1">
                    <div class="w-14 shrink-0 text-center">
                        @if($ranking->rank === 1)
                            <span class="rounded bg-amber-300 px-1.5 py-1 text-[10px] font-black text-slate-950">現在1位</span>
                        @else
                            <span class="text-sm font-black text-slate-700">{{ $ranking->rank }}位</span>
                        @endif
                    </div>
                    @if($ranking->character)
                        <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($ranking->character->icon_path) }}" alt="" class="h-10 w-10 shrink-0 object-contain drop-shadow">
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="flex min-w-0 items-center gap-2">
                            @if($isMe)
                                <span class="shrink-0 rounded bg-amber-400 px-1.5 py-0.5 text-[10px] font-black text-slate-950">あなた</span>
                            @endif
                            <span class="truncate text-sm font-black text-slate-950">{{ $ranking->character?->name ?? '冒険者' }}</span>
                        </div>
                        <div class="mt-0.5 text-[10px] font-bold text-slate-500">
                            公式攻撃 {{ $ranking->official_attack_wins }}勝 {{ $ranking->official_attack_losses }}敗
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="px-4 py-8 text-center text-sm font-bold text-slate-500">まだ参加者はいません。</div>
        @endforelse
    </div>

    @if($rankings->hasPages())
        <div class="border-t border-slate-300 bg-white px-3 py-3 text-slate-800" data-ranking-pagination>
            {{ $rankings->onEachSide(1)->links(data: ['scrollTo' => false]) }}
        </div>
    @endif
</div>
