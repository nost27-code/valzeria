<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6">
        <p class="text-xs font-black tracking-[0.24em] text-amber-600">ADMIN CHAT</p>
        <h1 class="mt-2 text-3xl font-black text-slate-950">管理人チャット</h1>
        <p class="mt-2 text-sm font-bold text-slate-500">
            ホーム画面下部の全体チャットへ、管理人メッセージとして投稿します。
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
                <h2 class="text-lg font-black text-slate-950">全体チャット</h2>
                <p class="mt-1 text-xs font-bold text-slate-500">個人チャットは表示しません。</p>
            </div>

            <div wire:poll.5s class="h-[360px] overflow-y-auto bg-white px-4 py-3 text-xs leading-relaxed">
                @forelse($logs as $log)
                    <div class="flex gap-2 py-1">
                        <span class="w-11 shrink-0 text-slate-400">{{ $log['time'] }}</span>
                        <span class="min-w-0 break-words
                            @if($log['type'] === 'admin') text-[#1e40af] font-black
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

            <form wire:submit="sendMessage" class="border-t border-slate-200 bg-slate-50 p-3">
                <label class="block">
                    <span class="text-xs font-black text-slate-500">管理人メッセージ</span>
                    <textarea wire:model="message" rows="3" maxlength="160" required placeholder="全体チャットに流す内容を入力"
                              class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#1e40af] focus:ring focus:ring-[#1e40af]/30"></textarea>
                </label>
                @error('message')
                    <div class="mt-2 text-xs font-bold text-red-600">{{ $message }}</div>
                @enderror
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs font-bold text-slate-500">プレイヤー側ではヴァルゼリアブルーで表示されます。</p>
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
