<div class="w-full px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-black tracking-[0.24em] text-amber-600">USER INVESTIGATION</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">ユーザー個別調査</h1>
        </div>
        <form wire:submit.prevent="searchUser" class="flex flex-col gap-2 sm:flex-row">
            <input type="number" min="1" wire:model.defer="userIdInput" placeholder="ユーザーID" class="w-full rounded-md border border-slate-300 bg-white px-4 py-2 text-sm shadow-sm focus:border-[#d4af37] focus:ring focus:ring-[#d4af37]/30 sm:w-48">
            <button type="submit" class="rounded-md bg-slate-950 px-5 py-2 text-sm font-black text-white shadow hover:bg-slate-800">調査</button>
        </form>
    </div>

    @if(!$selectedUserId)
        <section class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200 sm:p-6">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-lg font-black text-slate-950">最近ログインしたユーザー</h2>
                    <p class="mt-1 text-xs font-bold text-slate-500">直近60件を最後に記録されたログイン日が新しい順に表示します。選ぶと個別調査を開けます。</p>
                </div>
                <div class="text-xs font-black text-slate-400">{{ number_format($recentlyLoggedInUsers->count()) }}件</div>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                @forelse($recentlyLoggedInUsers as $recentUser)
                    @php
                        $recentCharacter = $recentUser->characters->first();
                        $recentIconPath = \App\Support\CharacterIconCatalog::versionedAsset($recentCharacter?->icon_path);
                    @endphp
                    <article class="group rounded-md border border-slate-200 bg-slate-50 p-4 text-left shadow-sm transition hover:border-amber-300 hover:bg-amber-50 hover:shadow">
                        <div class="flex items-start gap-3">
                            <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-md bg-white ring-1 ring-slate-200">
                                <img src="{{ $recentIconPath }}" alt="{{ $recentCharacter?->name ?? '冒険者' }}" class="h-full w-full object-contain">
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="text-[11px] font-black tracking-[0.12em] text-slate-500">USER #{{ $recentUser->id }}</div>
                                        <div class="mt-1 truncate text-xl font-black leading-tight text-slate-950 group-hover:text-amber-800">{{ $recentCharacter?->name ?? 'キャラクターなし' }}</div>
                                        <div class="mt-1 truncate text-xs font-bold text-slate-500">{{ $recentUser->email ?? 'メールなし' }}</div>
                                    </div>
                                    <button type="button" wire:click="investigateUser({{ $recentUser->id }})" class="shrink-0 rounded bg-white px-2 py-1 text-[11px] font-black text-slate-500 ring-1 ring-slate-200 hover:bg-slate-100">調査</button>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 border-t border-slate-200 pt-3 text-xs font-bold text-slate-600">
                            <div>最終ログイン {{ $recentUser->last_login_at?->format('Y/m/d H:i') ?? '-' }}</div>
                        </div>
                        @if($recentCharacter)
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <a href="{{ route('admin.player-controls', ['character_id' => $recentCharacter->id]) }}" class="rounded-md bg-slate-950 px-2 py-2 text-center text-[11px] font-black text-white hover:bg-slate-800">輝石付与・調整</a>
                                <a href="{{ route('admin.public-logs', ['character_id' => $recentCharacter->id]) }}" class="rounded-md bg-white px-2 py-2 text-center text-[11px] font-black text-slate-700 ring-1 ring-slate-200 hover:bg-slate-100">この人の公開ログ</a>
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="col-span-full rounded-md bg-slate-50 px-5 py-10 text-center text-sm font-bold text-slate-500">
                        ログイン記録がまだありません。ユーザーIDを入力すると個別に調査できます。
                    </div>
                @endforelse
            </div>
        </section>
    @elseif(!$user)
        <div class="rounded-md bg-red-50 p-6 font-bold text-red-700 ring-1 ring-red-200">
            User #{{ $selectedUserId }} は見つかりません。
        </div>
    @else
        <div class="mb-6 grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.45fr)]">
            <div class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="text-xs font-black text-slate-500">USER</div>
                        <div class="mt-1 text-2xl font-black text-slate-950">#{{ $user->id }} {{ $user->name ?? '名称なし' }}</div>
                        <div class="mt-1 text-sm font-bold text-slate-500">{{ $user->email ?? 'メールなし' }}</div>
                    </div>
                    <div class="text-left text-xs font-bold text-slate-500 sm:text-right">
                        <div>登録 {{ optional($user->created_at)->format('Y/m/d H:i') ?? '-' }}</div>
                        <div>更新 {{ optional($user->updated_at)->format('Y/m/d H:i') ?? '-' }}</div>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach($user->characters as $row)
                        @php $active = (int) $selectedCharacterId === (int) $row->id; @endphp
                        <button type="button" wire:click="selectCharacter({{ $row->id }})" class="rounded-md px-3 py-2 text-xs font-black {{ $active ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
                            #{{ $row->id }} {{ $row->name }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div class="text-xs font-black text-slate-500">調査サマリー</div>
                <div class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <div class="text-xs font-bold text-slate-500">最終表示</div>
                        <div class="font-black text-slate-950">{{ $character?->last_seen_at ? $character->last_seen_at->format('Y/m/d H:i') : '-' }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-slate-500">最終戦闘</div>
                        <div class="font-black text-slate-950">{{ $character?->last_battle_at ? $character->last_battle_at->format('Y/m/d H:i') : '-' }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-slate-500">現在地</div>
                        <div class="font-black text-slate-950">{{ $character?->currentCity?->name ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-slate-500">最高到達街</div>
                        <div class="font-black text-slate-950">{{ $character?->highestCity?->name ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-slate-500">有償/無償輝石</div>
                        <div class="font-black text-slate-950">{{ number_format($character->paid_kiseki ?? 0) }} / {{ number_format($character->free_kiseki ?? 0) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-slate-500">所持金</div>
                        <div class="font-black text-slate-950">{{ number_format($character->money ?? 0) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-slate-500">探索力</div>
                        @if($explorationStamina)
                            <div class="font-black text-slate-950">
                                {{ number_format((int) ($explorationStamina['current'] ?? 0)) }}
                                /
                                {{ number_format((int) ($explorationStamina['max'] ?? 0)) }}
                            </div>
                        @else
                            <div class="font-black text-slate-950">-</div>
                        @endif
                    </div>
                    <div>
                        <div class="text-xs font-bold text-slate-500">探索力状態</div>
                        @if($explorationStamina && ($explorationStamina['enabled'] ?? false))
                            <div class="font-black text-slate-950">
                                @if(($explorationStamina['next_recovery_seconds'] ?? null) !== null)
                                    次回 +1 まで {{ number_format((int) $explorationStamina['next_recovery_seconds']) }}秒
                                @else
                                    上限
                                @endif
                            </div>
                        @else
                            <div class="font-black text-slate-950">探索力制OFF</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        @if($character)
            <nav aria-label="調査項目" class="mb-6 flex flex-wrap gap-2 rounded-md bg-white p-3 shadow-sm ring-1 ring-slate-200">
                @foreach([
                    '#investigation-overview' => '現在の状態',
                    '#investigation-assets' => '所持・育成',
                    '#investigation-progress' => '進行・行動',
                    '#investigation-audit' => '運営・監査',
                    '#investigation-technical' => '技術調査',
                ] as $href => $label)
                    <a href="{{ $href }}" class="rounded-md bg-slate-100 px-3 py-2 text-xs font-black text-slate-700 hover:bg-amber-50 hover:text-amber-800">{{ $label }}</a>
                @endforeach
            </nav>
        @endif

        @if(!$character)
            <div class="rounded-md bg-amber-50 p-6 font-bold text-amber-800 ring-1 ring-amber-200">
                このユーザーには表示できるキャラクターがありません。
            </div>
        @else
            @php
                $maxHp = max(1, (int) ($finalStats['max_hp'] ?? 1));
                $maxMp = max(1, (int) ($finalStats['max_mp'] ?? 1));
                $hpPercent = min(100, max(0, ((int) ($character->current_hp ?? 0) / $maxHp) * 100));
                $mpPercent = min(100, max(0, ((int) ($character->current_mp ?? 0) / $maxMp) * 100));
                $expPercent = $nextExp > 0 ? min(100, max(0, ((int) $character->exp / $nextExp) * 100)) : 0;
                $jobExpPercent = 0;
                if ($jobExpInfo) {
                    $jobExpPercent = $jobExpInfo['is_mastered']
                        ? 100
                        : (($jobExpInfo['next_required'] ?? 0) > 0 ? min(100, max(0, (($jobExpInfo['current'] ?? 0) / $jobExpInfo['next_required']) * 100)) : 0);
                }
                $arenaRank = $character->arenaRanking ? (int) $character->arenaRanking->rank : null;
                $partnerValmon = $character->partnerValmon;
                $rankColors = [
                    'EPIC' => '#e11d48',
                    'SSS' => '#f97316',
                    'SS' => '#c084fc',
                    'S' => '#d4af37',
                    'A' => '#ef4444',
                    'B' => '#3b82f6',
                    'C' => '#22c55e',
                    'D' => '#94a3b8',
                    'E' => '#64748b',
                    'F' => '#b0bec5',
                    'G' => '#d1d5db',
                ];
                $equippedBySlot = $equippedItems->keyBy('equipped_slot');
                $equippedSlots = [
                    ['label' => '武器', 'item' => $equippedBySlot->get('weapon') ?? $equippedItems->first(fn ($row) => ($row->item?->type ?? null) === 'weapon')],
                    ['label' => '防具', 'item' => $equippedBySlot->get('armor') ?? $equippedItems->first(fn ($row) => ($row->item?->type ?? null) === 'armor')],
                    ['label' => '装飾', 'item' => $equippedBySlot->get('accessory') ?? $equippedItems->first(fn ($row) => ($row->item?->type ?? null) === 'accessory')],
                ];
            @endphp

            <div id="investigation-overview" class="mb-6 scroll-mt-6 rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(340px,0.72fr)]">
                    <div class="min-w-0">
                        <div class="grid grid-cols-[5.5rem_minmax(0,1fr)] gap-4">
                            <div class="flex h-28 w-20 items-center justify-center overflow-hidden">
                                @if($character->icon_path)
                                    <img src="{{ \App\Support\CharacterIconCatalog::versionedAsset($character->icon_path) }}" alt="{{ $character->name }}" class="h-full w-full object-contain">
                                @else
                                    <span class="text-5xl text-slate-300">👤</span>
                                @endif
                            </div>

                            <div class="min-w-0">
                                <div class="flex min-w-0 items-start justify-between gap-3 border-b border-slate-100 pb-2">
                                    <div class="min-w-0">
                                        <div class="truncate text-2xl font-black tracking-wide text-[#003366]">{{ $character->name }}</div>
                                        <div class="mt-1 truncate text-sm font-bold text-slate-600">
                                            Lv {{ number_format((int) $character->level) }}
                                            <span class="mx-1 text-slate-300">/</span>
                                            {{ $character->currentJob?->name ?? '無職' }}★{{ number_format((int) $jobLevel) }}
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2 rounded-md bg-slate-50 px-3 py-2 ring-1 ring-slate-200">
                                        <img src="{{ asset('images/icon/colosseum01.webp') }}" alt="ランク" class="h-5 w-5 object-contain">
                                        <span class="text-xs font-black text-slate-500">ランク</span>
                                        @if($arenaRank)
                                            <span class="text-lg font-black text-slate-900">{{ number_format($arenaRank) }}<span class="text-xs text-slate-500">位</span></span>
                                        @else
                                            <span class="text-xs font-bold text-slate-400">未参加</span>
                                        @endif
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    <div class="min-w-0">
                        @if($partnerValmon)
                            <div class="grid grid-cols-[5.25rem_minmax(0,1fr)] items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-3 shadow-sm">
                                <div class="text-center">
                                    <div class="flex h-20 w-20 items-center justify-center">
                                        @if($partnerValmon->master?->image_path)
                                            <img src="{{ $partnerValmon->master->imageUrl() }}" alt="{{ $partnerValmon->displayName() }}" class="h-full w-full object-contain">
                                        @else
                                            <img src="{{ asset('images/icon/icon_038.webp') }}" alt="" class="h-16 w-16 object-contain">
                                        @endif
                                    </div>
                                    <div class="mt-1 text-xs font-black text-slate-500">Lv{{ number_format((int) $partnerValmon->level) }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="text-xs font-black text-slate-400">相棒ヴァルモン</div>
                                    <div class="mt-1 truncate text-xl font-black text-slate-950">{{ $partnerValmon->displayName() }}</div>
                                    <div class="mt-1 whitespace-nowrap text-sm font-bold text-slate-500">
                                        @if($valmonIsMaxLevel)
                                            最大Lv
                                        @else
                                            次のLvまで{{ number_format((int) ($valmonNextLevelRemaining ?? 0)) }}
                                        @endif
                                    </div>
                                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                                        <div class="h-full rounded-full bg-slate-400" style="width: {{ $valmonExpPercent }}%"></div>
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="flex min-h-28 items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 text-sm font-bold text-slate-400">
                                相棒ヴァルモンなし
                            </div>
                        @endif
                    </div>
                </div>

                <div class="mt-5 grid grid-cols-1 gap-3 lg:grid-cols-2">
                    @foreach([
                        ['label' => 'HP', 'value' => number_format((int) ($character->current_hp ?? 0)) . ' / ' . number_format($maxHp), 'percent' => $hpPercent, 'class' => 'bg-red-500 text-red-600'],
                        ['label' => 'SP', 'value' => number_format((int) ($character->current_mp ?? 0)) . ' / ' . number_format($maxMp), 'percent' => $mpPercent, 'class' => 'bg-blue-500 text-blue-600'],
                        ['label' => '経験値', 'value' => number_format((int) $character->exp) . ' / ' . number_format((int) $nextExp), 'percent' => $expPercent, 'class' => 'bg-amber-500 text-amber-500'],
                        ['label' => '職業EXP', 'value' => $jobExpInfo && $jobExpInfo['is_mastered'] ? 'MASTER' : number_format((int) ($jobExpInfo['current'] ?? 0)) . ' / ' . number_format((int) ($jobExpInfo['next_required'] ?? 0)), 'percent' => $jobExpPercent, 'class' => 'bg-green-500 text-green-600'],
                    ] as $gauge)
                        @php [$barClass, $textClass] = explode(' ', $gauge['class']); @endphp
                        <div class="px-1 py-1">
                            <div class="mb-1 flex items-end justify-between gap-2">
                                <span class="text-sm font-black {{ $textClass }}">{{ $gauge['label'] }}</span>
                                <span class="text-sm font-black tabular-nums text-slate-900">{{ $gauge['value'] }}</span>
                            </div>
                            <div class="h-2.5 overflow-hidden rounded-full bg-slate-200">
                                <div class="h-full rounded-full {{ $barClass }}" style="width: {{ $gauge['percent'] }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if(($character->bonus_points ?? 0) > 0)
                    <div class="mt-4 flex items-center justify-between rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-black text-amber-800">
                        <span>能力割振り</span>
                        <span>未使用BP {{ number_format((int) $character->bonus_points) }}</span>
                    </div>
                @endif

                <div class="mt-4 grid grid-cols-2 gap-x-8 gap-y-2 text-sm lg:grid-cols-3">
                    @foreach([
                        ['icon' => 'icon_str.webp', 'label' => '攻撃', 'key' => 'str'],
                        ['icon' => 'icon_def.webp', 'label' => '防御', 'key' => 'def'],
                        ['icon' => 'icon_agi.webp', 'label' => '敏捷', 'key' => 'agi'],
                        ['icon' => 'icon_mag.webp', 'label' => '魔力', 'key' => 'mag'],
                        ['icon' => 'icon_spr.webp', 'label' => '精神', 'key' => 'spr'],
                        ['icon' => 'icon_luk.webp', 'label' => '運', 'key' => 'luk'],
                    ] as $stat)
                        @php
                            $bonus = (int) ($finalStats['bonuses'][$stat['key']] ?? 0);
                            $total = (int) ($finalStats[$stat['key']] ?? 0);
                            $base = $total - $bonus;
                        @endphp
                        <div class="flex items-center gap-2">
                            <img src="{{ asset('images/icon/' . $stat['icon']) }}" alt="{{ $stat['label'] }}" class="h-4 w-4 object-contain">
                            <span class="w-10 font-bold text-slate-500">{{ $stat['label'] }}</span>
                            <span class="font-black text-slate-900">{{ number_format($base) }}</span>
                            @if($bonus > 0)
                                <span class="text-xs font-black text-emerald-600">+{{ number_format($bonus) }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="mt-5 border-t border-dashed border-slate-200 pt-4">
                    <div class="mb-2 text-sm font-black text-slate-500">◆ 現在の装備</div>
                    <div class="space-y-1.5">
                        @foreach($equippedSlots as $slot)
                            @php
                                $ci = $slot['item'];
                                $rank = $ci?->item?->weapon_rank ?? $ci?->item?->armor_rank ?? $ci?->item?->accessory_rank ?? null;
                                $rankColor = $rankColors[$rank] ?? '#94a3b8';
                            @endphp
                            <div class="flex items-center overflow-hidden rounded border border-slate-200 bg-slate-50 text-sm">
                                <div class="w-14 shrink-0 border-r border-slate-200 bg-slate-100 px-3 py-1.5 text-center text-xs font-black text-slate-500">{{ $slot['label'] }}</div>
                                @if($ci)
                                    @if($rank)
                                        <div class="ml-2 inline-flex h-6 min-w-6 items-center justify-center border border-black/20 px-1 text-xs font-black text-white shadow-sm" style="background-color: {{ $rankColor }};">{{ $rank }}</div>
                                    @endif
                                    <div class="min-w-0 flex-1 truncate px-3 py-1.5 font-black text-slate-900">{{ $ci->displayName(false) }}</div>
                                @else
                                    <div class="min-w-0 flex-1 truncate px-3 py-1.5 font-bold text-slate-400">なし</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <details class="group mb-8 overflow-hidden rounded-md bg-white shadow-sm ring-1 ring-slate-200">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 [&::-webkit-details-marker]:hidden">
                    <div>
                        <h2 class="text-lg font-black text-slate-950">能力値の内訳</h2>
                        <p class="mt-1 text-xs font-bold text-slate-500">基礎値と装備などによる補正を確認</p>
                    </div>
                    <svg aria-hidden="true" class="h-5 w-5 shrink-0 text-slate-400 transition-transform duration-200 group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                    </svg>
                </summary>
                <div class="grid grid-cols-1 gap-5 border-t border-slate-200 bg-slate-50/60 p-5 xl:grid-cols-2">
                    <div class="rounded-md bg-white p-4 ring-1 ring-slate-200">
                        <h3 class="text-sm font-black text-slate-900">基礎値・BP</h3>
                        <div class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
                            @foreach([
                                '未使用BP' => $character->bonus_points ?? 0,
                                'HP基礎' => $character->hp_base ?? 0,
                                'SP基礎' => $character->mp_base ?? 0,
                                '攻撃基礎' => $character->attack_base ?? 0,
                                '防御基礎' => $character->defense_base ?? 0,
                                '魔力基礎' => $character->magic_base ?? 0,
                                '精神基礎' => $character->spirit_base ?? 0,
                                '敏捷基礎' => $character->speed_base ?? 0,
                                '運基礎' => $character->luck_base ?? 0,
                            ] as $label => $value)
                                <div class="rounded bg-slate-50 px-3 py-2">
                                    <div class="text-[11px] font-black text-slate-500">{{ $label }}</div>
                                    <div class="font-black text-slate-950">{{ number_format((int) $value) }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-md bg-white p-4 ring-1 ring-slate-200">
                        <h3 class="text-sm font-black text-slate-900">装備などによる補正</h3>
                        <div class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
                            @foreach([
                                'HP' => $finalStats['bonuses']['hp'] ?? 0,
                                'SP' => $finalStats['bonuses']['mp'] ?? 0,
                                '攻撃' => $finalStats['bonuses']['str'] ?? 0,
                                '防御' => $finalStats['bonuses']['def'] ?? 0,
                                '魔力' => $finalStats['bonuses']['mag'] ?? 0,
                                '精神' => $finalStats['bonuses']['spr'] ?? 0,
                                '敏捷' => $finalStats['bonuses']['agi'] ?? 0,
                                '運' => $finalStats['bonuses']['luk'] ?? 0,
                            ] as $label => $value)
                                <div class="rounded bg-emerald-50 px-3 py-2">
                                    <div class="text-[11px] font-black text-emerald-700">{{ $label }}</div>
                                    <div class="font-black text-slate-950">{{ (int) $value >= 0 ? '+' : '' }}{{ number_format((int) $value) }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </details>

            <section id="investigation-assets" class="mb-10 scroll-mt-6">
                <div class="mb-4">
                    <p class="text-[11px] font-black tracking-[0.2em] text-amber-600">ASSETS & GROWTH</p>
                    <h2 class="mt-1 text-xl font-black text-slate-950">所持・育成</h2>
                    <p class="mt-1 text-xs font-bold text-slate-500">装備、消耗品、素材、ヴァルモンを確認します。</p>
                </div>

                <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <x-admin-investigation.table title="装備中" summary="{{ number_format($equippedItems->count()) }}件">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left">部位</th><th class="px-4 py-3 text-left">装備</th><th class="px-4 py-3 text-right">強化</th><th class="px-4 py-3 text-left">性能</th>
                    </x-slot:head>
                    @forelse($equippedItems as $ci)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-xs font-bold text-slate-500">{{ ['weapon' => '武器', 'armor' => '防具', 'accessory' => '装飾'][$ci->equipped_slot] ?? ($ci->equipped_slot ?? '-') }}</td>
                            <td class="px-4 py-3 font-black text-slate-900">{{ $ci->displayName() }}<div class="text-xs font-bold text-slate-500">#{{ $ci->id }} / {{ $ci->item?->type ?? '-' }}</div></td>
                            <td class="px-4 py-3 text-right font-black text-slate-900">+{{ (int) $ci->enhance_level }}</td>
                            <td class="px-4 py-3 text-xs font-bold text-slate-600">{{ implode(' / ', $ci->basePerformanceLines()) ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-8 text-center text-slate-500">装備中アイテムはありません。</td></tr>
                    @endforelse
                </x-admin-investigation.table>

                <x-admin-investigation.table title="装備倉庫" summary="{{ number_format($ownedEquipment->count()) }}件">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left">装備</th><th class="px-4 py-3 text-left">種別</th><th class="px-4 py-3 text-right">強化</th><th class="px-4 py-3 text-left">状態</th>
                    </x-slot:head>
                    @forelse($ownedEquipment as $ci)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-black text-slate-900">{{ $ci->displayName() }}<div class="text-xs font-bold text-slate-500">#{{ $ci->id }}</div></td>
                            <td class="px-4 py-3 text-xs font-bold text-slate-600">{{ ['weapon' => '武器', 'armor' => '防具', 'accessory' => '装飾'][$ci->item?->type] ?? ($ci->item?->type ?? '-') }}</td>
                            <td class="px-4 py-3 text-right font-black text-slate-900">+{{ (int) $ci->enhance_level }}</td>
                            <td class="px-4 py-3 text-xs font-bold text-slate-600">{{ $ci->is_locked ? '保護中' : '通常' }}{{ $ci->market_listing_id ? ' / 市場出品中' : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-8 text-center text-slate-500">装備倉庫は空です。</td></tr>
                    @endforelse
                </x-admin-investigation.table>
            </div>

                <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
                <x-admin-investigation.table title="所持アイテム" summary="{{ number_format($inventoryItems->sum('quantity')) }}個 / {{ number_format($inventoryItems->count()) }}種">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left">アイテム</th><th class="px-4 py-3 text-left">種別</th><th class="px-4 py-3 text-right">数量</th>
                    </x-slot:head>
                    @forelse($inventoryItems as $row)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-black text-slate-900">{{ $row['item']?->name ?? '不明' }}<div class="text-xs font-bold text-slate-500">Item #{{ $row['item']?->id ?? '-' }}</div></td>
                            <td class="px-4 py-3 text-xs font-bold text-slate-600">{{ $row['item']?->type ?? '-' }}</td>
                            <td class="px-4 py-3 text-right font-black text-slate-900">{{ number_format((int) $row['quantity']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-6 py-8 text-center text-slate-500">所持アイテムはありません。</td></tr>
                    @endforelse
                </x-admin-investigation.table>

                <x-admin-investigation.table title="所持素材" summary="{{ number_format($materials->count()) }}種">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left">素材</th><th class="px-4 py-3 text-left">種別</th><th class="px-4 py-3 text-right">数量</th>
                    </x-slot:head>
                    @forelse($materials as $row)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-black text-slate-900">{{ $row->material?->name ?? '不明' }}<div class="text-xs font-bold text-slate-500">{{ $row->material?->material_code ?? '-' }}</div></td>
                            <td class="px-4 py-3 text-xs font-bold text-slate-600">{{ $row->material?->material_type ?? '-' }}</td>
                            <td class="px-4 py-3 text-right font-black text-slate-900">{{ number_format((int) $row->quantity) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-6 py-8 text-center text-slate-500">所持素材はありません。</td></tr>
                    @endforelse
                </x-admin-investigation.table>

                </div>

                <div class="mt-4">
                <x-admin-investigation.table title="ヴァルモン" summary="{{ number_format($valmons->count()) }}体">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left">名前</th><th class="px-4 py-3 text-right">Lv</th><th class="px-4 py-3 text-right">好感度</th><th class="px-4 py-3 text-left">状態</th>
                    </x-slot:head>
                    @forelse($valmons as $valmon)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-black text-slate-900">{{ $valmon->displayName() }}<div class="text-xs font-bold text-slate-500">{{ $valmon->master?->name ?? '-' }}</div></td>
                            <td class="px-4 py-3 text-right font-black text-slate-900">{{ number_format((int) $valmon->level) }}</td>
                            <td class="px-4 py-3 text-right font-black text-slate-900">{{ number_format((int) $valmon->affection) }}</td>
                            <td class="px-4 py-3 text-xs font-bold text-slate-600">{{ $valmon->is_partner ? 'パートナー' : '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-8 text-center text-slate-500">ヴァルモンはいません。</td></tr>
                    @endforelse
                </x-admin-investigation.table>
                </div>
            </section>

            <section id="investigation-progress" class="mb-10 scroll-mt-6">
                <div class="mb-4">
                    <p class="text-[11px] font-black tracking-[0.2em] text-sky-600">PROGRESS & ACTIVITY</p>
                    <h2 class="mt-1 text-xl font-black text-slate-950">進行・行動</h2>
                    <p class="mt-1 text-xs font-bold text-slate-500">到達状況と直近の戦闘を確認します。</p>
                </div>

                <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <x-admin-investigation.table title="街進行度" summary="{{ number_format($cityProgress->count()) }}街">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left">街</th><th class="px-4 py-3 text-right">解放D</th><th class="px-4 py-3 text-right">撃破D</th><th class="px-4 py-3 text-left">状態</th>
                    </x-slot:head>
                    @foreach($cityProgress as $city)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-black text-slate-900">#{{ $city['id'] }} {{ $city['name'] }}</td>
                            <td class="px-4 py-3 text-right font-black text-slate-900">{{ $city['unlocked_areas'] }}</td>
                            <td class="px-4 py-3 text-right font-black text-slate-900">{{ $city['cleared_areas'] }}</td>
                            <td class="px-4 py-3 text-xs font-bold text-slate-600">{{ $city['is_current'] ? '現在地 ' : '' }}{{ $city['is_highest'] ? '最高到達' : '' }}</td>
                        </tr>
                    @endforeach
                </x-admin-investigation.table>

                <x-admin-investigation.table title="ダンジョン進行度" summary="{{ number_format($areaProgresses->where('is_unlocked', true)->count()) }}解放">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left">ダンジョン</th><th class="px-4 py-3 text-left">街</th><th class="px-4 py-3 text-left">状態</th><th class="px-4 py-3 text-left">撃破日時</th>
                    </x-slot:head>
                    @forelse($areaProgresses as $progress)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-black text-slate-900">#{{ $progress->area_id }} {{ $progress->area?->name ?? '-' }}</td>
                            <td class="px-4 py-3 text-xs font-bold text-slate-600">{{ $progress->area?->city?->name ?? '-' }}</td>
                            <td class="px-4 py-3 text-xs font-bold {{ $progress->boss_defeated ? 'text-emerald-700' : ($progress->is_unlocked ? 'text-sky-700' : 'text-slate-500') }}">{{ $progress->boss_defeated ? 'ボス撃破' : ($progress->is_unlocked ? '解放済み' : '未解放') }}</td>
                            <td class="px-4 py-3 text-xs font-bold text-slate-500">{{ $progress->boss_defeated_at ? $progress->boss_defeated_at->format('Y/m/d H:i') : '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-8 text-center text-slate-500">進行データはありません。</td></tr>
                    @endforelse
                </x-admin-investigation.table>
            </div>

                <div class="mt-4">
                <x-admin-investigation.table title="戦闘履歴" summary="直近{{ number_format($battleLogs->count()) }}件">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left">日時</th><th class="px-4 py-3 text-left">敵/場所</th><th class="px-4 py-3 text-left">結果</th><th class="px-4 py-3 text-right">EXP</th>
                    </x-slot:head>
                    @forelse($battleLogs as $log)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 whitespace-nowrap text-xs font-bold text-slate-500">{{ $log->created_at?->format('Y/m/d H:i:s') }}</td>
                            <td class="px-4 py-3 font-bold text-slate-900">{{ $log->enemy?->name ?? '-' }}<div class="text-xs text-slate-500">{{ $log->area?->name ?? '-' }}</div></td>
                            <td class="px-4 py-3 text-xs font-black {{ in_array($log->result, ['win', 'victory'], true) ? 'text-emerald-700' : 'text-red-700' }}">{{ $log->battle_type }} / {{ $log->result }}</td>
                            <td class="px-4 py-3 text-right font-black text-slate-900">{{ number_format((int) $log->exp_gained) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-8 text-center text-slate-500">戦闘履歴はありません。</td></tr>
                    @endforelse
                </x-admin-investigation.table>
                </div>
            </section>

            <section id="investigation-audit" class="mb-10 scroll-mt-6">
                <div class="mb-4">
                    <p class="text-[11px] font-black tracking-[0.2em] text-fuchsia-600">OPERATIONS & AUDIT</p>
                    <h2 class="mt-1 text-xl font-black text-slate-950">運営・監査</h2>
                    <p class="mt-1 text-xs font-bold text-slate-500">課金・鍛錬・通知・ログを補填や問い合わせ確認に使います。</p>
                </div>

                <div>
                <x-admin-investigation.table title="課金履歴" summary="{{ number_format($paymentLogs->count()) }}件">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left">日時</th><th class="px-4 py-3 text-left">種別</th><th class="px-4 py-3 text-left">内容</th>
                    </x-slot:head>
                    @forelse($paymentLogs as $log)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 whitespace-nowrap text-xs font-bold text-slate-500">{{ $log['occurred_at'] ? \Carbon\Carbon::parse($log['occurred_at'])->format('Y/m/d H:i:s') : '-' }}</td>
                            <td class="px-4 py-3 text-xs font-black text-slate-700">{{ $log['type'] }}</td>
                            <td class="px-4 py-3 font-bold text-slate-900">{{ $log['summary'] }}<div class="max-w-[360px] truncate text-xs text-slate-500">{{ $log['detail'] }}</div></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-6 py-8 text-center text-slate-500">課金履歴はありません。</td></tr>
                    @endforelse
                </x-admin-investigation.table>
            </div>

                <div class="mt-4">
                <x-admin-investigation.table title="銘・特攻鍛錬履歴（補填確認用）" summary="{{ number_format($weaponTraitLogs->count()) }}件">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left">日時</th><th class="px-4 py-3 text-left">操作</th><th class="px-4 py-3 text-left">ベース武器</th><th class="px-4 py-3 text-left">消費した素材武器</th><th class="px-4 py-3 text-left">完成武器</th><th class="px-4 py-3 text-right">Gold</th>
                    </x-slot:head>
                    @forelse($weaponTraitLogs as $log)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 whitespace-nowrap text-xs font-bold text-slate-500">{{ $log->created_at?->format('Y/m/d H:i:s') }}</td>
                            <td class="px-4 py-3 text-xs font-black text-fuchsia-700">{{ $log->operationLabel() }}</td>
                            <td class="px-4 py-3 text-sm font-bold text-slate-900">{{ $log->baseDisplayName() }}</td>
                            <td class="px-4 py-3 text-sm font-bold text-orange-700">{{ $log->materialDisplayName() }}</td>
                            <td class="px-4 py-3 text-sm font-black text-emerald-700">{{ $log->completedDisplayName() }}</td>
                            <td class="px-4 py-3 text-right text-sm font-black text-slate-900">-{{ number_format((int) $log->gold_cost) }}G</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-8 text-center text-slate-500">銘・特攻鍛錬の履歴はありません。</td></tr>
                    @endforelse
                </x-admin-investigation.table>
            </div>

                <div class="mt-4">
                <x-admin-investigation.table title="通知（閲覧のみ）" summary="未読 {{ number_format($notifications->whereNull('read_at')->count()) }} / 全{{ number_format($notifications->count()) }}件">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left">日時</th><th class="px-4 py-3 text-left">状態</th><th class="px-4 py-3 text-left">種別</th><th class="px-4 py-3 text-left">内容</th>
                    </x-slot:head>
                    @forelse($notifications as $notification)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 whitespace-nowrap text-xs font-bold text-slate-500">{{ $notification->created_at?->format('Y/m/d H:i:s') }}</td>
                            <td class="px-4 py-3 text-xs font-black {{ $notification->read_at ? 'text-slate-500' : 'text-rose-600' }}">{{ $notification->read_at ? '既読' : '未読' }}</td>
                            <td class="px-4 py-3 text-xs font-bold text-slate-600">{{ $notification->category ?? $notification->type ?? '-' }}</td>
                            <td class="px-4 py-3">
                                <div class="font-black text-slate-900">{{ $notification->title }}</div>
                                @if($notification->body)
                                    <div class="mt-1 whitespace-pre-wrap text-xs font-bold text-slate-500">{{ $notification->body }}</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-8 text-center text-slate-500">届いている通知はありません。</td></tr>
                    @endforelse
                </x-admin-investigation.table>
                <p class="mt-2 text-xs font-bold text-slate-500">管理画面で表示しても、プレイヤー側の未読通知は既読になりません。</p>
            </div>

                <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
                    <x-admin-investigation.simple-list title="ログイン履歴" :logs="$loginLogs" summary="{{ number_format($loginLogs->count()) }}件" empty="ログイン履歴テーブルは未作成、または履歴がありません。" />
                    <x-admin-investigation.simple-list title="エラー履歴" :logs="$errorLogs" summary="{{ number_format($errorLogs->count()) }}件" empty="エラー履歴テーブルは未作成、または履歴がありません。" />
                </div>
            </section>

            <details id="investigation-technical" class="group scroll-mt-6 overflow-hidden rounded-md bg-white shadow-sm ring-1 ring-slate-200">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 [&::-webkit-details-marker]:hidden">
                    <div>
                        <p class="text-[11px] font-black tracking-[0.2em] text-slate-500">TECHNICAL</p>
                        <h2 class="mt-1 text-xl font-black text-slate-950">技術調査</h2>
                        <p class="mt-1 text-xs font-bold text-slate-500">敵データとシミュレーション用スナップショット。必要な場合のみ開きます。</p>
                    </div>
                    <svg aria-hidden="true" class="h-5 w-5 shrink-0 text-slate-400 transition-transform duration-200 group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                    </svg>
                </summary>
                <div class="grid grid-cols-1 gap-4 border-t border-slate-200 bg-slate-50/60 p-4 xl:grid-cols-[minmax(0,0.75fr)_minmax(0,1.25fr)]">
                <div class="rounded-md bg-white p-5 shadow-sm ring-1 ring-slate-200">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-black text-slate-950">敵データ候補</h2>
                        <input type="text" wire:model.live.debounce.300ms="enemySearch" placeholder="敵名/ダンジョン" class="w-44 rounded-md border border-slate-300 px-3 py-2 text-xs">
                    </div>
                    <div class="mt-4 space-y-2">
                        @forelse($enemyCandidates as $enemy)
                            <div class="rounded bg-slate-50 p-3 text-sm">
                                <div class="font-black text-slate-950">#{{ $enemy->id }} {{ $enemy->name }}</div>
                                <div class="mt-1 text-xs font-bold text-slate-500">{{ $enemy->area?->city?->name ?? '-' }} / {{ $enemy->area?->name ?? '-' }}</div>
                                <div class="mt-2 grid grid-cols-4 gap-1 text-[11px] font-black text-slate-600">
                                    <span>Lv {{ $enemy->level }}</span><span>HP {{ $enemy->max_hp }}</span><span>攻撃 {{ $enemy->str }}</span><span>防御 {{ $enemy->def }}</span>
                                </div>
                            </div>
                        @empty
                            <div class="rounded bg-slate-50 p-4 text-sm font-bold text-slate-500">敵候補がありません。</div>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-md bg-slate-950 p-5 shadow-sm ring-1 ring-slate-800">
                    <h2 class="text-lg font-black text-white">バトルシミュレーション用スナップショット</h2>
                    <pre class="mt-4 max-h-[520px] overflow-auto rounded bg-black/40 p-4 text-xs leading-relaxed text-slate-100">{{ $simulationSnapshotJson }}</pre>
                </div>
                </div>
            </details>
        @endif
    @endif
</div>
