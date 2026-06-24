<x-layouts.facility title="プロフィール編集" headerIcon="✎" bgImage="images/valmon/ranch_bg.webp">
    @php
        $currentBackground = old('profile_ranch_background', $selectedBackground);
        $currentFrameTheme = old('profile_frame_theme', $selectedFrameTheme);
    @endphp

    <div class="mx-auto max-w-3xl space-y-4">
        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
                入力内容を確認してください。
            </div>
        @endif

        @if (session('message'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">
                {{ session('message') }}
            </div>
        @endif

        <form method="POST"
              action="{{ route('profile.update') }}"
              class="space-y-4"
              x-data="{ selectedBackground: @js($currentBackground), selectedFrameTheme: @js($currentFrameTheme) }">
            @csrf

            <section class="rounded-lg border border-[#d4af37]/50 bg-white p-4 shadow-sm">
                <div class="mb-3 flex items-center gap-3">
                    <div class="h-14 w-14 shrink-0 overflow-hidden bg-white">
                        @if($character->icon_path)
                            <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($character->icon_path) }}" alt="{{ $character->name }}" class="h-full w-full object-contain">
                        @else
                            <div class="flex h-full w-full items-center justify-center text-2xl text-slate-400">?</div>
                        @endif
                    </div>
                    <div class="min-w-0">
                        <div class="truncate text-xl font-black text-[#003366]">{{ $character->name }}</div>
                        <div class="mt-0.5 text-xs font-bold text-slate-500">Lv.{{ $character->level }} / {{ $character->jobClass?->name ?? '冒険者' }}</div>
                    </div>
                </div>

                <label for="profile_comment" class="text-sm font-black text-slate-900">一言コメント</label>
                <textarea id="profile_comment"
                          name="profile_comment"
                          rows="4"
                          maxlength="160"
                          class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-bold leading-relaxed text-slate-800 shadow-inner focus:border-[#d4af37] focus:ring-2 focus:ring-[#d4af37]/30"
                          placeholder="よろしくお願いします">{{ old('profile_comment', $character->profile_comment) }}</textarea>
                @error('profile_comment')
                    <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>
                @enderror
            </section>

            <section class="rounded-lg border border-[#d4af37]/50 bg-white p-4 shadow-sm">
                <div class="mb-3">
                    <div class="text-sm font-black text-slate-900">プロフィール枠</div>
                    <div class="mt-0.5 text-xs font-bold text-slate-500">街にいる冒険者名をタップしたときのプロフィール枠を変更できます。</div>
                </div>

                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach($frameThemes as $theme)
                        <label class="cursor-pointer rounded-xl border bg-gradient-to-br p-3 shadow-sm transition {{ $theme['preview_class'] }}"
                               :class="selectedFrameTheme === @js($theme['code']) ? 'ring-2 ring-[#d4af37]/35 shadow-md' : 'opacity-85 hover:opacity-100'">
                            <input type="radio"
                                   name="profile_frame_theme"
                                   value="{{ $theme['code'] }}"
                                   class="sr-only"
                                   x-model="selectedFrameTheme">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-sm font-black text-slate-900">{{ $theme['label'] }}</div>
                                <div class="h-5 w-5 rounded-full border-2 border-white bg-white/60 shadow-inner">
                                    <div x-show="selectedFrameTheme === @js($theme['code'])" class="m-1 h-3 w-3 rounded-full bg-[#d4af37]"></div>
                                </div>
                            </div>
                            <div class="mt-3 flex items-center gap-2 rounded-lg border border-white/70 bg-white/55 p-2 shadow-inner">
                                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border-2 border-white bg-white/80 shadow-sm">
                                    @if($character->icon_path)
                                        <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($character->icon_path) }}" alt="" class="h-8 w-8 object-contain">
                                    @else
                                        <span class="text-sm font-black text-slate-400">?</span>
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="h-2 w-20 rounded-full bg-slate-900/70"></div>
                                    <div class="mt-1.5 h-1.5 w-14 rounded-full bg-slate-400/50"></div>
                                </div>
                            </div>
                            <div class="mt-2 text-xs font-bold leading-relaxed text-slate-600">{{ $theme['description'] }}</div>
                        </label>
                    @endforeach
                </div>
                @error('profile_frame_theme')
                    <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>
                @enderror
            </section>

            <section class="rounded-lg border border-[#d4af37]/50 bg-white p-4 shadow-sm">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <div class="text-sm font-black text-slate-900">牧場背景</div>
                        <div class="mt-0.5 text-xs font-bold text-slate-500">所持している背景だけをプロフィールに設定できます。</div>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg border border-slate-200 shadow-sm">
                    <div class="relative w-full overflow-hidden" style="aspect-ratio: 16/9;">
                        @foreach($backgrounds as $background)
                            <div x-show="selectedBackground === @js($background['path'])"
                                 class="absolute inset-0 bg-cover bg-center"
                                 style="background-image: url('{{ asset($background['path']) }}');"></div>
                        @endforeach
                        <div class="absolute left-3 top-3 rounded-full bg-black/55 px-3 py-1 text-xs font-black text-white shadow">プレビュー</div>
                        <div class="absolute inset-x-0 bottom-0 h-[18%] pointer-events-none"
                             style="background:linear-gradient(to top, rgba(255,255,255,0.95) 0%, transparent 100%);"></div>
                    </div>
                </div>

                <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach($backgrounds as $background)
                        <label class="cursor-pointer overflow-hidden rounded-lg border bg-white shadow-sm transition"
                               :class="selectedBackground === @js($background['path']) ? 'border-[#d4af37] ring-2 ring-[#d4af37]/30' : 'border-slate-200 hover:border-slate-300'">
                            <input type="radio"
                                   name="profile_ranch_background"
                                   value="{{ $background['path'] }}"
                                   class="sr-only"
                                   x-model="selectedBackground">
                            <div class="relative w-full bg-slate-100" style="aspect-ratio: 16/9;">
                                <img src="{{ asset($background['path']) }}" alt="{{ $background['label'] }}" class="h-full w-full object-cover">
                            </div>
                            <div class="truncate px-2 py-1.5 text-xs font-black text-slate-700">{{ $background['label'] }}</div>
                        </label>
                    @endforeach
                </div>
                @error('profile_ranch_background')
                    <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>
                @enderror
            </section>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 bg-white px-5 text-sm font-black text-slate-700 shadow-sm hover:bg-slate-50">
                    戻る
                </a>
                <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-[#1e3a8a] bg-[#1e40af] px-5 text-sm font-black text-white shadow-sm hover:bg-[#1e3a8a]">
                    保存する
                </button>
            </div>
        </form>

        <section class="rounded-lg border border-[#d4af37]/50 bg-white p-4 shadow-sm">
            <div class="mb-3">
                <div class="text-sm font-black text-slate-900">プロフィール枠の解放</div>
                <div class="mt-0.5 text-xs font-bold leading-relaxed text-slate-500">
                    地方限定素材10個を装飾片1個に圧縮し、装飾片10個でその地方のプロフィール枠を解放できます。
                </div>
            </div>

            <div class="space-y-2">
                @foreach($frameUnlocks as $unlock)
                    <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <div class="text-sm font-black text-slate-900">{{ $unlock['label'] }}</div>
                                    @if($unlock['unlocked'])
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-black text-emerald-700">解放済み</span>
                                    @else
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-black text-amber-700">未解放</span>
                                    @endif
                                </div>
                                <div class="mt-1 text-xs font-bold text-slate-500">
                                    地方素材 {{ number_format($unlock['regional_material_count']) }}個 /
                                    装飾片 {{ number_format($unlock['fragment_count']) }}個
                                </div>
                                @if(!empty($unlock['material_names']))
                                    <div class="mt-1 truncate text-[11px] font-bold text-slate-400">
                                        対象: {{ implode('、', $unlock['material_names']) }}
                                    </div>
                                @endif
                            </div>

                            <div class="flex shrink-0 flex-col gap-2">
                                <form method="POST" action="{{ route('profile.frame.compress') }}">
                                    @csrf
                                    <input type="hidden" name="profile_frame_theme" value="{{ $unlock['code'] }}">
                                    <button type="submit"
                                            @disabled(!$unlock['can_compress'])
                                            class="inline-flex min-h-9 items-center justify-center rounded-lg border border-amber-300 bg-white px-3 text-xs font-black text-amber-700 shadow-sm enabled:hover:bg-amber-50 disabled:cursor-not-allowed disabled:opacity-45">
                                        10個を圧縮
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('profile.frame.unlock') }}">
                                    @csrf
                                    <input type="hidden" name="profile_frame_theme" value="{{ $unlock['code'] }}">
                                    <button type="submit"
                                            @disabled(!$unlock['can_unlock'])
                                            class="inline-flex min-h-9 items-center justify-center rounded-lg border border-[#1e3a8a] bg-[#1e40af] px-3 text-xs font-black text-white shadow-sm enabled:hover:bg-[#1e3a8a] disabled:cursor-not-allowed disabled:opacity-45">
                                        枠を解放
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</x-layouts.facility>
