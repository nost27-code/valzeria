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
        文言を整えて「表示する」をONにした項目だけが街へ公開されます。不要な候補は「掲載から除外」を選んでください。
    </div>

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

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-black text-slate-500">表示順</label>
                        <input type="number" min="0" max="9999" wire:model="form.sort_order" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                        @error('form.sort_order') <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div> @enderror
                    </div>

                    <label class="flex items-end gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-black text-slate-700">
                        <input type="checkbox" wire:model="form.is_active" class="rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                        街に表示する
                    </label>
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
                <h2 class="text-lg font-black text-slate-950">更新候補・公開履歴</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-700">
                        <tr>
                            <th class="px-4 py-3 text-left font-bold">公開日</th>
                            <th class="px-4 py-3 text-left font-bold">表示内容</th>
                            <th class="px-4 py-3 text-right font-bold">順</th>
                            <th class="px-4 py-3 text-center font-bold">状態</th>
                            <th class="px-4 py-3 text-right font-bold">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse($updates as $update)
                            <tr class="{{ $editingId === $update->id ? 'bg-amber-50' : ($update->is_dismissed ? 'bg-slate-50 opacity-65' : 'hover:bg-slate-50') }}">
                                <td class="whitespace-nowrap px-4 py-3 font-black text-slate-900">{{ $update->published_on?->format('Y/m/d') }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @if($update->source_key)
                                            <span class="rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 text-[10px] font-black text-blue-700">自動生成</span>
                                        @else
                                            <span class="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-black text-slate-600">手動作成</span>
                                        @endif
                                        @if($update->is_dismissed)
                                            <span class="rounded-full border border-rose-200 bg-rose-50 px-2 py-0.5 text-[10px] font-black text-rose-700">掲載除外</span>
                                        @endif
                                    </div>
                                    <div class="mt-1 font-black text-slate-800">{{ $update->body }}</div>
                                    @if(filled($update->detail))
                                        <div class="mt-1 line-clamp-2 text-xs font-bold leading-relaxed text-slate-500">{{ $update->detail }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-bold text-slate-500">{{ number_format($update->sort_order) }}</td>
                                <td class="px-4 py-3 text-center">
                                    @if($update->is_dismissed)
                                        <span class="text-xs font-black text-slate-400">除外中</span>
                                    @else
                                        <button type="button" wire:click="toggleActive({{ $update->id }})" wire:loading.attr="disabled" wire:loading.class="is-action-processing" wire:target="toggleActive" class="rounded-full px-3 py-1 text-xs font-black disabled:pointer-events-none {{ $update->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                                            {{ $update->is_active ? '表示中' : '下書き' }}
                                        </button>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    @if($update->is_dismissed)
                                        <button type="button" wire:click="restore({{ $update->id }})" wire:loading.attr="disabled" wire:target="restore" class="rounded-md border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-black text-blue-700 hover:bg-blue-100 disabled:opacity-60">下書きへ戻す</button>
                                    @else
                                        <button type="button" wire:click="edit({{ $update->id }})" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-black text-slate-700 hover:bg-slate-50">編集</button>
                                        <button type="button"
                                                wire:click="delete({{ $update->id }})"
                                                wire:confirm="{{ $update->source_key ? 'この更新候補を掲載対象から除外しますか？' : 'この更新情報を削除しますか？' }}"
                                                wire:loading.attr="disabled"
                                                wire:loading.class="is-action-processing"
                                                wire:target="delete"
                                                class="ml-1 rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-black text-red-700 hover:bg-red-100 disabled:pointer-events-none">
                                            {{ $update->source_key ? '掲載から除外' : '削除' }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-sm font-bold text-slate-500">更新情報がありません。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
