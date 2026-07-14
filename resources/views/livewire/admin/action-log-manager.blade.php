<div class="w-full px-4 sm:px-6 lg:px-8 py-8 lg:py-10">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between mb-6">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">ACTION LOGS</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">行動ログ閲覧</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">通常戦闘は10分単位で集約し、JobEXP合計・最高値・上限超過を確認できます。輝石・課金・進化・転職・銘・特攻鍛錬・重要戦利品などは個別に確認できます。</p>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row">
            <a href="{{ route('admin.private-chat-logs') }}" class="inline-flex items-center justify-center rounded-md bg-slate-950 px-4 py-2 text-sm font-black text-white shadow-sm hover:bg-slate-800">
                個人チャットログへ
            </a>
            <select wire:model.live="eventType" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                @foreach($eventTypes as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <input type="text" wire:model.live.debounce.300ms="searchQuery" placeholder="プレイヤー名・メールで検索" class="w-full sm:w-80 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
        </div>
    </div>

    <div class="rounded-md bg-white/95 shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <div class="border-b border-slate-200 px-4 py-3 flex items-center justify-between">
            <h2 class="text-lg font-black text-slate-950">最新ログ</h2>
            <div class="text-xs font-bold text-slate-500">{{ number_format($perPage) }}件ずつ表示</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-slate-700">
                    <tr>
                        <th class="px-4 py-3 text-left font-bold">日時</th>
                        <th class="px-4 py-3 text-left font-bold">種別</th>
                        <th class="px-4 py-3 text-left font-bold">プレイヤー</th>
                        <th class="px-4 py-3 text-left font-bold">内容</th>
                        <th class="px-4 py-3 text-left font-bold">詳細</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse($logs as $log)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 whitespace-nowrap text-xs font-bold text-slate-500">
                                {{ $log['occurred_at']->format('Y/m/d H:i:s') }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="inline-flex rounded px-2 py-1 text-[11px] font-black {{ $log['badge_class'] }}">
                                    {{ $log['type'] }}
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap font-black text-slate-900">
                                {{ $log['character_name'] }}
                            </td>
                            <td class="px-4 py-3 font-bold text-slate-900">
                                {{ $log['summary'] }}
                            </td>
                            <td class="px-4 py-3 text-xs font-bold text-slate-500">
                                {{ $log['detail'] }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-sm font-bold text-slate-500">
                                ログが見つかりません。
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3">
            <button type="button" wire:click="previousPage" @disabled($currentPage <= 1) class="rounded-md px-3 py-2 text-sm font-black transition {{ $currentPage <= 1 ? 'cursor-not-allowed bg-slate-200 text-slate-400' : 'bg-white text-slate-700 shadow-sm ring-1 ring-slate-300 hover:bg-slate-100' }}">前へ</button>
            <span class="text-xs font-black text-slate-500">{{ number_format($currentPage) }}ページ目</span>
            <button type="button" wire:click="nextPage" @disabled(!$hasMore) class="rounded-md px-3 py-2 text-sm font-black transition {{ !$hasMore ? 'cursor-not-allowed bg-slate-200 text-slate-400' : 'bg-slate-950 text-white shadow-sm hover:bg-slate-800' }}">次へ</button>
        </div>
    </div>
</div>
