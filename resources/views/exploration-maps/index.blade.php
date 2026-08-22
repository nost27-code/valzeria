<x-layouts.facility title="地図院" headerIcon="🗺️" :showGameHeader="true" :exitUrl="route('home')" exitLabel="街へ戻る">
    @php
        $dungeonTypeLabels = config('exploration_maps.dungeon_type_labels');
    @endphp

    <div class="mx-auto max-w-6xl space-y-5">
        <section class="rounded-xl border border-indigo-200 bg-indigo-50 p-4 shadow-sm">
            <h2 class="font-black text-indigo-950">探索の地図</h2>
            <p class="mt-1 text-sm font-bold text-indigo-900">見つけた地図を調査して公開すると、冒険者たちが同じ地図を探索できる。</p>
            <p class="mt-2 text-xs font-black text-indigo-800">手持ち {{ number_format((int) $bankSummary['hand_gold']) }}G ／ 銀行 {{ number_format((int) $bankSummary['bank_gold']) }}G</p>
            <details class="mt-3 rounded-lg border border-indigo-200 bg-white/80 px-3 py-2 text-sm text-indigo-950">
                <summary class="cursor-pointer font-black">Q. なぜ調査を依頼する地図院を選ぶの？</summary>
                <div class="mt-2 space-y-2 border-t border-indigo-100 pt-2 text-xs font-bold leading-relaxed text-slate-700">
                    <p><span class="text-indigo-800">A.</span> 遠征調査費は地図の等級で決まります（通常 500G／希少 1,500G／英雄 5,000G／伝説 10,000G）。依頼先は、公開する地図院と入場料の積み立て先を選ぶために指定します。</p>
                    <p>ほかの冒険者が支払った入場料は、発見者に70%、選んだ街の地図院に20%、システム分として10%に分かれます。たとえばルミナス地図院へ依頼した地図なら、入場料の20%がルミナス地図院の発展値へ積み立てられます。</p>
                    <p>地図院の発展値は、今後その街で利用できる地図院の設備や機能を充実させるために使われる予定です。現在は積み立てのみで、地図内の敵の強さやドロップ率は、どこへ依頼しても変わりません。</p>
                    <p>迷ったときは、好きな街の地図院を選んでかまいません。</p>
                </div>
            </details>
        </section>

        <section
            class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
            x-data="{
                selected: [],
                discardable: @js($ownedMaps->whereIn('status', ['uninvestigated', 'surveyed'])->pluck('id')->map(fn ($id) => (string) $id)->values()),
                confirmBulkDiscard: false,
                submittingBulkDiscard: false,
                toggleAll() {
                    this.selected = this.selected.length === this.discardable.length ? [] : [...this.discardable];
                },
            }"
            @keydown.escape.window="confirmBulkDiscard = false"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-black text-slate-900">手元の探索地図</h2>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <p class="rounded-full bg-slate-100 px-3 py-1 text-xs font-black text-slate-700">表示 {{ $ownedMaps->count() }} / {{ $ownedMapCount }}件</p>
                    <p class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-black text-indigo-900">公開枠 {{ $activePublicationCount }} / {{ $activePublicationLimit }}件</p>
                </div>
            </div>
            <p class="mt-2 text-xs font-bold text-slate-600">公開中の地図は詳細画面から取り下げると、すぐに公開枠が空きます。</p>
            <form method="GET" action="{{ route('exploration-maps.index') }}" class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <label class="block text-xs font-black text-slate-700">
                        状態で絞り込む
                        <select name="status" class="mt-1 w-full rounded border-slate-300 bg-white text-sm font-bold text-slate-800">
                            @foreach($ownedMapStatusFilterOptions as $value => $label)
                                <option value="{{ $value }}" @selected($ownedMapStatusFilter === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-xs font-black text-slate-700">
                        等級で絞り込む
                        <select name="grade" class="mt-1 w-full rounded border-slate-300 bg-white text-sm font-bold text-slate-800">
                            @foreach($ownedMapGradeFilterOptions as $value => $label)
                                <option value="{{ $value }}" @selected($ownedMapGradeFilter === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-xs font-black text-slate-700">
                        並べ替え
                        <select name="sort" class="mt-1 w-full rounded border-slate-300 bg-white text-sm font-bold text-slate-800">
                            @foreach($ownedMapSortOptions as $value => $label)
                                <option value="{{ $value }}" @selected($ownedMapSort === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                    <button type="submit" class="w-full rounded bg-indigo-700 px-4 py-2 text-sm font-black text-white hover:bg-indigo-800 sm:w-auto">この条件で表示</button>
                    @if($ownedMapFiltersActive)
                        <a href="{{ route('exploration-maps.index') }}" class="w-full rounded border border-slate-300 bg-white px-4 py-2 text-center text-sm font-black text-slate-700 hover:bg-slate-100 sm:w-auto">条件をクリア</a>
                    @endif
                </div>
            </form>
            @if($ownedMaps->whereIn('status', ['uninvestigated', 'surveyed'])->isNotEmpty())
                <div class="mt-3 flex flex-col gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:flex-row sm:items-center sm:justify-between">
                    <button type="button" @click="toggleAll()" class="rounded border border-slate-300 bg-white px-3 py-2 text-xs font-black text-slate-700 hover:bg-slate-100">
                        <span x-text="selected.length === discardable.length ? '選択を解除' : '破棄可能な地図をすべて選択'">破棄可能な地図をすべて選択</span>
                    </button>
                    <button type="button" @click="confirmBulkDiscard = true" :disabled="selected.length === 0" class="rounded bg-rose-600 px-4 py-2 text-xs font-black text-white hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-40">
                        選択した地図を破棄（<span x-text="selected.length">0</span>件）
                    </button>
                </div>
            @endif
            <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
                @forelse($ownedMaps as $map)
                    @php
                        $dungeonTypeLabel = $dungeonTypeLabels[$map->dungeon_type] ?? $map->dungeon_type;
                        $surveyCost = $surveyCosts[$map->map_grade] ?? $surveyCosts['normal'];
                        $surveyHandUsed = min((int) $bankSummary['hand_gold'], (int) $surveyCost);
                        $surveyBankUsed = max(0, (int) $surveyCost - $surveyHandUsed);
                        $surveyCanPay = (int) $bankSummary['total_gold'] >= (int) $surveyCost;
                        $registration = $map->registration;
                        $isEnded = ($registration?->isPublished() || $registration?->isWithdrawn()) && !$registration->isOpen();
                        $status = $registration?->isWithdrawn()
                            ? '取り下げ済み'
                            : ($isEnded
                                ? '終了'
                                : (['uninvestigated'=>'未調査','surveying'=>'調査中','surveyed'=>'調査完了','published'=>'公開中'][$map->status] ?? $map->status));
                        $details = $mapDetails[$map->id] ?? null;
                        $ancientFragmentName = $details && str_starts_with((string) ($details['reward'] ?? ''), '古代片：')
                            ? \Illuminate\Support\Str::after((string) $details['reward'], '古代片：')
                            : null;
                    @endphp
                    <div class="relative overflow-hidden rounded-lg border p-3 {{ $isEnded ? 'border-slate-300 bg-slate-100 opacity-75 grayscale' : 'border-slate-200 bg-white' }}">
                        @if($isEnded)
                            <div class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center">
                                <span class="-rotate-12 rounded border-4 border-slate-500 px-4 py-1 text-2xl font-black tracking-[0.2em] text-slate-600">{{ $registration?->isWithdrawn() ? '取り下げ' : '終了' }}</span>
                            </div>
                        @endif
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-black">{{ $map->status === 'uninvestigated' ? '未調査の探索地図' : $map->name }}</p>
                                <p class="mt-1 text-xs font-bold text-slate-500">等級：{{ ['normal'=>'通常','rare'=>'希少','hero'=>'英雄','legend'=>'伝説'][$map->map_grade] ?? $map->map_grade }}　状態：{{ $status }}</p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-2">
                                @if(in_array($map->status, ['uninvestigated', 'surveyed'], true))
                                    <label class="inline-flex cursor-pointer items-center gap-1.5 rounded border border-rose-200 bg-rose-50 px-2 py-1 text-[11px] font-black text-rose-700">
                                        <input type="checkbox" value="{{ $map->id }}" x-model="selected" class="rounded border-rose-300 text-rose-600 focus:ring-rose-500">
                                        破棄対象
                                    </label>
                                @endif
                                @if($map->registration)
                                    <a href="{{ route('exploration-maps.show', $map->registration) }}" class="rounded bg-indigo-700 px-3 py-2 text-xs font-black text-white">詳細へ</a>
                                @endif
                            </div>
                        </div>

                        @if($map->status === 'surveyed' && $details)
                            <div class="mt-3 flex flex-wrap gap-1.5 text-[11px] font-bold">
                                <span class="rounded border border-violet-100 bg-violet-50 px-2 py-1 text-violet-800">報酬傾向：{{ $ancientFragmentName ? '古代片' : ($details['reward'] ?? '不明') }}</span>
                                @if($ancientFragmentName)
                                    <span class="rounded border border-amber-100 bg-amber-50 px-2 py-1 text-amber-800">古代片：{{ $ancientFragmentName }}</span>
                                @endif
                                <span class="rounded border border-emerald-100 bg-emerald-50 px-2 py-1 text-emerald-800">目安戦力：{{ $details['enemy_power_range'] }}</span>
                            </div>
                        @endif

                        @if($map->status === 'uninvestigated')
                            <form method="POST" action="{{ route('exploration-maps.survey.start', $map) }}" class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3" x-data="{ townId: '' }" @if($surveyBankUsed > 0) onsubmit="return confirm(@js('遠征調査費を手持ち' . number_format($surveyHandUsed) . 'G・銀行' . number_format($surveyBankUsed) . 'Gで支払います。'))" @endif>
                                @csrf
                                <input type="hidden" name="use_bank" value="{{ $surveyBankUsed > 0 ? 1 : 0 }}">
                                <label for="town-{{ $map->id }}" class="block text-sm font-black text-slate-900">調査を依頼する地図院</label>
                                <p class="mt-1 text-xs font-bold text-slate-600">推定地形：<span class="text-amber-800">{{ $dungeonTypeLabel }}</span>。遠征調査費：{{ number_format($surveyCost) }}G</p>
                                @if($surveyBankUsed > 0)
                                    <p class="mt-1 text-xs font-bold text-amber-800">手持ち {{ number_format($surveyHandUsed) }}G・銀行 {{ number_format($surveyBankUsed) }}G</p>
                                @endif
                                <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                                    <select id="town-{{ $map->id }}" name="town_id" x-model="townId" required class="min-w-0 flex-1 rounded border-slate-300 text-sm font-bold">
                                        <option value="" disabled>地図院を選択してください</option>
                                        @foreach($towns as $town)
                                            <option value="{{ $town->id }}">{{ $town->name }}地図院</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div x-cloak x-show="townId !== ''" class="mt-3">
                                    <button type="submit" @disabled(!$surveyCanPay) class="w-full rounded bg-amber-600 px-4 py-3 text-sm font-black text-white hover:bg-amber-700 disabled:cursor-not-allowed disabled:bg-slate-400">{{ $surveyCanPay ? '遠征調査を始める' : 'Goldが不足しています' }}</button>
                                </div>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="py-4 text-sm font-bold text-slate-500">{{ $ownedMapCount > 0 ? '条件に合う探索地図はない。絞り込み条件を変えてみよう。' : 'まだ探索地図を持っていない。通常探索や討伐で見つけよう。' }}</p>
                @endforelse
            </div>

            <div x-cloak x-show="confirmBulkDiscard" class="fixed inset-0 z-50 flex items-center justify-center p-4" aria-modal="true" role="dialog" aria-labelledby="bulk-map-discard-title">
                <div x-show="confirmBulkDiscard" x-transition.opacity class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" @click="confirmBulkDiscard = false"></div>
                <div x-show="confirmBulkDiscard" x-transition.scale.origin.center class="relative w-full max-w-md overflow-hidden rounded-xl border-2 border-rose-300 bg-white shadow-2xl" @click.stop>
                    <div class="bg-rose-600 px-4 py-3">
                        <h3 id="bulk-map-discard-title" class="text-sm font-black text-white">探索地図の一括破棄</h3>
                    </div>
                    <form method="POST" action="{{ route('exploration-maps.bulk-discard') }}" class="p-4" @submit="submittingBulkDiscard = true">
                        @csrf
                        <template x-for="mapId in selected" :key="mapId">
                            <input type="hidden" name="map_ids[]" :value="mapId">
                        </template>
                        <p class="text-sm font-bold text-slate-800">選択した<span class="font-black text-rose-700" x-text="selected.length"></span>件の地図を破棄します。</p>
                        <ul class="mt-3 max-h-48 space-y-1 overflow-y-auto rounded-lg border border-rose-100 bg-rose-50 p-3 text-xs font-bold text-slate-700">
                            @foreach($ownedMaps->whereIn('status', ['uninvestigated', 'surveyed']) as $discardableMap)
                                <li x-show="selected.includes('{{ $discardableMap->id }}')">・{{ $discardableMap->status === 'uninvestigated' ? '未調査の探索地図' : $discardableMap->name }}</li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs font-bold text-rose-700">破棄した地図は元に戻せません。調査済みの場合、遠征調査費は戻りません。</p>
                        <div class="mt-4 grid grid-cols-2 gap-2">
                            <button type="button" @click="confirmBulkDiscard = false" :disabled="submittingBulkDiscard" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-60">キャンセル</button>
                            <button type="submit" :disabled="submittingBulkDiscard || selected.length === 0" class="rounded-lg bg-rose-600 px-3 py-2 text-sm font-black text-white hover:bg-rose-700 disabled:opacity-60">
                                <span x-show="!submittingBulkDiscard">破棄する</span>
                                <span x-cloak x-show="submittingBulkDiscard">処理中...</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-indigo-200 bg-indigo-50 p-4 shadow-sm">
            <h2 class="font-black text-indigo-950">公開中の地図</h2>
            <p class="mt-1 text-sm font-bold text-indigo-900">ほかの冒険者が公開した地図は、探索画面から選んで入場できます。</p>
            <a href="{{ route('exploration-maps.published') }}" class="mt-3 inline-flex w-full items-center justify-center rounded-lg bg-indigo-700 px-4 py-3 text-sm font-black text-white hover:bg-indigo-800 sm:w-auto">公開地図を見る</a>
        </section>
    </div>
</x-layouts.facility>
