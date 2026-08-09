<details class="mt-4 rounded-lg border border-violet-200 bg-violet-50/60" data-job-art-presets>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 text-sm font-black text-violet-950">
        <span>マイプリセット</span>
        <span class="rounded-full bg-white px-2 py-0.5 text-xs text-violet-700">{{ count($jobArtPresets) }} / {{ $jobArtPresetLimit }}</span>
    </summary>

    <div class="space-y-3 border-t border-violet-100 px-3 py-3">
        <p class="text-[11px] font-bold leading-relaxed text-violet-800/80">現在の5枠を名前付きで保存し、開いている通常・ボス・PvPセットへ呼び出せます。</p>

        @forelse($jobArtPresets as $preset)
            <article class="min-w-0 rounded-lg border border-violet-100 bg-white p-3 shadow-sm" data-job-art-preset-card="{{ $preset['id'] }}">
                <div class="flex min-w-0 items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h3 class="break-words text-sm font-black text-slate-900">{{ $preset['name'] }}</h3>
                        <p class="mt-0.5 text-[11px] font-bold text-slate-500">{{ $preset['slot_count'] }}戦技 / Cost {{ $preset['cost'] }}</p>
                    </div>
                    @if($preset['source_context'])
                        <span class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-black text-slate-500">{{ ['normal' => '通常から保存', 'boss' => 'ボスから保存', 'pvp' => 'PvPから保存'][$preset['source_context']] ?? '保存済み' }}</span>
                    @endif
                </div>

                <div
                    x-show="!(@js($preset['application_statuses'])[activeContext]?.can_apply ?? false)"
                    class="mt-2 rounded-md border border-rose-100 bg-rose-50 px-2 py-1.5 text-[11px] font-bold leading-relaxed text-rose-700"
                >
                        <div class="font-black">現在は適用できません</div>
                        <div x-text="@js($preset['application_statuses'])[activeContext]?.reason ?? '現在の条件では適用できません。'"></div>
                </div>

                <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                    <form method="POST" action="{{ route('job-arts.presets.apply', $preset['id']) }}">
                        @csrf
                        <input type="hidden" name="slot_context" x-bind:value="activeContext">
                        <button type="submit" x-bind:disabled="!(@js($preset['application_statuses'])[activeContext]?.can_apply ?? false)" class="w-full rounded-md bg-violet-700 px-2 py-2 text-xs font-black text-white disabled:cursor-not-allowed disabled:bg-slate-300">この構成を適用</button>
                    </form>

                    <details class="contents">
                        <summary class="cursor-pointer list-none rounded-md border border-slate-300 bg-white px-2 py-2 text-center text-xs font-black text-slate-700">名前変更</summary>
                        <form method="POST" action="{{ route('job-arts.presets.update', $preset['id']) }}" class="col-span-2 flex min-w-0 gap-2 sm:col-span-3">
                            @csrf
                            @method('PATCH')
                            <input type="text" name="name" value="{{ $preset['name'] }}" required maxlength="20" class="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-2 text-sm">
                            <button type="submit" class="shrink-0 rounded-md border border-violet-300 bg-violet-50 px-3 py-2 text-xs font-black text-violet-800">変更</button>
                        </form>
                    </details>

                    <form method="POST" action="{{ route('job-arts.presets.destroy', $preset['id']) }}" onsubmit="return confirm('このマイプリセットを削除しますか？');" class="col-span-2 sm:col-span-1">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="w-full rounded-md border border-rose-200 bg-white px-2 py-2 text-xs font-black text-rose-700">削除</button>
                    </form>
                </div>
            </article>
        @empty
            <div class="rounded-lg border border-dashed border-violet-200 bg-white/70 px-3 py-4 text-center text-xs font-bold text-slate-500">保存したマイプリセットはまだありません。</div>
        @endforelse

        @if(count($jobArtPresets) < $jobArtPresetLimit)
            <form method="POST" action="{{ route('job-arts.presets.store') }}" class="space-y-2 rounded-lg border border-violet-200 bg-white p-3">
                @csrf
                <input type="hidden" name="slot_context" x-bind:value="activeContext">
                <label class="block text-xs font-black text-slate-700" for="job-art-preset-name">現在の構成を保存</label>
                <div class="flex min-w-0 flex-col gap-2 sm:flex-row">
                    <input id="job-art-preset-name" type="text" name="name" value="{{ old('name') }}" required maxlength="20" placeholder="例：竜槍・循環型" class="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <button type="submit" class="shrink-0 rounded-md bg-violet-700 px-3 py-2 text-xs font-black text-white">＋ 保存する</button>
                </div>
            </form>
        @else
            <p class="text-center text-[11px] font-bold text-slate-500">上限に達しています。不要なプリセットを削除すると新しく保存できます。</p>
        @endif
    </div>
</details>
