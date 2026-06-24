<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">CONTACT INBOX</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">お問い合わせ受信箱</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">info@valzeria.com 宛のお問い合わせフォーム送信とメール受信を確認します。</p>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <button type="button"
                    wire:click="importMailbox"
                    wire:loading.attr="disabled"
                    class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-black text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                <span wire:loading.remove>メール取り込み</span>
                <span wire:loading>取り込み中...</span>
            </button>
            <input type="text"
                   wire:model.live.debounce.300ms="search"
                   placeholder="差出人・件名・本文で検索"
                   class="w-full rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30 sm:w-80">
        </div>
    </div>

    @if($importMessage)
        <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-black text-amber-900">
            {{ $importMessage }}
        </div>
    @endif

    @if($replyMessage)
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-black text-emerald-900">
            {{ $replyMessage }}
        </div>
    @endif

    <div class="mb-5 rounded-md bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-lg font-black text-slate-950">新規メール作成</h2>
                <p class="mt-1 text-xs font-bold text-slate-500">登録プレイヤーを検索して、info@valzeria.com から個別にメールを送信します。</p>
            </div>
            @if($selectedComposeCharacter)
                <div class="rounded bg-emerald-50 px-3 py-2 text-xs font-black text-emerald-800 ring-1 ring-emerald-100">
                    宛先: {{ $selectedComposeCharacter->name }} / {{ $selectedComposeCharacter->user?->email }}
                </div>
            @endif
        </div>

        @if($composeMessage)
            <div class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-black text-amber-900">
                {{ $composeMessage }}
            </div>
        @endif

        <form wire:submit="sendNewMail" class="grid gap-3 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1fr)]">
            <div class="space-y-3">
                <div>
                    <label class="text-xs font-black text-slate-600">送信先プレイヤー</label>
                    <input type="text"
                           wire:model.live.debounce.300ms="composeSearch"
                           placeholder="プレイヤー名・メール・IDで検索"
                           class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                    @error('composeCharacterId')
                        <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>
                    @enderror
                </div>

                @if($composeCandidates->isNotEmpty())
                    <div class="max-h-56 overflow-y-auto rounded-md border border-slate-200 bg-slate-50 p-2">
                        @foreach($composeCandidates as $candidate)
                            <button type="button"
                                    wire:click="selectComposeRecipient({{ $candidate->id }})"
                                    class="mb-1 block w-full rounded bg-white px-3 py-2 text-left text-sm shadow-sm ring-1 ring-slate-100 transition hover:bg-amber-50">
                                <span class="font-black text-slate-900">{{ $candidate->name }}</span>
                                <span class="ml-2 text-xs font-bold text-slate-500">Lv{{ $candidate->level ?? '-' }} / {{ $candidate->user?->email ?? 'メールなし' }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif

                <div>
                    <label class="text-xs font-black text-slate-600">件名</label>
                    <input type="text"
                           wire:model.defer="composeSubject"
                           maxlength="160"
                           placeholder="件名"
                           class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                    @error('composeSubject')
                        <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="space-y-3">
                <div>
                    <label class="text-xs font-black text-slate-600">本文</label>
                    <textarea wire:model.defer="composeBody"
                              rows="8"
                              maxlength="5000"
                              class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold leading-7 text-slate-800 shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30"></textarea>
                    @error('composeBody')
                        <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>
                    @enderror
                </div>
                <button type="submit"
                        wire:loading.attr="disabled"
                        wire:target="sendNewMail"
                        class="inline-flex min-h-11 items-center justify-center rounded-md bg-slate-950 px-5 text-sm font-black text-white shadow hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                    <span wire:loading.remove wire:target="sendNewMail">新規メールを送信</span>
                    <span wire:loading wire:target="sendNewMail">送信中...</span>
                </button>
            </div>
        </form>

        @if($recentAdminMails->isNotEmpty())
            <div class="mt-4 border-t border-slate-100 pt-3">
                <div class="mb-2 text-xs font-black text-slate-500">最近の新規送信</div>
                <div class="space-y-2">
                    @foreach($recentAdminMails as $mail)
                        <div class="rounded bg-slate-50 px-3 py-2 text-xs font-bold text-slate-600">
                            <span class="font-black text-slate-900">{{ $mail->subject }}</span>
                            <span class="mx-1">/</span>
                            <span>{{ $mail->character?->name ?? 'キャラ不明' }} &lt;{{ $mail->to_email }}&gt;</span>
                            <span class="mx-1">/</span>
                            <span>{{ ($mail->sent_at ?? $mail->created_at)?->format('Y/m/d H:i') }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach([
            'new' => '未読',
            'read' => '対応中',
            'replied' => '返信済み',
            'archived' => '保管',
            'all' => 'すべて',
        ] as $key => $label)
            <button type="button"
                    wire:click="$set('status', '{{ $key }}')"
                    class="rounded-md px-3 py-2 text-xs font-black shadow-sm ring-1 transition {{ $status === $key ? 'bg-slate-950 text-white ring-slate-950' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' }}">
                {{ $label }} {{ number_format($counts[$key] ?? 0) }}
            </button>
        @endforeach
    </div>

    <div class="grid gap-4 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
        <div class="overflow-hidden rounded-md bg-white shadow-sm ring-1 ring-slate-200">
            @forelse($messages as $message)
                <button type="button"
                        wire:click="selectMessage({{ $message->id }})"
                        class="block w-full border-b border-slate-100 px-4 py-3 text-left transition hover:bg-slate-50 {{ $selectedMessage?->id === $message->id ? 'bg-amber-50' : 'bg-white' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-black text-slate-950">{{ $message->subject }}</div>
                            <div class="mt-1 truncate text-xs font-bold text-slate-500">{{ $message->sender_name ?: '名前なし' }} / {{ $message->sender_email }}</div>
                        </div>
                        <span class="shrink-0 rounded px-2 py-1 text-[11px] font-black {{ $message->status === 'new' ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-600' }}">
                            {{ ['new' => '未読', 'read' => '対応中', 'replied' => '返信済み', 'archived' => '保管'][$message->status] ?? $message->status }}
                        </span>
                    </div>
                    <div class="mt-2 line-clamp-2 text-xs font-semibold leading-relaxed text-slate-500">{{ $message->body }}</div>
                    <div class="mt-2 flex flex-wrap gap-2 text-[11px] font-bold text-slate-400">
                        <span>{{ ($message->received_at ?? $message->created_at)?->format('Y/m/d H:i') }}</span>
                        <span>{{ $message->source === 'pop3' ? 'メール受信' : 'フォーム' }}</span>
                    </div>
                </button>
            @empty
                <div class="px-4 py-10 text-center text-sm font-bold text-slate-500">お問い合わせはありません。</div>
            @endforelse

            <div class="px-4 py-3">
                {{ $messages->links() }}
            </div>
        </div>

        <div class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
            @if($selectedMessage)
                <div class="flex flex-col gap-3 border-b border-slate-100 pb-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="text-xs font-black tracking-widest text-amber-600">{{ strtoupper($selectedMessage->category) }}</div>
                        <h2 class="mt-1 break-words text-xl font-black text-slate-950">{{ $selectedMessage->subject }}</h2>
                        <div class="mt-2 text-sm font-bold text-slate-600">
                            {{ $selectedMessage->sender_name ?: '名前なし' }} &lt;{{ $selectedMessage->sender_email }}&gt;
                        </div>
                        <div class="mt-1 text-xs font-bold text-slate-400">
                            宛先 {{ $selectedMessage->recipient_email }} / {{ ($selectedMessage->received_at ?? $selectedMessage->created_at)?->format('Y/m/d H:i') }} / {{ $selectedMessage->source === 'pop3' ? 'メール受信' : 'フォーム' }}
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                        <button type="button" wire:click="markStatus({{ $selectedMessage->id }}, 'read')" class="rounded bg-slate-100 px-3 py-2 text-xs font-black text-slate-700 hover:bg-slate-200">対応中</button>
                        <button type="button" wire:click="markStatus({{ $selectedMessage->id }}, 'replied')" class="rounded bg-emerald-600 px-3 py-2 text-xs font-black text-white hover:bg-emerald-700">返信済み</button>
                        <button type="button" wire:click="markStatus({{ $selectedMessage->id }}, 'archived')" class="rounded bg-slate-800 px-3 py-2 text-xs font-black text-white hover:bg-slate-700">保管</button>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 text-sm md:grid-cols-2">
                    <div class="rounded-md bg-slate-50 px-3 py-2">
                        <div class="text-xs font-black text-slate-500">ユーザー</div>
                        <div class="mt-1 font-bold text-slate-800">{{ $selectedMessage->user?->email ?? '未ログイン/未紐づけ' }}</div>
                    </div>
                    <div class="rounded-md bg-slate-50 px-3 py-2">
                        <div class="text-xs font-black text-slate-500">キャラクター</div>
                        <div class="mt-1 font-bold text-slate-800">{{ $selectedMessage->character?->name ?? '-' }}</div>
                    </div>
                </div>

                <div class="mt-4 whitespace-pre-wrap rounded-md border border-slate-200 bg-slate-50 px-4 py-4 text-sm font-semibold leading-8 text-slate-800">{{ $selectedMessage->body }}</div>

                @if($selectedMessage->attachment_path)
                <div class="mt-3">
                    <div class="mb-1 text-xs font-black text-slate-500">添付画像</div>
                    <a href="{{ asset($selectedMessage->attachment_path) }}" target="_blank" rel="noopener">
                        <img src="{{ asset($selectedMessage->attachment_path) }}"
                             alt="添付画像"
                             class="max-h-80 max-w-full rounded-md border border-slate-200 object-contain shadow-sm hover:opacity-90">
                    </a>
                </div>
                @endif

                <div class="mt-4 rounded-md border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="text-sm font-black text-slate-950">返信メール</div>
                            <div class="mt-0.5 text-xs font-bold text-slate-500">info@valzeria.com から {{ $selectedMessage->sender_email }} へ送信します。</div>
                        </div>
                        <a class="text-xs font-black text-amber-700 underline" href="mailto:{{ $selectedMessage->sender_email }}?subject=Re:%20{{ rawurlencode($selectedMessage->subject) }}">メールソフトで返信</a>
                    </div>
                    <form wire:submit="sendReply" class="space-y-3">
                        <textarea wire:model.defer="replyBody"
                                  rows="9"
                                  maxlength="5000"
                                  class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold leading-7 text-slate-800 shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30"></textarea>
                        @error('replyBody')
                            <div class="text-xs font-bold text-red-600">{{ $message }}</div>
                        @enderror
                        <button type="submit"
                                wire:loading.attr="disabled"
                                class="inline-flex min-h-11 items-center justify-center rounded-md bg-emerald-700 px-5 text-sm font-black text-white shadow hover:bg-emerald-800 disabled:cursor-wait disabled:opacity-60">
                            <span wire:loading.remove>返信を送信</span>
                            <span wire:loading>送信中...</span>
                        </button>
                    </form>
                </div>

                @if($selectedMessage->replies->isNotEmpty())
                    <div class="mt-4 space-y-3">
                        <h3 class="text-sm font-black text-slate-950">返信履歴</h3>
                        @foreach($selectedMessage->replies->sortByDesc('created_at') as $reply)
                            <div class="rounded-md border border-emerald-100 bg-emerald-50/70 px-4 py-3">
                                <div class="flex flex-wrap justify-between gap-2 text-xs font-black text-emerald-800">
                                    <span>{{ $reply->subject }}</span>
                                    <span>{{ ($reply->sent_at ?? $reply->created_at)?->format('Y/m/d H:i') }}</span>
                                </div>
                                <div class="mt-1 text-xs font-bold text-slate-500">
                                    {{ $reply->from_email }} → {{ $reply->to_email }} / {{ $reply->adminUser?->email ?? '管理者' }}
                                </div>
                                <div class="mt-2 whitespace-pre-wrap text-sm font-semibold leading-7 text-slate-700">{{ $reply->body }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            @else
                <div class="py-16 text-center text-sm font-bold text-slate-500">左の一覧からお問い合わせを選択してください。</div>
            @endif
        </div>
    </div>
</div>
