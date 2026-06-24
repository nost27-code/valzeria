<div class="w-full px-4 py-8 sm:px-6 lg:px-8">
    @php
        $fieldClass = 'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow-sm focus:border-amber-400 focus:ring-2 focus:ring-amber-200';
        $severityClass = [
            'danger' => 'border-red-200 bg-red-50 text-red-900',
            'warning' => 'border-amber-200 bg-amber-50 text-amber-950',
            'info' => 'border-sky-200 bg-sky-50 text-sky-950',
        ];
        $severityLabel = [
            'danger' => '要修正',
            'warning' => '確認',
            'info' => '情報',
        ];
    @endphp

    <div class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <div class="text-xs font-bold tracking-[0.35em] text-orange-600">EQUIPMENT COMPATIBILITY</div>
            <h1 class="mt-2 text-3xl font-black text-slate-950">職業・装備相性マトリクス</h1>
            <p class="mt-2 text-sm font-semibold text-slate-600">職業ごとの装備可能武器・防具をON/OFFで管理します。変更は装備変更画面の可否判定に反映されます。</p>
        </div>

        <div class="grid gap-2 sm:grid-cols-[180px_minmax(220px,320px)]">
            <select wire:model.live="rankFilter" class="{{ $fieldClass }}">
                <option value="all">全ランク</option>
                @foreach($rankLabels as $key => $label)
                    <option value="{{ $key }}">{{ $label }}職</option>
                @endforeach
            </select>
            <input type="search" wire:model.live.debounce.250ms="search" class="{{ $fieldClass }}" placeholder="職業名・キーで検索">
        </div>
    </div>

    @if (session()->has('message'))
        <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800 shadow-sm">
            {{ session('message') }}
        </div>
    @endif

    <section class="mb-6 rounded-md border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-lg font-black text-slate-950">相性診断</h2>
                <div class="text-xs font-bold text-slate-500">{{ count($diagnostics) }}件</div>
            </div>
        </div>
        <div class="grid gap-3 p-5 lg:grid-cols-2 2xl:grid-cols-3">
            @forelse($diagnostics as $diagnostic)
                <div class="rounded-md border p-4 {{ $severityClass[$diagnostic['severity']] ?? $severityClass['info'] }}">
                    <div class="mb-2 inline-flex rounded bg-white/70 px-2 py-1 text-[11px] font-black">
                        {{ $severityLabel[$diagnostic['severity']] ?? '情報' }}
                    </div>
                    <div class="text-sm font-black">{{ $diagnostic['title'] }}</div>
                    <div class="mt-1 text-xs font-semibold opacity-80">{{ $diagnostic['body'] }}</div>
                </div>
            @empty
                <div class="rounded-md border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-800">
                    現在、目立った相性不整合はありません。
                </div>
            @endforelse
        </div>
    </section>

    <div class="space-y-6">
        <section class="rounded-md border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-black text-slate-950">武器相性</h2>
                <p class="mt-1 text-xs font-semibold text-slate-500">ONにした武器種だけ、その職業で装備できます。</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-left text-xs font-black uppercase tracking-wide text-slate-500">
                            <th class="sticky left-0 z-10 min-w-[220px] border-b border-slate-200 bg-slate-50 px-4 py-3">職業</th>
                            @foreach($weaponCategories as $category)
                                <th class="min-w-[104px] border-b border-slate-200 px-3 py-3 text-center">
                                    <div>{{ $category['name'] }}</div>
                                    <div class="mt-1 text-[10px] font-bold text-slate-400">{{ $category['item_count'] }} items</div>
                                </th>
                            @endforeach
                            <th class="min-w-[84px] border-b border-slate-200 px-3 py-3 text-center">許可数</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($jobs as $job)
                            @php $weaponCount = count($weaponPermissions[$job->id] ?? []); @endphp
                            <tr class="hover:bg-amber-50/40">
                                <th class="sticky left-0 z-10 border-r border-slate-100 bg-white px-4 py-3 text-left align-middle">
                                    <div class="font-black text-slate-950">{{ $job->name }}</div>
                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs font-bold text-slate-500">
                                        <span>#{{ $job->id }}</span>
                                        <span>{{ $rankLabels[$job->rank] ?? $job->rank }}</span>
                                        @if(!$job->is_active)
                                            <span class="rounded bg-slate-200 px-1.5 py-0.5 text-[10px] text-slate-600">非表示</span>
                                        @endif
                                    </div>
                                </th>
                                @foreach($weaponCategories as $category)
                                    @php $enabled = !empty($weaponPermissions[$job->id][$category['key']]); @endphp
                                    <td class="px-3 py-3 text-center">
                                        <button
                                            type="button"
                                            wire:click="toggleWeapon({{ $job->id }}, @js($category['key']))"
                                            class="h-9 w-16 rounded-md text-xs font-black shadow-sm transition {{ $enabled ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'border border-slate-200 bg-slate-50 text-slate-400 hover:border-slate-400 hover:text-slate-700' }}"
                                        >
                                            {{ $enabled ? '可' : '-' }}
                                        </button>
                                    </td>
                                @endforeach
                                <td class="px-3 py-3 text-center font-black {{ $weaponCount === 0 ? 'text-red-600' : ($weaponCount === 1 ? 'text-amber-700' : 'text-slate-700') }}">
                                    {{ $weaponCount }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-md border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-black text-slate-950">防具相性</h2>
                <p class="mt-1 text-xs font-semibold text-slate-500">ONにした防具種だけ、その職業で装備できます。</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-left text-xs font-black uppercase tracking-wide text-slate-500">
                            <th class="sticky left-0 z-10 min-w-[220px] border-b border-slate-200 bg-slate-50 px-4 py-3">職業</th>
                            @foreach($armorCategories as $category)
                                <th class="min-w-[120px] border-b border-slate-200 px-3 py-3 text-center">
                                    <div>{{ $category['name'] }}</div>
                                    <div class="mt-1 text-[10px] font-bold text-slate-400">{{ $category['item_count'] }} items</div>
                                </th>
                            @endforeach
                            <th class="min-w-[84px] border-b border-slate-200 px-3 py-3 text-center">許可数</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($jobs as $job)
                            @php $armorCount = count($armorPermissions[$job->id] ?? []); @endphp
                            <tr class="hover:bg-amber-50/40">
                                <th class="sticky left-0 z-10 border-r border-slate-100 bg-white px-4 py-3 text-left align-middle">
                                    <div class="font-black text-slate-950">{{ $job->name }}</div>
                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs font-bold text-slate-500">
                                        <span>#{{ $job->id }}</span>
                                        <span>{{ $rankLabels[$job->rank] ?? $job->rank }}</span>
                                        @if(!$job->is_active)
                                            <span class="rounded bg-slate-200 px-1.5 py-0.5 text-[10px] text-slate-600">非表示</span>
                                        @endif
                                    </div>
                                </th>
                                @foreach($armorCategories as $category)
                                    @php $enabled = !empty($armorPermissions[$job->id][$category['key']]); @endphp
                                    <td class="px-3 py-3 text-center">
                                        <button
                                            type="button"
                                            wire:click="toggleArmor({{ $job->id }}, @js($category['key']))"
                                            class="h-9 w-16 rounded-md text-xs font-black shadow-sm transition {{ $enabled ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'border border-slate-200 bg-slate-50 text-slate-400 hover:border-slate-400 hover:text-slate-700' }}"
                                        >
                                            {{ $enabled ? '可' : '-' }}
                                        </button>
                                    </td>
                                @endforeach
                                <td class="px-3 py-3 text-center font-black {{ $armorCount === 0 ? 'text-red-600' : 'text-slate-700' }}">
                                    {{ $armorCount }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
