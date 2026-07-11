<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6">
        <p class="text-xs font-black tracking-[0.24em] text-amber-600">ADMIN CHAT</p>
        <h1 class="mt-2 text-3xl font-black text-slate-950">管理人チャット</h1>
        <p class="mt-2 text-sm font-bold text-slate-500">
            ホーム画面下部の全体チャットへ、管理人メッセージまたはお知らせとして投稿します。
        </p>
    </div>

    @if(session('status'))
        <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <section class="overflow-hidden rounded-md bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 px-4 py-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-black text-slate-950">全体チャット</h2>
                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-black text-slate-500">最新{{ number_format($logLimit) }}件</span>
                </div>
                <p class="mt-1 text-xs font-bold text-slate-500">個人チャットは表示しません。</p>
            </div>

            <div wire:poll.5s class="h-[360px] overflow-y-auto bg-white px-4 py-3 text-xs leading-relaxed">
                @forelse($logs as $log)
                    <div class="flex gap-2 py-1">
                        <span class="w-11 shrink-0 text-slate-400">{{ $log['time'] }}</span>
                        <span class="min-w-0 break-words
                            @if($log['type'] === 'admin') text-[#1e40af] font-black
                            @elseif($log['type'] === 'notice') text-cyan-700 font-black
                            @elseif($log['type'] === 'system') text-orange-600 font-bold
                            @elseif($log['type'] === 'chat') text-green-700 font-bold
                            @elseif($log['type'] === 'drop') text-yellow-600 font-bold
                            @elseif($log['type'] === 'job') text-purple-600 font-bold
                            @elseif($log['type'] === 'arena') text-amber-700 font-bold
                            @elseif($log['type'] === 'duel') text-red-600 font-bold
                            @elseif($log['type'] === 'guild') text-blue-600 font-bold
                            @elseif($log['type'] === 'valmon') text-teal-600 font-bold
                            @elseif($log['type'] === 'sub_area') text-cyan-600 font-bold
                            @elseif($log['type'] === 'growth') text-slate-700 font-semibold
                            @else text-slate-700 font-semibold
                            @endif
                        ">
                            @if($log['type'] === 'admin')
                                【管理人】{{ $log['message'] }}
                            @elseif($log['type'] === 'notice')
                                【お知らせ】{{ $log['message'] }}
                            @elseif(in_array($log['type'], ['chat', 'guild'], true))
                                【{{ $log['name'] ?? '名無し' }}】{{ $log['message'] }}
                            @else
                                {{ $log['message'] }}
                            @endif
                        </span>
                    </div>
                @empty
                    <div class="py-10 text-center text-sm font-bold text-slate-400">表示できるログがありません。</div>
                @endforelse
            </div>
            <div class="flex items-center justify-center border-t border-slate-100 bg-white px-4 py-2">
                @if($canLoadMoreLogs)
                    <button type="button"
                            wire:click="loadMoreLogs"
                            class="rounded-md border border-slate-200 bg-slate-50 px-4 py-2 text-xs font-black text-slate-700 shadow-sm hover:bg-slate-100">
                        さらに遡る
                    </button>
                @else
                    <span class="text-[11px] font-bold text-slate-400">これ以上表示できるログはありません</span>
                @endif
            </div>

            <form wire:submit="sendMessage" class="border-t border-slate-200 bg-slate-50 p-3">
                <div class="mb-3">
                    <span class="text-xs font-black text-slate-500">投稿モード</span>
                    <div class="mt-1 grid grid-cols-2 gap-2">
                        <label class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-xs font-black shadow-sm {{ $messageType === 'admin' ? 'border-[#1e40af] bg-blue-50 text-[#1e40af]' : 'border-slate-200 bg-white text-slate-600' }}">
                            <input type="radio" wire:model.live="messageType" value="admin" class="border-slate-300 text-[#1e40af] focus:ring-[#1e40af]">
                            <span>管理人</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-xs font-black shadow-sm {{ $messageType === 'notice' ? 'border-cyan-500 bg-cyan-50 text-cyan-700' : 'border-slate-200 bg-white text-slate-600' }}">
                            <input type="radio" wire:model.live="messageType" value="notice" class="border-slate-300 text-cyan-600 focus:ring-cyan-500">
                            <span>お知らせ</span>
                        </label>
                    </div>
                    @error('messageType')
                        <div class="mt-2 text-xs font-bold text-red-600">{{ $message }}</div>
                    @enderror
                </div>
                <label class="block">
                    <span class="text-xs font-black text-slate-500">{{ $messageType === 'notice' ? 'お知らせ' : '管理人メッセージ' }}</span>
                    <textarea wire:model="message" rows="3" maxlength="160" required placeholder="全体チャットに流す内容を入力"
                              class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#1e40af] focus:ring focus:ring-[#1e40af]/30"></textarea>
                </label>
                @error('message')
                    <div class="mt-2 text-xs font-bold text-red-600">{{ $message }}</div>
                @enderror
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs font-bold text-slate-500">
                        プレイヤー側では{{ $messageType === 'notice' ? '【お知らせ】として表示されます。' : '【管理人】としてヴァルゼリアブルーで表示されます。' }}
                    </p>
                    <button type="submit" class="rounded-md bg-[#1e40af] px-5 py-2.5 text-sm font-black text-white shadow hover:bg-[#1e3a8a]">
                        送信する
                    </button>
                </div>
            </form>
        </section>

        <aside class="rounded-md bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-black text-slate-950">現在の冒険者</h2>
                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-black text-emerald-700">{{ $onlineCharacters->count() }}人</span>
            </div>
            <p class="mt-1 text-xs font-bold text-slate-500">直近{{ $onlineWindowMinutes }}分の活動キャラ</p>

            <div wire:poll.10s class="mt-4 flex max-h-[470px] flex-wrap content-start gap-2 overflow-y-auto">
                @forelse($onlineCharacters as $character)
                    <span class="rounded-full border border-emerald-100 bg-emerald-50 px-3 py-1 text-xs font-black text-slate-800">
                        {{ $character->name }}
                    </span>
                @empty
                    <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-bold text-slate-500">
                        現在、街に冒険者はいません。
                    </div>
                @endforelse
            </div>
        </aside>
    </div>
</div>
