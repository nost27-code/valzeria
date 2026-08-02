<div class="w-full px-4 sm:px-6 lg:px-8 py-8 lg:py-10">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between mb-6">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">TOWN UPDATES</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">街の更新履歴管理</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">街ヘッダのお知らせと、ゲーム内の更新履歴モーダルを編集できます。</p>
        </div>
        <button type="button" wire:click="createNew" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-black text-white shadow hover:bg-slate-800">
            新規作成
        </button>
    </div>

    @if(session('status'))
        <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    <div class="mb-5 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm font-bold leading-relaxed text-blue-800">
        管理ダッシュボード用の更新サマリから、プレイヤー向け候補を非公開の下書きとして自動作成します。
        文言を整えて「表示する」ボタンを押した項目だけが街へ公開されます。不要な候補は「削除」を選んでください。
    </div>

    <section class="mb-6 overflow-hidden rounded-md bg-white shadow-sm ring-2 ring-amber-300">
        <div class="flex flex-col gap-1 border-b border-amber-200 bg-amber-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-black text-slate-950">街に表示中</h2>
                <p class="mt-0.5 text-xs font-bold text-amber-800">上から順に街のお知らせへ表示されます。▲▼で1件ずつ移動できます。</p>
            </div>
            <span class="text-xs font-black text-amber-700">{{ $activeUpdates->count() }}件</span>
        </div>

        <div class="divide-y divide-slate-100">
            @forelse($activeUpdates as $update)
                <article class="grid gap-3 px-4 py-3 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-center {{ $editingId === $update->id ? 'bg-amber-50' : 'bg-white' }}">
                    <div class="flex items-center gap-2">
                        <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-amber-100 text-xs font-black text-amber-800">{{ $loop->iteration }}</span>
                        <div class="flex gap-1">
                            <button type="button"
                                    wire:click="moveActive({{ $update->id }}, 'up')"
                                    wire:loading.attr="disabled"
                                    wire:target="moveActive"
                                    @disabled($loop->first)
                                    class="grid h-8 w-8 place-items-center rounded-md border border-slate-300 bg-white text-sm font-black text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-30"
                                    aria-label="{{ $update->body }}を上へ移動">▲</button>
                            <button type="button"
                                    wire:click="moveActive({{ $update->id }}, 'down')"
                                    wire:loading.attr="disabled"
                                    wire:target="moveActive"
                                    @disabled($loop->last)
                                    class="grid h-8 w-8 place-items-center rounded-md border border-slate-300 bg-white text-sm font-black text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-30"
                                    aria-label="{{ $update->body }}を下へ移動">▼</button>
                        </div>
                    </div>

                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <time class="text-[11px] font-black text-slate-500">{{ $update->published_on?->format('Y/m/d') }}</time>
                            @if($update->source_key)
                                <span class="rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 text-[10px] font-black text-blue-700">自動生成</span>
                            @endif
                        </div>
                        <div class="mt-1 break-words text-sm font-black leading-relaxed text-slate-900">{{ $update->body }}</div>
                        @if(filled($update->detail))
                            <div class="mt-1 line-clamp-2 break-words text-xs font-bold leading-relaxed text-slate-500">{{ $update->detail }}</div>
                        @endif
                    </div>

                    <div class="flex flex-wrap justify-end gap-2">
                        <button type="button" wire:click="edit({{ $update->id }})" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-black text-slate-700 hover:bg-slate-50">編集</button>
                        <button type="button"
                                wire:click="toggleActive({{ $update->id }})"
                                wire:loading.attr="disabled"
                                wire:target="toggleActive"
                                class="rounded-md border border-slate-300 bg-slate-100 px-3 py-1.5 text-xs font-black text-slate-700 hover:bg-slate-200 disabled:opacity-50">
                            表示をやめる
                        </button>
                    </div>
                </article>
            @empty
                <div class="px-4 py-8 text-center text-sm font-bold text-slate-500">現在、街に表示している更新情報はありません。</div>
            @endforelse
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
        <form wire:submit="save" class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-lg font-black text-slate-950">{{ $editingId ? '更新情報を編集' : '更新情報を追加' }}</h2>

            <div class="mt-5 space-y-4">
                <div>
                    <label class="mb-1 block text-xs font-black text-slate-500">公開日</label>
                    <input type="date" wire:model="form.published_on" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                    @error('form.published_on') <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-xs font-black text-slate-500">街のお知らせ文</label>
                    <input type="text" wire:model="form.body" maxlength="255" placeholder="例: ヴァルモン牧場を更新しました" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                    <p class="mt-1 text-[11px] font-bold text-slate-400">街ヘッダでは、最新3件を［1/3］形式で表示します。</p>
                    @error('form.body') <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-xs font-black text-slate-500">モーダル内の詳細文</label>
                    <textarea wire:model="form.detail"
                              rows="5"
                              maxlength="2000"
                              placeholder="更新内容の詳しい説明。空欄でも保存できます。"
                              class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold leading-relaxed shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30"></textarea>
                    @error('form.detail') <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div> @enderror
                </div>

                <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold leading-relaxed text-slate-600">
                    新規追加した項目は下書きとして保存されます。表示・非表示と並び順は「街に表示中」から変更できます。
                </div>
            </div>

            <div class="mt-5 flex gap-2">
                <button type="submit" wire:loading.attr="disabled" wire:target="save" class="flex-1 rounded-md bg-amber-500 px-4 py-2.5 text-sm font-black text-slate-950 shadow hover:bg-amber-400 disabled:cursor-wait disabled:opacity-60">
                    <span wire:loading.remove wire:target="save">保存する</span>
                    <span wire:loading wire:target="save">保存中...</span>
                </button>
                @if($editingId)
                    <button type="button" wire:click="createNew" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-black text-slate-700 hover:bg-slate-50">
                        解除
                    </button>
                @endif
            </div>
        </form>

        <div class="overflow-hidden rounded-md bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 px-4 py-3">
                <h2 class="text-lg font-black text-slate-950">更新候補・下書き</h2>
            </div>
            <div class="xl:overflow-x-auto">
                <table class="block min-w-0 text-sm xl:table xl:min-w-full xl:divide-y xl:divide-slate-200">
                    <thead class="hidden bg-slate-50 text-slate-700 xl:table-header-group">
                        <tr>
                            <th class="px-4 py-3 text-left font-bold">公開日</th>
                            <th class="px-4 py-3 text-left font-bold">表示内容</th>
                            <th class="px-4 py-3 text-center font-bold">状態</th>
                            <th class="px-4 py-3 text-right font-bold">操作</th>
                        </tr>
                    </thead>
                    <tbody class="block space-y-3 bg-slate-50 p-3 xl:table-row-group xl:space-y-0 xl:divide-y xl:divide-slate-100 xl:bg-white xl:p-0">
                        @forelse($draftUpdates as $update)
                            <tr class="grid grid-cols-2 gap-x-3 gap-y-4 rounded-md border border-slate-200 p-4 shadow-sm xl:table-row xl:rounded-none xl:border-0 xl:p-0 xl:shadow-none {{ $editingId === $update->id ? 'bg-amber-50' : 'bg-white xl:hover:bg-slate-50' }}">
                                <td class="whitespace-nowrap font-black text-slate-900 xl:px-4 xl:py-3">
                                    <span class="mb-1 block text-[10px] font-black text-slate-400 xl:hidden">公開日</span>
                                    {{ $update->published_on?->format('Y/m/d') }}
                                </td>
                                <td class="col-span-2 min-w-0 border-t border-slate-100 pt-3 xl:table-cell xl:border-0 xl:px-4 xl:py-3">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @if($update->source_key)
                                            <span class="rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 text-[10px] font-black text-blue-700">自動生成</span>
                                        @else
                                            <span class="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-black text-slate-600">手動作成</span>
                                        @endif
                                    </div>
                                    <div class="mt-2 break-words font-black leading-relaxed text-slate-800 xl:mt-1">{{ $update->body }}</div>
                                    @if(filled($update->detail))
                                        <div class="mt-1 line-clamp-3 break-words text-xs font-bold leading-relaxed text-slate-500 xl:line-clamp-2">{{ $update->detail }}</div>
                                    @endif
                                </td>
                                <td class="text-right xl:px-4 xl:py-3 xl:text-center">
                                    <span class="mb-1 block text-[10px] font-black text-slate-400 xl:hidden">状態</span>
                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-black text-slate-500">下書き</span>
                                </td>
                                <td class="col-span-2 flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-3 xl:table-cell xl:whitespace-nowrap xl:border-0 xl:px-4 xl:py-3 xl:text-right">
                                    <button type="button"
                                            wire:click="toggleActive({{ $update->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="toggleActive"
                                            class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-black text-emerald-700 hover:bg-emerald-100 disabled:opacity-50">
                                        表示する
                                    </button>
                                    <button type="button" wire:click="edit({{ $update->id }})" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-black text-slate-700 hover:bg-slate-50">編集</button>
                                    <button type="button"
                                            wire:click="delete({{ $update->id }})"
                                            wire:confirm="{{ $update->source_key ? 'この更新候補を削除しますか？削除後は再生成されません。' : 'この更新情報を削除しますか？' }}"
                                            wire:loading.attr="disabled"
                                            wire:loading.class="is-action-processing"
                                            wire:target="delete"
                                            class="rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-black text-red-700 hover:bg-red-100 disabled:pointer-events-none xl:ml-1">
                                        削除
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr class="block rounded-md bg-white xl:table-row">
                                <td colspan="4" class="block px-4 py-8 text-center text-sm font-bold text-slate-500 xl:table-cell">下書きはありません。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
