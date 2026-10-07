<div class="w-full px-4 py-8 sm:px-6 lg:px-8">
    <h1 class="text-2xl font-black text-slate-950">削除済みチャット</h1>
    <p class="mt-2 text-sm font-bold text-slate-600">本人が削除した全体・個人チャットの証跡です。プレイヤーには表示されません。名前・IDは削除時の情報を保持しています。</p>
    <div class="my-5 flex flex-col gap-3 sm:flex-row">
        <label class="min-w-0 flex-1 text-xs font-bold text-slate-600">検索（名前・本文・キャラID・ユーザーID）
            <input wire:model.live.debounce.300ms="searchQuery" type="search" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </label>
        <label class="text-xs font-bold text-slate-600">種別
            <select wire:model.live="typeFilter" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">
                <option value="all">全体・個人</option><option value="chat">全体チャット</option><option value="private">個人チャット</option>
            </select>
        </label>
        <label class="text-xs font-bold text-slate-600">表示件数
            <select wire:model.live="perPage" class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm">
                <option value="50">50件</option><option value="100">100件</option><option value="200">200件</option>
            </select>
        </label>
    </div>
    @if(!$ready)
        <p class="rounded bg-amber-50 p-4 text-sm font-bold text-amber-900">削除履歴のDB準備が必要です。</p>
    @else
        <p class="mb-3 text-sm font-bold text-slate-600">該当履歴：{{ number_format($logs->total()) }}件</p>
        <div class="space-y-3">
            @forelse($logs as $log)
                <article wire:key="deleted-chat-{{ $log->id }}" class="min-w-0 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-center gap-2 text-xs font-bold text-slate-600">
                        <span class="rounded bg-slate-100 px-2 py-1">{{ $log->type === 'private' ? '個人チャット' : '全体チャット' }}</span>
                        <span>本人削除：{{ $log->deleted_at->format('Y/m/d H:i:s') }}</span>
                        <span>送信：{{ $log->sent_at?->format('Y/m/d H:i:s') ?? '-' }}</span>
                        <span>元ログID：{{ $log->public_log_id }}</span>
                    </div>
                    <div class="mt-3 break-words text-sm font-bold text-slate-900">
                        <span>送信者：{{ $log->sender_name }}（CID {{ $log->character_id }} / UID {{ $log->user_id ?? '-' }}）</span>
                        @if($log->type === 'private')
                            <div class="mt-1">受信者：{{ $log->receiver_name ?? '-' }}（CID {{ $log->receiver_id ?? '-' }} / UID {{ $log->receiver_user_id ?? '-' }}）</div>
                        @endif
                    </div>
                    <div class="mt-3 whitespace-pre-wrap break-words text-sm leading-relaxed text-slate-700">{{ $log->message }}</div>
                </article>
            @empty
                <p class="rounded bg-white p-6 text-center text-sm font-bold text-slate-500">条件に一致する削除済みチャットはありません。</p>
            @endforelse
        </div>
        <div class="mt-4">{{ $logs->links() }}</div>
    @endif
</div>
