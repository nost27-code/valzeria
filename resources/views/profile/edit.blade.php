<x-layouts.facility title="プロフィール編集" headerIcon="✎" bgImage="images/valmon/ranch_bg.webp">
    @php
        $currentCardBackground = old('profile_card_background', $selectedCardBackground);
        $currentCardFrame = old('profile_card_frame', $selectedCardFrame);
        $currentAvatarFrame = old('profile_avatar_frame', $selectedAvatarFrame);
        $currentValmonCase = old('profile_valmon_case', $selectedValmonCase);
        $currentCardSkin = old('selected_card_skin', $selectedCardSkin);
        $currentFavoriteWeaponIds = collect(old('favorite_weapon_ids', $selectedFavoriteWeaponIds))->map(fn ($id) => (int) $id)->all();
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
                  } else if (window.location.hash === '#card_skin') {
                      $nextTick(() => {
                          const cardSkinField = document.getElementById('card_skin');
                          if (cardSkinField) {
                              cardSkinField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                          }
                      });
                  }
              "
              x-data="{
                  selectedCardBackground: @js($currentCardBackground),
                  selectedCardFrame: @js($currentCardFrame),
                  selectedAvatarFrame: @js($currentAvatarFrame),
                  selectedValmonCase: @js($currentValmonCase),
                  selectedCardSkin: @js($currentCardSkin),
                  selectedFavoriteWeaponIds: @js($currentFavoriteWeaponIds),
                  favoriteWeaponPage: @js($favoriteWeaponPage),
                  favoriteWeaponsLoading: false,
                  favoriteWeaponsError: '',
                  async loadFavoriteWeaponPage(page) {
                      if (this.favoriteWeaponsLoading || page < 1 || page > this.favoriteWeaponPage.last_page) return;
                      this.favoriteWeaponsLoading = true;
                      this.favoriteWeaponsError = '';
                      try {
                          const response = await fetch(`{{ route('profile.favorite-weapons') }}?page=${page}`, {
                              headers: { Accept: 'application/json' },
                          });
                          if (!response.ok) throw new Error('favorite weapons request failed');
                          this.favoriteWeaponPage = await response.json();
                      } catch {
                          this.favoriteWeaponsError = '武器一覧を読み込めませんでした。時間をおいてもう一度お試しください。';
                      } finally {
                          this.favoriteWeaponsLoading = false;
                      }
                  },
                  toggleFavoriteWeapon(id, checked) {
                      id = Number(id);
                      const index = this.selectedFavoriteWeaponIds.indexOf(id);
                      if (checked && index === -1) {
                          if (this.selectedFavoriteWeaponIds.length >= 3) return false;
                          this.selectedFavoriteWeaponIds.push(id);
                      } else if (!checked && index !== -1) {
                          this.selectedFavoriteWeaponIds.splice(index, 1);
                      }
                      return true;
                  }
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
                    <div id="card_skin" class="scroll-mt-20">
                        <div class="mb-2 flex flex-wrap items-center gap-2">
                            <span class="text-xs font-black text-slate-700">カードデザイン</span>
                            @if($supportPassStatus['active'] ?? false)
                                <span class="rounded bg-emerald-100 px-2 py-0.5 text-[10px] font-black text-emerald-700">
                                    冒険者支援パス 有効 / あと{{ number_format((int) ($supportPassStatus['remaining_days'] ?? 0)) }}日
                                </span>
                            @else
                                <span class="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-black text-slate-500">支援パス未加入</span>
                            @endif
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach($cardSkinOptions as $option)
                                @php
                                    $isSupportSkin = $option['value'] === \App\Services\SupportPassService::CARD_SKIN_SUPPORT_PASS;
                                    $isSupportBlueGoldSkin = $option['value'] === \App\Services\SupportPassService::CARD_SKIN_SUPPORT_PASS_BLUE_GOLD;
                                    $isPassSkin = $isSupportSkin || $isSupportBlueGoldSkin;
                                @endphp
                                <label class="rounded-lg border bg-white p-3 shadow-sm transition"
                                       :class="selectedCardSkin === @js($option['value']) ? 'border-[#d4af37] ring-2 ring-[#d4af37]/30' : 'border-slate-200'"
                                       @if(!($option['selectable'] ?? false)) title="支援パスカードは冒険者支援パス有効中のみ選択できます" @endif>
                                    <input type="radio"
                                           name="selected_card_skin"
                                           value="{{ $option['value'] }}"
                                           class="sr-only"
                                           x-model="selectedCardSkin"
                                           @disabled(!($option['selectable'] ?? false))>
                                    <div class="flex items-start gap-3">
                                        <div class="relative grid h-14 w-14 shrink-0 place-items-center overflow-hidden rounded-md border {{ $isSupportBlueGoldSkin ? 'border-sky-300 bg-gradient-to-br from-slate-950 via-blue-700 to-sky-300 text-sky-50 shadow-inner' : ($isSupportSkin ? 'border-amber-300 bg-gradient-to-br from-amber-50 via-white to-sky-100 text-amber-800 shadow-inner' : 'border-slate-200 bg-slate-50 text-slate-500') }}">
                                            @if($isPassSkin)
                                                <span class="absolute inset-x-2 top-2 h-px {{ $isSupportBlueGoldSkin ? 'bg-sky-100/80' : 'bg-amber-300/70' }}"></span>
                                                <span class="absolute inset-x-2 bottom-2 h-px {{ $isSupportBlueGoldSkin ? 'bg-sky-100/80' : 'bg-amber-300/70' }}"></span>
                                            @endif
                                            <span class="relative text-lg font-black">{{ $isPassSkin ? 'PASS' : 'N' }}</span>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-sm font-black text-slate-900">{{ $option['label'] }}</div>
                                            <p class="mt-1 text-[11px] font-bold leading-relaxed text-slate-500">{{ $option['description'] }}</p>
                                            @if(!($option['selectable'] ?? false))
                                                <div class="mt-2 inline-flex rounded bg-amber-50 px-2 py-0.5 text-[10px] font-black text-amber-700">{{ $option['button_label'] }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                        @error('selected_card_skin')
                            <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div>
                        @enderror
                        @if(in_array(($selectedCardSkin ?? 'default'), [\App\Services\SupportPassService::CARD_SKIN_SUPPORT_PASS, \App\Services\SupportPassService::CARD_SKIN_SUPPORT_PASS_BLUE_GOLD], true) && !in_array($displayedCardSkin, [\App\Services\SupportPassService::CARD_SKIN_SUPPORT_PASS, \App\Services\SupportPassService::CARD_SKIN_SUPPORT_PASS_BLUE_GOLD], true))
                            <p class="mt-2 rounded bg-slate-50 px-3 py-2 text-[11px] font-bold leading-relaxed text-slate-500">
                                支援パスカードの選択は保存されています。冒険者支援パスが有効になると、再び支援パスカードで表示されます。
                            </p>
                        @endif
                    </div>

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

            @if($favoriteWeaponsEnabled)
                <section id="favorite_weapons" class="scroll-mt-20 rounded-lg border border-[#d4af37]/50 bg-white p-4 shadow-sm">
                    <div class="mb-3">
                        <div class="text-sm font-black text-slate-900">お気に入り武器 <span class="text-emerald-700">3本</span></div>
                        <div class="mt-0.5 text-xs font-bold text-slate-500">冒険者カードに飾る、所持中の武器を最大3本選べます。</div>
                    </div>

                    <template x-if="favoriteWeaponPage.total">
                        <div>
                            <template x-for="id in selectedFavoriteWeaponIds" :key="`selected-favorite-${id}`">
                                <input type="hidden" name="favorite_weapon_ids[]" :value="id">
                            </template>
                            <div class="relative grid grid-cols-3 gap-2" :class="favoriteWeaponsLoading ? 'pointer-events-none opacity-50' : ''">
                                <template x-for="weapon in favoriteWeaponPage.weapons" :key="weapon.id">
                                    <label class="cursor-pointer overflow-hidden rounded-xl border bg-white shadow-sm transition"
                                           :class="selectedFavoriteWeaponIds.includes(weapon.id) ? 'border-amber-400 ring-2 ring-amber-400/60 shadow-[0_0_0_3px_rgba(251,191,36,0.16)]' : 'border-slate-200 hover:border-slate-300'"
                                           :style="weapon.quality ? `border-color: ${weapon.quality.border_color}` : ''">
                                        <input type="checkbox"
                                               :value="weapon.id"
                                               :checked="selectedFavoriteWeaponIds.includes(weapon.id)"
                                               class="sr-only"
                                               @change="if (!toggleFavoriteWeapon(weapon.id, $event.target.checked)) $event.target.checked = false">
                                        <div class="relative grid aspect-square place-items-center bg-white p-1.5 sm:p-2" :style="weapon.quality ? `background: ${weapon.quality.display_background}` : ''">
                                            <img :src="weapon.image" :alt="weapon.name" class="h-full w-full object-contain drop-shadow-sm">
                                            <template x-if="weapon.rank">
                                                <span class="absolute left-1 top-1 inline-flex h-5 min-w-5 items-center justify-center rounded px-1 text-[10px] font-black leading-none text-white shadow-sm" :style="`background-color: ${weapon.rank_color}`" x-text="weapon.rank"></span>
                                            </template>
                                            <template x-if="selectedFavoriteWeaponIds.includes(weapon.id)">
                                                <span class="absolute right-1 top-1 rounded-md border border-amber-100 bg-[#51350f] px-1.5 py-1 text-[10px] font-black leading-none text-amber-50 shadow-sm"><span x-text="selectedFavoriteWeaponIds.indexOf(weapon.id) + 1"></span>番目</span>
                                            </template>
                                            <span class="absolute bottom-1 right-1 rounded-full border px-1.5 py-0.5 font-black leading-none" :style="`color: ${weapon.enhance_style.color}; background-color: ${weapon.enhance_style.background}; border-color: ${weapon.enhance_style.border_color}; font-size: ${weapon.enhance_style.font_size}; box-shadow: ${weapon.enhance_style.shadow}`">+<span x-text="weapon.enhance_level"></span></span>
                                        </div>
                                        <div class="border-t border-slate-100 px-1.5 py-1.5 sm:px-2">
                                            <div class="mb-0.5 flex h-5 items-center overflow-hidden">
                                                <template x-if="weapon.quality">
                                                    <span class="rounded border px-1 py-px text-[9px] font-black leading-tight shadow-sm" :style="`color: ${weapon.quality.color}; background-color: ${weapon.quality.background}; border-color: ${weapon.quality.border_color}`" x-text="weapon.quality.label"></span>
                                                </template>
                                            </div>
                                            <div class="break-words text-xs font-black leading-snug text-slate-800" x-text="weapon.name"></div>
                                            <div x-show="weapon.engraving || weapon.killer" class="mt-1 flex items-center gap-1 whitespace-nowrap text-[9px] font-black leading-tight">
                                                <template x-if="weapon.engraving"><span :style="`color: ${weapon.engraving.color}`" x-text="weapon.engraving.label"></span></template>
                                                <template x-if="weapon.engraving && weapon.killer"><span class="text-slate-300">/</span></template>
                                                <template x-if="weapon.killer"><span :style="`color: ${weapon.killer.color}`" x-text="weapon.killer.label"></span></template>
                                            </div>
                                        </div>
                                    </label>
                                </template>
                            </div>
                            <div x-show="favoriteWeaponPage.last_page > 1" class="mt-3 flex items-center justify-between gap-3">
                                <button type="button" class="min-h-9 rounded-lg border border-slate-300 bg-white px-3 text-xs font-black text-slate-700 shadow-sm disabled:cursor-not-allowed disabled:opacity-40" :disabled="favoriteWeaponsLoading || favoriteWeaponPage.current_page <= 1" @click="loadFavoriteWeaponPage(favoriteWeaponPage.current_page - 1)">前へ</button>
                                <span class="text-xs font-black text-slate-500"><span x-text="favoriteWeaponPage.current_page"></span> / <span x-text="favoriteWeaponPage.last_page"></span></span>
                                <button type="button" class="min-h-9 rounded-lg border border-slate-300 bg-white px-3 text-xs font-black text-slate-700 shadow-sm disabled:cursor-not-allowed disabled:opacity-40" :disabled="favoriteWeaponsLoading || favoriteWeaponPage.current_page >= favoriteWeaponPage.last_page" @click="loadFavoriteWeaponPage(favoriteWeaponPage.current_page + 1)">次へ</button>
                            </div>
                            <p x-show="favoriteWeaponsError" x-text="favoriteWeaponsError" class="mt-2 text-xs font-bold text-red-600"></p>
                        </div>
                    </template>
                    <template x-if="!favoriteWeaponPage.total">
                        <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-4 text-center text-xs font-bold text-slate-500">飾れる武器をまだ所持していません。</div>
                    </template>
                    @error('favorite_weapon_ids')
                        <div class="mt-2 text-xs font-bold text-red-600">{{ $message }}</div>
                    @enderror
                </section>
            @endif

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
