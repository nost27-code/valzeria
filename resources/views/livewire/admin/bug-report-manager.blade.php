<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-rose-600">BUG REPORTS</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">不具合フォーム</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">ゲーム内から届いた不具合報告を新しい順に確認します。</p>
        </div>
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="冒険者名・本文で検索" class="w-full rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold shadow-sm focus:border-rose-400 focus:ring focus:ring-rose-200 sm:w-80">
    </div>

    <div class="grid gap-4 xl:grid-cols-[24rem_minmax(0,1fr)]">
        <aside class="overflow-hidden rounded-md bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-100 bg-slate-50 p-3">
                <div class="flex flex-wrap gap-1.5">
                    @foreach(['new' => '未確認', 'read' => '確認中', 'resolved' => '対応済み', 'archived' => '保管', 'all' => 'すべて'] as $key => $label)
                        <button type="button" wire:click="$set('status', '{{ $key }}')" class="rounded-md px-2.5 py-1.5 text-[11px] font-black shadow-sm ring-1 transition {{ $status === $key ? 'bg-slate-950 text-white ring-slate-950' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' }}">
                            {{ $label }} {{ number_format($counts[$key] ?? 0) }}
                        </button>
                    @endforeach
                </div>
            </div>
            <div class="divide-y divide-slate-100">
                @forelse($reports as $report)
                    <button type="button" wire:click="selectReport({{ $report->id }})" class="block w-full px-4 py-3 text-left transition hover:bg-slate-50 {{ $selectedReport?->id === $report->id ? 'bg-rose-50' : 'bg-white' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-black text-slate-950">{{ $report->character?->name ?? 'キャラクター不明' }}</div>
                                <div class="mt-1 line-clamp-2 text-xs font-semibold leading-relaxed text-slate-500">{{ $report->body }}</div>
                            </div>
                            <span class="shrink-0 rounded px-2 py-1 text-[10px] font-black {{ $report->status === 'new' ? 'bg-rose-100 text-rose-700' : 'bg-slate-100 text-slate-600' }}">{{ ['new' => '未確認', 'read' => '確認中', 'resolved' => '対応済み', 'archived' => '保管'][$report->status] ?? $report->status }}</span>
                        </div>
                        <div class="mt-2 text-[11px] font-bold text-slate-400">{{ $report->created_at->format('Y/m/d H:i') }} / 画像 {{ $report->attachments->count() }}枚</div>
                    </button>
                @empty
                    <div class="px-4 py-10 text-center text-sm font-bold text-slate-500">不具合報告はありません。</div>
                @endforelse
            </div>
            <div class="border-t border-slate-100 p-3">{{ $reports->links() }}</div>
        </aside>

        <section class="min-h-[30rem] rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200 sm:p-6">
            @if($selectedReport)
                <div class="flex flex-col gap-3 border-b border-slate-100 pb-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-xl font-black text-slate-950">{{ $selectedReport->character?->name ?? 'キャラクター不明' }} の報告</h2>
                        <p class="mt-1 text-xs font-bold text-slate-400">送信 {{ $selectedReport->created_at->format('Y/m/d H:i') }} @if($selectedReport->character?->jobClass) / {{ $selectedReport->character->jobClass->name }} @endif</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <div x-data="{ copied: false, failed: false, copy() { if (!navigator.clipboard?.writeText) { this.failed = true; return; } navigator.clipboard.writeText(@js($codexInvestigationText)).then(() => { this.copied = true; this.failed = false; setTimeout(() => this.copied = false, 2500); }).catch(() => { this.failed = true; this.copied = false; }); } }">
                            <button type="button" x-on:click="copy" class="rounded-md bg-sky-600 px-3 py-2 text-xs font-black text-white shadow-sm hover:bg-sky-700">
                                <span x-show="!copied && !failed">Codex調査用にコピー</span>
                                <span x-cloak x-show="copied">コピーしました</span>
                                <span x-cloak x-show="failed">コピーできませんでした</span>
                            </button>
                        </div>
                        @if($selectedReport->user_id || $selectedReport->character?->user_id)
                            <a href="{{ route('admin.user-investigation', ['user_id' => $selectedReport->user_id ?? $selectedReport->character?->user_id]) }}" target="_blank" rel="noopener" class="rounded-md bg-amber-50 px-3 py-2 text-xs font-black text-amber-800 ring-1 ring-amber-200 hover:bg-amber-100">
                                ユーザー個別調査を開く
                            </a>
                        @endif
                        @foreach(['read' => '確認中にする', 'resolved' => '対応済みにする', 'archived' => '保管する'] as $nextStatus => $label)
                            <button type="button" wire:click="markStatus({{ $selectedReport->id }}, '{{ $nextStatus }}')" class="rounded-md border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-700 hover:bg-slate-50">{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="whitespace-pre-wrap py-5 text-sm font-semibold leading-8 text-slate-800">{{ $selectedReport->body }}</div>
                <p class="-mt-2 pb-5 text-xs font-semibold leading-relaxed text-slate-500">「Codex調査用にコピー」で本文・報告元URL・利用環境をコピーできます。添付画像は必要なものを続けて貼り付けてください。</p>

                <div class="border-t border-slate-100 pt-5">
                    <h3 class="text-sm font-black text-slate-900">管理人から返信</h3>
                    @if(session('status'))
                        <p class="mt-2 rounded-md bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-700">{{ session('status') }}</p>
                    @endif
                    @if($selectedReport->character)
                        <p class="mt-1 text-xs font-semibold leading-relaxed text-slate-500">{{ $selectedReport->character->name }}さんの個人チャットへ「管理人」名義で届きます。冒険者からの返信も、この運営スレッドで確認できます。</p>
                        @if($adminConversation->isNotEmpty())
                            <div class="mt-3 max-h-64 space-y-2 overflow-y-auto rounded-md border border-slate-200 bg-slate-50 p-3">
                                @foreach($adminConversation as $message)
                                    @php($isAdminMessage = $message->type === 'admin_private')
                                    <div class="rounded-md px-3 py-2 text-xs font-semibold leading-relaxed {{ $isAdminMessage ? 'bg-white text-slate-700' : 'bg-sky-50 text-sky-900' }}">
                                        <div class="mb-1 font-black {{ $isAdminMessage ? 'text-slate-900' : 'text-sky-800' }}">{{ $isAdminMessage ? '管理人' : ($message->character?->name ?? '冒険者') }} <span class="ml-1 text-[10px] font-bold text-slate-400">{{ $message->created_at?->format('m/d H:i') }}</span></div>
                                        <div class="whitespace-pre-wrap">{{ $message->message }}</div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        <form wire:submit.prevent="sendReply" class="mt-3">
                            <textarea wire:model="replyMessage" rows="4" maxlength="200" placeholder="不具合フォームへの返答を入力" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold leading-relaxed text-slate-800 focus:border-rose-400 focus:ring focus:ring-rose-200"></textarea>
                            @error('replyMessage') <p class="mt-1 text-xs font-bold text-rose-600">{{ $message }}</p> @enderror
                            <div class="mt-2 flex items-center justify-between gap-3">
                                <span class="text-[11px] font-bold text-slate-400"><span x-data x-text="$wire.replyMessage.length"></span> / 200</span>
                                <button type="submit" wire:loading.attr="disabled" wire:target="sendReply" class="rounded-md bg-slate-950 px-4 py-2 text-xs font-black text-white shadow-sm hover:bg-slate-800 disabled:opacity-60">個人チャットへ送信</button>
                            </div>
                        </form>
                    @else
                        <p class="mt-2 text-xs font-bold text-rose-600">送信者キャラクターが見つからないため、個人チャットへは返信できません。</p>
                    @endif
                </div>

                @if($selectedReport->attachments->isNotEmpty())
                    <div class="border-t border-slate-100 pt-5">
                        <h3 class="text-sm font-black text-slate-900">添付画像</h3>
                        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach($selectedReport->attachments as $attachment)
                                <a href="{{ route('admin.bug-reports.attachments.show', $attachment) }}" target="_blank" rel="noopener" class="overflow-hidden rounded-md border border-slate-200 bg-slate-50 hover:border-rose-300">
                                    <img src="{{ route('admin.bug-reports.attachments.show', $attachment) }}" alt="{{ $attachment->original_name }}" class="h-40 w-full object-cover">
                                    <div class="truncate px-3 py-2 text-xs font-bold text-slate-600">{{ $attachment->original_name }}</div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                <details class="mt-5 rounded-md border border-slate-200 bg-slate-50 p-3">
                    <summary class="cursor-pointer text-xs font-black text-slate-700">調査用の送信情報</summary>
                    <dl class="mt-3 space-y-2 break-all text-xs font-semibold leading-relaxed text-slate-500">
                        <div><dt class="font-black text-slate-700">報告元URL</dt><dd>{{ $selectedReport->reported_url ?: '未取得' }}</dd></div>
                        <div><dt class="font-black text-slate-700">利用環境</dt><dd>{{ $selectedReport->user_agent ?: '未取得' }}</dd></div>
                    </dl>
                </details>
            @else
                <div class="flex h-full items-center justify-center text-sm font-bold text-slate-500">左の一覧から不具合報告を選択してください。</div>
            @endif
        </section>
    </div>
</div>
