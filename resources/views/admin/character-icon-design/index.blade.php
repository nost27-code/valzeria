<x-layouts.admin>
    <div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
        <div class="mb-6">
            <p class="text-xs font-black tracking-[0.24em] text-violet-600">CHARACTER ICON DESIGN</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">キャラアイコン制作管理</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">提出されたヒアリング内容、候補画像と微調整の専用チャットを管理します。</p>
        </div>

        @if(session('status'))
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-black text-emerald-800">{{ session('status') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-black text-rose-800">{{ session('error') }}</div>
        @endif

        <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-xl font-black text-slate-950">制作依頼一覧</h2>
                    <p class="mt-1 text-sm font-bold text-slate-500">提出された依頼を新しい順に最大100件表示します。プレイヤーの下書きは表示しません。</p>
                </div>
                <form method="GET" action="{{ route('admin.character-icon-design.index') }}" class="flex w-full gap-2 sm:w-auto">
                    <input type="search" name="q" value="{{ $search }}" placeholder="冒険者名で検索" class="min-h-11 min-w-0 flex-1 rounded-lg border border-slate-300 px-3 text-sm font-bold sm:w-64">
                    <button type="submit" class="min-h-11 rounded-lg bg-slate-950 px-4 text-sm font-black text-white">検索</button>
                </form>
            </div>
            <div class="mt-3 w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-black text-slate-600">{{ number_format($designRequests->count()) }}件</div>

            <div class="mt-4 space-y-3">
                @forelse($designRequests as $designRequest)
                    <a href="{{ route('admin.character-icon-design.show', $designRequest) }}" class="block rounded-xl border border-slate-200 bg-slate-50 p-4 transition hover:border-violet-300 hover:bg-violet-50">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-base font-black text-slate-950">{{ $designRequest->character?->name ?? '削除済み冒険者' }}</span>
                                    <span class="rounded-full bg-violet-100 px-2 py-1 text-[11px] font-black text-violet-700">{{ $designRequest->statusLabel() }}</span>
                                    @if($designRequest->unread_player_messages_count > 0)
                                        <span class="rounded-full bg-rose-600 px-2 py-1 text-[11px] font-black text-white">未読返信 {{ number_format($designRequest->unread_player_messages_count) }}</span>
                                    @endif
                                </div>
                                <div class="mt-2 text-xs font-bold text-slate-500">
                                    提出 {{ $designRequest->submitted_at->format('Y/m/d H:i') }}
                                </div>
                            </div>
                            <span class="text-sm font-black text-violet-700">詳細を開く →</span>
                        </div>
                    </a>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 px-4 py-8 text-center text-sm font-bold text-slate-500">制作依頼はまだありません。</div>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.admin>
