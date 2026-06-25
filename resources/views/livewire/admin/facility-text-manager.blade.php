<div class="w-full px-4 sm:px-6 lg:px-8 py-8 lg:py-10">
    <div class="mb-6">
        <p class="text-xs font-black tracking-[0.24em] text-amber-600">FACILITY TEXTS</p>
        <h1 class="mt-2 text-3xl font-black text-slate-950">施設テキスト管理</h1>
        <p class="mt-2 text-sm font-bold text-slate-500">
            街タブ・街リスト（簡易モード）・冒険者メニューの施設名・説明・アイコン画像パスを変更できます。<br>
            デフォルト値があらかじめ入力されています。空欄にすると保存時にデフォルトへ戻ります。
        </p>
    </div>

    @if(session('status'))
        <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    {{-- タブ切り替え --}}
    <div class="mb-6 flex gap-1 rounded-lg border border-slate-200 bg-white p-1 w-fit shadow-sm">
        @foreach([
            ['key' => 'town',   'label' => '街タブ施設',       'count' => count($entries['town'])],
            ['key' => 'simple', 'label' => '街リスト（独自）',  'count' => count($entries['simple'])],
            ['key' => 'home',   'label' => '冒険者メニュー',    'count' => count($entries['home'])],
        ] as $tab)
            <button type="button"
                    wire:click="$set('activeTab', '{{ $tab['key'] }}')"
                    class="rounded-md px-4 py-2 text-sm font-black transition {{ $activeTab === $tab['key'] ? 'bg-slate-950 text-white shadow' : 'text-slate-600 hover:bg-slate-100' }}">
                {{ $tab['label'] }}
                <span class="ml-1 text-[10px] font-bold {{ $activeTab === $tab['key'] ? 'text-amber-300' : 'text-slate-400' }}">{{ $tab['count'] }}</span>
            </button>
        @endforeach
    </div>

    <form wire:submit="save">
        {{-- 街タブ施設 --}}
        <div @class(['space-y-3', 'hidden' => $activeTab !== 'town'])>
            <div class="overflow-hidden rounded-md bg-white shadow-sm ring-1 ring-slate-200">
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="w-36 px-4 py-3 text-left text-xs font-black text-slate-500">施設</th>
                            <th class="px-4 py-3 text-left text-xs font-black text-slate-500">タイトル</th>
                            <th class="px-4 py-3 text-left text-xs font-black text-slate-500">説明</th>
                            <th class="px-4 py-3 text-left text-xs font-black text-slate-500">アイコン画像パス</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($entries['town'] as $entry)
                            @php $slug = $entry['slug']; @endphp
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-3">
                                    <span class="block text-xs font-black text-slate-800">{{ $entry['label'] }}</span>
                                    <span class="block text-[10px] font-mono text-slate-400">{{ $slug }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <input type="text" wire:model="townValues.{{ $slug }}.name"
                                           class="w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                                </td>
                                <td class="px-4 py-3">
                                    <input type="text" wire:model="townValues.{{ $slug }}.desc"
                                           class="w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                                </td>
                                <td class="px-4 py-3">
                                    <input type="text" wire:model="townValues.{{ $slug }}.icon"
                                           class="w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-mono text-xs shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- 街リスト（独自） --}}
        <div @class(['space-y-3', 'hidden' => $activeTab !== 'simple'])>
            <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-2.5 text-xs font-bold text-blue-700">
                宿屋・補給所など街タブから引き継がれる施設は「街タブ施設」タブで編集してください。ここでは簡易モード独自のアイテムのみ管理します。
            </div>
            <div class="overflow-hidden rounded-md bg-white shadow-sm ring-1 ring-slate-200">
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="w-36 px-4 py-3 text-left text-xs font-black text-slate-500">施設</th>
                            <th class="px-4 py-3 text-left text-xs font-black text-slate-500">タイトル</th>
                            <th class="px-4 py-3 text-left text-xs font-black text-slate-500">説明</th>
                            <th class="px-4 py-3 text-left text-xs font-black text-slate-500">アイコン画像パス</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($entries['simple'] as $entry)
                            @php $slug = $entry['slug']; @endphp
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-3">
                                    <span class="block text-xs font-black text-slate-800">{{ $entry['label'] }}</span>
                                    <span class="block text-[10px] font-mono text-slate-400">{{ $slug }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <input type="text" wire:model="simpleValues.{{ $slug }}.name"
                                           class="w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                                </td>
                                <td class="px-4 py-3">
                                    <input type="text" wire:model="simpleValues.{{ $slug }}.desc"
                                           class="w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                                </td>
                                <td class="px-4 py-3">
                                    <input type="text" wire:model="simpleValues.{{ $slug }}.icon"
                                           class="w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-mono text-xs shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- 冒険者メニュー --}}
        <div @class(['space-y-3', 'hidden' => $activeTab !== 'home'])>
            <div class="overflow-hidden rounded-md bg-white shadow-sm ring-1 ring-slate-200">
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="w-36 px-4 py-3 text-left text-xs font-black text-slate-500">施設</th>
                            <th class="px-4 py-3 text-left text-xs font-black text-slate-500">タイトル</th>
                            <th class="px-4 py-3 text-left text-xs font-black text-slate-500">説明</th>
                            <th class="px-4 py-3 text-left text-xs font-black text-slate-500">アイコン画像パス</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($entries['home'] as $entry)
                            @php $slug = $entry['slug']; @endphp
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-3">
                                    <span class="block text-xs font-black text-slate-800">{{ $entry['label'] }}</span>
                                    <span class="block text-[10px] font-mono text-slate-400">{{ $slug }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <input type="text" wire:model="homeValues.{{ $slug }}.name"
                                           class="w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                                </td>
                                <td class="px-4 py-3">
                                    <input type="text" wire:model="homeValues.{{ $slug }}.desc"
                                           class="w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-bold shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                                </td>
                                <td class="px-4 py-3">
                                    <input type="text" wire:model="homeValues.{{ $slug }}.icon"
                                           class="w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-mono text-xs shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6 flex items-center gap-4">
            <button type="submit"
                    class="rounded-md bg-amber-500 px-6 py-2.5 text-sm font-black text-slate-950 shadow hover:bg-amber-400">
                保存する
            </button>
            <p class="text-xs font-bold text-slate-500">
                保存後、最大1時間でゲーム画面に反映されます。空欄にすると保存時にデフォルトへ戻ります。
            </p>
        </div>
    </form>
</div>
