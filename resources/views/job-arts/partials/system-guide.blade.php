@php
    $lineageGuides = collect($lineageGuides ?? []);
    $guideMaxSlots = (int) ($maxSlots ?? 5);
    $guideMaxCost = (int) ($maxCost ?? 9);
    $compact = (bool) ($compact ?? false);
@endphp

<div
    class="{{ $compact ? 'shrink-0' : 'w-full sm:ml-auto sm:w-auto' }}"
    x-data="{
        guideOpen: false,
        guideOpener: null,
        openGuide(event) {
            this.guideOpener = event.currentTarget;
            this.guideOpen = true;
            this.$nextTick(() => this.$refs.guideDialog?.focus());
        },
        closeGuide() {
            this.guideOpen = false;
            this.$nextTick(() => this.guideOpener?.focus?.());
        },
    }"
    data-job-art-system-guide
>
    <button
        type="button"
        @click="openGuide($event)"
        x-bind:aria-expanded="guideOpen.toString()"
        aria-haspopup="dialog"
        aria-controls="job-art-system-guide-modal"
        class="inline-flex items-center justify-center gap-1.5 border border-indigo-200 bg-indigo-50 font-black text-indigo-700 shadow-sm transition-colors hover:border-indigo-300 hover:bg-indigo-100 {{ $compact ? 'h-7 rounded-full px-2 text-[10px]' : 'min-h-9 w-full rounded-lg px-3 text-[11px] sm:w-auto' }}"
        data-job-art-system-guide-link
    >
        <span class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-indigo-600 text-[10px] text-white" aria-hidden="true">?</span>
        <span class="{{ $compact ? 'hidden sm:inline' : '' }}">戦技セットの解説を見る</span>
        @if($compact)
            <span class="sm:hidden">解説</span>
        @endif
    </button>

    <template x-teleport="body">
        <div
            x-cloak
            x-show="guideOpen"
            @keydown.escape.window="if (guideOpen) closeGuide()"
            class="fixed inset-0 z-[100] overflow-y-auto overscroll-contain bg-slate-950/70 px-3 py-3 sm:px-6 sm:py-6"
            style="-webkit-overflow-scrolling: touch; overscroll-behavior: contain;"
            role="presentation"
            data-job-art-system-guide-overlay
        >
            <div class="flex min-h-full items-start justify-center">
                <section
                    id="job-art-system-guide-modal"
                    x-ref="guideDialog"
                    @click.outside="closeGuide()"
                    tabindex="-1"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="job-art-system-guide-title"
                    aria-describedby="job-art-system-guide-description"
                    class="w-full max-w-3xl overflow-hidden rounded-xl bg-slate-50 shadow-2xl"
                    data-job-art-system-guide-modal
                >
                    <header class="flex items-start justify-between gap-3 bg-slate-950 px-4 py-3 text-white sm:px-5 sm:py-4">
                        <div class="min-w-0">
                            <p class="text-[10px] font-black tracking-[0.14em] text-indigo-200">はじめての戦技セット</p>
                            <h2 id="job-art-system-guide-title" class="mt-0.5 text-lg font-black sm:text-xl">5枠の順番と系譜リソース</h2>
                            <p id="job-art-system-guide-description" class="mt-1 text-[11px] font-bold leading-relaxed text-slate-300">
                                戦技が候補になる順番、リソースの貯め方、PvPの奥義予告をまとめています。
                            </p>
                        </div>
                        <button type="button" @click="closeGuide()" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-lg font-black hover:bg-white/20" aria-label="戦技セットの解説を閉じる">×</button>
                    </header>

                    <div class="space-y-4 p-3 sm:p-5">
                        <section class="grid gap-3 sm:grid-cols-2" aria-label="系譜とリソースの基本">
                            <article class="rounded-xl border border-indigo-100 bg-white p-3 shadow-sm">
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-100 text-sm font-black text-indigo-700" aria-hidden="true">系</span>
                                    <h3 class="text-sm font-black text-slate-950">系譜とは？</h3>
                                </div>
                                <p class="mt-2 text-[11px] font-bold leading-relaxed text-slate-600">
                                    戦技ごとの「戦い方」と「使うリソース」を示す分類です。反撃・冥蝕・貫通など10種類あり、職業そのものを固定するものではありません。習得済みなら異なる系譜を混ぜても、カードに書かれた威力と効果をそのまま使えます。
                                </p>
                            </article>

                            <article class="rounded-xl border border-emerald-100 bg-white p-3 shadow-sm">
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-100 text-sm font-black text-emerald-700" aria-hidden="true">資</span>
                                    <h3 class="text-sm font-black text-slate-950">リソースとは？</h3>
                                </div>
                                <p class="mt-2 text-[11px] font-bold leading-relaxed text-slate-600">
                                    SPとは別に、戦闘中だけ0〜12で貯まる系譜ごとの力です。始動や系譜固有の行動で増え、原則として連携は4、奥義は12を使います。系譜ごとに別々に貯まり、戦闘が終わるとリセットされます。
                                </p>
                            </article>
                        </section>

                        <section class="rounded-xl border border-sky-200 bg-white p-3 shadow-sm" data-job-art-system-guide-order>
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <h3 class="text-sm font-black text-slate-950">5枠の順番には意味があります</h3>
                                <span class="text-[10px] font-black text-sky-700">1 → 2 → 3 → 4 → 5 → 1…</span>
                            </div>

                            <div class="mt-3 grid grid-cols-5 gap-1.5" aria-label="戦技の候補順">
                                @for($slotNo = 1; $slotNo <= $guideMaxSlots; $slotNo++)
                                    <div class="rounded-lg border border-sky-100 bg-sky-50 px-1 py-2 text-center">
                                        <div class="text-base font-black text-sky-800">{{ $slotNo }}</div>
                                        <div class="text-[9px] font-black text-sky-600">枠目</div>
                                    </div>
                                @endfor
                            </div>

                            <ol class="mt-3 space-y-2 text-[11px] font-bold leading-relaxed text-slate-600">
                                <li class="flex gap-2"><span class="shrink-0 font-black text-sky-700">1.</span><span>戦闘開始時は1枠目から見て、必要なリソース・SPなどを満たす最初の1枠だけを発動候補にします。</span></li>
                                <li class="flex gap-2"><span class="shrink-0 font-black text-sky-700">2.</span><span>候補になった戦技は1回だけ発動抽選します。基礎発動率は始動50%・連携55%・奥義60%で、戦技や場の効果によって増減します。発動しなくても、同じ手番で後ろの枠を再抽選しません。</span></li>
                                <li class="flex gap-2"><span class="shrink-0 font-black text-sky-700">3.</span><span>次の自分の行動では、直前に候補になった枠の次から見ます。5枠目の次は1枠目へ戻るため、5枚に順番に出番が回ります。</span></li>
                            </ol>

                            <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-[10px] font-bold leading-relaxed text-amber-900">
                                例外として、使用条件を満たした奥義、PvPで相手の奥義予告へ応答できる戦技、カード本文に候補優先とある効果は先に判定されます。同じ優先度なら、セットした順番が先の戦技から判定します。
                            </p>
                        </section>

                        <section class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm" data-job-art-system-guide-resources>
                            <div>
                                <h3 class="text-sm font-black text-slate-950">系譜ごとのリソースの貯め方</h3>
                                <p class="mt-1 text-[10px] font-bold leading-relaxed text-slate-500">
                                    セットした戦技が使う系譜だけが、戦闘中の獲得対象になります。
                                </p>
                            </div>

                            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                @foreach($lineageGuides as $guide)
                                    <article class="rounded-lg border border-slate-200 bg-slate-50/70 p-2.5" data-job-art-system-guide-lineage="{{ $guide['lineage_key'] }}">
                                        <div class="flex flex-wrap items-baseline justify-between gap-1.5">
                                            <h4 class="text-xs font-black text-slate-900">{{ $guide['lineage_name'] }}系譜</h4>
                                            <span class="rounded-full bg-white px-2 py-0.5 text-[9px] font-black text-indigo-700 shadow-sm">{{ $guide['resource_name'] }}</span>
                                        </div>
                                        <dl class="mt-2 space-y-1.5 text-[10px] font-bold leading-relaxed text-slate-600">
                                            <div>
                                                <dt class="font-black text-slate-400">始動・固有の直接獲得</dt>
                                                <dd class="mt-0.5 text-slate-700">{{ $guide['direct_gain'] }}</dd>
                                            </div>
                                            <div>
                                                <dt class="font-black text-slate-400">共通獲得</dt>
                                                <dd class="mt-0.5 text-slate-700">{{ $guide['common_gain'] }}</dd>
                                            </div>
                                        </dl>
                                    </article>
                                @endforeach
                            </div>

                            <p class="mt-3 rounded-lg border border-rose-100 bg-rose-50 px-3 py-2 text-[10px] font-bold leading-relaxed text-rose-800">
                                1回の戦技で同じリソースを直接増減した場合、その行動のHIT・自傷などによる共通獲得は重なりません。たとえば「血潮の咆哮」は使用成立で冥蝕+4となり、自傷分を足して+6にはなりません。
                            </p>
                        </section>

                        <section class="rounded-xl border border-amber-200 bg-white p-3 shadow-sm" data-job-art-system-guide-pvp>
                            <h3 class="text-sm font-black text-slate-950">PvPの奥義予告と100ターン判定</h3>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <article class="rounded-lg bg-amber-50 p-3">
                                    <h4 class="text-xs font-black text-amber-950">奥義は予告してから発動</h4>
                                    <ol class="mt-2 space-y-1.5 text-[10px] font-bold leading-relaxed text-amber-900">
                                        <li>1. セットした奥義と同じ系譜の連携を1回実行する</li>
                                        <li>2. 奥義に必要なリソースまで貯める</li>
                                        <li>3. 奥義予告が出て、相手に次の1行動が渡る</li>
                                        <li>4. 準備が残れば、その後に奥義が発動候補になる</li>
                                    </ol>
                                    <p class="mt-2 text-[10px] font-bold leading-relaxed text-amber-800">相手は予告中の1行動で、対応する連携による中断・遅延・軽減などを狙えます。</p>
                                </article>

                                <article class="rounded-lg bg-slate-100 p-3">
                                    <h4 class="text-xs font-black text-slate-950">ランク戦は最大100ターン</h4>
                                    <p class="mt-2 text-[10px] font-bold leading-relaxed text-slate-700">
                                        1ターン内で双方が敏捷の高い順に行動し、どちらかを倒せばその時点で決着します。100ターン終了時に双方が生存している場合、最大HPに対する残りHPの割合を比べます。挑戦者の割合が防衛者より高ければ挑戦者の判定勝利、同じ割合または防衛者の方が高ければ防衛成功になります。
                                    </p>
                                </article>
                            </div>
                        </section>

                        <section class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm" data-job-art-system-guide-rules>
                            <h3 class="text-sm font-black text-slate-950">セット時のそのほかのルール</h3>
                            <ul class="mt-2 grid gap-2 text-[10px] font-bold leading-relaxed text-slate-700 sm:grid-cols-2">
                                <li class="rounded-lg bg-slate-50 px-3 py-2">最大{{ $guideMaxSlots }}枠、合計Costは{{ $guideMaxCost }}までです。空き枠があっても保存できます。</li>
                                <li class="rounded-lg bg-slate-50 px-3 py-2">始動はCost1、連携はCost2、奥義はCost3です。</li>
                                <li class="rounded-lg bg-slate-50 px-3 py-2">奥義は1セットにつき1つまで。同じ戦技を複数枠へ入れることもできません。</li>
                                <li class="rounded-lg bg-slate-50 px-3 py-2">習得済みで、その戦闘種別に使用できる戦技だけをセットできます。</li>
                                <li class="rounded-lg bg-slate-50 px-3 py-2">通常・ボス・PvPは別々のセットです。変更するとその場で自動保存されます。</li>
                                <li class="rounded-lg bg-slate-50 px-3 py-2">SP方針は5枠へ一括適用されます。通常はSP30%以上、温存はSP60%以上で候補になります。</li>
                            </ul>
                        </section>
                    </div>

                    <footer class="border-t border-slate-200 bg-white px-4 py-3 text-right sm:px-5">
                        <button type="button" @click="closeGuide()" class="inline-flex min-h-10 items-center justify-center rounded-md border border-slate-300 bg-white px-4 text-xs font-black text-slate-700 hover:bg-slate-100">閉じる</button>
                    </footer>
                </section>
            </div>
        </div>
    </template>
</div>
