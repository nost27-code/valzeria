<div class="p-0">
    <div class="mb-5 flex flex-col gap-3 border-b border-[#d4af37]/50 pb-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-black tracking-widest text-[#1e293b]">闘技場ランキング</h2>
            <p class="mt-1 text-sm font-bold text-slate-500">TOP100まで表示します。名前を押すと冒険者カードを確認できます。</p>
        </div>
        <a href="{{ route('home') }}" class="inline-flex items-center justify-center rounded bg-slate-800 px-4 py-2 text-sm font-bold text-white shadow transition hover:bg-slate-700">
            闘技場へ戻る
        </a>
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="grid gap-2 border-b border-slate-200 bg-slate-50 px-3 py-2 text-[11px] font-black text-slate-500" style="grid-template-columns: 64px minmax(0, 1fr) 82px;">
            <div>順位</div>
            <div>冒険者</div>
            <div class="text-right">戦力</div>
        </div>

        <div class="divide-y divide-slate-100">
            @forelse($rankings as $ranking)
                @php
                    $player = $ranking['character'] ?? null;
                    $isNpc = ($ranking['type'] ?? null) === 'npc';
                    $isMe = $player && (int) $player->id === (int) $myCharacterId;
                @endphp
                <div class="grid items-center gap-2 px-3 py-3.5 text-sm {{ $isMe ? 'relative bg-amber-100 ring-2 ring-inset ring-amber-500 shadow-[inset_4px_0_0_#d97706]' : 'bg-white' }}" style="grid-template-columns: 64px minmax(0, 1fr) 82px;">
                    <div class="font-black {{ $ranking['rank'] === 1 ? 'text-amber-500' : ($ranking['rank'] === 2 ? 'text-slate-400' : ($ranking['rank'] === 3 ? 'text-amber-700' : 'text-slate-600')) }}">
                        {{ $ranking['rank'] }}位
                    </div>
                    <div class="min-w-0">
                        @if($player)
                            <div class="flex min-w-0 items-center gap-3">
                                @if(!empty($ranking['image_path']))
                                    <span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded border border-slate-200 bg-slate-50">
                                        <img src="{{ asset($ranking['image_path']) }}" alt="" class="h-full w-full object-contain">
                                    </span>
                                @endif
                                <div class="min-w-0">
                                    <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                                        @if($isMe)
                                            <span class="shrink-0 rounded bg-amber-600 px-2 py-0.5 text-[10px] font-black text-white shadow">あなた</span>
                                        @endif
                                        <button type="button" wire:click="openPlayerModal({{ $player->id }})" class="break-words text-left font-extrabold leading-tight {{ $isMe ? 'text-amber-950' : 'text-[#1e40af]' }} underline-offset-2 hover:underline">
                                            {{ $player->name }}
                                        </button>
                                    </div>
                                    <div class="mt-0.5 text-xs font-bold text-slate-500">{{ $player->jobClass?->name ?? '冒険者' }} / Lv.{{ $player->level }}</div>
                                </div>
                            </div>
                        @elseif($isNpc)
                            <div class="flex min-w-0 items-center gap-2">
                                @if(!empty($ranking['image_path']))
                                    <span class="h-9 w-9 shrink-0 overflow-hidden rounded border border-slate-200 bg-slate-50">
                                        <img src="{{ asset($ranking['image_path']) }}" alt="" class="h-full w-full object-contain">
                                    </span>
                                @endif
                                <div class="min-w-0">
                                    <button type="button" wire:click="openNpcModal({{ $ranking['id'] }})" class="break-words text-left font-extrabold leading-tight text-[#1e40af] underline-offset-2 hover:underline">
                                        {{ $ranking['name'] }}
                                    </button>
                                    <div class="mt-0.5 text-xs font-bold text-slate-500">{{ $ranking['job'] }} / Lv.{{ $ranking['level'] }}</div>
                                </div>
                            </div>
                        @else
                            <span class="font-bold text-slate-400">不明</span>
                        @endif
                    </div>
                    <div class="whitespace-nowrap text-right text-sm font-black text-amber-700">
                        {{ isset($ranking['power']) ? number_format((int) $ranking['power']) : '？？？' }}
                    </div>
                </div>
            @empty
                <div class="px-4 py-8 text-center text-sm font-bold text-slate-400">
                    まだランキングがありません。
                </div>
            @endforelse
        </div>
    </div>

    @if($selectedNpc)
        <div class="fixed inset-0 z-[9998] bg-black/50" wire:click="closeNpcModal"></div>
        <div class="fixed left-1/2 top-1/2 z-[9999] w-[92vw] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-lg border-2 border-[#d4af37] bg-white p-5 shadow-xl">
            <button type="button" wire:click="closeNpcModal" class="absolute right-3 top-3 text-slate-400 hover:text-slate-700">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>

            <div class="mb-4 flex items-center gap-4 border-b border-slate-200 pb-4">
                <div class="h-16 w-16 shrink-0 overflow-hidden rounded-full border-2 border-[#d4af37] bg-slate-100">
                    <img src="{{ $selectedNpc['icon'] }}" alt="" class="h-full w-full object-contain">
                </div>
                <div class="min-w-0">
                    <div class="truncate text-xl font-black text-slate-900">{{ $selectedNpc['name'] }}</div>
                    <div class="mt-1 text-sm font-bold text-slate-500">Lv.{{ $selectedNpc['level'] }} / {{ $selectedNpc['job'] }}</div>
                    <div class="mt-1 text-xs font-black text-amber-700">戦力 {{ number_format((int) $selectedNpc['power']) }}</div>
                    <div class="mt-1 text-xs font-bold text-amber-700">闘技場 {{ $selectedNpc['rank'] }}位</div>
                </div>
            </div>

            <div class="mb-4 grid grid-cols-2 gap-2 text-sm">
                <div class="rounded border border-red-100 bg-red-50 px-3 py-2 font-bold text-red-700">HP <span class="float-right text-slate-900">？？？</span></div>
                <div class="rounded border border-blue-100 bg-blue-50 px-3 py-2 font-bold text-blue-700">SP <span class="float-right text-slate-900">？？？</span></div>
                <div class="rounded border border-slate-200 px-3 py-2 font-bold">攻撃 <span class="float-right">？？？</span></div>
                <div class="rounded border border-slate-200 px-3 py-2 font-bold">防御 <span class="float-right">？？？</span></div>
                <div class="rounded border border-slate-200 px-3 py-2 font-bold">魔力 <span class="float-right">？？？</span></div>
                <div class="rounded border border-slate-200 px-3 py-2 font-bold">精神 <span class="float-right">？？？</span></div>
                <div class="rounded border border-slate-200 px-3 py-2 font-bold">敏捷 <span class="float-right">？？？</span></div>
                <div class="rounded border border-slate-200 px-3 py-2 font-bold">運 <span class="float-right">？？？</span></div>
            </div>

            <div class="space-y-1.5 rounded border border-slate-200 bg-slate-50 p-3 text-sm">
                <div class="mb-1 text-xs font-black text-slate-500">現在の装備</div>
                <div class="font-bold">武器: <span class="font-medium text-slate-700">{{ $selectedNpc['weapon'] }}</span></div>
                <div class="font-bold">防具: <span class="font-medium text-slate-700">{{ $selectedNpc['armor'] }}</span></div>
                <div class="font-bold">装飾: <span class="font-medium text-slate-700">{{ $selectedNpc['accessory'] }}</span></div>
            </div>

            <button type="button" wire:click="closeNpcModal" class="mt-4 w-full rounded bg-slate-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-700">
                閉じる
            </button>
        </div>
    @endif

    <livewire:city-header :show-city-panel="false" :modal-only="true" />
</div>
