<div class="p-0">
    <div class="mb-5 flex flex-col gap-3 border-b border-[#d4af37]/50 pb-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-black tracking-widest text-[#1e293b]">闘技場ランキング</h2>
            <p class="mt-1 text-sm font-bold text-slate-500">TOP100まで表示します。名前を押すと個別ステータスを確認できます。</p>
        </div>
        <a href="{{ route('home') }}" class="inline-flex items-center justify-center rounded bg-slate-800 px-4 py-2 text-sm font-bold text-white shadow transition hover:bg-slate-700">
            闘技場へ戻る
        </a>
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="grid grid-cols-[58px_1fr_58px] gap-2 border-b border-slate-200 bg-slate-50 px-3 py-2 text-[11px] font-black text-slate-500 sm:grid-cols-[70px_1fr_90px]">
            <div>順位</div>
            <div>冒険者</div>
            <div class="text-right">Lv</div>
        </div>

        <div class="divide-y divide-slate-100">
            @forelse($rankings as $ranking)
                @php
                    $player = $ranking->character;
                    $isMe = $player && (int) $player->id === (int) $myCharacterId;
                @endphp
                <div class="grid grid-cols-[58px_1fr_58px] items-center gap-2 px-3 py-3 text-sm sm:grid-cols-[70px_1fr_90px] {{ $isMe ? 'relative bg-amber-100 ring-2 ring-inset ring-amber-500 shadow-[inset_4px_0_0_#d97706]' : 'bg-white' }}">
                    <div class="font-black {{ $ranking->rank === 1 ? 'text-amber-500' : ($ranking->rank === 2 ? 'text-slate-400' : ($ranking->rank === 3 ? 'text-amber-700' : 'text-slate-600')) }}">
                        {{ $ranking->rank }}位
                    </div>
                    <div class="min-w-0">
                        @if($player)
                            <div class="flex min-w-0 items-center gap-2">
                                @if($isMe)
                                    <span class="shrink-0 rounded bg-amber-600 px-2 py-0.5 text-[10px] font-black text-white shadow">あなた</span>
                                @endif
                                <button type="button" wire:click="openPlayerModal({{ $player->id }})" class="max-w-full truncate text-left font-extrabold {{ $isMe ? 'text-amber-950' : 'text-[#1e40af]' }} underline-offset-2 hover:underline">
                                    {{ $player->name }}
                                </button>
                            </div>
                            <div class="mt-0.5 truncate text-xs font-bold text-slate-500">{{ $player->jobClass?->name ?? '冒険者' }}</div>
                        @else
                            <span class="font-bold text-slate-400">不明</span>
                        @endif
                    </div>
                    <div class="text-right font-bold text-slate-700">
                        {{ $player ? 'Lv.' . $player->level : '-' }}
                    </div>
                </div>
            @empty
                <div class="px-4 py-8 text-center text-sm font-bold text-slate-400">
                    まだランキングがありません。
                </div>
            @endforelse
        </div>
    </div>

    @if($selectedPlayer)
        <div class="fixed inset-0 z-[9998] bg-black/50" wire:click="closePlayerModal"></div>
        <div class="fixed left-1/2 top-1/2 z-[9999] w-[92vw] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-lg border-2 border-[#d4af37] bg-white p-5 shadow-xl">
            <button type="button" wire:click="closePlayerModal" class="absolute right-3 top-3 text-slate-400 hover:text-slate-700">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>

            <div class="mb-4 flex items-center gap-4 border-b border-slate-200 pb-4">
                <div class="h-16 w-16 shrink-0 overflow-hidden rounded-full border-2 border-[#d4af37] bg-slate-100">
                    @if(!empty($selectedPlayer['icon']))
                        <img src="{{ $selectedPlayer['icon'] }}" alt="" class="h-full w-full object-cover">
                    @else
                        <div class="flex h-full w-full items-center justify-center text-2xl font-black text-slate-400">?</div>
                    @endif
                </div>
                <div class="min-w-0">
                    <div class="truncate text-xl font-black text-slate-900">{{ $selectedPlayer['name'] }}</div>
                    <div class="mt-1 text-sm font-bold text-slate-500">Lv.{{ $selectedPlayer['level'] }} / {{ $selectedPlayer['job'] }}</div>
                    <div class="mt-1 text-xs font-bold text-amber-700">闘技場 {{ $selectedPlayer['rank'] ?? '-' }}位</div>
                </div>
            </div>

            <div class="mb-4 grid grid-cols-2 gap-2 text-sm">
                <div class="rounded border border-red-100 bg-red-50 px-3 py-2 font-bold text-red-700">HP <span class="float-right text-slate-900">{{ $selectedPlayer['hp'] }} / {{ $selectedPlayer['max_hp'] }}</span></div>
                <div class="rounded border border-blue-100 bg-blue-50 px-3 py-2 font-bold text-blue-700">SP <span class="float-right text-slate-900">{{ $selectedPlayer['mp'] }} / {{ $selectedPlayer['max_mp'] }}</span></div>
                <div class="rounded border border-slate-200 px-3 py-2 font-bold">ATK <span class="float-right">{{ $selectedPlayer['str'] }}</span></div>
                <div class="rounded border border-slate-200 px-3 py-2 font-bold">DEF <span class="float-right">{{ $selectedPlayer['def'] }}</span></div>
                <div class="rounded border border-slate-200 px-3 py-2 font-bold">MAG <span class="float-right">{{ $selectedPlayer['mag'] }}</span></div>
                <div class="rounded border border-slate-200 px-3 py-2 font-bold">SPR <span class="float-right">{{ $selectedPlayer['spr'] }}</span></div>
                <div class="rounded border border-slate-200 px-3 py-2 font-bold">SPD <span class="float-right">{{ $selectedPlayer['agi'] }}</span></div>
                <div class="rounded border border-slate-200 px-3 py-2 font-bold">LUK <span class="float-right">{{ $selectedPlayer['luk'] }}</span></div>
            </div>

            <div class="space-y-1.5 rounded border border-slate-200 bg-slate-50 p-3 text-sm">
                <div class="mb-1 text-xs font-black text-slate-500">現在の装備</div>
                <div class="font-bold">武器: <span class="font-medium text-slate-700">{{ $selectedPlayer['weapon'] }}</span></div>
                <div class="font-bold">防具: <span class="font-medium text-slate-700">{{ $selectedPlayer['armor'] }}</span></div>
                <div class="font-bold">装飾: <span class="font-medium text-slate-700">{{ $selectedPlayer['accessory'] }}</span></div>
            </div>

            <button type="button" wire:click="closePlayerModal" class="mt-4 w-full rounded bg-slate-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-700">
                閉じる
            </button>
        </div>
    @endif
</div>
