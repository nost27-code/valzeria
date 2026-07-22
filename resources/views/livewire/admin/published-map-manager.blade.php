<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mx-auto max-w-7xl space-y-5">
        <div class="flex flex-col gap-4 rounded-xl bg-slate-950 p-5 text-white shadow-lg sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-black tracking-[0.24em] text-amber-300">EXPLORATION MAPS</p>
                <h1 class="mt-2 text-2xl font-black">公開中の探索地図</h1>
                <p class="mt-2 text-sm font-bold text-slate-300">現在入場できる地図の内容と公開条件を確認できます。終了した地図は含みません。</p>
            </div>
            <div class="rounded-lg border border-white/15 bg-white/10 px-4 py-3 text-center sm:min-w-36">
                <div class="text-xs font-bold text-slate-300">公開中</div>
                <div class="mt-1 text-2xl font-black text-amber-300">{{ number_format($publishedCount) }}<span class="ml-1 text-sm">件</span></div>
            </div>
        </div>

        <div class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
            <p class="text-sm font-bold text-slate-600">新しく公開された地図から表示しています。</p>
            <label class="flex items-center gap-2 text-sm font-bold text-slate-600">
                <span class="whitespace-nowrap">表示件数</span>
                <select wire:model.live="perPage" class="rounded-md border-slate-300 py-1.5 text-sm font-bold text-slate-900">
                    <option value="25">25件</option>
                    <option value="50">50件</option>
                    <option value="100">100件</option>
                </select>
            </label>
        </div>

        <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                @forelse($registrations as $registration)
                    @php
                        $map = $registration->map;
                        $details = $mapDetails[$registration->id] ?? null;
                    @endphp
                    @if($map && $details)
                        <details class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                            <summary class="flex cursor-pointer list-none items-start justify-between gap-3 p-4 [&::-webkit-details-marker]:hidden">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="font-black text-slate-950">{{ $map->name }}</h2>
                                        <span class="rounded border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-black text-emerald-800">公開中</span>
                                    </div>
                                    <p class="mt-1 text-xs font-bold text-slate-500">発見者：{{ $map->owner?->name ?? '削除済み冒険者' }}　公開地図院：{{ $registration->town?->name ?? '不明' }}</p>
                                    <div class="mt-3 flex flex-wrap gap-1.5 text-[11px] font-bold">
                                        <span class="rounded border border-indigo-100 bg-indigo-50 px-2 py-1 text-indigo-800">残り {{ number_format($registration->remaining_explorations) }} / {{ number_format($registration->exploration_limit) }} 回</span>
                                        <span class="rounded border border-emerald-100 bg-emerald-50 px-2 py-1 text-emerald-800">入場料 {{ number_format($registration->entry_fee_per_exploration) }}G</span>
                                        <span class="rounded border border-amber-100 bg-amber-50 px-2 py-1 text-amber-800">目安戦力 {{ $details['enemy_power_range'] }}</span>
                                    </div>
                                </div>
                                <span class="shrink-0 rounded-lg bg-slate-900 px-3 py-2 text-xs font-black text-white">詳細</span>
                            </summary>

                            <div class="border-t border-slate-200 bg-slate-50 p-4">
                                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm font-bold text-slate-700 sm:grid-cols-3">
                                    <div><dt class="text-xs text-slate-500">地図等級</dt><dd class="mt-1 text-slate-950">{{ ['normal' => '通常', 'rare' => '希少', 'hero' => '英雄', 'legend' => '伝説'][$map->map_grade] ?? $map->map_grade }}</dd></div>
                                    <div><dt class="text-xs text-slate-500">地図Lv</dt><dd class="mt-1 text-slate-950">{{ number_format($map->map_level) }}</dd></div>
                                    <div><dt class="text-xs text-slate-500">探索地</dt><dd class="mt-1 text-slate-950">{{ $details['dungeon_type'] }}</dd></div>
                                    <div><dt class="text-xs text-slate-500">出現敵Lv</dt><dd class="mt-1 text-slate-950">{{ $details['enemy_level_range'] }}</dd></div>
                                    <div><dt class="text-xs text-slate-500">危険度</dt><dd class="mt-1 text-slate-950">{{ $details['threat_tier'] }}</dd></div>
                                    <div><dt class="text-xs text-slate-500">目安戦力</dt><dd class="mt-1 text-slate-950">{{ $details['enemy_power_range'] }}</dd></div>
                                    <div><dt class="text-xs text-slate-500">報酬傾向</dt><dd class="mt-1 text-slate-950">{{ $details['reward'] ?? 'なし' }}</dd></div>
                                    <div><dt class="text-xs text-slate-500">公開日時</dt><dd class="mt-1 text-slate-950">{{ $registration->published_at?->format('Y/m/d H:i') ?? '-' }}</dd></div>
                                    <div><dt class="text-xs text-slate-500">公開終了</dt><dd class="mt-1 text-slate-950">{{ $registration->expires_at?->format('Y/m/d H:i') ?? '-' }}</dd></div>
                                </dl>

                                <div class="mt-4 rounded-lg border border-slate-200 bg-white p-3">
                                    <h3 class="text-sm font-black text-slate-900">出現モンスター</h3>
                                    <ul class="mt-2 space-y-1 text-sm font-bold text-slate-700">
                                        @forelse($map->normal_monster_variants_json ?? [] as $variant)
                                            <li>・{{ $variant['display_name'] ?? '名称不明の魔物' }} <span class="text-xs text-slate-500">Lv{{ $variant['enemy_level'] ?? '?' }}</span></li>
                                        @empty
                                            <li class="text-slate-500">出現モンスターの記録がありません。</li>
                                        @endforelse
                                    </ul>
                                </div>

                                @if($details['environment'])
                                    <div class="mt-3 rounded-lg border border-sky-100 bg-sky-50 p-3 text-sm font-bold text-sky-950">
                                        <span class="text-xs text-sky-700">周辺の様子</span>
                                        <p class="mt-1">{{ implode('、', $details['environment']) }}</p>
                                    </div>
                                @endif
                            </div>
                        </details>
                    @endif
                @empty
                    <p class="col-span-full py-10 text-center text-sm font-bold text-slate-500">現在公開中の探索地図はありません。</p>
                @endforelse
            </div>

            <div class="mt-4">
                {{ $registrations->links() }}
            </div>
        </section>
    </div>
</div>
