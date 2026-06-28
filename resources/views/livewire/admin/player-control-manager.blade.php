<div class="w-full px-4 py-8 sm:px-6 lg:px-8">
    @php
        $fieldClass = 'w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow-sm focus:border-amber-400 focus:ring-2 focus:ring-amber-200';
        $labelClass = 'text-xs font-black uppercase tracking-wide text-slate-500';
    @endphp

    <div class="mb-6">
        <div class="text-xs font-bold tracking-[0.35em] text-orange-600">PLAYER CONTROLS</div>
        <h1 class="mt-2 text-3xl font-black text-slate-950">プレイヤー運用調整</h1>
        <p class="mt-2 text-sm font-semibold text-slate-600">問い合わせ対応や検証用に、プレイヤー単位の運用値を調整します。</p>
    </div>

    @if (session()->has('message'))
        <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800 shadow-sm">
            {{ session('message') }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[420px_1fr]">
        <section class="rounded-md border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-black text-slate-950">送付先プレイヤー検索</h2>
                <p class="mt-1 text-xs font-semibold text-slate-500">キャラ名、キャラID、ユーザー名、ユーザーID、メールアドレスで検索できます。</p>
            </div>
            <div class="p-5">
                <input type="search" wire:model.live.debounce.250ms="search" class="{{ $fieldClass }}" placeholder="例: アベル / 123 / user@example.com">

                <div class="mt-4 space-y-2">
                    @foreach($characters as $character)
                        @php $active = (int) $selectedCharacterId === (int) $character->id; @endphp
                        <button
                            type="button"
                            wire:click="selectCharacter({{ $character->id }})"
                            class="w-full rounded-md border px-4 py-3 text-left transition {{ $active ? 'border-amber-400 bg-amber-50 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50' }}"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <div class="font-black text-slate-950">{{ $character->name }}</div>
                                        <span class="rounded bg-slate-900 px-2 py-0.5 text-[11px] font-black text-amber-200">CID {{ $character->id }}</span>
                                        @if($character->is_frozen)
                                            <span class="rounded bg-red-600 px-2 py-0.5 text-[11px] font-black text-white">凍結中</span>
                                        @endif
                                    </div>
                                    <div class="mt-1 flex flex-wrap gap-2 text-xs font-bold text-slate-500">
                                        <span>Lv{{ $character->level }}</span>
                                        <span>{{ $character->currentJob?->name ?? '職業なし' }}</span>
                                        <span>UID {{ $character->user_id }}</span>
                                    </div>
                                    <div class="mt-1 text-xs font-semibold text-slate-500">{{ $character->user?->name ?? 'ユーザー名なし' }}</div>
                                    <div class="mt-1 rounded bg-white px-2 py-1 text-xs font-black text-slate-700 shadow-sm ring-1 ring-slate-200">{{ $character->user?->email ?? 'メール未登録' }}</div>
                                </div>
                                <div class="shrink-0 rounded bg-slate-100 px-2 py-1 text-xs font-black text-slate-600">
                                    {{ number_format((int) ($character->material_storage_limit ?? 500)) }} / {{ number_format((int) ($character->equipment_storage_limit ?? 200)) }}
                                </div>
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>
        </section>

        <div class="space-y-6">
            {{-- 凍結 --}}
            <section class="rounded-md border border-red-200 bg-white shadow-sm">
                <div class="border-b border-red-200 bg-red-50 px-5 py-4">
                    <h2 class="text-lg font-black text-red-900">アカウント凍結</h2>
                    <p class="mt-1 text-xs font-semibold text-red-600">凍結中はそのキャラクターで戦闘・チャンプ挑戦ができなくなります。</p>
                </div>
                @if($selectedCharacter)
                    <div class="p-5">
                        @if($selectedCharacter->is_frozen)
                            <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3">
                                <div class="text-sm font-black text-red-800">凍結中</div>
                                <div class="mt-1 text-xs font-bold text-red-700">理由: {{ $selectedCharacter->freeze_reason ?? '-' }}</div>
                                <div class="mt-1 text-xs font-bold text-red-500">凍結日時: {{ $selectedCharacter->frozen_at?->format('Y/m/d H:i') ?? '-' }}</div>
                            </div>
                            <button type="button" wire:click="unfreezeCharacter"
                                    wire:confirm="{{ $selectedCharacter->name }} の凍結を解除しますか？"
                                    class="rounded-md bg-emerald-600 px-5 py-2.5 text-sm font-black text-white shadow-sm hover:bg-emerald-700">
                                凍結を解除する
                            </button>
                        @else
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                                <div class="flex-1">
                                    <label class="text-xs font-black uppercase tracking-wide text-slate-500">凍結理由（必須）</label>
                                    <input type="text" wire:model="freezeReason" maxlength="255" placeholder="例: 規約違反・不正行為の疑い"
                                           class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold shadow-sm focus:border-red-400 focus:ring-2 focus:ring-red-100">
                                    @error('freezeReason') <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div> @enderror
                                </div>
                                <button type="button" wire:click="freezeCharacter"
                                        wire:confirm="{{ $selectedCharacter->name }} を凍結しますか？この操作はいつでも解除できます。"
                                        class="shrink-0 rounded-md bg-red-600 px-5 py-2.5 text-sm font-black text-white shadow-sm hover:bg-red-700">
                                    凍結する
                                </button>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="px-5 py-8 text-center text-sm font-bold text-slate-400">左でキャラクターを選択してください。</div>
                @endif
            </section>

            <section class="rounded-md border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-lg font-black text-slate-950">倉庫上限</h2>
                    <p class="mt-1 text-xs font-semibold text-slate-500">素材倉庫と装備倉庫の最大数を個別に調整します。</p>
                </div>

                @if($selectedCharacter)
                    <form wire:submit.prevent="saveStorageLimits" class="p-5">
                        <div class="mb-5 rounded-md border border-slate-200 bg-slate-50 p-4">
                            <div class="text-sm font-black text-slate-950">{{ $selectedCharacter->name }}</div>
                            <div class="mt-1 text-xs font-bold text-slate-500">
                                ID {{ $selectedCharacter->id }} / {{ $selectedCharacter->user?->email ?? '-' }}
                            </div>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="{{ $labelClass }}">素材倉庫上限</label>
                                <input type="number" min="1" max="999999" wire:model="materialStorageLimit" class="mt-1 {{ $fieldClass }}">
                                @error('materialStorageLimit') <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">装備倉庫上限</label>
                                <input type="number" min="1" max="999999" wire:model="equipmentStorageLimit" class="mt-1 {{ $fieldClass }}">
                                @error('equipmentStorageLimit') <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        @if($storageSummary)
                            <div class="mt-5 grid gap-3 md:grid-cols-2">
                                <div class="rounded-md border border-emerald-200 bg-emerald-50 p-4">
                                    <div class="text-xs font-black text-emerald-700">現在の素材倉庫</div>
                                    <div class="mt-1 text-2xl font-black text-slate-950">{{ number_format($storageSummary['material_total']) }} / {{ number_format($storageSummary['material_limit']) }}</div>
                                </div>
                                <div class="rounded-md border border-amber-200 bg-amber-50 p-4">
                                    <div class="text-xs font-black text-amber-700">現在の装備倉庫</div>
                                    <div class="mt-1 text-2xl font-black text-slate-950">{{ number_format($storageSummary['equipment_total']) }} / {{ number_format($storageSummary['equipment_limit']) }}</div>
                                </div>
                            </div>
                        @endif

                        <div class="mt-5 flex justify-end">
                            <button type="submit" class="rounded-md bg-slate-950 px-5 py-2.5 text-sm font-black text-white shadow-sm hover:bg-slate-800">
                                上限を保存
                            </button>
                        </div>
                    </form>
                @else
                    <div class="p-5">
                        <div class="rounded-md border border-slate-200 bg-slate-50 p-5 text-sm font-bold text-slate-600">
                            左の一覧からキャラクターを選択してください。
                        </div>
                    </div>
                @endif
            </section>

            <section class="rounded-md border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-lg font-black text-slate-950">探索クールタイム</h2>
                    <p class="mt-1 text-xs font-semibold text-slate-500">通常探索の連戦待機と、宿屋後の探索待機を解除します。</p>
                </div>

                @if($selectedCharacter && $cooldownSummary)
                    <div class="p-5">
                        <div class="grid gap-3 md:grid-cols-2">
                            <div class="rounded-md border border-slate-200 bg-slate-50 p-4">
                                <div class="text-xs font-black text-slate-500">最終戦闘</div>
                                <div class="mt-1 text-sm font-bold text-slate-900">{{ $cooldownSummary['last_battle_at'] }}</div>
                                <div class="mt-2 text-xs font-black {{ $cooldownSummary['battle_remaining'] > 0 ? 'text-amber-700' : 'text-emerald-700' }}">
                                    {{ $cooldownSummary['battle_remaining'] > 0 ? 'あと ' . $cooldownSummary['battle_remaining'] . ' 秒' : '待機なし' }}
                                </div>
                            </div>
                            <div class="rounded-md border border-slate-200 bg-slate-50 p-4">
                                <div class="text-xs font-black text-slate-500">宿屋後待機</div>
                                <div class="mt-1 text-sm font-bold text-slate-900">{{ $cooldownSummary['exploration_cooldown_until'] }}</div>
                                <div class="mt-2 text-xs font-black {{ $cooldownSummary['inn_remaining'] > 0 ? 'text-amber-700' : 'text-emerald-700' }}">
                                    {{ $cooldownSummary['inn_remaining'] > 0 ? 'あと ' . $cooldownSummary['inn_remaining'] . ' 秒' : '待機なし' }}
                                </div>
                            </div>
                        </div>
                        <div class="mt-5 flex justify-end">
                            <button type="button" wire:click="clearExplorationCooldown" class="rounded-md bg-amber-500 px-5 py-2.5 text-sm font-black text-slate-950 shadow-sm hover:bg-amber-400">
                                探索待機を解除
                            </button>
                        </div>
                    </div>
                @else
                    <div class="p-5">
                        <div class="rounded-md border border-slate-200 bg-slate-50 p-5 text-sm font-bold text-slate-600">
                            左の一覧からキャラクターを選択してください。
                        </div>
                    </div>
                @endif
            </section>

            <section class="rounded-md border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-lg font-black text-slate-950">アイテム送付</h2>
                    <p class="mt-1 text-xs font-semibold text-slate-500">素材は数量加算、装備と探索アイテムは個体として追加します。</p>
                </div>

                @if($selectedCharacter)
                    <form wire:submit.prevent="grantItem" class="p-5">
                        <div class="mb-5 rounded-md border border-amber-200 bg-amber-50 p-4">
                            <div class="text-xs font-black tracking-wide text-amber-700">送付先</div>
                            <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-lg font-black text-slate-950">{{ $selectedCharacter->name }}</span>
                                        <span class="rounded bg-slate-950 px-2 py-1 text-xs font-black text-amber-200">CID {{ $selectedCharacter->id }}</span>
                                        <span class="rounded bg-white px-2 py-1 text-xs font-black text-slate-700">UID {{ $selectedCharacter->user_id }}</span>
                                    </div>
                                    <div class="mt-1 text-xs font-bold text-slate-600">{{ $selectedCharacter->user?->name ?? 'ユーザー名なし' }}</div>
                                    <div class="mt-1 text-sm font-black text-slate-900">{{ $selectedCharacter->user?->email ?? 'メール未登録' }}</div>
                                </div>
                                <div class="rounded bg-white px-3 py-2 text-xs font-black text-slate-600 shadow-sm ring-1 ring-amber-100">
                                    Lv{{ $selectedCharacter->level }} / {{ $selectedCharacter->currentJob?->name ?? '職業なし' }}
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-[180px_1fr]">
                            <div>
                                <label class="{{ $labelClass }}">送付タイプ</label>
                                <select wire:model.live="grantType" class="mt-1 {{ $fieldClass }}">
                                    @foreach($grantTypeLabels as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">対象検索</label>
                                <input type="search" wire:model.live.debounce.250ms="grantSearch" class="mt-1 {{ $fieldClass }}" placeholder="名前・ID・コードで検索">
                            </div>
                        </div>

                        <div class="mt-4">
                            <label class="{{ $labelClass }}">送付対象</label>
                            <select wire:model="grantTargetId" class="mt-1 {{ $fieldClass }}">
                                <option value="">選択してください</option>
                                @foreach($grantCandidates as $candidate)
                                    <option value="{{ $candidate['id'] }}">{{ $candidate['name'] }}（{{ $candidate['meta'] }}）</option>
                                @endforeach
                            </select>
                            @error('grantTargetId') <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div> @enderror
                        </div>

                        <div class="mt-4 grid gap-4 md:grid-cols-3">
                            <div>
                                <label class="{{ $labelClass }}">数量</label>
                                <input type="number" min="1" max="9999" wire:model="grantQuantity" class="mt-1 {{ $fieldClass }}">
                                @error('grantQuantity') <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div> @enderror
                            </div>
                            @if($grantType === 'equipment')
                                <div>
                                    <label class="{{ $labelClass }}">強化値</label>
                                    <input type="number" min="0" max="999" wire:model="grantEnhanceLevel" class="mt-1 {{ $fieldClass }}">
                                    @error('grantEnhanceLevel') <div class="mt-1 text-xs font-bold text-red-600">{{ $message }}</div> @enderror
                                </div>
                            @endif
                        </div>

                        <div class="mt-5 flex justify-end">
                            <button type="submit" class="rounded-md bg-slate-950 px-5 py-2.5 text-sm font-black text-white shadow-sm hover:bg-slate-800">
                                選択したアイテムを送付
                            </button>
                        </div>
                    </form>
                @else
                    <div class="p-5">
                        <div class="rounded-md border border-slate-200 bg-slate-50 p-5 text-sm font-bold text-slate-600">
                            左の一覧からキャラクターを選択してください。
                        </div>
                    </div>
                @endif
            </section>

            <section class="rounded-md border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-lg font-black text-slate-950">今後ここで制御できる候補</h2>
                </div>
                <div class="grid gap-3 p-5 md:grid-cols-2">
                    @foreach($controlIdeas as $idea)
                        <div class="rounded-md border border-slate-200 bg-slate-50 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <div class="font-black text-slate-950">{{ $idea['label'] }}</div>
                                <div class="shrink-0 rounded bg-white px-2 py-1 text-[11px] font-black text-slate-600">{{ $idea['state'] }}</div>
                            </div>
                            <div class="mt-2 text-xs font-semibold leading-relaxed text-slate-500">{{ $idea['body'] }}</div>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>
    </div>
</div>
