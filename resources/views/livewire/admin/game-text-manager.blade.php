<div class="w-full px-4 sm:px-6 lg:px-8 py-8 lg:py-10">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between mb-6">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">GAME TEXTS</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">画面文言管理</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">ゲーム画面に表示する文言をコード変更なしで編集できます。</p>
        </div>
        <button type="button" wire:click="createNew" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-black text-white shadow hover:bg-slate-800">
            新規追加
        </button>
    </div>

    @if(session('status'))
        <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
        {{-- 編集フォーム --}}
        <form wire:submit="save" class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200 h-fit">
            <h2 class="text-lg font-black text-slate-950">{{ $editingId ? '文言を編集' : '文言を追加' }}</h2>

            <div class="mt-5 space-y-4">
                <div>
                    <label class="mb-1 block text-xs font-black text-slate-500">キー <span class="text-red-500">*</span></label>
                    <input type="text" wire:model="form.key"
                           placeholder="例: top.catchcopy"
                           {{ $editingId ? 'readonly' : '' }}
                           class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-mono font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30 {{ $editingId ? 'bg-slate-50 text-slate-500' : '' }}">
                    <p class="mt-1 text-[11px] text-slate-400">英小文字・数字・ドット・ハイフン・アンダースコアのみ</p>
                    @error('form.key') <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-xs font-black text-slate-500">文言 <span class="text-red-500">*</span></label>
                    <textarea wire:model="form.value" rows="4"
                              placeholder="表示するテキストを入力"
                              class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30 resize-y"></textarea>
                    @error('form.value') <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-xs font-black text-slate-500">メモ（どこで使うか）</label>
                    <input type="text" wire:model="form.description"
                           placeholder="例: トップページのキャッチコピー"
                           class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                    @error('form.description') <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="mt-5 flex gap-2">
                <button type="submit" class="flex-1 rounded-md bg-amber-500 px-4 py-2.5 text-sm font-black text-slate-950 shadow hover:bg-amber-400">
                    保存する
                </button>
                @if($editingId)
                    <button type="button" wire:click="createNew" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-black text-slate-700 hover:bg-slate-50">
                        解除
                    </button>
                @endif
            </div>

            @if(!$editingId)
                <div class="mt-5 rounded-md bg-slate-50 border border-slate-200 px-4 py-3">
                    <p class="text-xs font-black text-slate-600 mb-1">Bladeでの使い方</p>
                    <code class="text-xs text-slate-700 font-mono">@{{ game_text('キー', 'デフォルト文言') }}</code>
                </div>
            @endif
        </form>

        {{-- 一覧 --}}
        <div class="overflow-hidden rounded-md bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 px-4 py-3 flex items-center gap-3">
                <h2 class="text-lg font-black text-slate-950 shrink-0">登録済み文言</h2>
                <input type="text" wire:model.live.debounce.300ms="search"
                       placeholder="キー・メモで絞り込み"
                       class="ml-auto w-48 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-700">
                        <tr>
                            <th class="px-4 py-3 text-left font-bold">キー</th>
                            <th class="px-4 py-3 text-left font-bold">文言</th>
                            <th class="px-4 py-3 text-left font-bold">メモ</th>
                            <th class="px-4 py-3 text-right font-bold">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse($texts as $text)
                            <tr class="{{ $editingId === $text->id ? 'bg-amber-50' : 'hover:bg-slate-50' }}">
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs font-bold text-slate-900">{{ $text->key }}</td>
                                <td class="px-4 py-3 text-slate-700 max-w-[200px]">
                                    <span class="line-clamp-2 text-xs font-bold">{{ $text->value }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-500 max-w-[160px]">
                                    <span class="line-clamp-1">{{ $text->description ?: '—' }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <button type="button" wire:click="edit({{ $text->id }})" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-black text-slate-700 hover:bg-slate-50">編集</button>
                                    <button type="button" wire:click="delete({{ $text->id }})" wire:confirm="「{{ $text->key }}」を削除しますか？" class="ml-1 rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-black text-red-700 hover:bg-red-100">削除</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-sm font-bold text-slate-500">
                                    {{ $search ? '該当する文言がありません。' : '文言がまだ登録されていません。' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
