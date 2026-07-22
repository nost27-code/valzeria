<x-layouts.facility title="公開地図" headerIcon="🗺️" :showGameHeader="true" :exitUrl="route('home')" exitLabel="探索へ戻る">
    <div class="mx-auto max-w-6xl space-y-5">
        <section class="rounded-xl border border-indigo-200 bg-indigo-50 p-4 shadow-sm">
            <h2 class="font-black text-indigo-950">地図院で公開された地図</h2>
            <p class="mt-1 text-sm font-bold text-indigo-900">地図を開くと、出現する魔物や地図の特徴を確認できます。入場中は×10探索を何度続けても追加料金はかかりません。街へ戻って入り直すと、入場料がもう一度かかります。</p>
        </section>

        <form method="GET" action="{{ route('exploration-maps.published') }}" class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <label for="map-sort" class="block text-sm font-black text-slate-900">並び替え</label>
            <select id="map-sort" name="sort" onchange="this.form.submit()" class="mt-2 w-full rounded-lg border-slate-300 text-sm font-bold text-slate-900 sm:w-72">
                @foreach($sortOptions as $value => $label)
                    <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <noscript><button class="mt-2 rounded bg-indigo-700 px-3 py-2 text-xs font-black text-white">並び替える</button></noscript>
        </form>

        <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                @forelse($published as $registration)
                    @php
                        $map = $registration->map;
                        $owner = (int) $map->owner_character_id === (int) $character->id;
                        $details = $mapDetails[$registration->id];
                        $isEnded = !$registration->isOpen();
                        $isActive = (int) $registration->id === $activeRegistrationId;
                        $exploreCounts = $isEnded ? [] : array_values(array_unique([1, min(10, (int) $registration->remaining_explorations)]));
                    @endphp
                    <details class="group relative overflow-hidden rounded-xl border {{ $isEnded ? 'border-red-300 bg-slate-100 opacity-75 grayscale' : ($isActive ? 'border-indigo-400 bg-indigo-50/50' : 'border-slate-200 bg-white') }}" @if($details['background_image']) style="background-image: linear-gradient(90deg, rgba(255, 255, 255, 1) 0%, rgba(255, 255, 255, 0.94) 38%, rgba(255, 255, 255, 0.72) 100%), url('{{ asset($details['background_image']) }}'); background-position: center; background-size: cover;" @endif @if($isActive && !$isEnded) open @endif>
                        @if($isEnded)
                            <div class="pointer-events-none absolute inset-0 z-20 flex items-center justify-center">
                                <span class="-rotate-12 rounded border-4 border-red-600 bg-white/85 px-5 py-1 text-2xl font-black tracking-[0.3em] text-red-700 shadow-sm">終了</span>
                            </div>
                        @endif
                        <summary class="relative z-10 flex cursor-pointer list-none items-start justify-between gap-3 p-4 [&::-webkit-details-marker]:hidden">
                            <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($map->owner?->icon_path) }}" alt="{{ $map->owner?->name ?? '発見者' }}" class="h-14 w-14 shrink-0 rounded-full border-2 border-indigo-200 bg-indigo-50 object-contain shadow-sm">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-black text-slate-900">{{ $map->name }}</p>
                                    @if($isEnded)
                                        <span class="rounded border border-red-300 bg-red-50 px-2 py-0.5 text-[11px] font-black text-red-700">公開終了</span>
                                    @elseif($isActive)
                                        <span class="rounded border border-indigo-200 bg-indigo-100 px-2 py-0.5 text-[11px] font-black text-indigo-800">入場中</span>
                                    @endif
                                </div>
                                <p class="mt-1 text-xs font-bold text-slate-500">公開地図院：{{ $registration->town->name }}　発見者：{{ $map->owner->name }}</p>
                                <div class="mt-2 flex flex-wrap gap-1.5 text-[11px] font-bold">
                                    <span class="rounded border border-indigo-100 bg-indigo-50 px-2 py-1 text-indigo-800">{{ $isEnded ? '公開終了' : '残り ' . number_format($registration->remaining_explorations) . ' 回' }}</span>
                                    <span class="rounded border border-emerald-100 bg-emerald-50 px-2 py-1 text-emerald-800">{{ $isEnded ? '入場できません' : ($isActive ? '入場中：追加料金なし' : ($owner ? '発見者は無料（他の冒険者：' . number_format($registration->entry_fee_per_exploration) . 'G）' : '入場料：' . number_format($registration->entry_fee_per_exploration) . 'G')) }}</span>
                                    <span class="rounded border border-amber-100 bg-amber-50 px-2 py-1 text-amber-800">目安戦力：{{ $details['enemy_power_range'] }}</span>
                                    @if($details['reward'])
                                        <span class="rounded border border-violet-100 bg-violet-50 px-2 py-1 text-violet-800">報酬：{{ $details['reward'] }}</span>
                                    @endif
                                </div>
                            </div>
                            <span class="shrink-0 rounded-lg px-3 py-2 text-xs font-black text-white {{ $isEnded ? 'bg-slate-500' : 'bg-indigo-700 group-open:bg-indigo-800' }}">{{ $isEnded ? '公開終了' : '詳細を見る' }}</span>
                        </summary>

                        <div class="relative z-10 border-t border-slate-200 px-4 pb-4 pt-3">
                            <dl class="grid grid-cols-2 gap-3 text-sm font-bold text-slate-700">
                                <div><dt class="text-xs text-slate-500">地図Lv</dt><dd class="mt-1 text-slate-900">{{ $map->map_level }}</dd></div>
                                <div><dt class="text-xs text-slate-500">探索地</dt><dd class="mt-1 text-slate-900">{{ $details['dungeon_type'] }}</dd></div>
                                <div><dt class="text-xs text-slate-500">出現敵Lv</dt><dd class="mt-1 text-slate-900">{{ $details['enemy_level_range'] }}</dd></div>
                                <div><dt class="text-xs text-slate-500">目安戦力</dt><dd class="mt-1 text-slate-900">{{ $details['enemy_power_range'] }}</dd></div>
                                <div><dt class="text-xs text-slate-500">危険度</dt><dd class="mt-1 text-slate-900">{{ $details['threat_tier'] }}</dd></div>
                                @if($details['reward'])
                                    <div><dt class="text-xs text-slate-500">報酬傾向</dt><dd class="mt-1 text-slate-900">{{ $details['reward'] }}</dd></div>
                                @endif
                            </dl>

                            <div class="mt-4 rounded-lg border p-3 {{ $isEnded ? 'border-red-200 bg-red-50' : 'border-emerald-200 bg-emerald-50' }}">
                                <p class="text-sm font-black {{ $isEnded ? 'text-red-900' : 'text-emerald-950' }}">{{ $isEnded ? 'この地図の公開は終了しました' : ($isActive ? 'この地図を探索中です' : 'この地図に入場する') }}</p>
                                <p class="mt-1 text-xs font-bold {{ $isEnded ? 'text-red-800' : 'text-emerald-800' }}">{{ $isEnded ? '終了した地図は、公開終了からしばらく一覧で確認できます。' : ($isActive ? 'このまま探索を続けられます。追加の入場料はかかりません。' : ($owner ? '発見者として無料で入場できます。他の冒険者の入場料：' . number_format($registration->entry_fee_per_exploration) . 'G' : '入場料：' . number_format($registration->entry_fee_per_exploration) . 'G（街へ戻るまで1回だけ）')) }}</p>
                                @if(!$isEnded)
                                <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    @foreach($exploreCounts as $count)
                                        <form method="POST" action="{{ route('exploration-maps.explore', $registration) }}">
                                            @csrf
                                            <input type="hidden" name="count" value="{{ $count }}">
                                            <input type="hidden" name="request_uuid" value="{{ Illuminate\Support\Str::uuid() }}">
                                            <button class="w-full rounded-lg bg-emerald-700 px-3 py-3 text-sm font-black text-white hover:bg-emerald-800">{{ $count === 1 ? ($isActive ? '探索を続ける ×1' : '入場して探索する ×1') : ($isActive ? '探索を続ける ×' . $count : '入場して探索する ×' . $count) }}</button>
                                        </form>
                                    @endforeach
                                </div>
                                @endif
                            </div>
                        </div>
                    </details>
                @empty
                    <p class="py-8 text-center text-sm font-bold text-slate-500">いま公開されている地図はない。</p>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.facility>
