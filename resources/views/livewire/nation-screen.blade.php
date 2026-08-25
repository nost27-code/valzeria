<div
    class="mx-auto w-full max-w-4xl space-y-3 bg-white px-2.5 pb-24 pt-3 sm:space-y-4 sm:px-5 sm:pt-5"
    data-nation-screen
    data-nation-community
    data-nation-membership-state="{{ $membership ? 'member' : 'unaffiliated' }}"
>
    @if($actionMessage)
        <div class="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm font-black text-emerald-800" role="status">
            {{ $actionMessage }}
        </div>
    @endif
    @error('nationAction')
        <div class="rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm font-black text-rose-800" role="alert">{{ $message }}</div>
    @enderror

    @if($page !== 'home')
        <button type="button" wire:click="showHome" class="min-h-10 rounded-lg border border-stone-300 bg-white px-3 text-sm font-black text-stone-700 shadow-sm">
            ‹ 国家トップへ戻る
        </button>
    @endif

    @if(!$membership)
        @if($page === 'create')
            <section class="rounded-2xl border border-[#d4af37] bg-white p-4 shadow-sm sm:p-5" data-nation-create>
                <p class="text-xs font-black tracking-[0.2em] text-amber-700">FOUND A NATION</p>
                <h1 class="mt-1 text-2xl font-black text-stone-950">建国する</h1>
                <p class="mt-2 text-sm font-bold leading-relaxed text-stone-600">国号に能力差はありません。国の物語に合う呼び名を選んでください。</p>

                <form wire:submit="openFoundingConfirmation" class="mt-5 space-y-5">
                    <label class="block">
                        <span class="text-sm font-black text-stone-800">国家名</span>
                        <span class="ml-1 text-xs font-bold text-stone-500">国号を除く1〜40文字</span>
                        <input wire:model="foundingName" maxlength="40" class="mt-1 min-h-12 w-full rounded-xl border border-stone-300 px-3 text-base font-bold" placeholder="白銀">
                        @error('foundingName')<span class="mt-1 block text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                    </label>

                    <fieldset>
                        <legend class="text-sm font-black text-stone-800">国号</legend>
                        <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                            @foreach($nationTypes as $type)
                                <label class="flex min-h-14 cursor-pointer items-center gap-2 rounded-xl border p-3 {{ $foundingNationType === $type->value ? 'border-amber-500 bg-amber-50' : 'border-stone-200 bg-white' }}">
                                    <input type="radio" wire:model.live="foundingNationType" value="{{ $type->value }}" class="text-amber-600">
                                    <span><span class="block text-sm font-black text-stone-900">{{ $type->label() }}</span><span class="block text-[11px] font-bold text-stone-500">{{ $type->rulerTitle() }}</span></span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <label class="block">
                        <span class="text-sm font-black text-stone-800">国家紹介</span>
                    <textarea wire:model="foundingDescription" maxlength="200" rows="4" class="mt-1 w-full rounded-xl border border-stone-300 px-3 py-2 text-sm font-bold" placeholder="どんな国家を目指すか、冒険者へ伝えよう。"></textarea>
                    <span class="mt-1 block text-right text-[11px] font-bold text-stone-500">200文字以内</span>
                        @error('foundingDescription')<span class="mt-1 block text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                    </label>

                    <fieldset>
                        <legend class="text-sm font-black text-stone-800">国家紋章</legend>
                        <div class="mt-2 flex items-center gap-3 rounded-xl border border-stone-200 bg-stone-50 p-3" data-founding-emblem-preview>
                            <img src="{{ asset($foundingEmblem['path']) }}" alt="{{ $foundingEmblem['alt'] }}" width="128" height="128" class="h-16 w-16 shrink-0 object-contain">
                            <div class="min-w-0 flex-1">
                                <span class="block text-[11px] font-bold text-stone-500">選択中の紋章</span>
                                <span class="mt-0.5 block text-sm font-black text-stone-800">No.{{ substr($foundingEmblemKey, -3) }}</span>
                            </div>
                            <button type="button" wire:click="openFoundingEmblemModal" class="min-h-11 shrink-0 rounded-lg border border-amber-600 bg-white px-3 text-xs font-black text-amber-800 shadow-sm hover:bg-amber-50">紋章を選ぶ</button>
                        </div>
                        @error('foundingEmblemKey')<span class="mt-1 block text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                    </fieldset>

                    <div class="rounded-xl bg-amber-50 px-3 py-2 text-xs font-bold leading-relaxed text-amber-900">
                        完成名：{{ trim($foundingName) !== '' ? trim($foundingName) : '国家名' }}{{ collect($nationTypes)->firstWhere('value', $foundingNationType)?->label() ?? '王国' }}<br>
                        建国後、国号と国家名は今回の初期版では変更できません。
                    </div>
                    <button type="submit" wire:loading.attr="disabled" wire:target="openFoundingConfirmation" class="min-h-12 w-full rounded-xl border border-amber-700 bg-gradient-to-b from-amber-400 to-amber-600 px-4 font-black text-white shadow-sm disabled:opacity-50">
                        <span wire:loading.remove wire:target="openFoundingConfirmation">この名で建国する</span>
                        <span wire:loading wire:target="openFoundingConfirmation">内容を確認しています…</span>
                    </button>
                </form>
            </section>
        @elseif($page === 'detail' && $selectedNation)
            <section class="rounded-2xl border border-[#d4af37] bg-white p-4 shadow-sm sm:p-5" data-nation-detail>
                <div class="flex items-start gap-4">
                    <img src="{{ asset($selectedNation->emblem['path']) }}" alt="{{ $selectedNation->emblem['alt'] }}" width="128" height="128" class="h-24 w-24 shrink-0 object-contain">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-black tracking-[0.18em] text-blue-700">{{ $selectedNation->nation_type_label }}</p>
                        <h1 class="break-words text-2xl font-black text-stone-950">{{ $selectedNation->display_name }}</h1>
                        <p class="mt-1 text-sm font-black text-stone-600">{{ $selectedNation->ruler_title }}：{{ $selectedNation->rulerMembership?->character?->name ?? '不明' }}</p>
                        <p class="mt-1 text-sm font-bold text-stone-600">国民数：{{ $selectedNation->memberships_count }} / {{ $maxMembers }}人</p>
                        @if($developmentEnabled)<p class="mt-0.5 text-sm font-black text-amber-700">国家Lv{{ $nationLevels[$selectedNation->id] ?? 1 }}</p>@endif
                        <span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-black {{ $selectedNation->recruitment_enabled ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-100 text-stone-600' }}">
                            {{ $selectedNation->recruitment_enabled ? '国民募集中' : '募集停止' }}
                        </span>
                    </div>
                </div>
                <div class="mt-4 rounded-xl bg-stone-50 p-3">
                    <h2 class="text-sm font-black text-stone-800">国家紹介</h2>
                    <p class="mt-1 whitespace-pre-line text-sm font-bold leading-relaxed text-stone-600">{{ $selectedNation->description ?: 'この国の物語は、これから刻まれていく。' }}</p>
                </div>
                @if($selectedNation->recruitment_message)
                    <div class="mt-3 rounded-xl border border-blue-100 bg-blue-50 p-3">
                        <h2 class="text-sm font-black text-blue-900">募集文</h2>
                        <p class="mt-1 whitespace-pre-line text-sm font-bold text-blue-800">{{ $selectedNation->recruitment_message }}</p>
                    </div>
                @endif

                <div class="mt-4">
                    @if($joinEligibility['pending'] && (int) $joinEligibility['pending']->nation_id === (int) $selectedNation->id)
                        <div class="rounded-xl border border-sky-200 bg-sky-50 p-3">
                            <p class="text-sm font-black text-sky-900">加入申請中</p>
                            <button type="button" wire:click="cancelJoinApplication({{ $joinEligibility['pending']->id }})" wire:loading.attr="disabled" class="mt-2 min-h-11 w-full rounded-lg border border-sky-300 bg-white text-sm font-black text-sky-800 disabled:opacity-50">申請を取り消す</button>
                        </div>
                    @elseif($joinEligibility['allowed'])
                        <label class="block text-sm font-black text-stone-800">申請時の一言（任意）
                            <textarea wire:model="joinMessage" maxlength="100" rows="3" class="mt-1 w-full rounded-xl border border-stone-300 px-3 py-2 text-sm font-bold" placeholder="始めたばかりですが、よろしくお願いします！"></textarea>
                        </label>
                        @error('joinMessage')<span class="mt-1 block text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                        <button type="button" wire:click="submitJoinApplication" wire:loading.attr="disabled" wire:target="submitJoinApplication" class="mt-2 min-h-12 w-full rounded-xl border border-blue-800 bg-blue-600 font-black text-white disabled:opacity-50">加入申請を送る</button>
                    @else
                        <div class="rounded-xl border border-stone-200 bg-stone-50 p-3 text-sm font-bold text-stone-700">
                            <p class="font-black">加入申請できません</p>
                            <p class="mt-1">{{ $joinEligibility['reason'] }}</p>
                            @if($joinEligibility['blocked_until'])
                                <p class="mt-1 text-xs text-stone-500">残り {{ $cooldowns->remainingLabel($joinEligibility['blocked_until']) }}</p>
                            @endif
                        </div>
                    @endif
                </div>
            </section>

            <section class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
                <h2 class="text-base font-black text-stone-900">国民一覧</h2>
                <div class="mt-2 divide-y divide-stone-100">
                    @foreach($selectedNation->memberships as $nationMember)
                        <div class="flex items-center justify-between gap-3 py-2.5 text-sm">
                            <div class="min-w-0"><p class="truncate font-black text-stone-800">{{ $nationMember->character?->name ?? '不明' }}</p><p class="text-xs font-bold text-stone-500">{{ $nationMember->roleLabel($selectedNation) }}</p></div>
                            <div class="shrink-0 text-right text-xs font-bold text-stone-500">Lv{{ $nationMember->character?->level ?? 1 }}<br>{{ $nationMember->joined_at?->format('Y/m/d') }}</div>
                        </div>
                    @endforeach
                </div>
            </section>
        @else
            @if($page !== 'nation-list')
            <section class="rounded-2xl border border-[#d4af37] bg-white p-4 shadow-sm sm:p-5" data-nation-home-hero>
                <div class="flex items-center gap-3 sm:gap-5">
                    <img src="{{ asset('images/icon/icon_306.webp') }}" alt="国家" width="128" height="128" class="h-24 w-24 shrink-0 object-contain sm:h-28 sm:w-28">
                    <div class="min-w-0"><p class="text-xs font-black tracking-[0.22em] text-emerald-700">NATION</p><h1 class="mt-0.5 text-2xl font-black text-stone-950">国家</h1><p class="mt-1 text-sm font-bold leading-relaxed text-stone-600">国を興し、仲間を集め、要塞を築き、他国との戦いに備えよう。</p></div>
                </div>
                @if($ownPendingApplication)
                    <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-3">
                        <p class="text-sm font-black text-sky-900">{{ $ownPendingApplication->nation->display_name }}へ加入申請中</p>
                        <div class="mt-2 grid grid-cols-2 gap-2"><button type="button" wire:click="showNationDetail({{ $ownPendingApplication->nation_id }})" class="min-h-10 rounded-lg border border-sky-300 bg-white text-xs font-black text-sky-800">国家詳細</button><button type="button" wire:click="cancelJoinApplication({{ $ownPendingApplication->id }})" class="min-h-10 rounded-lg border border-rose-300 bg-white text-xs font-black text-rose-700">申請取消</button></div>
                    </div>
                @endif
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <button type="button" wire:click="showNationList" class="min-h-12 rounded-xl border border-blue-800 bg-gradient-to-b from-blue-500 to-blue-700 px-3 py-2.5 text-sm font-black text-white shadow-sm"><span aria-hidden="true">🔍</span> 国家を探す</button>
                    <button type="button" wire:click="showCreate" class="min-h-12 rounded-xl border border-amber-700 bg-gradient-to-b from-amber-400 to-amber-600 px-3 py-2.5 text-sm font-black text-white shadow-sm"><span aria-hidden="true">🏰</span> 建国する</button>
                </div>
            </section>
            @endif

            <section class="rounded-2xl border border-[#d4af37] bg-white p-3 shadow-sm sm:p-4" data-nation-list>
                <div class="flex items-start justify-between gap-3 border-b border-stone-200 pb-3">
                    <div>
                        <h2 class="text-base font-black text-stone-900">{{ $page === 'nation-list' ? '国家を探す' : '国家ピックアップ' }}</h2>
                        @if($page !== 'nation-list' && $activeNationCount > 0)
                            <p class="mt-1 text-[11px] font-bold text-stone-500">
                                {{ $activeNationCount > 3 ? '全'.number_format($activeNationCount).'国から日替わりで3国を紹介しています' : '現在の全'.number_format($activeNationCount).'国を紹介しています' }}
                            </p>
                        @endif
                    </div>
                    @if($page !== 'nation-list')<button type="button" wire:click="showNationList" class="min-h-10 shrink-0 rounded-lg border border-stone-300 bg-white px-3 text-xs font-black text-stone-700">全国家を見る</button>@endif
                </div>
                <div class="divide-y divide-stone-100">
                    @forelse($nations as $nation)
                        <article class="flex gap-3 py-3">
                            <img src="{{ asset($nation->emblem['path']) }}" alt="{{ $nation->emblem['alt'] }}" width="128" height="128" loading="lazy" class="h-16 w-16 shrink-0 object-contain sm:h-20 sm:w-20">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-2"><div class="min-w-0"><h3 class="truncate text-base font-black text-stone-950">{{ $nation->display_name }}</h3><p class="mt-0.5 text-xs font-bold text-stone-500">{{ $nation->ruler_title }}：{{ $nation->rulerMembership?->character?->name ?? '不明' }}</p>@if($developmentEnabled)<p class="mt-0.5 text-xs font-black text-amber-700">国家Lv{{ $nationLevels[$nation->id] ?? 1 }}</p>@endif</div><div class="shrink-0 text-right"><div class="text-sm font-black text-stone-700">{{ $nation->memberships_count }}/{{ $maxMembers }}人</div><div class="text-[11px] font-black {{ $nation->recruitment_enabled ? 'text-emerald-600' : 'text-stone-500' }}">{{ $nation->recruitment_enabled ? '国民募集中' : '募集停止' }}</div></div></div>
                                <p class="mt-1 line-clamp-2 text-xs font-bold leading-relaxed text-blue-700">{{ $nation->recruitment_message ?: '募集文はまだありません。' }}</p>
                                <p class="mt-1 line-clamp-2 text-xs font-bold leading-relaxed text-stone-600">{{ $nation->description ?: 'この国の物語は、これから刻まれていく。' }}</p>
                                <button type="button" wire:click="showNationDetail({{ $nation->id }})" class="mt-2 min-h-9 w-full rounded-lg border border-stone-300 bg-white px-2 text-xs font-black text-stone-700">詳細を見る</button>
                            </div>
                        </article>
                    @empty
                        <div class="py-7 text-center"><div class="text-3xl" aria-hidden="true">🛡️</div><p class="mt-2 text-sm font-black text-stone-700">まだ国家は存在しない。</p><p class="mt-1 text-xs font-bold text-stone-500">最初の建国者が現れる日を待っている。</p></div>
                    @endforelse
                </div>
                @if($page === 'nation-list' && method_exists($nations, 'links'))<div class="mt-3">{{ $nations->links() }}</div>@endif
            </section>

            @if($page === 'nation-list')
                <div class="flex justify-center pt-1">
                    <button
                        type="button"
                        wire:click="showHome"
                        data-nation-list-home-button
                        class="min-h-11 rounded-xl border border-stone-300 bg-white px-6 text-sm font-black text-stone-700 shadow-sm hover:bg-stone-50"
                    >
                        ‹ 国家トップへ戻る
                    </button>
                </div>
            @endif

            @if($page !== 'nation-list')
                <section class="rounded-2xl border border-[#d4af37] bg-white p-4 shadow-sm" data-nation-about>
                    <h2 class="text-base font-black text-amber-900">国家とは？</h2>
                    <p class="mt-2 text-sm font-bold leading-relaxed text-stone-700">国家は、冒険者同士で協力して活動するためのコミュニティです。</p>
                    <p class="mt-3 text-sm font-black text-stone-800">国家に所属すると、</p>
                    <ul class="mt-2 space-y-1.5 text-sm font-bold text-stone-700">
                        <li class="flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2"><span class="h-2 w-2 shrink-0 rounded-full bg-amber-500" aria-hidden="true"></span><span>国家への納品・物資の共有</span></li>
                        <li class="flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2"><span class="h-2 w-2 shrink-0 rounded-full bg-amber-500" aria-hidden="true"></span><span>国家同士の戦いへの参加</span></li>
                        <li class="flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2"><span class="h-2 w-2 shrink-0 rounded-full bg-amber-500" aria-hidden="true"></span><span>国家の発展・共同目標</span></li>
                        <li class="flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2"><span class="h-2 w-2 shrink-0 rounded-full bg-amber-500" aria-hidden="true"></span><span>国家戦績・ランキング</span></li>
                    </ul>
                    <p class="mt-3 text-sm font-bold leading-relaxed text-stone-700">など、<strong class="font-black text-amber-900">仲間と協力する新たな遊び</strong>に参加できます。</p>
                    <p class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm font-bold leading-relaxed text-emerald-900">国家への所属は必須ではなく、無所属でもこれまで通り冒険を楽しめます。</p>
                    <p class="mt-2 text-xs font-bold leading-relaxed text-stone-500">※{{ $developmentEnabled ? '国家戦・戦績・ランキングなど' : '国家への納品・国家戦・戦績・ランキングなど' }}、一部の機能は現在準備中です。</p>
                </section>
            @endif
        @endif
    @else
        @php
            $nation = $membership->nation;
        @endphp
        <section
            class="relative overflow-hidden rounded-2xl border-2 border-[#d4af37] bg-slate-900 shadow-lg"
            data-nation-home-header
            data-nation-header-background="{{ $nation->header_background_key }}"
        >
            <img src="{{ asset($nation->header_background['path']) }}" alt="" width="600" height="232" class="absolute inset-0 h-full w-full object-cover" aria-hidden="true">
            <div class="absolute inset-0 bg-gradient-to-b from-slate-950/25 via-slate-950/15 to-slate-950/85" aria-hidden="true"></div>
            <div class="pointer-events-none absolute inset-1 rounded-xl border border-amber-200/70 shadow-[inset_0_0_24px_rgba(15,23,42,0.7)]" aria-hidden="true"></div>

            @if($page === 'home' && $membership->isRuler())
                <button
                    type="button"
                    wire:click="openHeaderBackgroundModal"
                    class="absolute right-2 top-2 z-20 min-h-8 rounded-full border border-amber-200/80 bg-slate-950/75 px-2.5 text-[10px] font-black text-amber-50 shadow-lg backdrop-blur-sm transition hover:bg-slate-900 sm:right-3 sm:top-3 sm:px-3 sm:text-xs"
                    data-nation-header-background-open
                >
                    背景を変更
                </button>
            @endif

            <div class="relative z-10 px-3 pb-3 pt-3 sm:px-5 sm:pb-4 sm:pt-4">
                <div class="mx-auto w-full max-w-xl text-center">
                    <div class="inline-flex items-center gap-2 text-amber-100 drop-shadow-lg">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4 17-1-9 5 4 4-7 4 7 5-4-1 9H4Zm0 0h16v3H4v-3Z"/></svg>
                        <span class="text-[10px] font-black tracking-[0.26em] sm:text-[11px]">MY NATION</span>
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4 17-1-9 5 4 4-7 4 7 5-4-1 9H4Zm0 0h16v3H4v-3Z"/></svg>
                    </div>
                    <h1 class="mx-auto mt-1 w-fit max-w-[72%] break-words rounded-full border border-amber-200/80 bg-slate-950/75 px-4 py-1 text-xl font-black tracking-[0.08em] text-amber-50 shadow-xl backdrop-blur-[2px] sm:max-w-[60%] sm:px-5 sm:text-2xl" style="text-shadow: 0 2px 4px rgba(0, 0, 0, .9);" data-nation-nameplate>
                        {{ $nation->display_name }}
                    </h1>
                </div>

                <div class="mt-2 flex flex-nowrap items-center justify-center gap-2 sm:mt-3 sm:gap-4" data-nation-header-summary-row>
                    <div class="flex w-[4.5rem] shrink-0 justify-center sm:w-24">
                        <img src="{{ asset($nation->emblem['path']) }}" alt="{{ $nation->emblem['alt'] }}" width="128" height="128" class="h-[4.5rem] w-[4.5rem] object-contain drop-shadow-[0_4px_6px_rgba(0,0,0,0.75)] sm:h-24 sm:w-24">
                    </div>
                    <div class="w-[11rem] max-w-[11rem] min-w-0 shrink overflow-hidden rounded-xl border border-amber-200/70 bg-slate-950/75 shadow-xl backdrop-blur-[2px] sm:w-64 sm:max-w-64">
                        <dl class="grid grid-cols-2 text-amber-50" data-nation-header-stats-grid>
                            <div class="flex min-w-0 items-center gap-0.5 border-b border-r border-amber-100/20 px-1 py-1 sm:gap-1 sm:px-2 sm:py-1.5" data-nation-header-stat-cell="ruler">
                                <dt class="flex shrink-0 items-center gap-0.5 text-[9px] font-bold text-amber-100/80 sm:text-[11px]">
                                    <svg class="h-3.5 w-3.5 shrink-0 sm:h-4 sm:w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 16h14l1-9-5 4-3-7-3 7-5-4 1 9Zm0 2h14v2H5v-2Z"/></svg>
                                    {{ $nation->ruler_title }}
                                </dt>
                                <dd class="ml-auto min-w-0 truncate text-right text-[10px] font-black sm:text-xs">{{ $nation->rulerMembership?->character?->name ?? '不明' }}</dd>
                            </div>
                            <div class="flex min-w-0 items-center gap-0.5 border-b border-amber-100/20 px-1 py-1 sm:gap-1 sm:px-2 sm:py-1.5" data-nation-header-stat-cell="members">
                                <dt class="flex shrink-0 items-center gap-0.5 text-[9px] font-bold text-amber-100/80 sm:text-[11px]">
                                    <svg class="h-3.5 w-3.5 shrink-0 sm:h-4 sm:w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm8 0a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM1 21v-3c0-3 3-5 7-5s7 2 7 5v3H1Zm14.5 0v-3c0-1.5-.6-2.8-1.7-3.8.7-.1 1.4-.2 2.2-.2 4 0 7 2 7 5v2h-7.5Z"/></svg>
                                    国民数
                                </dt>
                                <dd class="ml-auto min-w-0 truncate whitespace-nowrap text-right text-[10px] font-black sm:text-xs">{{ $nation->memberships->count() }} / {{ $maxMembers }}人</dd>
                            </div>
                            <div class="flex min-w-0 items-center gap-0.5 border-r border-amber-100/20 px-1 py-1 sm:gap-1 sm:px-2 sm:py-1.5" data-nation-header-stat-cell="development">
                                @if($developmentEnabled && $developmentProgress)
                                    <dt class="shrink-0 text-[9px] font-bold text-amber-100/80 sm:text-[11px]">国家レベル</dt>
                                    <dd class="ml-auto shrink-0 text-[11px] font-black text-amber-300 sm:text-sm">Lv{{ $developmentProgress['level'] }}</dd>
                                @else
                                    <dt class="shrink-0 text-[9px] font-bold text-amber-100/80 sm:text-[11px]">国号</dt>
                                    <dd class="ml-auto min-w-0 truncate text-right text-[10px] font-black sm:text-xs">{{ $nation->nation_type_label }}</dd>
                                @endif
                            </div>
                            <div class="flex items-center justify-center px-1 py-1 sm:px-2 sm:py-1.5" data-nation-header-stat-cell="recruitment">
                                <span class="inline-flex max-w-full items-center rounded-full px-1.5 py-0.5 text-center text-[8px] font-black sm:px-2 sm:text-[10px] {{ $nation->recruitment_enabled ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-200 text-stone-700' }}">
                                    {{ $nation->recruitment_enabled ? '国民募集中' : '募集停止' }}
                                </span>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
            @if($nation->status === \App\Models\Nation::STATUS_DISBAND_PENDING)
                <div class="relative z-10 m-3 mt-0 rounded-xl border border-rose-300 bg-rose-50 p-3 text-sm font-bold text-rose-800 sm:m-4 sm:mt-0"><p class="font-black">国家解散の待機中です</p><p class="mt-1">{{ $nation->dissolution_effective_at?->format('Y/m/d H:i') }}以降に論理解散されます。</p><p class="mt-1">一般国民は解散完了時に、加入待機時間なしで自動的に無所属になります。</p>@if($membership->isRuler())<button type="button" wire:click="cancelDissolution" class="mt-2 min-h-10 w-full rounded-lg border border-rose-300 bg-white font-black text-rose-700">解散申請を取り消す</button>@endif</div>
            @endif
        </section>

        @if($page === 'resources' && $developmentEnabled)
            <section class="rounded-2xl border border-[#d4af37] bg-white p-4 shadow-sm sm:p-5" data-nation-resource-management>
                <div class="flex items-start justify-between gap-3 border-b border-stone-200 pb-3">
                    <div>
                        <p class="text-[11px] font-black tracking-[0.16em] text-amber-700">NATION DEVELOPMENT</p>
                        <h2 class="text-xl font-black text-stone-950">国家資材管理</h2>
                    </div>
                    <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-black text-amber-900">国家Lv{{ $developmentProgress['level'] }}</span>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3">
                    <div class="rounded-xl bg-slate-50 p-3">
                        <p class="text-xs font-bold text-slate-500">国家資材</p>
                        <p class="mt-1 text-xl font-black text-slate-950">{{ number_format($nation->treasury_points) }}<span class="ml-1 text-xs">pt</span></p>
                    </div>
                    <div class="rounded-xl bg-amber-50 p-3">
                        <p class="text-xs font-bold text-amber-700">あなたの貢献度</p>
                        <p class="mt-1 text-xl font-black text-amber-950">{{ number_format($personalContribution) }}<span class="ml-1 text-xs">EXP</span></p>
                    </div>
                </div>

                <div class="mt-4 rounded-xl border border-amber-200 bg-white p-3">
                    <div class="flex items-center justify-between gap-3 text-xs font-black text-stone-700">
                        <span>国家発展EXP {{ number_format($developmentProgress['total_exp']) }}</span>
                        @if($developmentProgress['is_max'])
                            <span class="text-amber-700">Lv{{ $developmentProgress['max_level'] }} MAX</span>
                        @else
                            <span>次まで {{ number_format($developmentProgress['exp_to_next']) }}</span>
                        @endif
                    </div>
                    <div class="mt-2 h-3 overflow-hidden rounded-full bg-stone-200" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ intdiv($developmentProgress['progress_bps'], 100) }}">
                        <div class="h-full rounded-full bg-gradient-to-r from-amber-400 to-amber-600" style="width: {{ $developmentProgress['progress_bps'] / 100 }}%"></div>
                    </div>
                    @if($developmentProgress['is_max'])
                        <p class="mt-2 text-xs font-bold leading-relaxed text-stone-600">Lvは上限ですが、国家発展EXPと納品実績は引き続き蓄積されます。</p>
                    @endif
                </div>
            </section>

            <section class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm sm:p-5" data-nation-donation-form>
                <h2 class="text-lg font-black text-stone-950">都市素材を納品する</h2>
                <p class="mt-1 text-sm font-bold leading-relaxed text-stone-600">納品した素材は国家資材となり、同時に国家発展EXPが加算されます。</p>
                <div class="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs font-bold leading-relaxed text-rose-800">
                    都市素材は装備進化・素材交換・NPC調達にも使用します。納品すると返却できないため、納品後の残数を必ず確認してください。
                </div>

                @if($donatableMaterials->isNotEmpty())
                    <form wire:submit="openDonationConfirmation" class="mt-4 space-y-4">
                        <label class="block">
                            <span class="text-sm font-black text-stone-800">納品する素材</span>
                            <select wire:model.live="donationMaterialId" class="mt-1 min-h-12 w-full rounded-xl border border-stone-300 px-3 text-sm font-bold">
                                @foreach($donatableMaterials as $material)
                                    <option value="{{ $material->material_id }}">{{ $material->name }}（所持 {{ number_format($material->quantity) }}個 / 1個={{ $material->points_per_unit }}pt・{{ $material->development_exp_per_unit }}EXP）</option>
                                @endforeach
                            </select>
                            @error('donationMaterialId')<span class="mt-1 block text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                        </label>
                        <label class="block">
                            <span class="text-sm font-black text-stone-800">納品数</span>
                            <input type="number" wire:model.live="donationQuantity" min="1" max="{{ $donationPreview?->quantity ?? 1 }}" inputmode="numeric" class="mt-1 min-h-12 w-full rounded-xl border border-stone-300 px-3 text-base font-bold">
                            @error('donationQuantity')<span class="mt-1 block text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                        </label>
                        @if($donationPreview)
                            @php
                                $previewQuantity = max(0, (int) $donationQuantity);
                            @endphp
                            <div class="grid grid-cols-3 gap-2 text-center text-xs font-bold">
                                <div class="rounded-lg bg-stone-50 p-2"><span class="block text-stone-500">納品後</span><strong class="mt-1 block text-sm text-stone-900">{{ number_format(max(0, $donationPreview->quantity - $previewQuantity)) }}個</strong></div>
                                <div class="rounded-lg bg-slate-50 p-2"><span class="block text-slate-500">国家資材</span><strong class="mt-1 block text-sm text-slate-900">+{{ number_format($previewQuantity * $donationPreview->points_per_unit) }}pt</strong></div>
                                <div class="rounded-lg bg-amber-50 p-2"><span class="block text-amber-700">発展EXP</span><strong class="mt-1 block text-sm text-amber-900">+{{ number_format($previewQuantity * $donationPreview->development_exp_per_unit) }}</strong></div>
                            </div>
                        @endif
                        <button type="submit" wire:loading.attr="disabled" wire:target="openDonationConfirmation" class="min-h-12 w-full rounded-xl bg-emerald-600 px-4 font-black text-white shadow-sm disabled:opacity-50">
                            <span wire:loading.remove wire:target="openDonationConfirmation">納品内容を確認する</span>
                            <span wire:loading wire:target="openDonationConfirmation">確認しています…</span>
                        </button>
                    </form>
                @else
                    <p class="mt-4 rounded-xl bg-stone-50 px-3 py-6 text-center text-sm font-bold text-stone-500">現在、納品できる都市素材を所持していません。</p>
                @endif
            </section>

            <section class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm sm:p-5" data-nation-contributions>
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-black text-stone-950">国家発展への貢献</h2>
                    <span class="text-xs font-black text-amber-700">合計 {{ number_format($contributionRows->sum('development_exp')) }} EXP</span>
                </div>
                <p class="mt-1 text-xs font-bold leading-relaxed text-stone-500">現在の国家へ納品した発展EXPです。アカウント削除後の記録は「退会した冒険者」にまとめます。</p>
                <div class="mt-3 divide-y divide-stone-100">
                    @forelse($contributionRows as $contribution)
                        <div class="flex items-center justify-between gap-3 py-2.5">
                            @if($contribution['character_id'])
                                <button type="button" x-on:click="Livewire.dispatch('open-adventurer-card', { characterId: {{ $contribution['character_id'] }} })" class="min-w-0 truncate text-left text-sm font-black text-blue-800 underline decoration-blue-300 underline-offset-2">{{ $contribution['name'] }}</button>
                            @else
                                <span class="min-w-0 truncate text-sm font-black text-stone-600">{{ $contribution['name'] }}</span>
                            @endif
                            <strong class="shrink-0 text-sm font-black text-amber-800">{{ number_format($contribution['development_exp']) }} EXP</strong>
                        </div>
                    @empty
                        <p class="py-5 text-center text-sm font-bold text-stone-500">まだ納品実績はありません。</p>
                    @endforelse
                </div>
            </section>
        @elseif($page === 'applications' && $membership->isRuler())
            <section class="rounded-2xl border border-[#d4af37] bg-white p-4 shadow-sm" data-nation-applications>
                <h2 class="text-xl font-black text-stone-950">加入申請</h2>
                <div class="mt-3 divide-y divide-stone-100">
                    @forelse($pendingApplications as $application)
                        <article class="py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="font-black text-stone-900">
                                        @if($application->character)
                                            <button
                                                type="button"
                                                x-on:click="Livewire.dispatch('open-adventurer-card', { characterId: {{ (int) $application->character->id }} })"
                                                class="max-w-full truncate text-left text-blue-800 underline decoration-blue-300 underline-offset-2 transition hover:text-blue-950 focus-visible:rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                                                aria-label="{{ $application->character->name }}の冒険者カードを見る"
                                                data-nation-application-profile-link="{{ $application->character->id }}"
                                            >
                                                {{ $application->character->name }}
                                            </button>
                                        @else
                                            不明
                                        @endif
                                    </h3>
                                    <p class="mt-1 text-xs font-bold text-stone-500">Lv{{ $application->character?->level ?? 1 }} / 戦力 {{ number_format($applicationPowers[$application->id] ?? 0) }} / {{ $application->character?->jobClass?->name ?? '無職' }}</p>
                                </div>
                                <time class="shrink-0 text-xs font-bold text-stone-500">{{ $application->requested_at?->format('m/d H:i') }}</time>
                            </div>
                            <p class="mt-2 rounded-lg bg-stone-50 px-3 py-2 text-sm font-bold text-stone-700">{{ $application->message ?: '一言はありません。' }}</p>
                            <div class="mt-3 grid grid-cols-2 gap-2"><button type="button" wire:click="openApplicationApprovalConfirmation({{ $application->id }})" wire:loading.attr="disabled" wire:target="openApplicationApprovalConfirmation({{ $application->id }})" class="min-h-11 rounded-lg bg-emerald-600 text-sm font-black text-white disabled:opacity-50">承認</button><button type="button" wire:click="rejectApplication({{ $application->id }})" wire:loading.attr="disabled" class="min-h-11 rounded-lg border border-rose-300 bg-white text-sm font-black text-rose-700 disabled:opacity-50">却下</button></div>
                        </article>
                    @empty
                        <p class="py-7 text-center text-sm font-bold text-stone-500">現在、加入申請はありません。</p>
                    @endforelse
                </div>
            </section>
        @elseif($page === 'members' && $membership->isRuler())
            <section class="rounded-2xl border border-[#d4af37] bg-white p-4 shadow-sm" data-nation-member-management>
                <h2 class="text-xl font-black text-stone-950">国民・役職管理</h2>
                <p class="mt-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold leading-relaxed text-amber-900">
                    現在、役職による権限変更は未実装です。宰相・元帥・兵站官への変更は肩書き表示のみで、この画面で実際に利用できる管理操作は追放です。
                </p>
                <div class="mt-3 divide-y divide-stone-100">
                    @foreach($nation->memberships as $nationMember)
                        <article class="py-3">
                            <div class="flex items-center justify-between gap-3"><div class="min-w-0"><p class="truncate font-black text-stone-900">{{ $nationMember->character?->name ?? '不明' }}</p><p class="text-xs font-bold text-stone-500">Lv{{ $nationMember->character?->level ?? 1 }} / {{ $nationMember->joined_at?->format('Y/m/d') }}加入</p></div><span class="shrink-0 rounded-full bg-stone-100 px-2 py-1 text-xs font-black text-stone-700">{{ $nationMember->roleLabel($nation) }}</span></div>
                            @unless($nationMember->isRuler())
                                <div class="mt-2 grid grid-cols-[1fr_auto] gap-2"><select wire:change="changeMemberRole({{ $nationMember->id }}, $event.target.value)" class="min-h-10 rounded-lg border border-stone-300 px-2 text-sm font-bold"><option value="citizen" @selected($nationMember->role === 'citizen')>国民</option><option value="chancellor" @selected($nationMember->role === 'chancellor')>宰相</option><option value="marshal" @selected($nationMember->role === 'marshal')>元帥</option><option value="logistics_officer" @selected($nationMember->role === 'logistics_officer')>兵站官</option></select><button type="button" wire:click="openExpelConfirmation({{ $nationMember->id }})" class="min-h-10 rounded-lg border border-rose-300 bg-white px-3 text-xs font-black text-rose-700">追放</button></div>
                            @endunless
                        </article>
                    @endforeach
                </div>
            </section>
        @elseif($page === 'profile' && $membership->isRuler())
            <section class="rounded-2xl border border-[#d4af37] bg-white p-4 shadow-sm" data-nation-profile>
                <h2 class="text-xl font-black text-stone-950">国家プロフィール</h2>
                <form wire:submit="saveProfile" class="mt-4 space-y-4">
                    <label class="block text-sm font-black text-stone-800">国家紹介<textarea wire:model="profileDescription" maxlength="200" rows="4" class="mt-1 w-full rounded-xl border border-stone-300 px-3 py-2 text-sm font-bold"></textarea></label>
                    <label class="flex min-h-12 items-center justify-between rounded-xl border border-stone-200 px-3"><span><span class="block text-sm font-black text-stone-800">国民募集</span><span class="block text-xs font-bold text-stone-500">OFF後も既存申請は審査できます</span></span><input type="checkbox" wire:model="profileRecruitmentEnabled" class="h-5 w-5 rounded text-emerald-600"></label>
                    <label class="block text-sm font-black text-stone-800">募集文<textarea wire:model="profileRecruitmentMessage" maxlength="100" rows="3" class="mt-1 w-full rounded-xl border border-stone-300 px-3 py-2 text-sm font-bold"></textarea></label>
                    <fieldset>
                        <legend class="text-sm font-black text-stone-800">国家紋章</legend>
                        @include('livewire.partials.nation-emblem-picker', [
                            'wireModel' => 'profileEmblemKey',
                            'selectedEmblemKey' => $profileEmblemKey,
                            'selectionAction' => null,
                        ])
                        @error('profileEmblemKey')<span class="mt-1 block text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                    </fieldset>
                    <button type="submit" wire:loading.attr="disabled" class="min-h-12 w-full rounded-xl bg-blue-700 font-black text-white disabled:opacity-50">保存する</button>
                </form>
            </section>
        @elseif($page === 'transfer' && $membership->isRuler())
            <section class="rounded-2xl border border-[#d4af37] bg-white p-4 shadow-sm" data-nation-transfer>
                <h2 class="text-xl font-black text-stone-950">統治者譲渡</h2><p class="mt-2 text-sm font-bold text-rose-700">譲渡後、あなたは一般国民になります。</p>
                <div class="mt-3 divide-y divide-stone-100">@forelse($nation->memberships->where('role', '!=', 'ruler') as $candidate)<div class="flex items-center justify-between gap-3 py-3"><div><p class="font-black text-stone-900">{{ $candidate->character?->name ?? '不明' }}</p><p class="text-xs font-bold text-stone-500">現在：{{ $candidate->roleLabel($nation) }}</p></div><button type="button" wire:click="openTransferConfirmation({{ $candidate->id }})" class="min-h-10 rounded-lg border border-amber-500 bg-amber-50 px-3 text-xs font-black text-amber-900">{{ $nation->ruler_title }}を譲る</button></div>@empty<p class="py-6 text-center text-sm font-bold text-stone-500">譲渡できる国民がいません。</p>@endforelse</div>
            </section>
        @elseif($page === 'dissolution' && $membership->isRuler())
            <section class="rounded-2xl border border-rose-300 bg-white p-4 shadow-sm" data-nation-dissolution>
                <h2 class="text-xl font-black text-rose-800">国家解散</h2>
                @if($nation->status === \App\Models\Nation::STATUS_DISBAND_PENDING)
                    <p class="mt-2 text-sm font-bold text-stone-700">{{ $nation->dissolution_effective_at?->format('Y/m/d H:i') }}以降に国家を論理解散します。それまでは取り消せます。</p><button type="button" wire:click="cancelDissolution" class="mt-4 min-h-11 w-full rounded-lg border border-rose-300 bg-white font-black text-rose-700">解散申請を取り消す</button>
                @else
                    <p class="mt-2 text-sm font-bold leading-relaxed text-stone-700">申請から{{ $dissolutionWaitHours }}時間後に解散します。国家戦の準備・進行・次戦予約がある場合は申請できません。国民には自主脱退の待機時間を付けません。</p><button type="button" wire:click="openDissolutionConfirmation" class="mt-4 min-h-12 w-full rounded-xl bg-rose-700 font-black text-white">国家解散を申請する</button>
                @endif
            </section>
        @else
            <section class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
                <h2 class="text-base font-black text-stone-900">国家紹介</h2><p class="mt-2 whitespace-pre-line text-sm font-bold leading-relaxed text-stone-600">{{ $nation->description ?: 'この国の物語は、これから刻まれていく。' }}</p>
                @if($nation->recruitment_message)<div class="mt-3 rounded-xl bg-blue-50 p-3"><p class="text-xs font-black text-blue-900">国民への募集文</p><p class="mt-1 whitespace-pre-line text-sm font-bold text-blue-800">{{ $nation->recruitment_message }}</p></div>@endif
            </section>

            <section wire:poll.60s="markNationChatRead" class="overflow-hidden rounded-2xl border border-[#d4af37] bg-white shadow-sm" aria-label="国家チャット" data-nation-chat>
                <div class="flex items-center justify-between gap-3 border-b border-stone-200 bg-stone-50 px-4 py-3">
                    <div>
                        <h2 class="text-base font-black text-stone-900">国家チャット</h2>
                        <p class="mt-0.5 text-[11px] font-bold text-stone-500">自国の国民だけが閲覧・送信できます</p>
                    </div>
                    <span class="shrink-0 rounded-full bg-blue-100 px-2.5 py-1 text-[11px] font-black text-blue-800">最新50件</span>
                </div>
                <div class="max-h-72 divide-y divide-stone-100 overflow-y-auto px-4" aria-live="polite">
                    @forelse($nationChatMessages as $chatMessage)
                        <article wire:key="nation-chat-message-{{ $chatMessage->id }}" class="py-2.5">
                            <div class="flex items-center justify-between gap-3">
                                <p class="min-w-0 truncate text-xs font-black text-blue-800">{{ $chatMessage->character?->name ?? '退会した冒険者' }}</p>
                                <time class="shrink-0 text-[10px] font-bold text-stone-400" title="{{ $chatMessage->created_at?->format('Y/m/d H:i') }}">{{ $chatMessage->created_at?->format('m/d H:i') }}</time>
                            </div>
                            <p class="mt-1 break-words whitespace-pre-wrap text-sm font-bold leading-relaxed text-stone-700">{{ $chatMessage->message }}</p>
                        </article>
                    @empty
                        <p class="py-8 text-center text-sm font-bold text-stone-500">まだ発言はありません。国民へ声をかけてみよう。</p>
                    @endforelse
                </div>
                <form wire:submit="sendNationChatMessage" class="border-t border-stone-200 bg-stone-50 p-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <input type="text" wire:model="nationChatMessage" maxlength="{{ \App\Services\Nation\NationChatService::MAX_MESSAGE_LENGTH }}" required placeholder="国民へメッセージ" class="min-h-11 min-w-0 flex-1 rounded-xl border-stone-300 px-3 text-sm font-bold focus:border-blue-600 focus:ring-blue-600">
                        <button type="submit" wire:loading.attr="disabled" wire:target="sendNationChatMessage" class="min-h-11 shrink-0 rounded-xl bg-blue-700 px-4 text-sm font-black text-white shadow-sm hover:bg-blue-800 disabled:cursor-wait disabled:opacity-50">
                            <span wire:loading.remove wire:target="sendNationChatMessage">送信</span>
                            <span wire:loading wire:target="sendNationChatMessage">送信中</span>
                        </button>
                    </div>
                    @error('nationChatMessage')<p class="mt-1.5 text-xs font-bold text-rose-700">{{ $message }}</p>@enderror
                    @error('nationChatRequestId')<p class="mt-1.5 text-xs font-bold text-rose-700">{{ $message }}</p>@enderror
                </form>
            </section>

            @if($membership->isRuler())
                @php
                    $pendingCount = $pendingApplications->count();
                    $rulerMenuGroups = [
                        [
                            'key' => 'operations',
                            'label' => '運営',
                            'items' => [
                                ['key' => 'applications', 'action' => 'showApplications', 'icon' => '📨', 'title' => '加入申請', 'description' => '届いた加入申請を確認・審査する', 'badge' => $pendingCount > 0 ? "{$pendingCount}件" : null],
                                ['key' => 'members', 'action' => 'showMemberManagement', 'icon' => '👥', 'title' => '国民・役職管理', 'description' => '国民の役職変更や追放を行う'],
                                ['key' => 'profile', 'action' => 'showProfileSettings', 'icon' => '📝', 'title' => '紹介・募集', 'description' => '国家紹介・募集文・紋章を編集する'],
                            ],
                        ],
                        [
                            'key' => 'authority',
                            'label' => '統治',
                            'items' => [
                                ['key' => 'transfer', 'action' => 'showTransfer', 'icon' => '👑', 'title' => '統治者譲渡', 'description' => '国民へ統治者の地位を譲る'],
                                ['key' => 'dissolution', 'action' => 'showDissolution', 'icon' => '⚠️', 'title' => '国家解散', 'description' => '国家の解散申請・取消を行う', 'tone' => 'danger'],
                            ],
                        ],
                    ];
                @endphp
                <section class="rounded-2xl border border-[#d4af37] bg-white p-4 shadow-sm sm:p-5" aria-label="統治者メニュー" data-nation-ruler-menu>
                    <div class="mb-3 flex items-center justify-between gap-3 border-b border-slate-200 pb-3">
                        <h2 class="text-base font-black text-slate-950">統治者メニュー</h2>
                        <span class="text-xs font-bold text-slate-400">詳細・管理</span>
                    </div>
                    @include('livewire.partials.nation-menu-groups', ['menuGroups' => $rulerMenuGroups])
                </section>
            @endif

            <section class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
                <h2 class="text-base font-black text-stone-900">国民一覧</h2><div class="mt-2 divide-y divide-stone-100">@foreach($nation->memberships as $nationMember)<div class="flex items-center justify-between gap-3 py-2.5"><div class="min-w-0"><p class="truncate text-sm font-black text-stone-800">{{ $nationMember->character?->name ?? '不明' }}</p><p class="text-xs font-bold text-stone-500">{{ $nationMember->roleLabel($nation) }}</p></div><div class="shrink-0 text-right text-xs font-bold text-stone-500">Lv{{ $nationMember->character?->level ?? 1 }}<br>{{ $nationMember->joined_at?->format('Y/m/d') }}</div></div>@endforeach</div>
            </section>

            @unless($membership->isRuler())
                <section class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm"><h2 class="text-base font-black text-stone-900">国家を脱退する</h2>@if($leaveEligibility['allowed'])<p class="mt-1 text-xs font-bold text-stone-600">脱退後{{ $leaveJoinCooldownHours }}時間は、ほかの国家へ加入申請できません。</p><button type="button" wire:click="openLeaveConfirmation" class="mt-3 min-h-11 w-full rounded-lg border border-rose-300 bg-white font-black text-rose-700">国家を脱退する</button>@else<p class="mt-2 text-sm font-bold text-stone-700">{{ $leaveEligibility['reason'] }}</p>@if($leaveEligibility['blocked_until'])<p class="mt-1 text-xs font-bold text-stone-500">脱退可能まで {{ $cooldowns->remainingLabel($leaveEligibility['blocked_until']) }}</p>@endif @endif</section>
            @endunless

            @if($membership->isRuler() && $activityLogs->isNotEmpty())
                <section class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm" data-nation-activity-log-preview>
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-base font-black text-stone-900">国家操作履歴</h2>
                        <span class="text-[11px] font-bold text-stone-400">最新{{ $activityLogs->count() }}件</span>
                    </div>
                    <div class="mt-2 divide-y divide-stone-100">
                        @foreach($activityLogs as $log)
                            <div class="py-2" data-nation-activity-log-preview-item>
                                <p class="text-xs font-bold text-stone-700">{{ $activityDescriptions[$log->id] }}</p>
                                <time class="mt-0.5 block text-[11px] font-bold text-stone-400">{{ $log->created_at?->format('Y/m/d H:i') }}</time>
                            </div>
                        @endforeach
                    </div>
                    @if($activityLogTotal > $activityLogPreviewLimit)
                        <button type="button" wire:click="openActivityLogModal" class="mt-3 min-h-11 w-full rounded-xl border border-stone-300 bg-stone-50 text-sm font-black text-stone-700 hover:bg-stone-100" data-nation-activity-log-open>
                            過去の履歴を見る
                        </button>
                    @endif
                </section>
            @endif

            @if($developmentEnabled)
                @php
                    $developmentMenuGroups = [[
                        'key' => 'development',
                        'label' => '発展',
                        'items' => [[
                            'key' => 'resource-management',
                            'action' => 'showResourceManagement',
                            'icon' => '📦',
                            'title' => '国家資材管理',
                            'description' => '都市素材を納品し、国家を発展させる',
                        ]],
                    ]];
                @endphp
                <section class="rounded-2xl border border-[#d4af37] bg-white p-4 shadow-sm sm:p-5" aria-label="国家発展メニュー" data-nation-development-menu>
                    <div class="mb-3 flex items-center justify-between gap-3 border-b border-slate-200 pb-3">
                        <h2 class="text-base font-black text-slate-950">国家発展</h2>
                        <span class="text-xs font-black text-amber-700">Lv{{ $developmentProgress['level'] }}</span>
                    </div>
                    @include('livewire.partials.nation-menu-groups', ['menuGroups' => $developmentMenuGroups])
                </section>
            @endif

            @php
                $upcomingNationMenuGroups = [[
                    'key' => 'development-war',
                    'label' => '発展・戦争',
                    'items' => array_values(array_filter([
                        $developmentEnabled ? null : ['key' => 'resource-management', 'action' => 'showResourceManagement', 'icon' => '📦', 'title' => '国家資材管理', 'description' => '国家戦に備える資材を確認・管理する'],
                        ['key' => 'fortress-upgrade', 'action' => "showNotImplemented('fortress-upgrade')", 'icon' => '🏰', 'title' => '要塞強化', 'description' => '国家要塞の施設と強化状況を確認する'],
                        ['key' => 'declare-war', 'action' => "showNotImplemented('declare-war')", 'icon' => '⚔️', 'title' => '宣戦布告', 'description' => '他国との国家戦を申し込む'],
                        ['key' => 'war-strategy', 'action' => "showNotImplemented('war-strategy')", 'icon' => '📜', 'title' => '戦争方針設定', 'description' => '国家戦での作戦方針を整える'],
                    ])),
                ]];
            @endphp
            <section class="rounded-2xl border border-[#d4af37] bg-white p-4 shadow-sm sm:p-5" aria-label="今後の国家機能" data-nation-upcoming-menu>
                <div class="mb-3 flex items-center justify-between gap-3 border-b border-slate-200 pb-3">
                    <h2 class="text-base font-black text-slate-950">今後の国家機能</h2>
                    <span class="text-xs font-bold text-slate-400">準備中</span>
                </div>
                @include('livewire.partials.nation-menu-groups', ['menuGroups' => $upcomingNationMenuGroups])
            </section>
        @endif
    @endif

    @if($showFoundingEmblemModal && !$membership && $page === 'create')
        <div class="fixed inset-0 z-[90] flex items-center justify-center bg-black/60 p-3 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="founding-emblem-modal-title" data-nation-founding-emblem-modal wire:click.self="closeFoundingEmblemModal" wire:keydown.escape.window="closeFoundingEmblemModal">
            <section class="w-full max-w-lg overflow-hidden rounded-2xl border border-[#d4af37] bg-white shadow-2xl">
                <header class="flex items-center justify-between gap-3 border-b border-stone-200 px-4 py-3">
                    <div>
                        <p class="text-[11px] font-black tracking-[0.16em] text-amber-700">NATION CREST</p>
                        <h2 id="founding-emblem-modal-title" class="text-lg font-black text-stone-950">国家紋章を選ぶ</h2>
                    </div>
                    <button type="button" wire:click="closeFoundingEmblemModal" class="min-h-10 min-w-10 rounded-full border border-stone-300 bg-white text-xl font-black text-stone-500" aria-label="国家紋章の選択を閉じる">×</button>
                </header>
                <div class="p-3 sm:p-4">
                    @include('livewire.partials.nation-emblem-picker', [
                        'wireModel' => 'foundingEmblemKey',
                        'selectedEmblemKey' => $foundingEmblemKey,
                        'selectionAction' => 'selectFoundingEmblem',
                    ])
                    @error('foundingEmblemKey')<span class="mt-2 block text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                    <p class="mt-2 text-center text-xs font-bold text-stone-500">紋章を選ぶと建国画面へ戻ります。</p>
                </div>
            </section>
        </div>
    @endif

    @if($showFoundingConfirmationModal && !$membership && $page === 'create')
        <div class="fixed inset-0 z-[95] flex items-center justify-center bg-black/60 p-3 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="founding-confirmation-modal-title" data-nation-founding-confirmation wire:click.self="closeFoundingConfirmation" wire:keydown.escape.window="closeFoundingConfirmation">
            <section class="w-full max-w-md overflow-hidden rounded-2xl border border-[#d4af37] bg-white shadow-2xl">
                <header class="flex items-center justify-between gap-3 border-b border-stone-200 px-4 py-3">
                    <div>
                        <p class="text-[11px] font-black tracking-[0.16em] text-amber-700">FOUND A NATION</p>
                        <h2 id="founding-confirmation-modal-title" class="text-lg font-black text-stone-950">この内容で建国しますか？</h2>
                    </div>
                    <button type="button" wire:click="closeFoundingConfirmation" class="min-h-10 min-w-10 rounded-full border border-stone-300 bg-white text-xl font-black text-stone-500" aria-label="建国内容の確認を閉じる">×</button>
                </header>
                <div class="max-h-[70vh] overflow-y-auto overscroll-contain p-4">
                    <div class="flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3">
                        <img src="{{ asset($foundingEmblem['path']) }}" alt="{{ $foundingEmblem['alt'] }}" width="128" height="128" class="h-16 w-16 shrink-0 object-contain">
                        <div class="min-w-0">
                            <p class="text-xs font-bold text-amber-800">完成する国家名</p>
                            <p class="break-words text-lg font-black text-stone-950">{{ trim($foundingName) }}{{ $foundingNationTypeOption->label() }}</p>
                            <p class="mt-0.5 text-xs font-bold text-stone-600">{{ $foundingNationTypeOption->rulerTitle() }}：{{ $character->name }}</p>
                        </div>
                    </div>
                    <div class="mt-3 rounded-xl border border-stone-200 bg-stone-50 p-3">
                        <p class="text-xs font-black text-stone-700">国家紹介</p>
                        <p class="mt-1 whitespace-pre-line break-words text-sm font-bold leading-relaxed text-stone-600">{{ trim($foundingDescription) !== '' ? trim($foundingDescription) : '紹介文はありません。' }}</p>
                    </div>
                    <p class="mt-3 text-xs font-bold leading-relaxed text-rose-700">建国後、国号と国家名は今回の初期版では変更できません。</p>
                </div>
                <footer class="grid grid-cols-2 gap-2 border-t border-stone-200 bg-white p-4">
                    <button type="button" wire:click="closeFoundingConfirmation" class="min-h-11 rounded-lg border border-stone-300 bg-white px-3 text-sm font-black text-stone-700">戻って修正</button>
                    <button type="button" wire:click="createNation" wire:loading.attr="disabled" wire:target="createNation" class="min-h-11 rounded-lg border border-amber-700 bg-gradient-to-b from-amber-400 to-amber-600 px-3 text-sm font-black text-white disabled:opacity-50">
                        <span wire:loading.remove wire:target="createNation">建国を確定する</span>
                        <span wire:loading wire:target="createNation">建国しています…</span>
                    </button>
                </footer>
            </section>
        </div>
    @endif

    @if($showDonationConfirmationModal && $membership && $page === 'resources' && $confirmedDonation !== [])
        <div class="fixed inset-0 z-[95] flex items-center justify-center bg-black/60 p-3 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="donation-confirmation-modal-title" data-nation-donation-confirmation wire:click.self="closeDonationConfirmation" wire:keydown.escape.window="closeDonationConfirmation">
            <section class="w-full max-w-md overflow-hidden rounded-2xl border border-[#d4af37] bg-white shadow-2xl">
                <header class="flex items-center justify-between gap-3 border-b border-stone-200 px-4 py-3">
                    <div>
                        <p class="text-[11px] font-black tracking-[0.16em] text-amber-700">CONTRIBUTE</p>
                        <h2 id="donation-confirmation-modal-title" class="text-lg font-black text-stone-950">この素材を納品しますか？</h2>
                    </div>
                    <button type="button" wire:click="closeDonationConfirmation" class="min-h-10 min-w-10 rounded-full border border-stone-300 bg-white text-xl font-black text-stone-500" aria-label="納品確認を閉じる">×</button>
                </header>
                <div class="p-4">
                    <div class="rounded-xl bg-stone-50 p-3">
                        <p class="text-base font-black text-stone-950">{{ $confirmedDonation['name'] }}</p>
                        <div class="mt-2 grid grid-cols-2 gap-2 text-sm font-bold text-stone-700">
                            <p>納品数 <strong class="float-right text-stone-950">{{ number_format($confirmedDonation['quantity']) }}個</strong></p>
                            <p>納品後の残数 <strong class="float-right text-stone-950">{{ number_format($confirmedDonation['remaining_quantity']) }}個</strong></p>
                            <p>国家資材 <strong class="float-right text-slate-950">+{{ number_format($confirmedDonation['points']) }}pt</strong></p>
                            <p>国家発展EXP <strong class="float-right text-amber-800">+{{ number_format($confirmedDonation['development_exp']) }}</strong></p>
                        </div>
                    </div>
                    <p class="mt-3 text-xs font-bold leading-relaxed text-rose-700">納品した素材は返却できません。装備進化・素材交換・NPC調達に使う予定がないか、残数を確認してください。</p>
                </div>
                <footer class="grid grid-cols-2 gap-2 border-t border-stone-200 bg-white p-4">
                    <button type="button" wire:click="closeDonationConfirmation" class="min-h-11 rounded-lg border border-stone-300 bg-white px-3 text-sm font-black text-stone-700">戻る</button>
                    <button type="button" wire:click="donateMaterials" wire:loading.attr="disabled" wire:target="donateMaterials" class="min-h-11 rounded-lg bg-emerald-600 px-3 text-sm font-black text-white disabled:opacity-50">
                        <span wire:loading.remove wire:target="donateMaterials">納品を確定する</span>
                        <span wire:loading wire:target="donateMaterials">納品しています…</span>
                    </button>
                </footer>
            </section>
        </div>
    @endif

    @if($showHeaderBackgroundModal && $membership?->isRuler() && $page === 'home')
        <div class="fixed inset-0 z-[95] flex items-center justify-center bg-black/70 p-3 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="nation-header-background-modal-title" data-nation-header-background-modal wire:click.self="closeHeaderBackgroundModal" wire:keydown.escape.window="closeHeaderBackgroundModal">
            <section class="flex max-h-[88vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-[#d4af37] bg-white shadow-2xl">
                <header class="flex shrink-0 items-center justify-between gap-3 border-b border-stone-200 px-4 py-3">
                    <div>
                        <p class="text-[11px] font-black tracking-[0.16em] text-amber-700">NATION HEADER</p>
                        <h2 id="nation-header-background-modal-title" class="text-lg font-black text-stone-950">国家ヘッダの背景を選ぶ</h2>
                        <p class="mt-0.5 text-xs font-bold text-stone-500">全20種から選べます</p>
                    </div>
                    <button type="button" wire:click="closeHeaderBackgroundModal" class="min-h-10 min-w-10 rounded-full border border-stone-300 bg-white text-xl font-black text-stone-500" aria-label="国家ヘッダ背景の選択を閉じる">×</button>
                </header>
                <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-3 sm:p-4">
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-3">
                        @foreach($headerBackgrounds as $backgroundKey => $background)
                            <button
                                type="button"
                                wire:click="selectHeaderBackground('{{ $backgroundKey }}')"
                                wire:key="nation-header-background-{{ $backgroundKey }}"
                                aria-pressed="{{ $profileHeaderBackgroundKey === $backgroundKey ? 'true' : 'false' }}"
                                class="overflow-hidden rounded-xl border-2 bg-stone-950 text-left shadow-sm transition {{ $profileHeaderBackgroundKey === $backgroundKey ? 'border-amber-500 ring-2 ring-amber-200' : 'border-transparent hover:border-amber-300' }}"
                                data-nation-header-background-option="{{ $backgroundKey }}"
                            >
                                <img src="{{ asset($background['path']) }}" alt="{{ $background['alt'] }}" width="600" height="232" loading="lazy" class="aspect-[600/232] w-full object-cover">
                                <span class="block px-2 py-1.5 text-[11px] font-black {{ $profileHeaderBackgroundKey === $backgroundKey ? 'bg-amber-100 text-amber-900' : 'bg-white text-stone-700' }}">{{ $background['label'] }}</span>
                            </button>
                        @endforeach
                    </div>
                    @error('profileHeaderBackgroundKey')<p class="mt-3 text-xs font-bold text-rose-700">{{ $message }}</p>@enderror
                </div>
                <footer class="grid shrink-0 grid-cols-2 gap-2 border-t border-stone-200 bg-white p-4">
                    <button type="button" wire:click="closeHeaderBackgroundModal" class="min-h-11 rounded-lg border border-stone-300 bg-white px-3 text-sm font-black text-stone-700">キャンセル</button>
                    <button type="button" wire:click="saveHeaderBackground" wire:loading.attr="disabled" wire:target="saveHeaderBackground" class="min-h-11 rounded-lg bg-blue-700 px-3 text-sm font-black text-white disabled:opacity-50">
                        <span wire:loading.remove wire:target="saveHeaderBackground">この背景に変更</span>
                        <span wire:loading wire:target="saveHeaderBackground">変更しています…</span>
                    </button>
                </footer>
            </section>
        </div>
    @endif

    @if($showActivityLogModal && $membership?->isRuler())
        <div class="fixed inset-0 z-[95] flex items-center justify-center bg-black/60 p-3 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="nation-activity-log-modal-title" data-nation-activity-log-modal wire:click.self="closeActivityLogModal" wire:keydown.escape.window="closeActivityLogModal">
            <section class="flex max-h-[82vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-[#d4af37] bg-white shadow-2xl">
                <header class="flex shrink-0 items-center justify-between gap-3 border-b border-stone-200 px-4 py-3">
                    <div>
                        <p class="text-[11px] font-black tracking-[0.16em] text-amber-700">NATION HISTORY</p>
                        <h2 id="nation-activity-log-modal-title" class="text-lg font-black text-stone-950">国家操作履歴</h2>
                    </div>
                    <button type="button" wire:click="closeActivityLogModal" class="min-h-10 min-w-10 rounded-full border border-stone-300 bg-white text-xl font-black text-stone-500" aria-label="国家操作履歴を閉じる">×</button>
                </header>
                <div class="min-h-0 flex-1 divide-y divide-stone-100 overflow-y-auto overscroll-contain px-4">
                    @foreach($activityLogModalEntries as $log)
                        <div class="py-3" data-nation-activity-log-modal-item>
                            <p class="text-sm font-bold leading-relaxed text-stone-700">{{ $activityDescriptions[$log->id] }}</p>
                            <time class="mt-1 block text-[11px] font-bold text-stone-400">{{ $log->created_at?->format('Y/m/d H:i') }}</time>
                        </div>
                    @endforeach
                </div>
                <footer class="shrink-0 border-t border-stone-200 bg-stone-50 px-4 py-3 text-center">
                    <p class="text-[11px] font-bold text-stone-500">
                        @if($activityLogTotal > $activityLogModalLimit)
                            全{{ number_format($activityLogTotal) }}件のうち、直近{{ number_format($activityLogModalLimit) }}件を表示しています。
                        @else
                            全{{ number_format($activityLogTotal) }}件を表示しています。
                        @endif
                    </p>
                </footer>
            </section>
        </div>
    @endif

    @if($pendingFeature)
        <div class="fixed inset-0 z-[80] flex items-center justify-center bg-black/60 p-4" role="dialog" aria-modal="true" data-nation-coming-soon wire:click.self="closeNotImplementedModal"><div class="w-full max-w-sm rounded-2xl border border-[#d4af37] bg-white p-5 text-center shadow-2xl"><div class="text-4xl" aria-hidden="true">🛠️</div><h2 class="mt-2 text-xl font-black text-stone-950">準備中</h2><p class="mt-2 text-sm font-black text-stone-700">{{ $pendingFeature }}</p><p class="mt-2 text-sm font-bold leading-relaxed text-stone-600">この機能は現在準備中です。<br>今後のアップデートで利用できるようになります。</p><button type="button" wire:click="closeNotImplementedModal" class="mt-5 min-h-11 w-full rounded-lg bg-stone-900 font-black text-white">閉じる</button></div></div>
    @endif

    @if($confirmationAction)
        <div class="fixed inset-0 z-[80] flex items-center justify-center bg-black/60 p-4" role="dialog" aria-modal="true" data-nation-confirmation @if($confirmationAction === 'approve-application') data-nation-application-approval-confirmation @endif wire:click.self="closeConfirmation"><div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl">
            @if($confirmationAction === 'approve-application')
                <h2 class="text-xl font-black text-stone-950">{{ $confirmationApplication?->character?->name ?? 'この冒険者' }}を国民として承認しますか？</h2><p class="mt-3 text-sm font-bold leading-relaxed text-stone-700">承認すると、{{ $confirmationApplication?->nation?->display_name ?? $membership?->nation?->display_name ?? 'この国家' }}へ直ちに加入します。</p>
            @elseif($confirmationAction === 'leave')
                <h2 class="text-xl font-black text-stone-950">{{ $membership?->nation?->display_name }}を脱退しますか？</h2><p class="mt-3 text-sm font-bold leading-relaxed text-stone-700">脱退後{{ $leaveJoinCooldownHours }}時間は、ほかの国家へ加入申請できません。</p>
            @elseif($confirmationAction === 'expel')
                <h2 class="text-xl font-black text-stone-950">{{ $confirmationTarget?->character?->name ?? 'この国民' }}を追放しますか？</h2><p class="mt-3 text-sm font-bold leading-relaxed text-stone-700">追放された冒険者は{{ $expelJoinCooldownHours }}時間すべての国家へ申請できず、{{ $membership?->nation?->display_name ?? 'この国家' }}へは{{ $expelSameNationCooldownDays }}日間再申請できません。</p>
            @elseif($confirmationAction === 'transfer')
                <h2 class="text-xl font-black text-stone-950">{{ $confirmationTarget?->character?->name ?? 'この国民' }}へ{{ $membership?->nation?->ruler_title ?? '統治者' }}の地位を譲ります。</h2><p class="mt-3 text-sm font-bold leading-relaxed text-rose-700">譲渡後、あなたは一般国民になります。<br>この操作を実行しますか？</p>
            @else
                <h2 class="text-xl font-black text-rose-800">{{ $membership?->nation?->display_name ?? '国家' }}を解散しますか？</h2><p class="mt-3 text-sm font-bold leading-relaxed text-stone-700">{{ $dissolutionWaitHours }}時間の待機後に論理解散します。確認のため完成国家名を入力してください。</p><input wire:model="dissolutionConfirmation" class="mt-3 min-h-11 w-full rounded-lg border border-rose-300 px-3 font-bold" placeholder="{{ $membership?->nation?->display_name ?? '' }}">@error('dissolutionConfirmation')<span class="mt-1 block text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
            @endif
            <div class="mt-5 grid grid-cols-2 gap-2"><button type="button" wire:click="closeConfirmation" class="min-h-11 rounded-lg border border-stone-300 bg-white font-black text-stone-700">戻る</button><button type="button" wire:click="confirmAction" wire:loading.attr="disabled" wire:target="confirmAction" class="min-h-11 rounded-lg font-black text-white disabled:opacity-50 {{ $confirmationAction === 'approve-application' ? 'bg-emerald-600' : 'bg-rose-700' }}">{{ $confirmationAction === 'approve-application' ? '承認する' : '実行する' }}</button></div>
        </div></div>
    @endif
</div>
