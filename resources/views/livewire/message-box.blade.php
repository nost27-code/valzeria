<div class="flex flex-col text-sm font-sans w-full h-full text-slate-800 pb-20">

    <!-- コンテンツエリア -->
    <div class="w-full mx-auto">
        
        <div class="mb-4 flex items-center justify-between gap-3">
            <button type="button"
                    wire:click="setTab('threads')"
                    class="rounded-lg px-3 py-2 text-sm font-black {{ $activeTab === 'threads' ? 'bg-white text-blue-900 shadow-sm' : 'text-amber-700' }}">
                会話一覧
            </button>
            <button type="button"
                    wire:click="setTab('create')"
                    class="rounded-lg bg-[#1e40af] px-4 py-2 text-sm font-black text-white shadow active:scale-95">
                ＋ 相手を選ぶ
            </button>
        </div>

        <!-- タブコンテンツ -->
        <div class="min-h-[400px]">

            @if($activeTab === 'threads')
                @if($selectedConversation)
                    <div class="overflow-hidden rounded-2xl border shadow-sm"
                         style="border-color: {{ $privateChatTheme['panel_border'] }}; background-color: {{ $privateChatTheme['panel_bg'] }};">
                        <div class="flex items-center gap-3 border-b px-4 py-3"
                             style="border-color: {{ $privateChatTheme['header_border'] }}; background-color: {{ $privateChatTheme['header_bg'] }};">
                            <button type="button"
                                    wire:click="backToConversationList"
                                    class="flex h-9 w-9 items-center justify-center rounded-full border bg-white text-lg font-black shadow-sm active:scale-95"
                                    style="border-color: {{ $privateChatTheme['accent'] }}; color: {{ $privateChatTheme['accent'] }};">
                                ‹
                            </button>
                            <div class="h-11 w-11 shrink-0 overflow-hidden rounded-full border border-amber-200 bg-white">
                                <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($selectedConversation->icon_path ?? 'images/chara/chara_001.webp') }}" class="h-full w-full object-contain" alt="icon">
                            </div>
                            <div class="min-w-0">
                                <div class="truncate text-base font-black text-blue-950">{{ $selectedConversation->name }}</div>
                                <div class="text-[11px] font-bold text-slate-500">個人チャット</div>
                            </div>
                        </div>

                        <div wire:poll.5s class="flex max-h-[62vh] min-h-[360px] flex-col gap-3 overflow-y-auto px-3 py-4"
                             style="background-color: {{ $privateChatTheme['thread_bg'] }};">
                            @forelse($threadMessages as $msg)
                                @php($isMine = (int) $msg->character_id === (int) $character->id)
                                <div class="flex {{ $isMine ? 'justify-end' : 'justify-start' }}">
                                    <div class="flex max-w-[82%] items-end gap-2 {{ $isMine ? 'flex-row-reverse' : '' }}">
                                        @unless($isMine)
                                            <div class="h-8 w-8 shrink-0 overflow-hidden rounded-full border border-white bg-white shadow-sm">
                                                <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($msg->character?->icon_path ?? 'images/chara/chara_001.webp') }}" class="h-full w-full object-contain" alt="icon">
                                            </div>
                                        @endunless
                                        <div>
                                            <div class="rounded-2xl px-4 py-2.5 text-sm font-bold leading-relaxed shadow-sm {{ $isMine ? 'rounded-br-sm' : 'rounded-bl-sm' }}"
                                                 style="background-color: {{ $isMine ? $privateChatTheme['own_bubble_bg'] : $privateChatTheme['partner_bubble_bg'] }}; color: {{ $isMine ? $privateChatTheme['own_bubble_text'] : $privateChatTheme['partner_bubble_text'] }};">
                                                <div class="whitespace-pre-wrap">{{ $msg->message }}</div>
                                            </div>
                                            <div class="mt-1 text-[10px] font-bold text-slate-400 {{ $isMine ? 'text-right' : 'text-left' }}">
                                                {{ $msg->created_at?->format('m/d H:i') }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="flex flex-1 items-center justify-center text-xs font-bold text-slate-500">
                                    まだ会話はありません
                                </div>
                            @endforelse
                        </div>

                        <form wire:submit.prevent="confirmMessage" class="border-t px-3 py-3"
                              style="border-color: {{ $privateChatTheme['input_border'] }}; background-color: {{ $privateChatTheme['input_bg'] }};">
                            <div class="flex items-end gap-2">
                                <textarea wire:model="message"
                                          required
                                          rows="2"
                                          maxlength="200"
                                          placeholder="{{ $selectedConversation->name }}さんへ返信..."
                                          class="min-h-[44px] flex-1 resize-none rounded-2xl border border-slate-300 bg-slate-50 px-4 py-2.5 text-sm font-bold text-slate-800 focus:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-300"></textarea>
                                <button type="submit"
                                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-lg font-black shadow active:scale-95"
                                        style="background-color: {{ $privateChatTheme['own_bubble_bg'] }}; color: {{ $privateChatTheme['own_bubble_text'] }};">
                                    ➤
                                </button>
                            </div>
                            <div class="mt-1 flex items-center justify-between px-2">
                                @error('message') <span class="text-xs font-bold text-rose-500">{{ $message }}</span> @enderror
                                @error('receiverId') <span class="text-xs font-bold text-rose-500">{{ $message }}</span> @enderror
                                <span class="ml-auto text-[10px] font-bold text-slate-400"><span x-data x-text="$wire.message.length"></span> / 200</span>
                            </div>
                        </form>

                        @if($showSendConfirm)
                            <div class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/55 px-4 py-6">
                                <div class="w-full max-w-md overflow-hidden rounded-2xl border-2 border-amber-300 bg-white shadow-2xl">
                                    <div class="border-b border-amber-100 bg-amber-50 px-5 py-4">
                                        <div class="text-base font-black text-slate-900">この内容でメッセージを送りますか？</div>
                                        <div class="mt-1 text-xs font-bold text-slate-500">送信後は取り消せません。</div>
                                    </div>

                                    <div class="space-y-4 px-5 py-5">
                                        <div>
                                            <div class="mb-1 text-[11px] font-black tracking-wide text-slate-400">宛先</div>
                                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-black text-blue-900">
                                                {{ $confirmReceiverName }}
                                            </div>
                                        </div>

                                        <div>
                                            <div class="mb-1 text-[11px] font-black tracking-wide text-slate-400">本文</div>
                                            <div class="max-h-48 overflow-y-auto whitespace-pre-wrap rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-bold leading-relaxed text-slate-700">{{ $message }}</div>
                                        </div>
                                    </div>

                                    <div class="flex gap-3 border-t border-slate-100 bg-slate-50 px-5 py-4">
                                        <button type="button"
                                                wire:click="cancelSendConfirm"
                                                class="flex-1 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-black text-slate-700 shadow-sm active:scale-95">
                                            修正する
                                        </button>
                                        <button type="button"
                                                wire:click="sendMessage"
                                                wire:loading.attr="disabled"
                                                wire:target="sendMessage"
                                                class="flex-1 rounded-lg border-2 px-4 py-2.5 text-sm font-black shadow active:scale-95 disabled:opacity-60"
                                                style="border-color: {{ $privateChatTheme['own_bubble_bg'] }}; background-color: {{ $privateChatTheme['own_bubble_bg'] }}; color: {{ $privateChatTheme['own_bubble_text'] }};">
                                            この内容で送る
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                @else
                    <div wire:poll.5s class="flex flex-col gap-3">
                        @forelse($conversations as $conversation)
                            @php($partner = $conversation['partner'])
                            <button type="button"
                                    wire:click="openConversation({{ $conversation['partner_id'] }})"
                                    class="flex w-full items-center gap-3 rounded-2xl border border-amber-200 bg-white px-4 py-3 text-left shadow-sm transition active:scale-[.99]">
                                <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-full border border-amber-200 bg-slate-50">
                                    <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($partner->icon_path ?? 'images/chara/chara_001.webp') }}" class="h-12 w-12 object-contain drop-shadow-sm" alt="icon">
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="truncate text-base font-black text-blue-950">{{ $partner->name }}</div>
                                        <div class="shrink-0 text-[11px] font-bold text-slate-400">{{ $conversation['last_at']?->format('m/d H:i') }}</div>
                                    </div>
                                    <div class="mt-1 truncate text-sm font-bold text-slate-500">
                                        @if($conversation['is_mine'])
                                            <span class="text-slate-400">自分: </span>
                                        @endif
                                        {{ $conversation['last_message'] }}
                                    </div>
                                </div>
                            </button>
                        @empty
                            <div class="flex flex-col items-center justify-center py-16 text-slate-400 bg-white/50 rounded-2xl border border-dashed border-amber-300">
                                <span class="text-4xl mb-3 opacity-50">📭</span>
                                <span class="font-bold">会話はありません</span>
                            </div>
                        @endforelse
                    </div>
                @endif
            @endif

            @if($activeTab === 'create')
                <div class="flex flex-col gap-3">
                    <div class="rounded-2xl border border-amber-200 bg-white px-4 py-3 shadow-sm">
                        <label class="mb-2 block text-xs font-black text-amber-700">名前検索</label>
                        <input type="search"
                               wire:model.live.debounce.400ms="receiverSearch"
                               placeholder="プレイヤー名で検索"
                               class="w-full rounded-xl border border-slate-300 bg-slate-50 px-4 py-2.5 text-sm font-bold text-slate-800 outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-200">
                        <div class="mt-2 text-[11px] font-bold text-slate-400">
                            未入力時はログイン/更新が新しい冒険者を最大100人表示します
                        </div>
                    </div>

                    @forelse($availableReceivers as $receiver)
                        @php($latestConversation = $latestConversationByPartner->get((int) $receiver->id))
                        <button type="button"
                                wire:click="openConversation({{ $receiver->id }})"
                                class="flex w-full items-center gap-3 rounded-2xl border border-amber-200 bg-white px-4 py-3 text-left shadow-sm transition active:scale-[.99]">
                            <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-full border border-amber-200 bg-slate-50">
                                <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($receiver->icon_path ?? 'images/chara/chara_001.webp') }}" class="h-12 w-12 object-contain drop-shadow-sm" alt="icon">
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-base font-black text-blue-950">{{ $receiver->name }}</div>
                                <div class="mt-1 truncate text-xs font-bold text-slate-500">
                                    @if($latestConversation)
                                        @if($latestConversation['is_mine'])
                                            <span class="text-slate-400">自分: </span>
                                        @endif
                                        {{ $latestConversation['last_message'] }}
                                    @else
                                        会話を開く
                                    @endif
                                </div>
                            </div>
                        </button>
                    @empty
                        <div class="flex flex-col items-center justify-center py-16 text-slate-400 bg-white/50 rounded-2xl border border-dashed border-amber-300">
                            <span class="text-4xl mb-3 opacity-50">👤</span>
                            <span class="font-bold">選択できる冒険者がいません</span>
                        </div>
                    @endforelse
                </div>
            @endif

        </div>
    </div>
</div>
