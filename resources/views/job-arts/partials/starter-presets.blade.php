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
    @php
        $starterPresetHighlighted = (bool) ($starterPresetHighlighted ?? false);
        $compact = (bool) ($compact ?? false);
    @endphp
    @if($compact)
        <div class="border-t border-slate-200 px-3 py-2" data-job-art-starter-preset-launcher data-job-art-starter-preset-compact>
            <button
                type="button"
                x-ref="starterPresetLink"
                @click="openStarterPresets()"
                x-bind:aria-expanded="starterPresetOpen.toString()"
                aria-haspopup="dialog"
                aria-controls="job-art-starter-preset-modal-{{ $slotContext }}"
                class="flex min-h-10 w-full items-center gap-2 text-left transition-colors hover:text-indigo-800"
                data-job-art-starter-preset-link
            >
                @if($starterPresetHighlighted)
                    <span class="shrink-0 rounded-md bg-amber-50 px-2 py-1 text-[9px] font-black text-amber-700">期間限定おすすめ</span>
                @else
                    <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-sm text-indigo-700" aria-hidden="true">✦</span>
                @endif
                <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-700" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 10h16v10H4V10Zm8 0v10M3 7h18v3H3V7Zm9 0H8.5a2.5 2.5 0 1 1 2.2-3.7L12 7Zm0 0h3.5a2.5 2.5 0 1 0-2.2-3.7L12 7Z" />
                    </svg>
                </span>
                <span class="min-w-0 flex-1 text-xs font-black text-slate-800">公式プリセットから選ぶ</span>
                <span class="shrink-0 text-[11px] font-black text-indigo-700">{{ $starterPresetCount }}件</span>
                <span class="shrink-0 text-base text-slate-300" aria-hidden="true">›</span>
            </button>
        </div>
    @else
        <div @class([
            'rounded-xl border px-3 py-2.5' => $starterPresetHighlighted,
            'border-amber-300 bg-gradient-to-r from-amber-50 to-indigo-50 shadow-sm' => $starterPresetHighlighted,
        ]) data-job-art-starter-preset-launcher>
            @if($starterPresetHighlighted)
                <div class="mb-1.5 flex items-center gap-2">
                    <span class="rounded-full bg-amber-500 px-2 py-0.5 text-[9px] font-black tracking-wide text-white">期間限定おすすめ</span>
                    <span class="text-[10px] font-bold text-slate-600">迷った時は、動作確認済みの構成から始められます。</span>
                </div>
            @endif
            <button
                type="button"
                x-ref="starterPresetLink"
                @click="openStarterPresets()"
                x-bind:aria-expanded="starterPresetOpen.toString()"
                aria-haspopup="dialog"
                aria-controls="job-art-starter-preset-modal-{{ $slotContext }}"
                @class([
                    'inline-flex min-h-8 items-center gap-1 text-xs font-black transition-colors',
                    'w-full justify-center rounded-lg bg-indigo-600 px-3 py-2 text-white shadow-sm hover:bg-indigo-700' => $starterPresetHighlighted,
                    'text-indigo-700 underline decoration-indigo-300 underline-offset-4 hover:text-indigo-900' => ! $starterPresetHighlighted,
                ])
                data-job-art-starter-preset-link
            >
                <span>公式プリセットから選ぶ</span>
                <span @class([
                    'text-[10px] no-underline',
                    'text-indigo-100' => $starterPresetHighlighted,
                    'text-slate-400' => ! $starterPresetHighlighted,
                ])>（{{ $starterPresetCount }}件）</span>
            </button>
        </div>
    @endif

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
