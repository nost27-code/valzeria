@if($chatDeletionStatus !== '')
    <div role="status" class="shrink-0 bg-green-50 px-3 py-2 text-xs font-bold text-green-800">{{ $chatDeletionStatus }}</div>
@endif
@error('chatDeletion')
    <div role="alert" class="shrink-0 bg-red-50 px-3 py-2 text-xs font-bold text-red-700">{{ $message }}</div>
@enderror

@if($pendingDeletionLogId)
    <div
        wire:key="chat-delete-confirm-{{ $pendingDeletionLogId }}"
        x-data="{
            deleting: false,
            error: '',
            async submitDeletion() {
                if (this.deleting) return;
                this.deleting = true;
                this.error = '';
                try {
                    await this.$wire.deleteMessage();
                } catch (error) {
                    this.error = '削除結果を確認できませんでした。表示を更新してから確認してください。';
                } finally {
                    this.deleting = false;
                }
            }
        }"
        @keydown.escape.window.stop="if (!deleting) $wire.cancelDeleteMessage()"
        @click.self="if (!deleting) $wire.cancelDeleteMessage()"
        class="fixed inset-0 z-[10000] flex items-center justify-center bg-slate-900/55 px-4 py-6 font-sans"
        role="dialog"
        aria-modal="true"
        aria-label="メッセージの削除確認"
        :aria-busy="deleting"
        data-chat-delete-confirm
    >
        <div class="w-full max-w-md rounded-2xl border border-red-200 bg-white p-5 text-slate-800 shadow-2xl">
            <div x-show="!deleting">
                <p class="text-base font-black">このメッセージを削除しますか？</p>
                <p class="mt-2 text-xs font-bold text-slate-600">削除すると元に戻せません。{{ $pendingDeletionIsPrivate ? '相手の会話画面からも消えます。' : '全体チャットから消えます。' }}</p>
                <div class="mt-3 max-h-48 overflow-y-auto whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-3 text-sm">{{ $pendingDeletionMessage }}</div>
                <p x-show="error" x-text="error" role="alert" class="mt-3 text-xs font-bold text-red-700"></p>
                <div class="mt-4 flex gap-3">
                    <button type="button" @click="$wire.cancelDeleteMessage()" :disabled="deleting" class="flex-1 rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-bold">やめる</button>
                    <button type="button" @click="submitDeletion()" :disabled="deleting" class="flex-1 rounded-lg bg-red-700 px-3 py-2.5 text-sm font-bold text-white disabled:opacity-60">削除する</button>
                </div>
            </div>
            <div x-show="deleting" x-cloak role="status" class="flex items-center justify-center gap-3 py-6 text-sm font-bold">
                <span class="submit-lock-spinner" aria-hidden="true"></span>
                削除中...
            </div>
        </div>
    </div>
@endif
