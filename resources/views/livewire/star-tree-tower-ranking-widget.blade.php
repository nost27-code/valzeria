<section @if($towerEnabled) x-data="{ tab: 'tower' }" @endif
         class="w-full overflow-hidden rounded-lg border border-[#d4af37] bg-white shadow-[0_4px_14px_rgba(126,96,28,0.12)]">
    @if($towerEnabled)
        <div class="flex bg-[#0a1628]">
            <button type="button"
                    @click="tab = 'tower'"
                    class="flex-1 px-3 py-1.5 text-[11px] font-black tracking-widest transition"
                    :class="tab === 'tower' ? 'bg-[#1b2c47] text-[#d4af37]' : 'text-amber-100/50'">
                星樹の塔
            </button>
            <button type="button"
                    @click="tab = 'arena'"
                    class="flex-1 px-3 py-1.5 text-[11px] font-black tracking-widest transition"
                    :class="tab === 'arena' ? 'bg-[#1b2c47] text-[#d4af37]' : 'text-amber-100/50'">
                闘技場
            </button>
        </div>

        <div x-show="tab === 'tower'">
            <div class="flex items-center bg-amber-50 px-3 py-1">
                <span class="text-[10px] font-bold text-amber-700">今期 〜7/15 17:59</span>
            </div>

            <div class="divide-y divide-slate-100">
                @forelse($towerRecords as $record)
                    @php
                        $rank = $loop->iteration;
                        $player = $record->character;
                        $rankColor = match ($rank) {
                            1 => 'text-amber-500',
                            2 => 'text-slate-400',
                            3 => 'text-orange-700',
                            default => 'text-slate-400',
                        };
                        $iconPath = \App\Support\CharacterIconCatalog::versionedAsset($player?->icon_path);
                    @endphp
                    <div class="flex items-center gap-2 px-3 py-1.5">
                        <div class="w-6 shrink-0 text-center text-sm font-black tabular-nums {{ $rankColor }}">{{ $rank }}</div>
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-amber-100 bg-white">
                            <img src="{{ $iconPath }}" alt="{{ $player?->name ?? '冒険者' }}" class="h-full w-full object-contain">
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-xs font-black text-slate-900">{{ $player?->name ?? '不明な冒険者' }}</div>
                            <div class="truncate text-[10px] font-bold text-slate-400">{{ $player?->jobClass?->name ?? '冒険者' }}</div>
                        </div>
                        <div class="shrink-0 text-right">
                            <span class="text-sm font-black text-amber-700">{{ number_format((int) $record->best_cleared_floor) }}</span>
                            <span class="text-[10px] font-bold text-slate-400">階</span>
                        </div>
                    </div>
                @empty
                    <div class="px-3 py-4 text-center text-[11px] font-bold text-slate-400">
                        まだ挑戦者がいません
                    </div>
                @endforelse
            </div>

            <a href="{{ route('tower.star-tree.ranking') }}"
               class="block bg-amber-50 px-3 py-1.5 text-center text-[11px] font-black text-amber-800 active:scale-[0.99]">
                星樹の塔ランキングを見る
            </a>
        </div>
    @else
        <div class="bg-[#0a1628] px-3 py-1.5 text-center text-[11px] font-black tracking-widest text-[#d4af37]">
            闘技場
        </div>
    @endif

    <div @if($towerEnabled) x-show="tab === 'arena'" style="display: none;" @endif>
        <div class="divide-y divide-slate-100">
            @forelse($arenaEntries as $entry)
                @php
                    $rank = (int) ($entry['rank'] ?? $loop->iteration);
                    $isNpc = ($entry['type'] ?? null) === 'npc';
                    $rankColor = match ($rank) {
                        1 => 'text-amber-500',
                        2 => 'text-slate-400',
                        3 => 'text-orange-700',
                        default => 'text-slate-400',
                    };
                    $iconPath = \App\Support\CharacterIconCatalog::versionedAsset($entry['image_path'] ?? null);
                @endphp
                <div class="flex items-center gap-2 px-3 py-1.5">
                    <div class="w-6 shrink-0 text-center text-sm font-black tabular-nums {{ $rankColor }}">{{ $rank }}</div>
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-amber-100 bg-white">
                        <img src="{{ $iconPath }}" alt="{{ $entry['name'] ?? '冒険者' }}" class="h-full w-full object-contain">
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-xs font-black text-slate-900">
                            {{ $entry['name'] ?? '不明' }}
                            @if($isNpc)
                                <span class="ml-1 rounded bg-slate-200 px-1 text-[9px] font-black text-slate-600">NPC</span>
                            @endif
                        </div>
                        <div class="truncate text-[10px] font-bold text-slate-400">{{ $entry['job'] ?? '冒険者' }}</div>
                    </div>
                    <div class="shrink-0 text-right">
                        <span class="text-sm font-black text-amber-700">{{ number_format((int) ($entry['power'] ?? 0)) }}</span>
                        <span class="text-[10px] font-bold text-slate-400">戦力</span>
                    </div>
                </div>
            @empty
                <div class="px-3 py-4 text-center text-[11px] font-bold text-slate-400">
                    まだ挑戦者がいません
                </div>
            @endforelse
        </div>

        <a href="{{ route('colosseum.ranking') }}"
           class="block bg-amber-50 px-3 py-1.5 text-center text-[11px] font-black text-amber-800 active:scale-[0.99]">
            闘技場ランキングを見る
        </a>
    </div>
</section>
