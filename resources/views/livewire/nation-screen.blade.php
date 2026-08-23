<div
    class="mx-auto w-full max-w-4xl space-y-3 bg-white px-2.5 pb-24 pt-3 sm:space-y-4 sm:px-5 sm:pt-5"
    data-nation-screen
    data-nation-membership-state="{{ $membership ? 'member' : 'unaffiliated' }}"
>
    @if(!$membership)
        <section class="rounded-2xl border border-[#d4af37] bg-white p-4 shadow-sm sm:p-5">
            <div class="flex items-center gap-3 sm:gap-5">
                <img
                    src="{{ asset('images/nation/nation-crest-green.webp') }}"
                    alt="緑の城を描いた国家の紋章"
                    width="128"
                    height="128"
                    class="h-24 w-24 shrink-0 object-contain sm:h-28 sm:w-28"
                >
                <div class="min-w-0">
                    <p class="text-xs font-black tracking-[0.22em] text-emerald-700">NATION</p>
                    <h1 class="mt-0.5 text-2xl font-black text-stone-950">国家</h1>
                    <p class="mt-1 text-sm font-bold leading-relaxed text-stone-600">国を興し、仲間を集め、要塞を築き、他国との戦いに備えよう。</p>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3">
                <button
                    type="button"
                    wire:click="showNotImplemented('nation-search')"
                    class="min-h-12 rounded-xl border border-blue-800 bg-gradient-to-b from-blue-500 to-blue-700 px-3 py-2.5 text-sm font-black text-white shadow-sm transition active:scale-[0.98]"
                >
                    <span aria-hidden="true">🔍</span> 国家を探す
                </button>
                <button
                    type="button"
                    wire:click="showNotImplemented('nation-create')"
                    class="min-h-12 rounded-xl border border-amber-700 bg-gradient-to-b from-amber-400 to-amber-600 px-3 py-2.5 text-sm font-black text-white shadow-sm transition active:scale-[0.98]"
                >
                    <span aria-hidden="true">🏰</span> 建国する
                </button>
            </div>
        </section>

        <section class="rounded-2xl border border-[#d4af37] bg-white p-3 shadow-sm sm:p-4">
            <div class="flex items-center justify-between gap-3 border-b border-[#e3ded2] pb-3">
                <h2 class="text-base font-black text-stone-900">国家一覧</h2>
                <button
                    type="button"
                    wire:click="showNotImplemented('nation-list')"
                    class="min-h-10 rounded-lg border border-[#cfc6b4] bg-white px-3 text-xs font-black text-stone-700 shadow-sm"
                >
                    一覧を見る
                </button>
            </div>

            <div class="divide-y divide-[#e8e2d8]">
                @forelse($nations as $nation)
                    @php($kingName = $nation->memberships->first()?->character?->name ?? '不明')
                    <article class="flex gap-3 py-3">
                        <img
                            src="{{ asset('images/nation/nation-crest-blue.webp') }}"
                            alt="{{ $nation->name }}の仮紋章"
                            width="128"
                            height="128"
                            loading="lazy"
                            class="h-16 w-16 shrink-0 object-contain sm:h-20 sm:w-20"
                        >
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <h3 class="truncate text-base font-black text-stone-950">{{ $nation->name }}</h3>
                                    <p class="mt-0.5 text-xs font-bold text-stone-500">国王：{{ $kingName }}</p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div class="text-sm font-black text-stone-700">{{ $nation->memberships_count }}/100人</div>
                                    <div class="text-[11px] font-black text-emerald-600">参加受付は準備中</div>
                                </div>
                            </div>
                            <p class="mt-1 line-clamp-2 text-xs font-bold leading-relaxed text-stone-600">{{ $nation->description ?: 'この国の物語は、これから刻まれていく。' }}</p>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <button type="button" wire:click="showNotImplemented('nation-detail')" class="min-h-9 rounded-lg border border-[#cfc6b4] bg-white px-2 text-xs font-black text-stone-700">詳細を見る</button>
                                <button type="button" wire:click="showNotImplemented('nation-apply')" class="min-h-9 rounded-lg border border-blue-800 bg-blue-600 px-2 text-xs font-black text-white">加入申請</button>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="py-7 text-center">
                        <div class="text-3xl" aria-hidden="true">🛡️</div>
                        <p class="mt-2 text-sm font-black text-stone-700">まだ国家は存在しない。</p>
                        <p class="mt-1 text-xs font-bold text-stone-500">最初の建国者が現れる日を待っている。</p>
                    </div>
                @endforelse
            </div>

            @if($nationCount > 3)
                <button type="button" wire:click="showNotImplemented('nation-list')" class="min-h-11 w-full rounded-lg border border-[#d4af37] bg-white text-sm font-black text-stone-700">
                    もっと多くの国家を見る <span aria-hidden="true">›</span>
                </button>
            @endif
        </section>

        <section class="rounded-2xl border border-[#d4af37] bg-white p-4 shadow-sm">
            <h2 class="text-base font-black text-amber-900">国家とは？</h2>
            <p class="mt-1 text-xs font-bold leading-relaxed text-stone-600">冒険者たちが集まり、資材を持ち寄って要塞を築く大きな共同体です。</p>
            <div class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-sm font-black text-stone-700">
                <div class="flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2"><span class="text-xl" aria-hidden="true">🏗️</span><span>要塞を発展</span></div>
                <div class="flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2"><span class="text-xl" aria-hidden="true">📦</span><span>資材を納品</span></div>
                <div class="flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2"><span class="text-xl" aria-hidden="true">👥</span><span>国民と協力</span></div>
                <div class="flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2"><span class="text-xl" aria-hidden="true">⚔️</span><span>他国と戦う</span></div>
            </div>
        </section>
    @else
        <section class="rounded-2xl border border-[#d4af37] bg-white p-4 shadow-sm sm:p-5">
            <div class="flex items-start gap-3 sm:gap-5">
                <img
                    src="{{ asset('images/nation/nation-crest-blue.webp') }}"
                    alt="{{ $dashboard['nation']->name }}の仮紋章"
                    width="128"
                    height="128"
                    class="h-20 w-20 shrink-0 object-contain sm:h-28 sm:w-28"
                >
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-black tracking-[0.2em] text-blue-700">MY NATION</p>
                    <h1 class="break-words text-xl font-black leading-tight text-stone-950 sm:text-2xl">{{ $dashboard['nation']->name }}</h1>
                    <p class="mt-1 text-xs font-bold text-stone-600">国王：{{ $dashboard['king_name'] }}</p>
                    <p class="mt-0.5 text-xs font-bold text-stone-600">国民数：{{ $dashboard['member_count'] }} / 100人</p>
                    <span class="mt-1 inline-flex rounded-full bg-stone-100 px-2 py-1 text-[11px] font-black text-stone-600">国家機能は準備中</span>
                    <button type="button" wire:click="showNotImplemented('nation-settings')" class="mt-2 block min-h-10 rounded-lg border border-[#cfc6b4] bg-white px-3 text-xs font-black text-stone-700 shadow-sm">
                        ⚙️ 国家設定
                    </button>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-3 gap-2">
                <div class="rounded-xl border border-[#e1dacd] bg-white px-2 py-3 text-center shadow-sm">
                    <div class="text-[11px] font-black text-amber-800">国家資材</div>
                    <div class="mt-1 text-lg font-black text-stone-950">{{ number_format($dashboard['nation']->treasury_points) }}<span class="ml-1 text-xs">pt</span></div>
                </div>
                <div class="rounded-xl border border-[#e1dacd] bg-white px-2 py-3 text-center shadow-sm">
                    <div class="text-[11px] font-black text-amber-800">要塞発展度</div>
                    <div class="mt-1 text-lg font-black text-stone-950">Lv.{{ number_format($dashboard['average_level'], 1) }}</div>
                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-stone-200"><div class="h-full rounded-full bg-blue-500" style="width: {{ $dashboard['development_percent'] }}%"></div></div>
                </div>
                <div class="rounded-xl border border-[#e1dacd] bg-white px-2 py-3 text-center shadow-sm">
                    <div class="text-[11px] font-black text-amber-800">戦績</div>
                    <div class="mt-1 text-sm font-black text-stone-950">{{ $dashboard['wins'] }}勝 {{ $dashboard['losses'] }}敗 {{ $dashboard['draws'] }}分</div>
                    <div class="mt-1 text-[11px] font-bold text-stone-500">勝率 {{ number_format($dashboard['win_rate'], 1) }}%</div>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-[#d4af37] bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-black text-stone-900">現在の状況</h2>
                <span class="rounded-lg border px-3 py-1 text-xs font-black {{ $dashboard['is_at_war'] ? 'border-rose-300 bg-rose-50 text-rose-700' : 'border-sky-300 bg-sky-50 text-sky-700' }}">{{ $dashboard['war_status'] }}</span>
            </div>
            <div class="mt-2 flex items-center gap-3 rounded-xl bg-stone-50 px-3 py-3">
                <span class="text-3xl" aria-hidden="true">{{ $dashboard['is_at_war'] ? '⚔️' : '🕊️' }}</span>
                <div>
                    <p class="text-sm font-black text-stone-800">{{ $dashboard['is_at_war'] ? '国家戦に関する状況を確認してください。' : '現在、戦争は行われていません。' }}</p>
                    <p class="mt-0.5 text-xs font-bold text-stone-500">国を強化し、来るべき戦いに備えよう。</p>
                </div>
            </div>
        </section>

        <div class="grid gap-3 md:grid-cols-2">
            <section class="rounded-2xl border border-[#d4af37] bg-white p-4 shadow-sm">
                <h2 class="text-base font-black text-stone-900">要塞の状態</h2>
                <div class="mt-3 space-y-2.5">
                    @foreach($dashboard['facilities'] as $facility)
                        <div class="grid grid-cols-[1.5rem_4.8rem_2.5rem_1fr_2.4rem] items-center gap-1 text-xs">
                            <span class="text-lg" aria-hidden="true">{{ $facility['icon'] }}</span>
                            <span class="font-black text-stone-700">{{ $facility['label'] }}</span>
                            <span class="font-bold text-stone-500">Lv.{{ $facility['level'] }}</span>
                            <div class="h-2 overflow-hidden rounded-full bg-stone-200"><div class="h-full rounded-full bg-emerald-500" style="width: {{ $facility['condition_percent'] }}%"></div></div>
                            <span class="text-right font-black text-emerald-700">{{ $facility['condition_percent'] }}%</span>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3 rounded-lg bg-stone-100 px-3 py-2 text-center text-xs font-black text-stone-600">要塞発展度平均：Lv.{{ number_format($dashboard['average_level'], 1) }}</div>
            </section>

            <section class="rounded-2xl border border-[#d4af37] bg-white p-4 shadow-sm">
                <h2 class="text-base font-black text-stone-900">お知らせ・申請</h2>
                <div class="mt-2 divide-y divide-[#e8e2d8]">
                    @foreach([['👤','加入申請'],['💌','招待中の冒険者'],['✉️','国家からのお知らせ']] as [$icon, $label])
                        <button type="button" wire:click="showNotImplemented('notices')" class="flex min-h-11 w-full items-center justify-between gap-3 py-2 text-left">
                            <span class="text-sm font-black text-stone-700"><span class="mr-2" aria-hidden="true">{{ $icon }}</span>{{ $label }}</span>
                            <span class="text-xs font-black text-stone-400">準備中</span>
                        </button>
                    @endforeach
                </div>
                <div class="mt-3 rounded-xl border border-blue-100 bg-blue-50 p-3 text-center">
                    <p class="text-sm font-black text-blue-900">🏰 国民募集中！</p>
                    <p class="mt-1 text-xs font-bold text-blue-700">仲間とともに国を強くしよう。</p>
                    <button type="button" wire:click="showNotImplemented('recruitment')" class="mt-2 min-h-10 w-full rounded-lg border border-blue-300 bg-white text-xs font-black text-blue-800">募集内容を編集</button>
                </div>
            </section>
        </div>

        <section class="grid grid-cols-3 gap-2 sm:gap-3" aria-label="国家メニュー">
            @foreach([
                ['members', '👥', '国民', 'メンバー管理'],
                ['donation', '📦', '納品', '資材を納品する'],
                ['fortress', '🏰', '要塞', '要塞を強化する'],
                ['war', '⚔️', '戦争', '宣戦・戦況確認'],
                ['history', '📜', '戦史', '過去の戦争記録'],
                ['nation-settings', '⚙️', '設定', '国家の各種設定'],
            ] as [$feature, $icon, $label, $description])
                <button type="button" wire:click="showNotImplemented('{{ $feature }}')" class="min-h-24 rounded-xl border border-[#d4af37] bg-white px-2 py-3 text-center shadow-sm transition active:scale-[0.98]">
                    <span class="block text-3xl" aria-hidden="true">{{ $icon }}</span>
                    <span class="mt-1 block text-sm font-black text-stone-900">{{ $label }}</span>
                    <span class="mt-0.5 block text-[10px] font-bold leading-tight text-stone-500">{{ $description }}</span>
                </button>
            @endforeach
        </section>
    @endif

    @if($pendingFeature)
        <div class="fixed inset-0 z-[10050] flex items-center justify-center px-4 py-6" data-nation-not-implemented-modal>
            <button type="button" wire:click="closeNotImplementedModal" class="absolute inset-0 bg-slate-950/55 backdrop-blur-[1px]" aria-label="モーダルを閉じる"></button>
            <section class="relative z-10 w-full max-w-sm overflow-hidden rounded-2xl border-2 border-[#d4af37] bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="nation-pending-modal-title">
                <div class="border-b border-amber-200 bg-gradient-to-r from-amber-50 to-stone-50 px-5 py-4 text-center">
                    <div class="text-4xl" aria-hidden="true">🛠️</div>
                    <h2 id="nation-pending-modal-title" class="mt-2 text-lg font-black text-stone-950">{{ $pendingFeature }}は準備中です</h2>
                </div>
                <div class="px-5 py-4 text-center">
                    <p class="text-sm font-bold leading-relaxed text-stone-600">この機能はまだ利用できません。現在は画面のみの実装です。</p>
                    <button type="button" wire:click="closeNotImplementedModal" class="mt-4 min-h-11 w-full rounded-xl bg-stone-900 px-4 text-sm font-black text-white">閉じる</button>
                </div>
            </section>
        </div>
    @endif
</div>
