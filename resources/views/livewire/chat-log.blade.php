<!-- 3. 下部：全幅チャットログエリア -->
<div wire:poll.60s class="w-full bg-white rounded-xl shadow-[0_8px_22px_rgba(126,96,28,0.18)] border border-[#d4af37] flex flex-col shrink-0 {{ $isExpanded ? 'h-[330px] md:h-[380px]' : 'h-[250px] md:h-[280px]' }} overflow-hidden font-sans">
    <!-- タブ -->
    <div class="flex items-stretch border-b border-gray-200 bg-gray-50 text-[11px] font-sans font-bold text-gray-500 shrink-0">
        <div class="flex min-w-0 flex-1 overflow-x-auto">
            <button wire:click="setTab('all')" class="px-5 py-2 whitespace-nowrap {{ $activeTab === 'all' ? 'bg-white text-[#1e40af] border-t-2 border-[#1e40af]' : 'hover:bg-white border-t-2 border-transparent' }}">全体</button>
            <button wire:click="setTab('system')" class="px-5 py-2 whitespace-nowrap {{ $activeTab === 'system' ? 'bg-white text-[#1e40af] border-t-2 border-[#1e40af]' : 'hover:bg-white border-t-2 border-transparent' }}">システム</button>
            <button wire:click="setTab('chat')" class="px-5 py-2 whitespace-nowrap {{ $activeTab === 'chat' ? 'bg-white text-[#1e40af] border-t-2 border-[#1e40af]' : 'hover:bg-white border-t-2 border-transparent' }}">チャット</button>
            <button wire:click="setTab('private')" class="px-5 py-2 whitespace-nowrap {{ $activeTab === 'private' ? 'bg-white text-[#1e40af] border-t-2 border-[#1e40af]' : 'hover:bg-white border-t-2 border-transparent' }}">個人(手紙)</button>
            <button wire:click="setTab('drop')" class="px-5 py-2 whitespace-nowrap {{ $activeTab === 'drop' ? 'bg-white text-[#1e40af] border-t-2 border-[#1e40af]' : 'hover:bg-white border-t-2 border-transparent text-gray-400' }}">レアドロップ</button>
            <button wire:click="setTab('info')" class="px-5 py-2 whitespace-nowrap {{ $activeTab === 'info' ? 'bg-white text-[#1e40af] border-t-2 border-[#1e40af]' : 'hover:bg-white border-t-2 border-transparent text-gray-400' }}">お知らせ</button>
        </div>
        <button
            wire:click="toggleExpanded"
            type="button"
            class="shrink-0 border-l border-gray-200 bg-white px-3 py-2 text-sm font-black leading-none text-[#1e40af] hover:bg-blue-50"
            aria-label="{{ $isExpanded ? 'チャット欄を短くする' : 'チャットを15行多く表示する' }}"
            title="{{ $isExpanded ? 'チャット欄を短くする' : 'チャットを15行多く表示する' }}"
        >
            {{ $isExpanded ? '▲' : '▼' }}
        </button>
    </div>
    <!-- ログ表示部 -->
    <div class="p-3 flex-grow overflow-y-auto space-y-1 text-[11px] bg-white font-sans leading-relaxed">
        @foreach($systemLogs as $log)
            <div class="flex" wire:key="chat-log-{{ $log['id'] }}">
                <span class="text-gray-400 w-10 shrink-0">{{ $log['time'] }}</span>
                <span class="
                    @if($log['type'] == 'system') text-orange-600 font-bold
                    @elseif($log['type'] == 'chat') text-green-700 font-bold
                    @elseif($log['type'] == 'private')
                        @if(isset($log['is_sender']) && $log['is_sender']) text-slate-900 font-bold
                        @else text-pink-600 font-bold
                        @endif
                    @elseif($log['type'] == 'drop')
                        @if(str_contains($log['message'] ?? '', 'SSSランク') || str_contains($log['message'] ?? '', 'EPICランク')) text-fuchsia-600 font-bold
                        @else text-yellow-600 font-bold
                        @endif
                    @elseif($log['type'] == 'job') text-purple-600 font-bold
                    @elseif($log['type'] == 'arena') text-amber-700 font-bold
                    @elseif($log['type'] == 'duel') text-red-600 font-bold
                    @elseif($log['type'] == 'admin') text-[#1e40af] font-black
                    @elseif($log['type'] == 'guild') text-blue-600 font-bold
                    @elseif($log['type'] == 'valmon') text-teal-600 font-bold
                    @elseif($log['type'] == 'sub_area') text-cyan-600 font-bold
                    @elseif($log['type'] == 'growth') text-gray-700 font-medium
                    @else text-gray-700 font-medium
                    @endif
                ">
                    @if($editingLogId === $log['id'])
                        <form wire:submit="updateMessage" class="inline-flex max-w-full flex-wrap items-center gap-1">
                            @if(isset($log['reply_prefix']) && $log['reply_prefix'])
                                <span>{{ $log['reply_prefix'] }}</span>
                            @endif
                            <input
                                type="text"
                                wire:model="editingMessage"
                                maxlength="100"
                                class="h-7 min-w-[12rem] max-w-full rounded border-gray-300 px-2 py-1 text-[11px] text-slate-800 focus:border-[#1e40af] focus:ring-[#1e40af]"
                            >
                            <button type="submit" class="rounded bg-[#1e40af] px-2 py-1 text-[10px] font-black text-white hover:bg-[#1e3a8a]">保存</button>
                            <button type="button" wire:click="cancelEdit" class="rounded border border-gray-200 bg-white px-2 py-1 text-[10px] font-black text-gray-500 hover:bg-gray-50">やめる</button>
                        </form>
                    @else
                        @if(isset($log['reply_prefix']) && $log['reply_prefix'])
                            @if(isset($log['reply_id']) && $log['reply_id'])
                                <span wire:click="setReplyTarget({{ $log['reply_id'] }})" onclick="setTimeout(() => { document.getElementById('chat-message-input').focus(); }, 100);" class="cursor-pointer hover:underline" title="タップして返信">{{ $log['reply_prefix'] }}</span>
                            @else
                                <span>{{ $log['reply_prefix'] }}</span>
                            @endif
                        @endif
                        {{ $log['message'] }}
                        @if($log['is_edited'])
                            <span class="ml-1 text-[10px] font-bold text-gray-400">修正済み</span>
                        @endif
                        @if($log['can_edit'])
                            <button type="button" wire:click="startEdit({{ $log['id'] }})" class="ml-1 rounded border border-gray-200 bg-white px-1.5 py-0.5 text-[10px] font-black text-gray-500 hover:bg-gray-50">
                                修正
                            </button>
                        @endif
                    @endif
                </span>
            </div>
        @endforeach
        @if($logLimit < \App\Livewire\ChatLog::LOG_MAX)
            <div class="pt-1">
                <button wire:click="loadMore" class="w-full text-center text-[10px] font-bold text-[#1e40af] hover:underline py-0.5">
                    もっとよむ（現在 {{ $logLimit }} 件 / 最大{{ \App\Livewire\ChatLog::LOG_MAX }}件）
                </button>
            </div>
        @endif
    </div>
    <!-- チャット入力欄 -->
    <form wire:submit="sendMessage" class="bg-gray-50 border-t border-gray-200 p-2 flex items-center gap-1.5 shrink-0 min-w-0">
        <select wire:model.live="chatTarget" class="w-[4.75rem] shrink-0 font-sans text-[11px] border-gray-300 rounded py-1.5 pl-2 pr-6 bg-white focus:ring-[#1e40af] text-gray-700">
            <option value="all">全体</option>
            <option value="private">個人</option>
        </select>

        @if($chatTarget === 'private')
            <select wire:model="receiverId" class="w-[7rem] sm:w-[8.25rem] shrink-0 min-w-0 font-sans text-[11px] border-gray-300 rounded py-1.5 pl-2 pr-6 bg-white focus:ring-[#1e40af] text-gray-700 truncate">
                @foreach($availableReceivers as $receiver)
                    <option value="{{ $receiver->id }}">{{ $receiver->name }}</option>
                @endforeach
            </select>
        @endif

        <input type="text" id="chat-message-input" wire:model="message" placeholder="メッセージ"
            required maxlength="100"
            class="min-w-0 basis-0 flex-1 border-gray-300 rounded focus:border-[#1e40af] focus:ring-[#1e40af] text-[11px] py-1.5 px-3">
            
        <button type="submit" aria-label="送信" title="送信" class="w-10 h-9 shrink-0 bg-[#1e40af] text-white rounded-lg text-lg font-bold shadow hover:bg-[#1e3a8a] flex items-center justify-center">
            <span aria-hidden="true">➤</span>
        </button>
    </form>
</div>
