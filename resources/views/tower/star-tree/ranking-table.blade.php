<div class="divide-y divide-slate-100">
    @forelse($records as $record)
        @php
            $rank = $loop->iteration;
            $player = $record->character;
            $isMe = $player && (int) $player->id === (int) $character->id;
            $iconClass = match ($rank) {
                1 => 'h-16 w-16',
                2 => 'h-14 w-14',
                3 => 'h-12 w-12',
                default => 'h-10 w-10',
            };
            $iconPath = \App\Support\CharacterIconCatalog::versionedAsset($player?->icon_path);
        @endphp
        <div class="grid items-center gap-3 px-4 py-3 {{ $isMe ? 'bg-amber-50 ring-2 ring-inset ring-amber-400' : 'bg-white' }}" style="grid-template-columns: 58px minmax(0, 1fr) 88px;">
            <div class="font-black tabular-nums {{ $rankClass($rank) }}">{{ $rank }}位</div>
            <div class="flex min-w-0 items-center gap-3">
                <div class="flex {{ $iconClass }} shrink-0 items-center justify-center overflow-hidden rounded-full border border-emerald-100 bg-white shadow-sm">
                    <img src="{{ $iconPath }}" alt="{{ $player?->name ?? '冒険者' }}" class="h-full w-full object-contain">
                </div>
                <div class="min-w-0">
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                        @if($isMe)
                            <span class="rounded bg-amber-600 px-2 py-0.5 text-[10px] font-black text-white">あなた</span>
                        @endif
                        <div class="truncate text-sm font-black text-slate-950">{{ $player?->name ?? '不明な冒険者' }}</div>
                    </div>
                    <div class="mt-0.5 text-xs font-bold text-slate-500">
                        {{ $player?->jobClass?->name ?? '冒険者' }} / Lv{{ number_format((int) ($player?->level ?? 0)) }}
                        @if($record->achieved_at)
                            ・{{ $record->achieved_at->format('m/d H:i') }}
                        @endif
                    </div>
                </div>
            </div>
            <div class="text-right">
                <div class="text-lg font-black text-emerald-700">{{ number_format((int) $record->best_cleared_floor) }}</div>
                <div class="text-[10px] font-bold text-slate-400">階突破</div>
            </div>
        </div>
    @empty
        <div class="px-4 py-10 text-center text-sm font-bold text-slate-400">
            まだ記録がありません。
        </div>
    @endforelse
</div>
