<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">PRIVATE CHAT LOGS</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">個人チャットログ閲覧</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">1対1の個人チャットだけを確認します。送信者・受信者・本文で検索できます。</p>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row">
            <select wire:model.live="perPage" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                <option value="50">50件</option>
                <option value="100">100件</option>
                <option value="200">200件</option>
            </select>
            <input type="text" wire:model.live.debounce.300ms="searchQuery" placeholder="送信者・受信者・本文で検索" class="w-full rounded-md border border-slate-300 bg-white px-4 py-2 text-sm shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30 sm:w-96">
        </div>
    </div>

    <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-md border border-slate-200 bg-white px-4 py-3 shadow-sm">
            <div class="text-xs font-black text-slate-400">該当ログ</div>
            <div class="mt-1 text-2xl font-black text-slate-950">{{ number_format($totalCount) }}</div>
        </div>
        <div class="rounded-md border border-slate-200 bg-white px-4 py-3 shadow-sm sm:col-span-2">
            <div class="text-xs font-black text-slate-400">保存方針</div>
            <div class="mt-1 text-sm font-bold text-slate-600">DB上の個人チャットログをページング表示します。表示件数でログを削除・切り詰める処理は行いません。</div>
        </div>
    </div>

    <div class="overflow-hidden rounded-md bg-white/95 shadow-sm ring-1 ring-slate-200">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-lg font-black text-slate-950">ログ一覧</h2>
            <div class="text-xs font-bold text-slate-500">1ページ {{ number_format($perPage) }} 件</div>
        </div>

        @if(!$canReadPrivateLogs)
            <div class="px-6 py-10 text-center text-sm font-bold text-slate-500">
                個人チャットログを表示するためのテーブルまたはカラムがありません。
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-700">
                        <tr>
                            <th class="px-4 py-3 text-left font-bold">日時</th>
                            <th class="px-4 py-3 text-left font-bold">送信者</th>
                            <th class="px-4 py-3 text-left font-bold">受信者</th>
                            <th class="px-4 py-3 text-left font-bold">本文</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse($logs as $log)
                            <tr class="hover:bg-slate-50">
                                <td class="whitespace-nowrap px-4 py-3 text-xs font-bold text-slate-500">
                                    {{ \Illuminate\Support\Carbon::parse($log->created_at)->format('Y/m/d H:i:s') }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 font-black text-slate-900">{{ $log->type === 'admin_private' ? '管理人' : ($log->sender_name ?? '-') }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-black text-blue-900">{{ $log->receiver_name ?? '-' }}</td>
                                <td class="min-w-[28rem] px-4 py-3 font-bold leading-relaxed text-slate-700">
                                    <div class="whitespace-pre-wrap break-words">{{ $log->message }}</div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-sm font-bold text-slate-500">
                                    個人チャットログが見つかりません。
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-100 px-4 py-3">
                {{ $logs->links() }}
            </div>
        @endif
    </div>
</div>
