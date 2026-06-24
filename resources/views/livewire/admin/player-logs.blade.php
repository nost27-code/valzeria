<div class="w-full px-4 sm:px-6 lg:px-8 py-8 lg:py-10">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between mb-6">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">PLAYER LOGS</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">プレイヤー一覧・ランキング</h1>
        </div>
        <div class="flex gap-2">
            <input type="text" wire:model.live.debounce.300ms="searchQuery" placeholder="名前・メアド・User IDで検索..." class="w-full lg:w-80 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37] focus:ring-opacity-50">
        </div>
    </div>

    <div class="bg-white/95 rounded-md shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-slate-50 text-slate-700">
                    <tr>
                        <th scope="col" class="px-3 py-3 text-left font-bold tracking-wider w-16">順位</th>
                        <th scope="col" class="px-4 py-3 text-left font-bold tracking-wider">ID</th>
                        <th scope="col" class="px-4 py-3 text-left font-bold tracking-wider">プレイヤー名</th>
                        <th scope="col" class="px-4 py-3 text-left font-bold tracking-wider">現在地 (街)</th>
                        <th scope="col" class="px-4 py-3 text-left font-bold tracking-wider">Lv (EXP)</th>
                        <th scope="col" class="px-4 py-3 text-left font-bold tracking-wider">HP / SP</th>
                        <th scope="col" class="px-4 py-3 text-left font-bold tracking-wider">ステータス (S/D/A/M/L/Sp)</th>
                        <th scope="col" class="px-4 py-3 text-left font-bold tracking-wider">最終更新</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($characters as $index => $character)
                        <tr class="hover:bg-gray-50 {{ $character->user && $character->user->role === 'admin' ? 'bg-yellow-50' : '' }}">
                            <td class="px-3 py-3 whitespace-nowrap text-gray-900 font-bold">
                                {{ $rankOffset + $index }}位
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="font-black text-slate-900">User #{{ $character->user_id ?? '-' }}</div>
                                <div class="text-xs font-bold text-slate-500">Char #{{ $character->id }}</div>
                                @if($character->user_id)
                                    <a href="{{ route('admin.user-investigation', ['user_id' => $character->user_id]) }}" class="mt-1 inline-flex rounded bg-slate-950 px-2 py-1 text-[11px] font-black text-white hover:bg-slate-800">
                                        調査
                                    </a>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($character->user_id)
                                    <a href="{{ route('admin.user-investigation', ['user_id' => $character->user_id]) }}" class="group inline-block">
                                        <div class="font-bold text-gray-900 underline-offset-4 group-hover:text-amber-700 group-hover:underline">{{ $character->name }}</div>
                                        <div class="text-xs text-gray-500 group-hover:text-amber-700">{{ optional($character->user)->email ?? 'N/A' }}</div>
                                    </a>
                                @else
                                    <div class="font-bold text-gray-900">{{ $character->name }}</div>
                                    <div class="text-xs text-gray-500">{{ optional($character->user)->email ?? 'N/A' }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-gray-700">
                                {{ optional($character->currentCity)->name ?? '不明' }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="font-bold text-gray-900">Lv {{ $character->level }}</div>
                                <div class="text-xs text-gray-500">EXP: {{ number_format($character->exp) }}</div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="text-green-600 font-semibold">HP: {{ $character->current_hp }} / {{ $character->hp_base }}</div>
                                <div class="text-blue-600 font-semibold">SP: {{ $character->current_mp }} / {{ $character->mp_base }}</div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-gray-600 text-xs">
                                <div>STR:{{ $character->attack_base }} DEF:{{ $character->defense_base }}</div>
                                <div>AGI:{{ $character->speed_base }} MAG:{{ $character->magic_base }}</div>
                                <div>LUK:{{ $character->luck_base }} SPR:{{ $character->spirit_base ?? 0 }}</div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-gray-500 text-xs">
                                {{ $character->updated_at ? $character->updated_at->format('Y/m/d H:i') : '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-8 text-center text-gray-500">
                                プレイヤーが見つかりませんでした。
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $characters->links() }}
        </div>
    </div>
</div>
