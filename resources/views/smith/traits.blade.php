@php
    $headerIcon = '⚒️';
    $bgImage = 'images/card_bg/shop_blacksmith.webp';
    $title = '武器の銘・特攻を鍛える (' . ($currentCity->name ?? '冒険都市ヴァルゼリア') . ')';
    $initialKind = old('trait_kind', session('weapon_trait_kind', 'engraving'));
@endphp

<x-layouts.facility :title="$title" :headerIcon="$headerIcon" :bgImage="$bgImage">
    <div
        class="mx-auto w-full pb-10"
        x-data="weaponTraitWorkshop(
            @js($workshopCandidates),
            @js($forgeGoldCosts),
            @js($dualDiscountRate),
            @js($initialKind),
            @js(old('base_character_item_id')),
            @js(old('material_character_item_id')),
            @js(csrf_token()),
        )"
    >
        <div class="rounded-lg border border-[#d4af37]/50 bg-white p-4 shadow-sm sm:p-6">
            <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="flex items-center gap-2 text-xl font-bold text-slate-800">
                        <span class="text-2xl">⚒️</span> 武器の銘・特攻を鍛える
                    </h2>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">
                        銘か種族特攻を選び、ベース武器と素材武器を選ぶだけです。結果は自動で判定されます。
                    </p>
                </div>
                <div class="flex items-center gap-2 self-end sm:self-start">
                    <a href="{{ route('blacksmith.traits.help') }}" @click.prevent="helpOpen = true" class="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2.5 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100" title="銘・特攻を鍛える解説">
                        <span class="text-sm leading-none">?</span> 解説
                    </a>
                    <div class="rounded border border-slate-200 bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700 sm:text-sm">
                        所持Gold <span class="text-slate-900">{{ number_format((int) ($character->money ?? 0)) }}G</span>
                    </div>
                </div>
            </div>

            <div class="mb-5 grid grid-cols-3 gap-2">
                <a href="{{ route('blacksmith.index') }}" class="rounded-lg border border-slate-300 bg-slate-50 px-2 py-3 text-center text-xs font-bold text-slate-700 transition hover:bg-slate-100 sm:text-sm">
                    装備強化
                </a>
                <a href="{{ route('blacksmith.traits.index') }}" class="rounded-lg bg-slate-900 px-2 py-3 text-center text-xs font-bold text-white shadow-sm sm:text-sm">
                    銘・特攻を鍛える
                </a>
                <a href="{{ route('smith.index') }}" class="rounded-lg border border-slate-300 bg-slate-50 px-2 py-3 text-center text-xs font-bold text-slate-700 transition hover:bg-slate-100 sm:text-sm">
                    進化合成
                </a>
            </div>

            @if(session('status'))
                <div class="mb-4 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 font-bold text-emerald-800">
                    @foreach(preg_split('/(?<=。)\s*/u', (string) session('status'), -1, PREG_SPLIT_NO_EMPTY) as $statusLine)
                        <p>{{ $statusLine }}</p>
                    @endforeach
                </div>
            @endif
            @if(session('error'))
                <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 font-bold text-red-700">
                    {{ session('error') }}
                </div>
            @endif

            <div class="mb-5 grid grid-cols-2 gap-2 rounded-lg border border-slate-200 bg-slate-50 p-1">
                <button type="button" @click="setKind('engraving')" :class="kind === 'engraving' ? 'border-slate-300 bg-white text-slate-900 shadow-sm' : 'border-transparent text-slate-500'" class="rounded-md border px-3 py-3 text-sm font-black transition">
                    銘を鍛える
                </button>
                <button type="button" @click="setKind('slayer')" :class="kind === 'slayer' ? 'border-slate-300 bg-white text-slate-900 shadow-sm' : 'border-transparent text-slate-500'" class="rounded-md border px-3 py-3 text-sm font-black transition">
                    特攻を鍛える
                </button>
            </div>

            <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-3 text-sm font-bold leading-relaxed text-indigo-900">
                <span x-show="kind === 'engraving'">同じ銘・同じ段階・同じ武器種なら段階を上げます。それ以外では、素材武器の銘を移せるか自動判定します。</span>
                <span x-show="kind === 'slayer'" style="display: none;">同じ特攻・同じ段階・同じ武器種なら段階を上げます。それ以外では、素材武器の特攻を移せるか自動判定します。</span>
            </div>

            <form method="POST" action="{{ route('blacksmith.traits.process') }}" @submit="confirmTransferBeforeSubmit($event)" class="mt-5 space-y-4">
                @csrf
                <input type="hidden" name="trait_kind" :value="kind">
                <input type="hidden" name="base_character_item_id" :value="baseId">
                <input type="hidden" name="material_character_item_id" :value="materialId">

                <div>
                    <p class="mb-1.5 text-sm font-black text-slate-800">1. 残したいベース武器 <span class="text-xs font-bold text-slate-500">（完成後に残る武器）</span></p>
                    <template x-if="selectedBase()">
                        <div class="rounded-xl border-2 border-slate-300 bg-slate-50 p-3 shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <p class="min-w-0 font-black text-slate-900" x-text="selectedBase().display_name"></p>
                                <div class="flex shrink-0 items-center gap-2">
                                    <button type="button" @click="toggleLock(selectedBase())" :disabled="lockingItemId === selectedBase().id" :class="selectedBase().is_locked ? 'border-amber-300 bg-amber-50 text-amber-800' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100'" class="rounded border px-2 py-1 text-xs font-black transition" x-text="lockButtonLabel(selectedBase())"></button>
                                    <button type="button" @click="openPicker('base')" class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-black text-white">変更</button>
                                </div>
                            </div>
                            <p class="mt-1 text-xs font-bold text-slate-500"><span x-text="selectedBase().rank"></span>ランク / <span x-text="selectedBase().weapon_category"></span></p>
                            <p x-show="effectLines(selectedBase(), 'base_performance_lines')" class="mt-2 rounded border border-slate-200 bg-white px-2 py-1.5 text-xs font-bold text-slate-700"><span class="text-slate-500">能力値:</span> <span x-text="effectLines(selectedBase(), 'base_performance_lines')"></span></p>
                            <div class="mt-2 grid gap-1 text-xs font-bold sm:grid-cols-2">
                                <p class="rounded border border-violet-100 bg-violet-50 px-2 py-1.5 text-violet-800">銘: <span x-text="selectedBase().engraving.label"></span><span x-show="effectLines(selectedBase(), 'engraving_effect_lines')"> / <span x-text="effectLines(selectedBase(), 'engraving_effect_lines')"></span></span></p>
                                <p class="rounded border border-sky-100 bg-sky-50 px-2 py-1.5 text-sky-800">特攻: <span x-text="selectedBase().slayer.label"></span><span x-show="effectLines(selectedBase(), 'slayer_effect_lines')"> / <span x-text="effectLines(selectedBase(), 'slayer_effect_lines')"></span></span></p>
                            </div>
                        </div>
                    </template>
                    <template x-if="!selectedBase()">
                        <button type="button" @click="openPicker('base')" class="flex w-full items-center justify-between gap-3 rounded-xl border-2 border-slate-300 bg-white px-4 py-3 text-left shadow-sm transition hover:border-slate-500 hover:bg-slate-50">
                            <div>
                                <p class="text-sm font-black text-slate-900">残したい武器を選ぶ</p>
                                <p class="mt-1 text-xs font-bold text-slate-500">タップして一覧から選択</p>
                            </div>
                            <span class="shrink-0 rounded-lg bg-slate-900 px-3 py-2 text-xs font-black text-white">選ぶ</span>
                        </button>
                    </template>
                    <p class="mt-1 text-xs font-bold text-slate-500">装備中の武器もベースに選べます。市場出品中の武器は選べません。</p>
                </div>

                <div>
                    <p class="mb-1.5 text-sm font-black text-slate-800">2. 消費する素材武器 <span class="text-xs font-bold text-orange-700">（選ぶと消滅）</span></p>
                    <template x-if="selectedMaterial()">
                        <div class="rounded-xl border-2 border-orange-200 bg-orange-50 p-3 shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <p class="min-w-0 font-black text-orange-950" x-text="selectedMaterial().display_name"></p>
                                <div class="flex shrink-0 items-center gap-2">
                                    <button type="button" @click="toggleLock(selectedMaterial())" :disabled="selectedMaterial().is_market_listed || lockingItemId === selectedMaterial().id" :class="selectedMaterial().is_locked ? 'border-amber-300 bg-amber-50 text-amber-800' : 'border-orange-200 bg-white text-orange-800 hover:bg-orange-100'" class="rounded border px-2 py-1 text-xs font-black transition disabled:cursor-not-allowed disabled:opacity-50" x-text="lockButtonLabel(selectedMaterial())"></button>
                                    <button type="button" @click="openPicker('material')" class="rounded-lg bg-orange-600 px-3 py-2 text-xs font-black text-white">変更</button>
                                </div>
                            </div>
                            <p class="mt-1 text-xs font-bold text-orange-700"><span x-text="selectedMaterial().rank"></span>ランク / <span x-text="selectedMaterial().weapon_category"></span></p>
                            <p x-show="effectLines(selectedMaterial(), 'base_performance_lines')" class="mt-2 rounded border border-orange-100 bg-white px-2 py-1.5 text-xs font-bold text-orange-800"><span class="text-orange-700">能力値:</span> <span x-text="effectLines(selectedMaterial(), 'base_performance_lines')"></span></p>
                            <div class="mt-2 grid gap-1 text-xs font-bold sm:grid-cols-2">
                                <p class="rounded border border-violet-100 bg-violet-50 px-2 py-1.5 text-violet-800">銘: <span x-text="selectedMaterial().engraving.label"></span><span x-show="effectLines(selectedMaterial(), 'engraving_effect_lines')"> / <span x-text="effectLines(selectedMaterial(), 'engraving_effect_lines')"></span></span></p>
                                <p class="rounded border border-sky-100 bg-sky-50 px-2 py-1.5 text-sky-800">特攻: <span x-text="selectedMaterial().slayer.label"></span><span x-show="effectLines(selectedMaterial(), 'slayer_effect_lines')"> / <span x-text="effectLines(selectedMaterial(), 'slayer_effect_lines')"></span></span></p>
                            </div>
                        </div>
                    </template>
                    <template x-if="!selectedMaterial()">
                        <button type="button" @click="openPicker('material')" class="flex w-full items-center justify-between gap-3 rounded-xl border-2 border-orange-200 bg-orange-50/50 px-4 py-3 text-left shadow-sm transition hover:border-orange-400 hover:bg-orange-50">
                            <div>
                                <p class="text-sm font-black text-orange-950">消費してよい武器を選ぶ</p>
                                <p class="mt-1 text-xs font-bold text-orange-700">タップして一覧から選択</p>
                            </div>
                            <span class="shrink-0 rounded-lg bg-orange-600 px-3 py-2 text-xs font-black text-white">選ぶ</span>
                        </button>
                    </template>
                    <p class="mt-1 text-xs font-bold text-slate-500">素材武器は消滅します。装備中・保護中・市場出品中の武器は使えません。</p>
                </div>

                <template x-if="lockNotice">
                    <div :class="lockNotice.success ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-800'" class="rounded-lg border px-4 py-3 text-sm font-bold" x-text="lockNotice.message"></div>
                </template>

                <template x-if="preview">
                    <div>
                        <template x-if="!preview.available">
                            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold leading-relaxed text-red-800" x-text="preview.reason"></div>
                        </template>

                        <template x-if="preview.available">
                            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold leading-relaxed text-emerald-950">
                                <p class="text-xs font-black text-emerald-700">完成後の武器名</p>
                                <p class="mt-1 text-base font-black text-emerald-950" x-text="preview.completed_name"></p>
                            </div>
                        </template>
                    </div>
                </template>

                <template x-if="dualPreview">
                    <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm font-bold leading-relaxed text-amber-950">
                        <p class="text-xs font-black tracking-wide text-amber-700">両方一致しているため選べます</p>
                        <p class="mt-1 text-base font-black">銘と特攻をまとめて鍛える</p>
                        <p class="mt-2"><span x-text="dualPreview.engraving_before"></span> → <span x-text="dualPreview.engraving_after"></span></p>
                        <p><span x-text="dualPreview.slayer_before"></span> → <span x-text="dualPreview.slayer_after"></span></p>
                        <div class="mt-3 rounded border border-amber-200 bg-white px-3 py-2">
                            <p class="text-xs font-black text-amber-700">完成後の武器名</p>
                            <p class="mt-1 text-sm font-black" x-text="dualPreview.completed_name"></p>
                        </div>
                        <p class="mt-2">必要Gold: <span class="font-black" x-text="formatGold(dualPreview.gold_cost)"></span> <span class="text-xs text-amber-800">（単独2回より20%お得）</span></p>
                        <button type="submit" name="action" value="dual" class="mt-3 w-full rounded-lg bg-amber-600 px-4 py-3 text-sm font-black text-white shadow-sm transition hover:bg-amber-700">両方まとめて鍛える</button>
                    </div>
                </template>

                <template x-if="preview && preview.available">
                    <div>
                        <p class="mb-2 text-center text-sm font-black text-amber-800">必要Gold: <span x-text="formatGold(preview.gold_cost)"></span></p>
                        <button type="submit" name="action" :value="preview.action" class="w-full rounded-lg bg-slate-900 px-4 py-3 text-sm font-black text-white shadow-sm transition hover:bg-slate-700" x-text="preview.button_label"></button>
                    </div>
                </template>

            </form>

            <template x-if="picker">
                <div @keydown.escape.window="picker = null" class="fixed inset-0 z-[75] overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="trait-picker-title">
                    <div class="flex min-h-screen items-center justify-center p-4 text-center">
                        <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" @click="picker = null" aria-hidden="true"></div>
                        <section @click.stop class="relative z-10 flex w-full max-w-2xl flex-col overflow-hidden rounded-xl bg-white text-left shadow-2xl">
                            <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3 sm:px-5">
                                <div>
                                    <h3 id="trait-picker-title" class="text-base font-black text-slate-900 sm:text-lg" x-text="pickerTitle()"></h3>
                                    <p class="mt-1 text-xs font-bold text-slate-500" x-text="picker === 'base' ? '完成後も残す武器を選びます。' : 'この武器は素材として消滅します。'"></p>
                                </div>
                                <button type="button" @click="picker = null" class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-lg font-black text-slate-500 transition hover:bg-slate-200" aria-label="武器選択を閉じる">×</button>
                            </header>
                            <div class="max-h-[calc(100vh-10rem)] space-y-2 overflow-y-auto p-4 sm:p-5">
                                <template x-if="pickerItems().length === 0">
                                    <p class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-center text-sm font-bold text-slate-500">選べる武器がありません。</p>
                                </template>
                                <template x-for="item in pickerItems()" :key="item.id">
                                    <button type="button" @click="selectPickerItem(item)" :class="isPickerSelected(item) ? 'border-slate-900 bg-slate-100 ring-1 ring-slate-900' : (picker === 'material' ? 'border-orange-200 bg-orange-50/50 hover:border-orange-400' : 'border-slate-200 bg-white hover:border-slate-400')" class="w-full rounded-lg border p-3 text-left transition">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="font-black text-slate-900" x-text="item.display_name"></p>
                                                <p class="mt-1 text-xs font-bold text-slate-500"><span x-text="item.rank"></span>ランク / <span x-text="item.weapon_category"></span></p>
                                            </div>
                                            <span x-show="isPickerSelected(item)" class="shrink-0 rounded bg-slate-900 px-2 py-1 text-xs font-black text-white">選択中</span>
                                        </div>
                                        <p x-show="effectLines(item, 'base_performance_lines')" class="mt-2 rounded border border-slate-200 bg-slate-50 px-2 py-1.5 text-xs font-bold text-slate-700"><span class="text-slate-500">能力値:</span> <span x-text="effectLines(item, 'base_performance_lines')"></span></p>
                                        <div class="mt-2 grid gap-1 text-xs font-bold sm:grid-cols-2">
                                            <p class="rounded border border-violet-100 bg-violet-50 px-2 py-1 text-violet-800">銘: <span x-text="item.engraving.label"></span><span x-show="effectLines(item, 'engraving_effect_lines')"> / <span x-text="effectLines(item, 'engraving_effect_lines')"></span></span></p>
                                            <p class="rounded border border-sky-100 bg-sky-50 px-2 py-1 text-sky-800">特攻: <span x-text="item.slayer.label"></span><span x-show="effectLines(item, 'slayer_effect_lines')"> / <span x-text="effectLines(item, 'slayer_effect_lines')"></span></span></p>
                                        </div>
                                        <div class="mt-2 flex flex-wrap gap-1 text-[11px] font-black">
                                            <span x-show="item.is_equipped" class="rounded bg-amber-100 px-2 py-1 text-amber-800">装備中</span>
                                            <span x-show="item.is_locked" class="rounded bg-yellow-100 px-2 py-1 text-yellow-800">保護中</span>
                                            <span x-show="item.is_market_listed" class="rounded bg-rose-100 px-2 py-1 text-rose-800">市場出品中</span>
                                            <span x-show="picker === 'material' && (item.is_equipped || item.is_locked || item.is_market_listed)" class="rounded bg-red-100 px-2 py-1 text-red-700">このままでは素材に使えません</span>
                                        </div>
                                    </button>
                                </template>
                            </div>
                            <footer class="border-t border-slate-200 bg-slate-50 px-4 py-3 text-right sm:px-5">
                                <button type="button" @click="picker = null" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100">閉じる</button>
                            </footer>
                        </section>
                    </div>
                </div>
            </template>

            <template x-if="confirmation">
                <div @keydown.escape.window="if (!submittingTransfer) confirmation = null" class="fixed inset-0 z-[80] overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="trait-confirmation-title">
                    <div class="flex min-h-screen items-center justify-center p-4 text-center">
                        <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" @click="if (!submittingTransfer) confirmation = null" aria-hidden="true"></div>
                        <section @click.stop class="relative z-10 w-full max-w-md overflow-hidden rounded-xl bg-white text-left shadow-2xl">
                            <header class="border-b border-slate-200 px-4 py-3 sm:px-5">
                                <h3 id="trait-confirmation-title" class="text-base font-black text-slate-900 sm:text-lg">素材武器を消費します。よろしいですか？</h3>
                            </header>
                            <div class="space-y-3 p-4 text-sm leading-relaxed text-slate-700 sm:p-5">
                                <p class="rounded-lg border border-red-200 bg-red-50 p-3 font-bold text-red-800">素材にした武器は消滅し、元に戻せません。</p>
                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <p class="text-xs font-black text-slate-500">残すベース武器</p>
                                    <p class="mt-1 font-black text-slate-900" x-text="confirmation.base_name"></p>
                                </div>
                                <div class="rounded-lg border border-orange-200 bg-orange-50 p-3">
                                    <p class="text-xs font-black text-orange-700">消える素材武器</p>
                                    <p class="mt-1 font-black text-orange-950" x-text="confirmation.material_name"></p>
                                </div>
                                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                                    <p class="text-xs font-black text-emerald-700">完成後の武器名</p>
                                    <p class="mt-1 font-black text-emerald-950" x-text="confirmation.completed_name"></p>
                                </div>
                                <p class="text-center text-base font-black text-amber-800">必要Gold: <span x-text="formatGold(confirmation.gold_cost)"></span></p>
                            </div>
                            <footer class="flex gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-5">
                                <button type="button" @click="confirmation = null" :disabled="submittingTransfer" class="flex-1 rounded-lg border border-slate-300 bg-white px-4 py-3 text-sm font-black text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50">キャンセル</button>
                                <button type="button" @click="confirmTransfer()" :disabled="submittingTransfer" class="flex flex-1 items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-3 text-sm font-black text-white shadow-sm transition hover:bg-slate-700 disabled:cursor-wait disabled:opacity-80">
                                    <svg x-show="submittingTransfer" x-cloak class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"></circle><path class="opacity-90" fill="currentColor" d="M21 12a9 9 0 0 0-9-9v3a6 6 0 0 1 6 6h3Z"></path></svg>
                                    <span x-text="submittingTransfer ? '鍛冶を始めています…' : '素材を消費して移す'"></span>
                                </button>
                            </footer>
                        </section>
                    </div>
                </div>
            </template>

            <template x-if="forgingAnimation">
                <div class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/65 p-6 text-center backdrop-blur-sm" role="status" aria-live="assertive" aria-label="武器を鍛えています">
                    <div class="w-full max-w-xs rounded-2xl border border-amber-200 bg-slate-900 px-6 py-7 text-white shadow-2xl">
                        <div class="relative mx-auto h-20 w-28" aria-hidden="true">
                            <span class="weapon-trait-sword absolute bottom-2 left-1/2 text-5xl">🗡️</span>
                            <span class="weapon-trait-hammer absolute left-[78%] top-0 -translate-x-1/2 text-4xl">🔨</span>
                        </div>
                        <p class="mt-3 text-xl font-black tracking-wider text-amber-300" x-text="forgingSound()"></p>
                        <p class="mt-2 text-sm font-bold text-slate-200">武器を鍛えています…</p>
                    </div>
                </div>
            </template>

            @include('smith.partials.operation-help-modal', ['helpType' => 'traits'])
        </div>
    </div>

    <style>
        @keyframes weapon-trait-hammer-strike {
            0%, 100% { transform: translateX(-50%) translateY(-2px) rotate(-18deg); }
            42% { transform: translateX(-50%) translateY(31px) rotate(12deg); }
            55% { transform: translateX(-50%) translateY(31px) rotate(12deg); }
        }

        .weapon-trait-hammer {
            animation: weapon-trait-hammer-strike 0.42s ease-in-out infinite;
            transform-origin: 20% 90%;
        }

        .weapon-trait-sword {
            transform: translateX(-50%) rotate(90deg);
        }

        @media (prefers-reduced-motion: reduce) {
            .weapon-trait-hammer { animation: none; }
        }
    </style>

    <script>
        function weaponTraitWorkshop(candidates, forgeGoldCosts, dualDiscountRate, initialKind, oldBaseId, oldMaterialId, csrfToken) {
            return {
                candidates,
                forgeGoldCosts,
                dualDiscountRate,
                kind: candidates[initialKind] ? initialKind : 'engraving',
                baseId: oldBaseId || '',
                materialId: oldMaterialId || '',
                csrfToken,
                helpOpen: false,
                lockingItemId: null,
                lockNotice: null,
                confirmation: null,
                submittingTransfer: false,
                pendingTransferForm: null,
                forgingAnimation: false,
                forgingStep: 0,
                picker: null,
                get activeData() {
                    return this.candidates[this.kind];
                },
                setKind(kind) {
                    this.kind = kind;
                    this.materialId = '';
                    this.picker = null;
                    this.confirmation = null;
                },
                openPicker(target) {
                    this.picker = target;
                },
                pickerItems() {
                    const options = this.picker === 'base'
                        ? this.activeData.base_options
                        : this.activeData.material_options;
                    const otherId = this.picker === 'base' ? this.materialId : this.baseId;

                    return options.filter((item) => Number(item.id) !== Number(otherId));
                },
                pickerTitle() {
                    return this.picker === 'base' ? '残したいベース武器を選ぶ' : '消費する素材武器を選ぶ';
                },
                isPickerSelected(item) {
                    const selectedId = this.picker === 'base' ? this.baseId : this.materialId;

                    return Number(item.id) === Number(selectedId);
                },
                selectPickerItem(item) {
                    const otherId = this.picker === 'base' ? this.materialId : this.baseId;
                    if (Number(item.id) === Number(otherId)) return;

                    if (this.picker === 'base') {
                        this.baseId = item.id;
                    } else {
                        this.materialId = item.id;
                    }

                    this.confirmation = null;
                    this.picker = null;
                },
                kindLabel() {
                    return this.kind === 'engraving' ? '銘' : '特攻';
                },
                traitKey() {
                    return this.kind === 'engraving' ? 'engraving' : 'slayer';
                },
                otherTraitKey() {
                    return this.kind === 'engraving' ? 'slayer' : 'engraving';
                },
                selectedBase() {
                    return this.activeData.base_options.find((item) => Number(item.id) === Number(this.baseId)) || null;
                },
                selectedMaterial() {
                    return this.activeData.material_options.find((item) => Number(item.id) === Number(this.materialId)) || null;
                },
                selectedTrait(item) {
                    return item ? item[this.traitKey()] : { id: null, level: 0, label: '' };
                },
                otherTrait(item) {
                    return item ? item[this.otherTraitKey()] : { id: null, level: 0, label: '' };
                },
                effectLines(item, key) {
                    return item?.[key]?.join(' / ') || '';
                },
                optionLabel(item) {
                    return `[${item.rank}] ${item.display_name_without_rank || item.display_name}`;
                },
                materialOptionLabel(item) {
                    return `${this.optionLabel(item)} / ${this.kindLabel()}: ${this.selectedTrait(item).label}`;
                },
                roman(level) {
                    return ['', 'I', 'II', 'III', 'IV', 'V'][level] || String(level);
                },
                formatGold(amount) {
                    return `${new Intl.NumberFormat('ja-JP').format(amount)}G`;
                },
                lockButtonLabel(item) {
                    return item.is_locked ? '★ 保護を解除' : '☆ 保護する';
                },
                syncLockState(itemId, isLocked) {
                    Object.values(this.candidates).forEach((data) => {
                        [data.base_options, data.material_options].forEach((items) => {
                            items.forEach((item) => {
                                if (Number(item.id) === Number(itemId)) item.is_locked = isLocked;
                            });
                        });
                    });
                },
                async toggleLock(item) {
                    if (!item?.lock_url || item.is_market_listed || this.lockingItemId === item.id) return;

                    this.lockNotice = null;
                    this.lockingItemId = item.id;
                    try {
                        const response = await fetch(item.lock_url, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: new URLSearchParams({ _token: this.csrfToken }).toString(),
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok || data.success !== true) throw new Error(data.message || '保護状態を変更できませんでした。');

                        this.syncLockState(item.id, data.is_locked === true);
                        this.lockNotice = { success: true, message: data.message || '保護状態を変更しました。' };
                    } catch (error) {
                        this.lockNotice = { success: false, message: error.message || '保護状態を変更できませんでした。' };
                    } finally {
                        this.lockingItemId = null;
                    }
                },
                confirmTransferBeforeSubmit(event) {
                    const action = event.submitter?.value || this.preview?.action;
                    if (action !== 'transfer') return;

                    event.preventDefault();
                    const base = this.selectedBase();
                    const material = this.selectedMaterial();
                    const preview = this.preview;
                    if (!base || !material || !preview?.available) return;

                    this.pendingTransferForm = event.currentTarget;
                    this.confirmation = {
                        base_name: base.display_name,
                        material_name: material.display_name,
                        completed_name: preview.completed_name,
                        gold_cost: preview.gold_cost,
                    };
                },
                confirmTransfer() {
                    if (!this.confirmation || this.submittingTransfer) return;

                    this.submittingTransfer = true;
                    window.setTimeout(() => {
                        this.confirmation = null;
                        this.forgingAnimation = true;
                        this.forgingStep = 1;

                        window.setTimeout(() => { this.forgingStep = 2; }, 350);
                        window.setTimeout(() => { this.forgingStep = 3; }, 700);
                        window.setTimeout(() => {
                            this.submitConfirmedTransfer();
                        }, 1200);
                    }, 180);
                },
                submitConfirmedTransfer() {
                    const form = this.pendingTransferForm;
                    if (!(form instanceof HTMLFormElement)) {
                        this.forgingAnimation = false;
                        this.submittingTransfer = false;
                        this.lockNotice = { success: false, message: '送信先を確認できませんでした。もう一度お試しください。' };
                        return;
                    }

                    const action = document.createElement('input');
                    action.type = 'hidden';
                    action.name = 'action';
                    action.value = 'transfer';
                    form.appendChild(action);

                    HTMLFormElement.prototype.submit.call(form);
                },
                forgingSound() {
                    return ['', 'カン！', 'カン！ カン！', 'カン！ カン！ カン！'][this.forgingStep] || 'カン！ カン！ カン！';
                },
                qualityLabel(item) {
                    return { good: '【良品】', excellent: '【逸品】' }[item.quality] || '';
                },
                weaponDisplayName(item, engraving, slayer) {
                    const rank = item.rank && item.rank !== '-' ? `[${item.rank}] ` : '';
                    const prefix = engraving?.id ? engraving.label : '';
                    const suffix = slayer?.id ? `・${slayer.label}` : '';
                    const enhance = Number(item.enhance_level) > 0 ? ` +${item.enhance_level}` : '';

                    return `${rank}${prefix}${item.item_name}${suffix}${this.qualityLabel(item)}${enhance}`;
                },
                withTraitLevel(trait, level) {
                    const currentRoman = this.roman(trait.level);
                    const resultRoman = this.roman(level);
                    let label;
                    if (trait.label.endsWith('の')) {
                        const withoutTrailingNo = trait.label.slice(0, -1);
                        const withoutLevel = withoutTrailingNo.endsWith(currentRoman)
                            ? withoutTrailingNo.slice(0, -currentRoman.length)
                            : withoutTrailingNo;
                        label = `${withoutLevel}${resultRoman}の`;
                    } else {
                        label = `${trait.label.replace(currentRoman, '')}${resultRoman}`;
                    }

                    return {
                        ...trait,
                        level,
                        label,
                    };
                },
                canHoldLevel(base, level) {
                    return level <= base.maximum_level;
                },
                rankLimitSummary(item, role) {
                    const rank = item.rank || '-';

                    return `${role}は${rank}ランクです。${rank}ランク武器が保持できる${this.kindLabel()}は${this.roman(item.maximum_level)}までです。`;
                },
                get preview() {
                    const base = this.selectedBase();
                    const material = this.selectedMaterial();
                    if (!base || !material) return null;
                    if (Number(base.id) === Number(material.id)) return { available: false, reason: '同じ武器をベースと素材に選べません。' };
                    if (material.is_equipped) return { available: false, reason: '素材武器は装備中です。先に装備を外してください。' };
                    if (material.is_locked) return { available: false, reason: '素材武器は保護中です。先に保護を解除してください。' };
                    if (material.is_market_listed) return { available: false, reason: '素材武器は市場へ出品中です。先に出品を取り消してください。' };

                    const current = this.selectedTrait(base);
                    const source = this.selectedTrait(material);
                    if (!source.id) return { available: false, reason: `素材武器に${this.kindLabel()}が付いていません。` };
                    if (source.level > base.maximum_level) {
                        return {
                            available: false,
                            reason: `${this.rankLimitSummary(base, 'ベース武器')} 素材の${this.kindLabel()}${this.roman(source.level)}は移せません。`,
                        };
                    }

                    const sameTrait = current.id && Number(current.id) === Number(source.id);
                    if (sameTrait && source.level < current.level) {
                        return { available: false, reason: `同じ${this.kindLabel()}の段階を下げることはできません。` };
                    }

                    if (sameTrait && source.level === current.level) {
                        if (base.weapon_category !== material.weapon_category) {
                            return { available: false, reason: `同じ${this.kindLabel()}・同じ段階でも、段階を上げるには同じ武器種が必要です。` };
                        }

                        const resultLevel = current.level + 1;
                        if (resultLevel > 5) {
                            return { available: false, reason: `${this.kindLabel()}はVが最大段階です。` };
                        }
                        if (!this.canHoldLevel(base, resultLevel)) {
                            return {
                                available: false,
                                reason: `${this.rankLimitSummary(base, 'ベース武器')} 完成後の${this.kindLabel()}${this.roman(resultLevel)}は作れません。`,
                            };
                        }
                        const resultTrait = this.withTraitLevel(current, resultLevel);

                        return {
                            available: true,
                            action: 'forge',
                            title: `${this.kindLabel()}を${this.roman(current.level)}から${this.roman(resultLevel)}へ鍛える`,
                            before: current.label,
                            after: resultTrait.label,
                            completed_name: this.weaponDisplayName(
                                base,
                                this.kind === 'engraving' ? resultTrait : base.engraving,
                                this.kind === 'slayer' ? resultTrait : base.slayer,
                            ),
                            gold_cost: this.forgeGoldCosts[resultLevel] || 0,
                            button_label: `${this.kindLabel()}を1段階上げる`,
                            description: '同じ武器種・同じ特性・同じ段階のため、素材武器を消費して1段階上げます。',
                        };
                    }

                    if (base.is_locked && current.id) {
                        return { available: false, reason: `保護中のベース武器に付いている${this.kindLabel()}は上書きできません。` };
                    }

                    return {
                        available: true,
                        action: 'transfer',
                        title: `${this.kindLabel()}を素材武器から移す`,
                        before: current.label,
                        after: source.label,
                        completed_name: this.weaponDisplayName(
                            base,
                            this.kind === 'engraving' ? source : base.engraving,
                            this.kind === 'slayer' ? source : base.slayer,
                        ),
                        gold_cost: this.activeData.gold_costs[source.level] || 0,
                        button_label: `素材武器を消費して${this.kindLabel()}を移す`,
                        description: '武器種は問いません。素材武器の特性だけを移し、もう一方の特性・品質・+強化値はベース武器に残ります。',
                    };
                },
                get dualPreview() {
                    const primary = this.preview;
                    const base = this.selectedBase();
                    const material = this.selectedMaterial();
                    if (!primary?.available || primary.action !== 'forge' || !base || !material) return null;

                    const engravingBase = base.engraving;
                    const engravingMaterial = material.engraving;
                    const slayerBase = base.slayer;
                    const slayerMaterial = material.slayer;
                    const bothMatch = engravingBase.id && engravingMaterial.id
                        && slayerBase.id && slayerMaterial.id
                        && Number(engravingBase.id) === Number(engravingMaterial.id)
                        && engravingBase.level === engravingMaterial.level
                        && Number(slayerBase.id) === Number(slayerMaterial.id)
                        && slayerBase.level === slayerMaterial.level;
                    if (!bothMatch) return null;

                    const engravingResult = engravingBase.level + 1;
                    const slayerResult = slayerBase.level + 1;
                    if (!this.canHoldLevel(base, engravingResult) || !this.canHoldLevel(base, slayerResult)) return null;
                    const engravingAfter = this.withTraitLevel(engravingBase, engravingResult);
                    const slayerAfter = this.withTraitLevel(slayerBase, slayerResult);

                    return {
                        engraving_before: engravingBase.label,
                        engraving_after: engravingAfter.label,
                        slayer_before: slayerBase.label,
                        slayer_after: slayerAfter.label,
                        completed_name: this.weaponDisplayName(base, engravingAfter, slayerAfter),
                        gold_cost: Math.floor(((this.forgeGoldCosts[engravingResult] || 0) + (this.forgeGoldCosts[slayerResult] || 0)) * this.dualDiscountRate),
                    };
                },
            };
        }
    </script>
</x-layouts.facility>
