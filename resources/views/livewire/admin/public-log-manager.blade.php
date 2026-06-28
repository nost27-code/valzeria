<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">PUBLIC LOG MANAGER</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">公開ログ管理</h1>
            <p class="mt-2 text-sm font-bold text-slate-500">下部チャットに表示される各種ログを検索し、選択して削除できます。</p>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row">
            <select wire:model.live="perPage" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                <option value="50">50件</option>
                <option value="100">100件</option>
                <option value="200">200件</option>
            </select>
            <button type="button" wire:click="clearFilters" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-black text-slate-700 shadow-sm hover:bg-slate-50">
                条件クリア
            </button>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
            {{ session('status') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-800">
            {{ session('error') }}
        </div>
    @endif

    <div class="mb-4 grid grid-cols-1 gap-3 lg:grid-cols-12">
        <div class="rounded-md border border-slate-200 bg-white px-4 py-3 shadow-sm lg:col-span-2">
            <div class="text-xs font-black text-slate-400">該当ログ</div>
            <div class="mt-1 text-2xl font-black text-slate-950">{{ number_format($totalCount) }}</div>
        </div>
        <div class="rounded-md border border-slate-200 bg-white px-4 py-3 shadow-sm lg:col-span-10">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                <label class="block">
                    <span class="text-xs font-black text-slate-500">種別</span>
                    <select wire:model.live="typeFilter" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                        @foreach($typeOptions as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-black text-slate-500">開始日</span>
                    <input type="date" wire:model.live="dateFrom" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                </label>
                <label class="block">
                    <span class="text-xs font-black text-slate-500">終了日</span>
                    <input type="date" wire:model.live="dateTo" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                </label>
                <label class="block">
                    <span class="text-xs font-black text-slate-500">検索</span>
                    <input type="text" wire:model.live.debounce.300ms="searchQuery" placeholder="名前・本文で検索" class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                </label>
            </div>
            @if($typeFilter === 'admin')
                <label class="mt-3 flex items-start gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs font-bold text-red-800">
                    <input type="checkbox" wire:model.live="includeProtectedLogs" class="mt-0.5 rounded border-red-300 text-red-700 focus:ring-red-500">
                    <span>管理人ログの削除保護を一時解除する</span>
                </label>
            @else
                <div class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800">
                    管理人ログは通常の選択削除から保護されています。削除する場合は種別を「管理人」に絞ってください。
                </div>
            @endif
        </div>
    </div>

    <div class="mb-4 flex flex-col gap-3 rounded-md border border-red-200 bg-red-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="text-sm font-black text-red-900">選択削除</div>
            <div class="mt-0.5 text-xs font-bold text-red-700">選択中: {{ number_format($selectedCount) }}件。削除すると下部チャット/ログ表示から消えます。</div>
        </div>
        <button type="button"
                wire:click="deleteSelected"
                wire:confirm="選択したログを削除します。よろしいですか？"
                class="inline-flex items-center justify-center rounded-md bg-red-700 px-4 py-2 text-sm font-black text-white shadow-sm hover:bg-red-800 disabled:cursor-not-allowed disabled:opacity-50"
                @disabled($selectedCount <= 0)>
            選択ログを削除
        </button>
    </div>

    <div class="overflow-hidden rounded-md bg-white/95 shadow-sm ring-1 ring-slate-200">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-lg font-black text-slate-950">ログ一覧</h2>
            <div class="text-xs font-bold text-slate-500">1ページ {{ number_format($perPage) }} 件</div>
        </div>

        @if(!$canManageLogs)
            <div class="px-6 py-10 text-center text-sm font-bold text-slate-500">
                公開ログを表示するためのテーブルがありません。
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-700">
                        <tr>
                            <th class="w-12 px-4 py-3 text-left">
                                <input type="checkbox" wire:model.live="selectPage" class="rounded border-slate-300 text-red-700 focus:ring-red-500">
                            </th>
                            <th class="px-4 py-3 text-left font-bold">日時</th>
                            <th class="px-4 py-3 text-left font-bold">種別</th>
                            <th class="px-4 py-3 text-left font-bold">送信者</th>
                            <th class="px-4 py-3 text-left font-bold">宛先</th>
                            <th class="px-4 py-3 text-left font-bold">本文</th>
                            <th class="px-4 py-3 text-right font-bold">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse($logs as $log)
                            @php
                                $isProtectedLog = in_array($log->type, $protectedTypes, true);
                                $canDeleteThisLog = ! $isProtectedLog || $canDeleteProtectedLogs;
                            @endphp
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <input type="checkbox"
                                           wire:model.live="selected.{{ $log->id }}"
                                           class="rounded border-slate-300 text-red-700 focus:ring-red-500 disabled:opacity-30"
                                           @disabled(! $canDeleteThisLog)>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs font-bold text-slate-500">
                                    {{ \Illuminate\Support\Carbon::parse($log->created_at)->format('Y/m/d H:i:s') }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="inline-flex rounded bg-slate-100 px-2 py-1 text-[11px] font-black text-slate-700">
                                        {{ $typeOptions[$log->type] ?? $log->type }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 font-black text-slate-900">{{ $log->sender_name ?? '-' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-bold text-blue-900">{{ $log->receiver_name ?? '-' }}</td>
                                <td class="min-w-[28rem] px-4 py-3 font-bold leading-relaxed text-slate-700">
                                    <div class="whitespace-pre-wrap break-words">{{ $log->message }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <button type="button"
                                            wire:click="deleteOne({{ $log->id }})"
                                            wire:confirm="このログを削除します。よろしいですか？"
                                            class="rounded-md border border-red-200 bg-white px-3 py-1.5 text-xs font-black text-red-700 shadow-sm hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-40"
                                            @disabled(! $canDeleteThisLog)>
                                        {{ $canDeleteThisLog ? '削除' : '保護中' }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-8 text-center text-sm font-bold text-slate-500">
                                    ログが見つかりません。
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-100 px-4 py-3">
                {{ $logs->links() }}
            </div>
        @endif
    </div>
</div>
