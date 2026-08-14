<div
    x-data="{ personalPresetOpen: @js(isset($errors) && ($errors->has('preset') || $errors->has('name'))) }"
    data-job-art-presets
>
    <button
        type="button"
        x-ref="personalPresetButton"
        @click="personalPresetOpen = true; $nextTick(() => $refs.personalPresetDialog?.focus())"
        x-bind:aria-expanded="personalPresetOpen.toString()"
        aria-haspopup="dialog"
        aria-controls="job-art-personal-preset-modal"
        class="flex min-h-10 w-full items-center justify-between gap-3 rounded-lg border border-violet-200 bg-violet-50 px-3 py-2.5 text-left text-sm font-black text-violet-950 transition-colors hover:border-violet-300 hover:bg-violet-100"
        data-job-art-personal-preset-button
    >
        <span class="inline-flex items-center gap-2">
            <span class="text-base text-violet-600" aria-hidden="true">＋</span>
            <span>マイプリセットに登録する</span>
        </span>
        <span class="shrink-0 rounded-full bg-white px-2 py-0.5 text-xs text-violet-700">{{ count($jobArtPresets) }} / {{ $jobArtPresetLimit }}</span>
    </button>

    <p class="mt-1.5 text-[10px] font-bold leading-relaxed text-slate-400">現在の5枠を3件まで保存し、通常・ボス・PvPセットへ呼び出せます。</p>

    <template x-teleport="body">
        <div
            x-cloak
            x-show="personalPresetOpen"
            @keydown.escape.window="personalPresetOpen = false"
            class="fixed inset-0 z-[100] overflow-y-auto overscroll-contain bg-slate-950/70 px-3 py-3 sm:px-6 sm:py-6"
            style="-webkit-overflow-scrolling: touch; overscroll-behavior: contain;"
            role="presentation"
            data-job-art-personal-preset-overlay
        >
            <div class="flex min-h-full items-start justify-center">
                <section
                    id="job-art-personal-preset-modal"
                    x-ref="personalPresetDialog"
                    @click.outside="personalPresetOpen = false"
                    tabindex="-1"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="job-art-personal-preset-title"
                    class="w-full max-w-4xl rounded-xl bg-white shadow-2xl"
                    data-job-art-personal-preset-modal
                >
                    <header class="sticky top-0 z-10 flex items-start justify-between gap-3 rounded-t-xl border-b border-slate-200 bg-slate-950 px-4 py-3 text-white sm:px-5 sm:py-4">
                        <div class="min-w-0">
                            <p class="text-[10px] font-black tracking-[0.14em] text-violet-300">MY PRESETS</p>
                            <h2 id="job-art-personal-preset-title" class="mt-0.5 text-base font-black sm:text-lg">マイプリセット</h2>
                            <p class="mt-1 text-[11px] font-bold leading-relaxed text-slate-300">
                                現在開いている<span class="text-white" x-text="({ normal: '通常', boss: 'ボス', pvp: 'PvP' })[activeContext] ?? activeContext"></span>セットを保存・呼び出しできます。
                            </p>
                        </div>
                        <button type="button" @click="personalPresetOpen = false" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-lg font-black hover:bg-white/20" aria-label="マイプリセットを閉じる">×</button>
                    </header>

                    <div class="space-y-4 p-3 sm:p-5">
                        @if(count($jobArtPresets) < $jobArtPresetLimit)
                            <form method="POST" action="{{ route('job-arts.presets.store') }}" class="rounded-lg border border-violet-200 bg-violet-50/60 p-3" data-job-art-personal-preset-create>
                                @csrf
                                <input type="hidden" name="slot_context" x-bind:value="activeContext">
                                <label class="block text-xs font-black text-slate-800" for="job-art-preset-name">現在の構成を新しく登録</label>
                                <p class="mt-0.5 text-[10px] font-bold text-slate-500">戦技・順番・SP方針をまとめて保存します。</p>
                                <div class="mt-2 flex min-w-0 flex-col gap-2 sm:flex-row">
                                    <input id="job-art-preset-name" type="text" name="name" value="{{ old('name') }}" required maxlength="20" placeholder="例：竜槍・決着型" class="min-w-0 flex-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
                                    <button type="submit" class="shrink-0 rounded-md bg-violet-700 px-4 py-2 text-xs font-black text-white shadow-sm hover:bg-violet-800">マイプリセットに登録する</button>
                                </div>
                            </form>
                        @else
                            <div class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-center text-[11px] font-bold text-violet-800">3件すべて登録済みです。下の「上書き保存」で現在の構成へ更新できます。</div>
                        @endif

                        <div class="grid min-w-0 gap-3 lg:grid-cols-3" data-job-art-personal-preset-list>
                            @forelse($jobArtPresets as $preset)
                                <article class="flex min-w-0 flex-col rounded-lg border border-slate-200 bg-white p-3 shadow-sm" data-job-art-preset-card="{{ $preset['id'] }}">
                                    <div class="flex min-w-0 items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <h3 class="break-words text-sm font-black text-slate-900">{{ $preset['name'] }}</h3>
                                            <p class="mt-0.5 text-[11px] font-bold text-slate-500">{{ $preset['slot_count'] }}戦技 / Cost {{ $preset['cost'] }}</p>
                                            <p class="mt-0.5 text-[10px] font-black text-indigo-700">SP方針：{{ $preset['sp_policy_label'] ?? '積極' }}</p>
                                        </div>
                                        @if($preset['source_context'])
                                            <span class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-black text-slate-500">{{ ['normal' => '通常', 'boss' => 'ボス', 'pvp' => 'PvP'][$preset['source_context']] ?? '保存済み' }}</span>
                                        @endif
                                    </div>

                                    <details class="group mt-3 rounded-lg border border-slate-200 bg-slate-50/70" data-job-art-personal-preset-arts>
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-2.5 py-2 text-[11px] font-black text-slate-700">
                                            <span>登録中の戦技（{{ count($preset['arts'] ?? []) }}枠）</span>
                                            <span class="text-slate-400 transition-transform group-open:rotate-180" aria-hidden="true">⌄</span>
                                        </summary>
                                        <ol class="space-y-1.5 border-t border-slate-200 p-2">
                                            @forelse(($preset['arts'] ?? []) as $art)
                                                <li class="flex min-w-0 items-start gap-2 rounded-md bg-white px-2 py-1.5 text-[11px]">
                                                    <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded bg-slate-700 text-[10px] font-black text-white">{{ $art['slot_no'] }}</span>
                                                    <div class="min-w-0 flex-1">
                                                        <div class="break-words font-black text-slate-800">{{ $art['name'] }} <span class="text-[10px] text-slate-400">{{ $art['role_label'] }}</span></div>
                                                    </div>
                                                </li>
                                            @empty
                                                <li class="rounded-md bg-white px-2 py-2 text-[11px] font-bold text-slate-500">このプリセットに登録された戦技はありません。</li>
                                            @endforelse
                                        </ol>
                                    </details>

                                    <div
                                        x-show="!(@js($preset['application_statuses'])[activeContext]?.can_apply ?? false)"
                                        class="mt-2 rounded-md border border-rose-100 bg-rose-50 px-2 py-1.5 text-[11px] font-bold leading-relaxed text-rose-700"
                                    >
                                        <div class="font-black">現在は呼び出せません</div>
                                        <div x-text="@js($preset['application_statuses'])[activeContext]?.reason ?? '現在の条件では呼び出せません。'"></div>
                                    </div>

                                    <div class="mt-3 space-y-2">
                                        <form method="POST" action="{{ route('job-arts.presets.apply', $preset['id']) }}" onsubmit="return confirm('現在のセットをこのプリセットで置き換えます。続けますか？');">
                                            @csrf
                                            <input type="hidden" name="slot_context" x-bind:value="activeContext">
                                            <button type="submit" x-bind:disabled="!(@js($preset['application_statuses'])[activeContext]?.can_apply ?? false)" class="w-full rounded-md bg-violet-700 px-2 py-2 text-xs font-black text-white hover:bg-violet-800 disabled:cursor-not-allowed disabled:bg-slate-300">このプリセットを呼び出す</button>
                                        </form>

                                        <form method="POST" action="{{ route('job-arts.presets.overwrite', $preset['id']) }}" onsubmit="return confirm('このプリセットを現在のセット内容で上書きします。続けますか？');">
                                            @csrf
                                            <input type="hidden" name="slot_context" x-bind:value="activeContext">
                                            <button type="submit" class="w-full rounded-md border border-violet-300 bg-white px-2 py-2 text-xs font-black text-violet-800 hover:bg-violet-50">現在の構成で上書き保存</button>
                                        </form>
                                    </div>

                                    <details class="mt-3 border-t border-slate-100 pt-2">
                                        <summary class="cursor-pointer list-none text-center text-[11px] font-black text-slate-500">名前変更・削除</summary>
                                        <form method="POST" action="{{ route('job-arts.presets.update', $preset['id']) }}" class="mt-2 flex min-w-0 gap-2">
                                            @csrf
                                            @method('PATCH')
                                            <input type="text" name="name" value="{{ $preset['name'] }}" required maxlength="20" class="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-2 text-xs">
                                            <button type="submit" class="shrink-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-[11px] font-black text-slate-700">変更</button>
                                        </form>
                                        <form method="POST" action="{{ route('job-arts.presets.destroy', $preset['id']) }}" onsubmit="return confirm('このマイプリセットを削除しますか？');" class="mt-2">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="w-full rounded-md border border-rose-200 bg-white px-2 py-2 text-[11px] font-black text-rose-700">このプリセットを削除</button>
                                        </form>
                                    </details>
                                </article>
                            @empty
                                <div class="rounded-lg border border-dashed border-violet-200 bg-white px-3 py-8 text-center text-xs font-bold text-slate-500 lg:col-span-3">まだ登録されていません。上の欄から現在の構成を保存できます。</div>
                            @endforelse
                        </div>
                    </div>

                    <footer class="sticky bottom-0 z-10 rounded-b-xl border-t border-slate-200 bg-slate-50 px-4 py-3 text-right sm:px-5">
                        <button type="button" @click="personalPresetOpen = false; $nextTick(() => $refs.personalPresetButton?.focus())" class="inline-flex min-h-10 items-center justify-center rounded-md border border-slate-300 bg-white px-4 text-xs font-black text-slate-700 hover:bg-slate-100">閉じる</button>
                    </footer>
                </section>
            </div>
        </div>
    </template>
</div>
