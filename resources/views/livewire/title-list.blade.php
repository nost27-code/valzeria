<div>
    @php
        $rarityLabels = [
            'normal' => '通常',
            'common' => '通常',
            'rare' => '希少',
            'epic' => '英雄',
            'legend' => '伝説',
            'legendary' => '伝説',
            'mythic' => '神話',
        ];
        $rarityClass = [
            'normal' => 'border-slate-200 bg-slate-50 text-slate-600',
            'common' => 'border-slate-200 bg-slate-50 text-slate-600',
            'rare' => 'border-blue-200 bg-blue-50 text-blue-700',
            'epic' => 'border-purple-200 bg-purple-50 text-purple-700',
            'legend' => 'border-amber-300 bg-amber-50 text-amber-700',
            'legendary' => 'border-amber-300 bg-amber-50 text-amber-700',
            'mythic' => 'border-rose-300 bg-rose-50 text-rose-700',
        ];
    @endphp

    <div class="mb-3 grid grid-cols-3 divide-x divide-amber-100 overflow-hidden rounded-xl border border-[#d4af37]/40 bg-amber-50/60">
        <div class="px-3 py-2.5">
            <div class="text-[10px] font-black tracking-wide text-amber-600 uppercase">獲得称号</div>
            <div class="mt-0.5 leading-none">
                <span class="text-base font-black tabular-nums text-slate-900">{{ number_format($summary['unlocked_count']) }}</span>
                <span class="text-xs font-bold text-slate-400">/{{ number_format($summary['total_count']) }}</span>
            </div>
        </div>
        <div class="px-3 py-2.5">
            <div class="text-[10px] font-black tracking-wide text-amber-600 uppercase">装備中</div>
            <div class="mt-0.5 truncate text-sm font-black leading-none text-slate-900">{{ $summary['equipped_name'] ?? 'なし' }}</div>
        </div>
        <div class="px-3 py-2.5">
            <div class="text-[10px] font-black tracking-wide text-amber-600 uppercase">未解放</div>
            <div class="mt-0.5 text-base font-black tabular-nums leading-none text-slate-900">{{ number_format(max(0, $summary['total_count'] - $summary['unlocked_count'])) }}</div>
        </div>
    </div>

    <div class="mb-4 rounded-xl border border-[#d4af37]/40 bg-white px-3 py-2.5">
        <div class="flex items-center gap-2">
            <img src="{{ asset('images/icon/icon_009.webp') }}" alt="" class="h-5 w-5 object-contain">
            <div class="min-w-0 flex-1">
                <div class="text-[10px] font-black tracking-widest text-amber-600 uppercase">称号装備</div>
                <div class="truncate text-sm font-bold text-slate-700">獲得済みの称号を選ぶと、冒険者カードに表示する称号を変更できます。</div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
        @forelse($titles as $title)
            @php
                $isUnlocked = isset($characterTitles[$title['id']]);
                $isEquipped = (bool) ($characterTitles[$title['id']]['is_equipped'] ?? false);
                $rarity = strtolower((string) ($title['rarity'] ?? 'common'));
                $rarityBadgeClass = $rarityClass[$rarity] ?? $rarityClass['common'];
                $displayName = $isUnlocked ? $title['name'] : '？？？';
            @endphp
            <button
                type="button"
                @if($isUnlocked && ! $isEquipped) wire:click="equipTitle({{ (int) $title['id'] }})" @endif
                @disabled(! $isUnlocked || $isEquipped)
                class="min-h-[74px] rounded-lg border px-2.5 py-2 text-left shadow-sm transition {{ $isEquipped ? 'border-amber-500 bg-amber-50 ring-1 ring-amber-300' : ($isUnlocked ? 'border-[#d4af37]/45 bg-white hover:bg-amber-50' : 'border-slate-100 bg-slate-50/70') }} {{ $isUnlocked && ! $isEquipped ? 'cursor-pointer' : 'cursor-default' }}"
            >
                <div class="flex items-start gap-2">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border {{ $isUnlocked ? 'border-amber-200 bg-white' : 'border-slate-200 bg-white/70' }}">
                        <img src="{{ asset($isEquipped ? 'images/icon/icon_009.webp' : 'images/icon/icon_242.webp') }}" alt="" class="h-6 w-6 object-contain {{ $isUnlocked ? '' : 'grayscale opacity-40' }}">
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="break-words text-sm font-black leading-tight {{ $isUnlocked ? 'text-slate-900' : 'text-slate-400' }}">{{ $displayName }}</div>
                        <div class="mt-1 flex flex-wrap gap-1">
                            <span class="rounded border px-1.5 py-0.5 text-[10px] font-black leading-none {{ $rarityBadgeClass }}">{{ $rarityLabels[$rarity] ?? strtoupper($rarity) }}</span>
                            @if($isEquipped)
                                <span class="rounded border border-amber-300 bg-amber-100 px-1.5 py-0.5 text-[10px] font-black leading-none text-amber-800">装備中</span>
                            @elseif($isUnlocked)
                                <span class="rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-black leading-none text-emerald-700">獲得済み</span>
                            @endif
                        </div>
                    </div>
                </div>
            </button>
        @empty
            <div class="col-span-full rounded-lg border border-slate-100 bg-white px-3 py-4 text-sm font-bold text-slate-500">
                称号がありません。
            </div>
        @endforelse
    </div>
</div>
