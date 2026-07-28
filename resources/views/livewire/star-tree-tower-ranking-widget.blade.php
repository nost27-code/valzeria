<section x-data="{ tab: 'weekly' }"
         class="w-full overflow-hidden rounded-lg border border-[#d4af37] bg-white shadow-[0_4px_14px_rgba(126,96,28,0.12)]">
    <div class="flex bg-[#0a1628]">
        <button type="button"
                @click="tab = 'weekly'"
                class="min-w-0 flex-1 px-2 py-1.5 text-[11px] font-black tracking-wider transition"
                :class="tab === 'weekly' ? 'bg-[#1b2c47] text-[#d4af37]' : 'text-amber-100/50'">
            週間勝利
        </button>
        @if($towerEnabled)
            <button type="button"
                    @click="tab = 'tower'"
                    class="min-w-0 flex-1 px-2 py-1.5 text-[11px] font-black tracking-wider transition"
                    :class="tab === 'tower' ? 'bg-[#1b2c47] text-[#d4af37]' : 'text-amber-100/50'">
                星樹の塔
            </button>
        @endif
        <button type="button"
                @click="tab = 'arena'"
                class="min-w-0 flex-1 px-2 py-1.5 text-[11px] font-black tracking-wider transition"
                :class="tab === 'arena' ? 'bg-[#1b2c47] text-[#d4af37]' : 'text-amber-100/50'">
            闘技場
        </button>
    </div>

    <div x-show="tab === 'weekly'">
        @if(! ($weeklyWinData['availability']['is_started'] ?? true))
            <div class="border-b border-amber-100 bg-amber-50 px-3 py-3 text-center">
                <div class="text-xs font-black text-amber-800">
                    週間番付は{{ $weeklyWinData['availability']['starts_at_label'] }}から始まります
                </div>
                <div class="mt-1 text-[10px] font-bold text-slate-500">
                    {{ $weeklyWinData['period']['label'] }}
                </div>
                <div class="mt-1 text-[10px] font-bold text-slate-500">
                    それ以前の勝利は集計・報酬の対象外です
                </div>
            </div>
        @else
        <div class="border-b border-amber-100 bg-amber-50 px-3 py-2">
            <div class="flex min-w-0 items-center justify-between gap-2">
                <span class="shrink-0 text-[10px] font-black text-amber-700">今週</span>
                <span class="min-w-0 truncate text-right text-[10px] font-bold text-slate-500">
                    {{ $weeklyWinData['period']['label'] }}
                </span>
            </div>

            @if($weeklyWinData['status'])
                @php
                    $weeklyStatus = $weeklyWinData['status'];
                @endphp
                <div class="mt-1.5 flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                    <div class="flex items-baseline gap-2">
                        <span class="text-[10px] font-black text-[#003366]">あなたの進捗</span>
                        <span class="text-sm font-black tabular-nums text-slate-950">
                            {{ number_format((int) $weeklyStatus['wins']) }}勝
                        </span>
                        <span class="text-[10px] font-black text-slate-500">
                            {{ $weeklyStatus['rank'] ? number_format((int) $weeklyStatus['rank']).'位' : '未参加' }}
                        </span>
                    </div>

                    @if($weeklyStatus['excluded'])
                        <span class="rounded bg-slate-200 px-1.5 py-0.5 text-[9px] font-black text-slate-600">集計対象外</span>
                    @elseif(!$weeklyStatus['is_account_eligible'])
                        <span class="rounded bg-slate-200 px-1.5 py-0.5 text-[9px] font-black text-slate-600">表示のみ・報酬対象外</span>
                    @elseif($weeklyStatus['potential_reward_free_kiseki'] > 0)
                        <span class="rounded bg-amber-200/70 px-1.5 py-0.5 text-[9px] font-black text-amber-900">
                            見込み 無償輝石{{ number_format((int) $weeklyStatus['potential_reward_free_kiseki']) }}個
                        </span>
                    @elseif($weeklyStatus['participation_remaining_wins'] > 0)
                        <span class="rounded bg-white px-1.5 py-0.5 text-[9px] font-black text-slate-600">
                            参加賞まであと{{ number_format((int) $weeklyStatus['participation_remaining_wins']) }}勝
                        </span>
                    @endif
                </div>
            @endif
        </div>

        <div class="divide-y divide-slate-100">
            @forelse($weeklyWinData['rows'] as $row)
                @php
                    $rank = (int) $row['rank'];
                    $rankColor = match ($rank) {
                        1 => 'text-amber-500',
                        2 => 'text-slate-400',
                        3 => 'text-orange-700',
                        default => 'text-slate-400',
                    };
                    $iconPath = \App\Support\CharacterIconCatalog::versionedAsset($row['icon_path'] ?? null);
                @endphp
                <div class="flex items-center gap-2 px-3 py-1.5">
                    <div class="w-6 shrink-0 text-center text-sm font-black tabular-nums {{ $rankColor }}">{{ $rank }}</div>
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-amber-100 bg-white">
                        <img src="{{ $iconPath }}" alt="{{ $row['name'] ?? '冒険者' }}" class="h-full w-full object-contain">
                    </div>
                    <div class="min-w-0 flex-1">
                        <button
                            type="button"
                            wire:click="openWeeklyWinPlayerModal({{ (int) $row['character_id'] }})"
                            x-on:click="$dispatch('adventurer-card-loading')"
                            class="block max-w-full truncate text-left text-xs font-black text-[#1e40af] underline-offset-2 hover:underline"
                            aria-label="{{ $row['name'] ?? '不明な冒険者' }}の冒険者カードを見る"
                        >
                            {{ $row['name'] ?? '不明な冒険者' }}
                        </button>
                        <div class="flex items-center gap-1 text-[10px] font-bold text-slate-400">
                            <span>Lv{{ number_format((int) ($row['level'] ?? 1)) }}</span>
                            @if(! ($row['is_account_eligible'] ?? false))
                                <span class="rounded bg-slate-100 px-1 text-[9px] font-black text-slate-500">表示のみ</span>
                            @endif
                        </div>
                    </div>
                    <div class="shrink-0 text-right">
                        <span class="text-sm font-black text-amber-700">{{ number_format((int) $row['score']) }}</span>
                        <span class="text-[10px] font-bold text-slate-400">勝</span>
                    </div>
                </div>
            @empty
                <div class="px-3 py-4 text-center text-[11px] font-bold text-slate-400">
                    今週の勝利はまだありません
                </div>
            @endforelse
        </div>
        @endif

        <a href="{{ route('ranking.index', ['board' => 'weekly_wins']) }}"
           class="block bg-amber-50 px-3 py-1.5 text-center text-[11px] font-black text-amber-800 active:scale-[0.99]">
            週間勝利数番付を見る
        </a>
    </div>

    @if($towerEnabled)
        <div x-show="tab === 'tower'" style="display: none;">
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
    @endif

    <div x-show="tab === 'arena'" style="display: none;">
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
