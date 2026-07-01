<x-layouts.facility title="プロフィール編集" headerIcon="✎" bgImage="images/valmon/ranch_bg.webp">
    @php
        $currentCardBackground = old('profile_card_background', $selectedCardBackground);
        $currentCardFrame = old('profile_card_frame', $selectedCardFrame);
        $currentAvatarFrame = old('profile_avatar_frame', $selectedAvatarFrame);
        $currentValmonCase = old('profile_valmon_case', $selectedValmonCase);
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
              x-init="
                  if (window.location.hash === '#profile_comment') {
                      $nextTick(() => {
                          const commentField = document.getElementById('profile_comment');
                          if (commentField) {
                              commentField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                              commentField.focus();
                          }
                      });
                  }
              "
              x-data="{
                  selectedCardBackground: @js($currentCardBackground),
                  selectedCardFrame: @js($currentCardFrame),
                  selectedAvatarFrame: @js($currentAvatarFrame),
                  selectedValmonCase: @js($currentValmonCase)
              }">
            @csrf

            <section class="rounded-lg border border-[#d4af37]/50 bg-white p-4 shadow-sm">
                <div class="mb-3 flex items-center gap-3">
                    <div class="relative flex h-16 w-16 shrink-0 items-center justify-center">
                        @if($character->icon_path)
                            <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($character->icon_path) }}" alt="{{ $character->name }}" class="h-[64%] w-[64%] object-contain drop-shadow-sm">
                        @else
                            <div class="flex h-[64%] w-[64%] items-center justify-center text-2xl text-slate-400">?</div>
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
                    <div class="text-sm font-black text-slate-900">冒険者カード</div>
                    <div class="mt-0.5 text-xs font-bold text-slate-500">入手済みの背景・四角枠・キャラ枠だけを選択できます。</div>
                </div>

                <div class="mt-4 space-y-4">
                    <div>
                        <div class="mb-2 text-xs font-black text-slate-700">背景</div>
                        <div class="grid grid-cols-5 gap-1.5">
                            @foreach($cardBackgrounds as $asset)
                                <label class="cursor-pointer overflow-hidden rounded-lg border bg-white shadow-sm transition"
                                       :class="selectedCardBackground === @js($asset['path']) ? 'border-[#d4af37] ring-2 ring-[#d4af37]/30' : 'border-slate-200 hover:border-slate-300'">
                                    <input type="radio" name="profile_card_background" value="{{ $asset['path'] }}" class="sr-only" x-model="selectedCardBackground">
                                    <div class="relative w-full bg-slate-100" style="aspect-ratio: 1;">
                                        <img src="{{ asset($asset['path']) }}" alt="{{ $asset['label'] }}" class="h-full w-full object-cover">
                                    </div>
                                    <div class="truncate px-1 py-1 text-[10px] font-black text-slate-700">{{ $asset['label'] }}</div>
                                </label>
                            @endforeach
                        </div>
                        @error('profile_card_background')
                            <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <div class="mb-2 text-xs font-black text-slate-700">四角枠</div>
                        <div class="grid grid-cols-5 gap-1.5">
                            @foreach($cardFrames as $asset)
                                <label class="cursor-pointer overflow-hidden rounded-lg border bg-white shadow-sm transition"
                                       :class="selectedCardFrame === @js($asset['path']) ? 'border-[#d4af37] ring-2 ring-[#d4af37]/30' : 'border-slate-200 hover:border-slate-300'">
                                    <input type="radio" name="profile_card_frame" value="{{ $asset['path'] }}" class="sr-only" x-model="selectedCardFrame">
                                    <div class="relative w-full bg-slate-50" style="aspect-ratio: 1;">
                                        <img src="{{ asset($asset['path']) }}" alt="{{ $asset['label'] }}" class="h-full w-full object-contain">
                                    </div>
                                    <div class="truncate px-1 py-1 text-[10px] font-black text-slate-700">{{ $asset['label'] }}</div>
                                </label>
                            @endforeach
                        </div>
                        @error('profile_card_frame')
                            <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <div class="mb-2 text-xs font-black text-slate-700">キャラ枠</div>
                        <div class="grid grid-cols-5 gap-1.5">
                            @foreach($avatarFrames as $asset)
                                <label class="cursor-pointer overflow-hidden rounded-lg border bg-white shadow-sm transition"
                                       :class="selectedAvatarFrame === @js($asset['path']) ? 'border-[#d4af37] ring-2 ring-[#d4af37]/30' : 'border-slate-200 hover:border-slate-300'">
                                    <input type="radio" name="profile_avatar_frame" value="{{ $asset['path'] }}" class="sr-only" x-model="selectedAvatarFrame">
                                    <div class="relative grid w-full place-items-center bg-slate-50" style="aspect-ratio: 1;">
                                        <img src="{{ asset($asset['path']) }}" alt="{{ $asset['label'] }}" class="h-full w-full object-contain">
                                    </div>
                                    <div class="truncate px-1 py-1 text-[10px] font-black text-slate-700">{{ $asset['label'] }}</div>
                                </label>
                            @endforeach
                        </div>
                        @error('profile_avatar_frame')
                            <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <div class="mb-2 text-xs font-black text-slate-700">ヴァルモン</div>
                        <div class="grid grid-cols-5 gap-1.5">
                            @foreach($valmonCases as $asset)
                                <label class="cursor-pointer overflow-hidden rounded-lg border bg-white shadow-sm transition"
                                       :class="selectedValmonCase === @js($asset['path']) ? 'border-[#d4af37] ring-2 ring-[#d4af37]/30' : 'border-slate-200 hover:border-slate-300'">
                                    <input type="radio" name="profile_valmon_case" value="{{ $asset['path'] }}" class="sr-only" x-model="selectedValmonCase">
                                    <div class="relative w-full bg-slate-100" style="aspect-ratio: 1;">
                                        <img src="{{ asset($asset['path']) }}" alt="{{ $asset['label'] }}" class="h-full w-full object-cover">
                                    </div>
                                    <div class="truncate px-1 py-1 text-[10px] font-black text-slate-700">{{ $asset['label'] }}</div>
                                </label>
                            @endforeach
                        </div>
                        @error('profile_valmon_case')
                            <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
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
    </div>
</x-layouts.facility>
