<div
    x-data="{
        starterPresetOpen: false,
        starterPresetLoading: false,
        starterPresetLoaded: false,
        starterPresetError: '',
        async openStarterPresets() {
            this.starterPresetOpen = true;
            this.$nextTick(() => this.$refs.starterPresetDialog?.focus());
            if (this.starterPresetLoaded || this.starterPresetLoading) return;

            this.starterPresetLoading = true;
            this.starterPresetError = '';
            try {
                const response = await fetch(@js(route('job-arts.starter-presets', ['slot_context' => $slotContext])), {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || typeof payload.html !== 'string') {
                    throw new Error(payload.message || '公式プリセットを読み込めませんでした。');
                }
                this.$refs.starterPresetContent.innerHTML = payload.html;
                this.starterPresetLoaded = true;
            } catch (error) {
                this.starterPresetError = error.message || '公式プリセットを読み込めませんでした。';
            } finally {
                this.starterPresetLoading = false;
            }
        },
    }"
    data-job-art-starter-presets="{{ $slotContext }}"
>
    <button
        type="button"
        x-ref="starterPresetLink"
        @click="openStarterPresets()"
        x-bind:aria-expanded="starterPresetOpen.toString()"
        aria-haspopup="dialog"
        aria-controls="job-art-starter-preset-modal-{{ $slotContext }}"
        class="inline-flex min-h-8 items-center gap-1 text-xs font-black text-indigo-700 underline decoration-indigo-300 underline-offset-4 hover:text-indigo-900"
        data-job-art-starter-preset-link
    >
        <span>公式プリセットから選ぶ</span>
        <span class="text-[10px] text-slate-400 no-underline">（{{ $starterPresetCount }}件）</span>
    </button>

    <template x-teleport="body">
        <div
            x-cloak
            x-show="starterPresetOpen"
            @keydown.escape.window="starterPresetOpen = false"
            class="fixed inset-0 z-[100] overflow-y-auto overscroll-contain bg-slate-950/70 px-3 py-3 sm:px-6 sm:py-6"
            style="-webkit-overflow-scrolling: touch; overscroll-behavior: contain;"
            role="presentation"
            data-job-art-starter-preset-overlay="{{ $slotContext }}"
            data-job-art-starter-preset-scroll
        >
            <div class="flex min-h-full items-start justify-center">
                <section
                    id="job-art-starter-preset-modal-{{ $slotContext }}"
                    x-ref="starterPresetDialog"
                    @click.outside="starterPresetOpen = false"
                    tabindex="-1"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="job-art-starter-preset-title-{{ $slotContext }}"
                    class="w-full max-w-5xl rounded-xl bg-white shadow-2xl"
                    data-job-art-starter-preset-modal="{{ $slotContext }}"
                >
                    <header
                        class="flex items-start justify-between gap-3 rounded-t-xl border-b border-slate-200 bg-slate-950 px-4 py-3 text-white sm:px-5 sm:py-4"
                        data-job-art-starter-preset-header
                    >
                        <div class="min-w-0">
                            <p class="text-[10px] font-black tracking-[0.14em] text-slate-300">{{ $slotContextLabel }}セットへ適用</p>
                            <h2 id="job-art-starter-preset-title-{{ $slotContext }}" class="mt-0.5 break-words text-base font-black sm:text-lg">全10系譜の公式プリセット</h2>
                            <p class="mt-1 text-[11px] font-bold leading-relaxed text-slate-300">現在の職業に関係なく、30件すべてから選べます。未習得技の自動差し替えや部分適用は行いません。</p>
                        </div>
                        <button type="button" @click="starterPresetOpen = false" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-lg font-black hover:bg-white/20" aria-label="公式プリセットを閉じる">×</button>
                    </header>

                    <div class="p-3 sm:p-5">
                        <div
                            x-show="starterPresetLoading"
                            class="flex min-h-40 flex-col items-center justify-center gap-3 rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-10 text-center text-sm font-black text-indigo-700"
                            aria-live="polite"
                            aria-busy="true"
                        >
                            <span
                                class="h-10 w-10 animate-spin rounded-full border-4 border-indigo-200 border-t-indigo-700"
                                aria-hidden="true"
                                data-job-art-starter-preset-spinner
                            ></span>
                            <span>公式プリセットを読み込んでいます…</span>
                        </div>
                        <div x-show="starterPresetError !== ''" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-6 text-center" aria-live="assertive">
                            <p class="text-sm font-black text-rose-700" x-text="starterPresetError"></p>
                            <button type="button" @click="starterPresetLoaded = false; openStarterPresets()" class="mt-3 rounded-md bg-rose-700 px-4 py-2 text-xs font-black text-white">再読み込み</button>
                        </div>
                        <div x-ref="starterPresetContent" x-show="starterPresetLoaded"></div>
                    </div>

                    <footer
                        class="rounded-b-xl border-t border-slate-200 bg-slate-50 px-4 py-3 text-right sm:px-5"
                        data-job-art-starter-preset-footer
                    >
                        <button type="button" @click="starterPresetOpen = false" class="inline-flex min-h-10 items-center justify-center rounded-md border border-slate-300 bg-white px-4 text-xs font-black text-slate-700 hover:bg-slate-100">閉じる</button>
                    </footer>
                </section>
            </div>
        </div>
    </template>
</div>
