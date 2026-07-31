<!DOCTYPE html>
<html lang="ja">
<head>
    @if(!auth()->check() || auth()->user()->role !== 'admin')
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-XGYVC4YYP2"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', 'G-XGYVC4YYP2');
    </script>
    @endif
    <!-- PWA -->
    <link rel="manifest" href="{{ asset('manifest.json') }}?v=3">
    <meta name="theme-color" content="#ffffff">
    <link rel="apple-touch-icon" href="{{ asset('images/icon-192x192.png') }}?v=3">
    <link id="admin-favicon" rel="icon" href="{{ asset('images/favicon.webp') }}?v=2" type="image/webp">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js?v=3').then(registration => {
                    console.log('ServiceWorker registration successful with scope: ', registration.scope);
                }).catch(err => {
                    console.log('ServiceWorker registration failed: ', err);
                });
            });
        }
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Valzeria - 管理者ダッシュボード</title>
    @include('partials.ogp', ['ogTitle' => 'Valzeria - 管理者ダッシュボード'])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        [x-cloak] { display: none !important; }

        /*
         * 通常画面のタブ切替中に付与された body の overflow: hidden が、
         * Livewire Navigate 経由で管理画面まで残る場合がある。
         * 管理画面はページ全体をスクロール領域として使うため、基準の
         * スクロール設定をここで明示する。
         */
        html {
            min-height: 100%;
            overflow-y: auto;
        }

        body {
            min-height: 100%;
            overflow-x: hidden;
            overflow-y: auto;
        }

        @media (min-width: 1024px) {
            .admin-layout {
                height: 100dvh;
                overflow: hidden;
            }

            .admin-content-scroll {
                height: 100dvh;
                overflow-y: auto;
                overscroll-behavior-y: contain;
                scrollbar-gutter: stable;
            }
        }
    </style>
</head>
<body class="bg-slate-100 font-sans antialiased text-slate-900">
    @php
        $mailNavItem = ['route' => 'admin.contact-messages', 'label' => 'メール', 'abbr' => 'M'];
        $navGroups = [
            [
                'key' => 'overview',
                'label' => '概要',
                'items' => [
                    ['route' => 'admin.dashboard', 'label' => '分析ダッシュボード', 'abbr' => 'A'],
                    ['route' => 'admin.world-metrics', 'label' => '世界指標', 'abbr' => 'W'],
                    ['route' => 'admin.world-activity-map', 'label' => '冒険者分布マップ', 'abbr' => 'MAP'],
                    ['route' => 'admin.inn-analytics', 'label' => '宿屋売上分析', 'abbr' => 'IN'],
                    ['route' => 'admin.operator-analytics', 'label' => '統計分析', 'abbr' => 'Y'],
                    ['route' => 'admin.growth-analytics', 'label' => '運営分析', 'abbr' => 'G'],
                    ['route' => 'admin.top-analytics', 'label' => 'TOPアクセス解析', 'abbr' => 'V'],
                ],
            ],
            [
                'key' => 'operations',
                'label' => '運用',
                'items' => [
                    ['route' => 'admin.security-anomalies', 'label' => '異常検知・不正調査', 'abbr' => 'P1'],
                    ['route' => 'admin.players', 'label' => 'プレイヤー一覧', 'abbr' => 'P'],
                    ['route' => 'admin.bug-reports', 'label' => '不具合フォーム', 'abbr' => '!'],
                    ['route' => 'admin.character-icon-design.index', 'active' => 'admin.character-icon-design.*', 'label' => 'キャラアイコン制作', 'abbr' => 'CI'],
                    ['route' => 'admin.user-investigation', 'label' => 'ユーザー調査', 'abbr' => 'U'],
                    ['route' => 'admin.player-controls', 'label' => '輝石付与・プレイヤー調整', 'abbr' => 'C'],
                    ['route' => 'admin.action-logs', 'label' => '行動ログ', 'abbr' => 'L'],
                    ['route' => 'admin.public-logs', 'label' => '公開ログ管理', 'abbr' => 'O'],
                    ['route' => 'admin.chat', 'label' => '管理人チャット', 'abbr' => 'Q'],
                    ['route' => 'admin.private-chat-logs', 'label' => '個人チャットログ', 'abbr' => 'D'],
                    ['route' => 'admin.kiseki-purchases', 'label' => '課金監査', 'abbr' => 'K'],
                    ['route' => 'admin.npc-market-analytics', 'label' => 'NPC市場分析', 'abbr' => 'M'],
                    ['route' => 'admin.equipment-market.index', 'label' => '装備市場管理', 'abbr' => 'EM'],
                    ['route' => 'admin.reward-settings', 'label' => '運営・報酬設定', 'abbr' => 'R'],
                    ['route' => 'admin.adventure-support-items', 'label' => '補給商会ON/OFF', 'abbr' => 'S'],
                    ['route' => 'admin.extra-contents', 'label' => '追加コンテンツON/OFF', 'abbr' => 'X'],
                    ['route' => 'admin.top-updates', 'label' => '街の更新履歴', 'abbr' => 'N'],
                    ['route' => 'admin.game-texts', 'label' => '画面文言管理', 'abbr' => 'T'],
                    ['route' => 'admin.help-texts', 'label' => 'ヘルプ文言管理', 'abbr' => 'H'],
                    ['route' => 'admin.facility-texts', 'label' => '施設テキスト管理', 'abbr' => 'F'],
                ],
            ],
            [
                'key' => 'masters',
                'label' => 'マスタ',
                'items' => [
                    ['route' => 'admin.items', 'label' => 'アイテム一覧', 'abbr' => 'I'],
                    ['route' => 'admin.jobs', 'label' => '職業管理', 'abbr' => 'J'],
                    ['route' => 'admin.dungeon-enemies', 'label' => '敵データ調整', 'abbr' => 'M'],
                    ['route' => 'admin.region-depth-dungeons', 'label' => '追加ダンジョン管理', 'abbr' => 'RD'],
                    ['route' => 'admin.published-maps', 'label' => '公開地図', 'abbr' => 'MAP'],
                    ['route' => 'admin.job-affinity', 'label' => '職業相性', 'abbr' => 'F'],
                    ['route' => 'admin.equipment-compatibility', 'label' => '装備相性', 'abbr' => 'E'],
                ],
            ],
            [
                'key' => 'tools',
                'label' => '検証',
                'items' => [
                    ['route' => 'admin.tools', 'label' => 'ツール集', 'abbr' => 'X'],
                    ['route' => 'admin.battle-simulator', 'label' => '戦闘シミュレーション', 'abbr' => 'B'],
                    ['route' => 'admin.balance-battle-lab', 'label' => '仮想バランス検証', 'abbr' => 'S'],
                    ['route' => 'admin.skill-effect-lab', 'label' => '技効果検証', 'abbr' => 'K'],
                    ['route' => 'admin.route-health', 'label' => '正常性チェック', 'abbr' => 'H'],
                    ['route' => 'admin.testers', 'label' => 'テストキャラ管理', 'abbr' => 'T'],
                ],
            ],
        ];

        $activeGroupKey = collect($navGroups)
            ->first(fn ($group) => collect($group['items'])->contains(
                fn ($item) => request()->routeIs($item['active'] ?? $item['route'])
            ))['key'] ?? 'overview';
        $mailNavActive = request()->routeIs($mailNavItem['route']);
        $iconDesignUnreadCount = \Illuminate\Support\Facades\Schema::hasTable('character_icon_design_requests')
            && \Illuminate\Support\Facades\Schema::hasTable('character_icon_design_messages')
            ? \App\Models\CharacterIconDesignRequest::query()
                ->where(function ($query) {
                    $query->where('status', 'submitted')
                        ->orWhereHas('messages', fn ($messageQuery) => $messageQuery
                            ->where('sender_type', 'player')
                            ->whereNull('read_by_admin_at'));
                })
                ->count()
            : 0;
    @endphp

    <div class="admin-layout min-h-screen lg:flex"
         x-data="{ mobileNavOpen: false, mobileOpenGroup: @js($activeGroupKey), replyPopoverOpen: false }"
         x-init="document.documentElement.style.removeProperty('overflow'); document.body.style.removeProperty('overflow')"
         @keydown.window.escape="mobileNavOpen = false; replyPopoverOpen = false">
        <aside class="hidden lg:flex lg:fixed lg:inset-y-0 lg:left-0 lg:w-72 lg:flex-col bg-slate-950 text-white shadow-2xl">
            <div class="flex h-full flex-col">
                <div class="px-7 pt-7 pb-4">
                    <a href="{{ route('admin.dashboard') }}" class="block">
                        <div class="text-xs font-bold tracking-[0.35em] text-amber-300">VALZERIA</div>
                        <div class="mt-2 text-2xl font-black tracking-[0.16em]">ADMIN</div>
                    </a>
                    <div class="mt-5 rounded-md border border-white/10 bg-white/5 p-3">
                        <div class="text-xs font-bold text-slate-400">管理コンソール</div>
                        <div class="mt-1 text-sm font-semibold text-slate-100">マスタと運用データの調整</div>
                    </div>
                </div>

                <nav class="flex-1 overflow-y-auto px-4 pb-4" x-data="{ openGroup: @js($activeGroupKey) }">
                    <div class="space-y-3">
                        <a href="{{ route($mailNavItem['route']) }}"
                           class="group flex items-center gap-3 rounded-md border px-3 py-3 text-sm font-bold transition {{ $mailNavActive ? 'border-amber-300 bg-amber-300 text-slate-950 shadow-lg shadow-amber-950/20' : 'border-white/10 bg-white/[0.03] text-slate-200 hover:bg-white/10 hover:text-white' }}">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-[11px] font-black {{ $mailNavActive ? 'bg-slate-950 text-amber-200' : 'bg-white/10 text-slate-200 group-hover:bg-white/15' }}">{{ $mailNavItem['abbr'] }}</span>
                            <span class="truncate">{{ $mailNavItem['label'] }}</span>
                        </a>
                        @foreach($navGroups as $group)
                            @php
                                $groupActive = collect($group['items'])->contains(
                                    fn ($item) => request()->routeIs($item['active'] ?? $item['route'])
                                );
                            @endphp
                            <section class="rounded-md border {{ $groupActive ? 'border-amber-300/40 bg-amber-300/10' : 'border-white/10 bg-white/[0.03]' }}">
                                <button type="button"
                                        @click="openGroup = openGroup === @js($group['key']) ? '' : @js($group['key'])"
                                        class="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left">
                                    <span class="text-xs font-black tracking-[0.16em] {{ $groupActive ? 'text-amber-200' : 'text-slate-400' }}">{{ $group['label'] }}</span>
                                    <span class="text-xs font-black text-slate-500" x-text="openGroup === @js($group['key']) ? '−' : '+'"></span>
                                </button>
                                <div x-show="openGroup === @js($group['key'])" class="space-y-1 px-2 pb-2">
                                    @foreach($group['items'] as $item)
                                        @php $active = request()->routeIs($item['active'] ?? $item['route']); @endphp
                                        <a href="{{ route($item['route']) }}" class="group flex items-center gap-3 rounded-md px-2.5 py-2.5 text-sm font-bold transition {{ $active ? 'bg-amber-300 text-slate-950 shadow-lg shadow-amber-950/20' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-[11px] font-black {{ $active ? 'bg-slate-950 text-amber-200' : 'bg-white/10 text-slate-200 group-hover:bg-white/15' }}">{{ $item['abbr'] }}</span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate">{{ $item['label'] }}</span>
                                                @if($item['route'] === 'admin.user-investigation')
                                                    <span data-admin-reply-senders hidden class="mt-0.5 block truncate text-[10px] font-bold leading-tight {{ $active ? 'text-slate-700' : 'text-rose-300' }}"></span>
                                                @endif
                                            </span>
                                            @if($item['route'] === 'admin.user-investigation')
                                                <span data-admin-reply-badge hidden class="ml-auto rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-black text-white"></span>
                                            @elseif($item['route'] === 'admin.character-icon-design.index' && $iconDesignUnreadCount > 0)
                                                <span class="ml-auto rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-black text-white">{{ $iconDesignUnreadCount > 99 ? '99+' : $iconDesignUnreadCount }}</span>
                                            @endif
                                        </a>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                </nav>

                <div class="px-4 pb-5">
                    <a href="{{ route('top') }}" class="mb-3 flex items-center justify-center rounded-md border border-white/10 px-4 py-2.5 text-sm font-bold text-slate-300 transition hover:bg-white/10 hover:text-white">
                        サイトトップへ
                    </a>
                    <form action="{{ route('admin.logout') }}" method="POST" data-submit-lock data-loading-text="ログアウト中...">
                        @csrf
                        <button type="submit" class="w-full rounded-md bg-white px-4 py-2.5 text-sm font-black text-slate-950 shadow hover:bg-amber-100">
                            ログアウト
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <div class="admin-content-scroll min-w-0 flex-1 lg:pl-72">
            <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 shadow-sm backdrop-blur lg:hidden">
                <div class="flex h-16 items-center justify-between px-4">
                    <button type="button"
                            @click="mobileNavOpen = true"
                            class="flex h-10 w-10 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-800 shadow-sm active:scale-95"
                            aria-label="管理メニューを開く"
                            :aria-expanded="mobileNavOpen.toString()">
                        <span class="flex w-5 flex-col gap-1.5">
                            <span class="h-0.5 rounded-full bg-current"></span>
                            <span class="h-0.5 rounded-full bg-current"></span>
                            <span class="h-0.5 rounded-full bg-current"></span>
                        </span>
                    </button>
                    <a href="{{ route('admin.dashboard') }}" class="text-sm font-black tracking-[0.14em] text-slate-950 sm:text-base sm:tracking-[0.18em]">
                        <span class="text-amber-500">VALZERIA</span> ADMIN
                    </a>
                    <div class="relative flex shrink-0 items-center gap-2">
                        <button id="admin-private-reply-bell"
                                type="button"
                                @click="replyPopoverOpen = !replyPopoverOpen"
                                :aria-expanded="replyPopoverOpen.toString()"
                                aria-controls="admin-private-reply-popover"
                                class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700 shadow-sm transition hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700"
                                aria-label="管理人個別メッセージの返信を確認"
                                title="管理人個別メッセージの返信を確認">
                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17H9.143m9.286 0H5.57c1.286-1.286 1.715-3.214 1.715-5.143 0-2.947 2.111-5.357 4.715-5.357s4.714 2.41 4.714 5.357c0 1.929.429 3.857 1.715 5.143ZM13.714 19.143a1.714 1.714 0 0 1-3.428 0" />
                            </svg>
                            <span data-admin-reply-badge hidden class="absolute -right-1.5 -top-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-black leading-none text-white ring-2 ring-white"></span>
                        </button>
                        <section id="admin-private-reply-popover"
                                 data-admin-reply-popover
                                 x-show="replyPopoverOpen"
                                 x-cloak
                                 x-transition.origin.top.right
                                 @click.outside="replyPopoverOpen = false"
                                 role="dialog"
                                 aria-labelledby="admin-private-reply-heading"
                                 class="absolute right-0 top-12 z-50 w-[calc(100vw-2rem)] max-w-sm overflow-hidden rounded-lg border border-slate-200 bg-white text-left shadow-2xl">
                            <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
                                <div>
                                    <h2 id="admin-private-reply-heading" class="text-sm font-black text-slate-950">未対応の返信</h2>
                                    <p data-admin-reply-summary class="mt-0.5 text-[11px] font-bold text-slate-500">通知を確認中...</p>
                                </div>
                                <button type="button"
                                        @click="replyPopoverOpen = false"
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-xl text-slate-500 hover:bg-slate-200 hover:text-slate-900"
                                        aria-label="通知一覧を閉じる">
                                    ×
                                </button>
                            </div>
                            <div data-admin-reply-list class="max-h-[min(60vh,28rem)] overflow-y-auto p-2">
                                <p class="px-3 py-5 text-center text-sm font-bold text-slate-500">通知を確認中...</p>
                            </div>
                            <p data-admin-reply-feedback
                               hidden
                               aria-live="polite"
                               class="border-t border-slate-200 bg-slate-50 px-4 py-2 text-center text-xs font-bold text-slate-600"></p>
                            <a href="{{ route('admin.user-investigation') }}"
                               class="block border-t border-slate-200 px-4 py-3 text-center text-xs font-black text-slate-700 hover:bg-slate-50 hover:text-slate-950">
                                ユーザー調査ですべて確認
                            </a>
                        </section>
                        <form action="{{ route('admin.logout') }}" method="POST" data-submit-lock data-loading-text="ログアウト中...">
                            @csrf
                            <button type="submit" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700">
                                ログアウト
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            <div x-show="mobileNavOpen"
                 x-cloak
                 class="fixed inset-0 z-50 lg:hidden"
                 aria-modal="true"
                 role="dialog">
                <div x-show="mobileNavOpen"
                     x-transition.opacity
                     @click="mobileNavOpen = false"
                     class="absolute inset-0 bg-slate-950/55"></div>
                <aside x-show="mobileNavOpen"
                       x-transition:enter="transition ease-out duration-200"
                       x-transition:enter-start="-translate-x-full"
                       x-transition:enter-end="translate-x-0"
                       x-transition:leave="transition ease-in duration-150"
                       x-transition:leave-start="translate-x-0"
                       x-transition:leave-end="-translate-x-full"
                       class="absolute inset-y-0 left-0 flex w-[min(86vw,320px)] flex-col bg-slate-950 text-white shadow-2xl">
                    <div class="flex h-16 items-center justify-between border-b border-white/10 px-5">
                        <a href="{{ route('admin.dashboard') }}" @click="mobileNavOpen = false" class="font-black tracking-[0.16em]">
                            <span class="text-amber-300">VALZERIA</span> ADMIN
                        </a>
                        <button type="button"
                                @click="mobileNavOpen = false"
                                class="flex h-9 w-9 items-center justify-center rounded-md border border-white/10 bg-white/5 text-2xl font-light text-slate-200 active:scale-95"
                                aria-label="管理メニューを閉じる">
                            ×
                        </button>
                    </div>

                    <nav class="flex-1 overflow-y-auto px-4 py-4">
                        <div class="space-y-3">
                            <a href="{{ route($mailNavItem['route']) }}"
                               @click="mobileNavOpen = false"
                               class="group flex items-center gap-3 rounded-md border px-3 py-3 text-sm font-bold transition {{ $mailNavActive ? 'border-amber-300 bg-amber-300 text-slate-950 shadow-lg shadow-amber-950/20' : 'border-white/10 bg-white/[0.03] text-slate-200' }}">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-[11px] font-black {{ $mailNavActive ? 'bg-slate-950 text-amber-200' : 'bg-white/10 text-slate-200' }}">{{ $mailNavItem['abbr'] }}</span>
                                <span class="truncate">{{ $mailNavItem['label'] }}</span>
                            </a>
                            @foreach($navGroups as $group)
                                @php
                                    $groupActive = collect($group['items'])->contains(
                                        fn ($item) => request()->routeIs($item['active'] ?? $item['route'])
                                    );
                                @endphp
                                <section class="rounded-md border {{ $groupActive ? 'border-amber-300/40 bg-amber-300/10' : 'border-white/10 bg-white/[0.03]' }}">
                                    <button type="button"
                                            @click="mobileOpenGroup = mobileOpenGroup === @js($group['key']) ? '' : @js($group['key'])"
                                            class="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left">
                                        <span class="text-xs font-black tracking-[0.16em] {{ $groupActive ? 'text-amber-200' : 'text-slate-400' }}">{{ $group['label'] }}</span>
                                        <span class="text-xs font-black text-slate-500" x-text="mobileOpenGroup === @js($group['key']) ? '−' : '+'"></span>
                                    </button>
                                    <div x-show="mobileOpenGroup === @js($group['key'])" class="space-y-1 px-2 pb-2">
                                        @foreach($group['items'] as $item)
                                            @php $active = request()->routeIs($item['active'] ?? $item['route']); @endphp
                                            <a href="{{ route($item['route']) }}"
                                               @click="mobileNavOpen = false"
                                               class="group flex items-center gap-3 rounded-md px-2.5 py-2.5 text-sm font-bold transition {{ $active ? 'bg-amber-300 text-slate-950 shadow-lg shadow-amber-950/20' : 'text-slate-300' }}">
                                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-[11px] font-black {{ $active ? 'bg-slate-950 text-amber-200' : 'bg-white/10 text-slate-200' }}">{{ $item['abbr'] }}</span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate">{{ $item['label'] }}</span>
                                                    @if($item['route'] === 'admin.user-investigation')
                                                        <span data-admin-reply-senders hidden class="mt-0.5 block truncate text-[10px] font-bold leading-tight {{ $active ? 'text-slate-700' : 'text-rose-300' }}"></span>
                                                    @endif
                                                </span>
                                                @if($item['route'] === 'admin.user-investigation')
                                                    <span data-admin-reply-badge hidden class="ml-auto rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-black text-white"></span>
                                                @elseif($item['route'] === 'admin.character-icon-design.index' && $iconDesignUnreadCount > 0)
                                                    <span class="ml-auto rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-black text-white">{{ $iconDesignUnreadCount > 99 ? '99+' : $iconDesignUnreadCount }}</span>
                                                @endif
                                            </a>
                                        @endforeach
                                    </div>
                                </section>
                            @endforeach
                        </div>
                    </nav>

                    <div class="border-t border-white/10 px-4 py-4">
                        <a href="{{ route('top') }}"
                           @click="mobileNavOpen = false"
                           class="mb-3 flex items-center justify-center rounded-md border border-white/10 px-4 py-2.5 text-sm font-bold text-slate-300">
                            サイトトップへ
                        </a>
                        <form action="{{ route('admin.logout') }}" method="POST" data-submit-lock data-loading-text="ログアウト中...">
                            @csrf
                            <button type="submit" class="w-full rounded-md bg-white px-4 py-2.5 text-sm font-black text-slate-950 shadow">
                                ログアウト
                            </button>
                        </form>
                    </div>
                </aside>
            </div>

            <main class="min-h-screen bg-[radial-gradient(circle_at_top_right,_rgba(212,175,55,0.12),_transparent_34%),linear-gradient(180deg,_#f8fafc_0%,_#eef2f7_100%)]">
                {{ $slot }}
            </main>
        </div>
    </div>

    <div data-admin-reply-resolve-modal
         hidden
         class="fixed inset-0 z-[100] hidden items-center justify-center px-4 py-6"
         role="dialog"
         aria-modal="true"
         aria-labelledby="admin-reply-resolve-modal-title"
         aria-describedby="admin-reply-resolve-modal-description">
        <button type="button"
                data-admin-reply-resolve-modal-dismiss
                class="absolute inset-0 bg-slate-950/60 backdrop-blur-sm"
                aria-label="対応済み確認を閉じる"></button>
        <section class="relative w-full max-w-sm overflow-hidden rounded-xl border border-emerald-200 bg-white shadow-2xl">
            <div class="border-b border-emerald-100 bg-emerald-50 px-5 py-4">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white shadow-sm" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6" />
                        </svg>
                    </span>
                    <div>
                        <h2 id="admin-reply-resolve-modal-title" class="text-base font-black text-slate-950">返信を対応済みにしますか？</h2>
                        <p data-admin-reply-resolve-character class="mt-0.5 text-xs font-bold text-emerald-800"></p>
                    </div>
                </div>
            </div>
            <div class="px-5 py-4">
                <p id="admin-reply-resolve-modal-description" class="text-sm font-semibold leading-6 text-slate-700">
                    未対応の通知から外します。会話履歴は削除されません。
                </p>
                <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold leading-5 text-slate-600">
                    このあと冒険者から新しい返信が届いた場合は、再び通知されます。
                </div>
                <p data-admin-reply-resolve-modal-status
                   hidden
                   aria-live="polite"
                   class="mt-3 rounded-md px-3 py-2 text-xs font-bold"></p>
                <div class="mt-5 grid grid-cols-2 gap-3">
                    <button type="button"
                            data-admin-reply-resolve-modal-cancel
                            class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-black text-slate-700 transition hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60">
                        キャンセル
                    </button>
                    <button type="button"
                            data-admin-reply-resolve-modal-confirm
                            class="rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-black text-white shadow-sm transition hover:bg-emerald-700 disabled:cursor-wait disabled:opacity-60">
                        対応済みにする
                    </button>
                </div>
            </div>
        </section>
    </div>

    @livewireScripts
    <script>
        (() => {
            const restoreAdminScroll = () => {
                document.documentElement.style.removeProperty('overflow');
                document.documentElement.style.removeProperty('overflow-y');
                document.body.style.removeProperty('overflow');
                document.body.style.removeProperty('overflow-y');
            };

            restoreAdminScroll();
            window.addEventListener('pageshow', restoreAdminScroll);
            document.addEventListener('livewire:navigated', restoreAdminScroll);
        })();

        (() => {
            const mailBadgeUrl = @js(route('admin.contact-messages.badge-count'));
            const privateReplyStatusUrl = @js(route('admin.private-replies.status'));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const baseTitle = document.title;
            const baseIconHref = @js(asset('images/favicon.webp') . '?v=2');
            const mailPollIntervalMs = 5 * 60 * 1000;
            const privateReplyPollIntervalMs = 60 * 1000;
            let faviconLink = document.getElementById('admin-favicon');
            let objectUrl = null;
            let mailCount = 0;
            let privateReplyCount = 0;

            const ensureFaviconLink = () => {
                if (faviconLink) {
                    return faviconLink;
                }

                faviconLink = document.createElement('link');
                faviconLink.id = 'admin-favicon';
                faviconLink.rel = 'icon';
                faviconLink.href = baseIconHref;
                document.head.appendChild(faviconLink);

                return faviconLink;
            };

            const drawBadge = async (count) => {
                const link = ensureFaviconLink();
                const numericCount = Math.max(0, Number.parseInt(count, 10) || 0);

                if (objectUrl) {
                    URL.revokeObjectURL(objectUrl);
                    objectUrl = null;
                }

                if (numericCount <= 0) {
                    link.type = 'image/webp';
                    link.href = baseIconHref;
                    document.title = baseTitle;
                    return;
                }

                document.title = `(${numericCount}) ${baseTitle}`;

                const canvas = document.createElement('canvas');
                canvas.width = 64;
                canvas.height = 64;
                const ctx = canvas.getContext('2d');

                ctx.clearRect(0, 0, 64, 64);

                try {
                    const img = await new Promise((resolve, reject) => {
                        const image = new Image();
                        image.onload = () => resolve(image);
                        image.onerror = reject;
                        image.src = baseIconHref;
                    });
                    ctx.drawImage(img, 0, 0, 64, 64);
                } catch (e) {
                    ctx.fillStyle = '#0f172a';
                    ctx.fillRect(0, 0, 64, 64);
                    ctx.fillStyle = '#facc15';
                    ctx.font = 'bold 30px sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText('V', 32, 34);
                }

                ctx.beginPath();
                ctx.arc(46, 18, 17, 0, Math.PI * 2);
                ctx.fillStyle = '#dc2626';
                ctx.fill();
                ctx.lineWidth = 4;
                ctx.strokeStyle = '#ffffff';
                ctx.stroke();

                const label = numericCount > 99 ? '99+' : String(numericCount);
                ctx.fillStyle = '#ffffff';
                ctx.font = label.length >= 3 ? 'bold 13px sans-serif' : 'bold 18px sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(label, 46, 18);

                canvas.toBlob((blob) => {
                    if (!blob) {
                        return;
                    }
                    objectUrl = URL.createObjectURL(blob);
                    link.type = 'image/png';
                    link.href = objectUrl;
                }, 'image/png');
            };

            const refreshOverallBadge = async () => {
                await drawBadge(mailCount + privateReplyCount);
            };

            const showPrivateReplyFeedback = (message, isError = false) => {
                const feedback = document.querySelector('[data-admin-reply-feedback]');
                if (!feedback) {
                    return;
                }

                feedback.hidden = !message;
                feedback.textContent = message || '';
                feedback.classList.toggle('text-rose-700', isError);
                feedback.classList.toggle('text-emerald-700', !isError && Boolean(message));
                feedback.classList.toggle('text-slate-600', !message);
            };

            const updatePrivateReplyIndicators = (payload) => {
                privateReplyCount = Math.max(0, Number.parseInt(payload.pending_count, 10) || 0);
                const countLabel = privateReplyCount > 99 ? '99+' : String(privateReplyCount);
                const replies = Array.isArray(payload.replies) ? payload.replies : [];
                const senderNames = [...new Set(
                    replies.map((reply) => reply.character_name || '冒険者')
                )];
                const remainingSenderCount = Math.max(0, privateReplyCount - senderNames.length);
                const senderLabel = senderNames.length > 0
                    ? `${senderNames.join('・')}${remainingSenderCount > 0 ? ` ほか${remainingSenderCount}名` : ''}`
                    : '';

                document.querySelectorAll('[data-admin-reply-badge]').forEach((badge) => {
                    badge.hidden = privateReplyCount <= 0;
                    badge.textContent = privateReplyCount > 0 ? countLabel : '';
                });

                document.querySelectorAll('[data-admin-reply-senders]').forEach((senders) => {
                    senders.hidden = privateReplyCount <= 0 || senderLabel === '';
                    senders.textContent = senderLabel === '' ? '' : `返信: ${senderLabel}`;
                    senders.title = senderLabel;
                });

                const bell = document.getElementById('admin-private-reply-bell');
                if (!bell) {
                    return;
                }

                if (privateReplyCount <= 0) {
                    bell.setAttribute('aria-label', '管理人個別メッセージの返信を確認（未対応なし）');
                    bell.title = '管理人個別メッセージの返信を確認（未対応なし）';
                } else {
                    const latestReply = payload.latest_reply || {};
                    const characterName = latestReply.character_name || '冒険者';
                    const label = `${characterName}さんから返信があります（未対応${privateReplyCount}件）`;
                    bell.setAttribute('aria-label', label);
                    bell.title = label;
                }

                const summary = document.querySelector('[data-admin-reply-summary]');
                if (summary) {
                    summary.textContent = privateReplyCount > 0
                        ? `新しい順に3件まで表示（全${privateReplyCount}件）`
                        : '現在、未対応の返信はありません';
                }

                const list = document.querySelector('[data-admin-reply-list]');
                if (!list) {
                    return;
                }

                list.replaceChildren();

                if (replies.length === 0) {
                    const empty = document.createElement('p');
                    empty.className = 'px-3 py-5 text-center text-sm font-bold text-slate-500';
                    empty.textContent = '未対応の返信はありません';
                    list.appendChild(empty);
                    return;
                }

                replies.forEach((reply) => {
                    const item = document.createElement('article');
                    item.className = 'rounded-md border border-transparent transition hover:border-rose-100 hover:bg-rose-50';

                    const link = document.createElement('a');
                    link.href = reply.url || @js(route('admin.user-investigation'));
                    link.className = 'block rounded-t-md px-3 pb-2 pt-3 focus:bg-rose-50 focus:outline-none';

                    const header = document.createElement('div');
                    header.className = 'flex items-start justify-between gap-3';

                    const name = document.createElement('span');
                    name.className = 'min-w-0 truncate text-sm font-black text-slate-950';
                    name.textContent = reply.character_name || '冒険者';

                    const repliedAt = document.createElement('time');
                    repliedAt.className = 'shrink-0 text-[10px] font-bold text-slate-500';
                    repliedAt.textContent = reply.replied_at || '';

                    const message = document.createElement('p');
                    message.className = 'mt-1 line-clamp-2 break-words text-xs font-semibold leading-5 text-slate-600';
                    message.textContent = reply.message || '';

                    header.append(name, repliedAt);
                    link.append(header, message);

                    const actions = document.createElement('div');
                    actions.className = 'flex items-center justify-end px-3 pb-2';

                    const resolveButton = document.createElement('button');
                    resolveButton.type = 'button';
                    resolveButton.dataset.adminReplyResolve = '';
                    resolveButton.dataset.resolveUrl = reply.resolve_url || '';
                    resolveButton.dataset.characterName = reply.character_name || '冒険者';
                    resolveButton.className = 'rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-[11px] font-black text-slate-700 transition hover:border-emerald-400 hover:bg-emerald-50 hover:text-emerald-800 disabled:cursor-wait disabled:opacity-60';
                    resolveButton.textContent = '対応済みにする';
                    resolveButton.disabled = !reply.resolve_url;

                    actions.appendChild(resolveButton);
                    item.append(link, actions);
                    list.appendChild(item);
                });
            };

            const pollMailBadge = async () => {
                try {
                    const response = await fetch(mailBadgeUrl, {
                        method: 'GET',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (!response.ok) {
                        return;
                    }

                    const payload = await response.json();
                    mailCount = Math.max(0, Number.parseInt(payload.new_count, 10) || 0);
                    await refreshOverallBadge();
                } catch (e) {
                    // 管理画面の操作を妨げないため、ポーリング失敗は黙って次回へ回す。
                }
            };

            const pollPrivateReplyStatus = async () => {
                try {
                    const response = await fetch(privateReplyStatusUrl, {
                        method: 'GET',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (!response.ok) {
                        return;
                    }

                    const payload = await response.json();
                    updatePrivateReplyIndicators(payload);
                    await refreshOverallBadge();
                } catch (e) {
                    // 管理画面の操作を妨げないため、ポーリング失敗は黙って次回へ回す。
                }
            };

            const privateReplyList = document.querySelector('[data-admin-reply-list]');
            const privateReplyResolveModal = document.querySelector('[data-admin-reply-resolve-modal]');
            const privateReplyResolveCharacter = document.querySelector('[data-admin-reply-resolve-character]');
            const privateReplyResolveStatus = document.querySelector('[data-admin-reply-resolve-modal-status]');
            const privateReplyResolveCancel = document.querySelector('[data-admin-reply-resolve-modal-cancel]');
            const privateReplyResolveConfirm = document.querySelector('[data-admin-reply-resolve-modal-confirm]');
            let pendingPrivateReplyResolution = null;
            let privateReplyResolutionSubmitting = false;

            const showPrivateReplyResolveStatus = (message, isError = false) => {
                if (!privateReplyResolveStatus) {
                    return;
                }

                privateReplyResolveStatus.hidden = !message;
                privateReplyResolveStatus.textContent = message || '';
                privateReplyResolveStatus.classList.toggle('bg-rose-50', isError);
                privateReplyResolveStatus.classList.toggle('text-rose-700', isError);
                privateReplyResolveStatus.classList.toggle('bg-emerald-50', !isError && Boolean(message));
                privateReplyResolveStatus.classList.toggle('text-emerald-700', !isError && Boolean(message));
            };

            const closePrivateReplyResolveModal = (restoreFocus = true) => {
                if (!privateReplyResolveModal || privateReplyResolutionSubmitting) {
                    return;
                }

                const sourceButton = pendingPrivateReplyResolution?.sourceButton;
                privateReplyResolveModal.hidden = true;
                privateReplyResolveModal.classList.add('hidden');
                privateReplyResolveModal.classList.remove('flex');
                pendingPrivateReplyResolution = null;
                showPrivateReplyResolveStatus('');

                if (restoreFocus && sourceButton?.isConnected) {
                    sourceButton.focus();
                }
            };

            const openPrivateReplyResolveModal = (sourceButton) => {
                if (!privateReplyResolveModal) {
                    return;
                }

                pendingPrivateReplyResolution = {
                    sourceButton,
                    resolveUrl: sourceButton.dataset.resolveUrl || '',
                    characterName: sourceButton.dataset.characterName || '冒険者',
                };

                if (privateReplyResolveCharacter) {
                    privateReplyResolveCharacter.textContent = `${pendingPrivateReplyResolution.characterName}さんからの返信`;
                }

                showPrivateReplyResolveStatus('');
                privateReplyResolveModal.hidden = false;
                privateReplyResolveModal.classList.remove('hidden');
                privateReplyResolveModal.classList.add('flex');
                window.requestAnimationFrame(() => privateReplyResolveCancel?.focus());
            };

            privateReplyList?.addEventListener('click', (event) => {
                const resolveButton = event.target.closest('[data-admin-reply-resolve]');
                if (!resolveButton || !privateReplyList.contains(resolveButton)) {
                    return;
                }

                const resolveUrl = resolveButton.dataset.resolveUrl || '';
                if (!resolveUrl) {
                    return;
                }

                openPrivateReplyResolveModal(resolveButton);
            });

            document.querySelectorAll('[data-admin-reply-resolve-modal-dismiss], [data-admin-reply-resolve-modal-cancel]')
                .forEach((button) => button.addEventListener('click', () => closePrivateReplyResolveModal()));

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && privateReplyResolveModal && !privateReplyResolveModal.hidden) {
                    closePrivateReplyResolveModal();
                }
            });

            privateReplyResolveConfirm?.addEventListener('click', async () => {
                const resolution = pendingPrivateReplyResolution;
                if (!resolution?.resolveUrl || privateReplyResolutionSubmitting) {
                    return;
                }

                privateReplyResolutionSubmitting = true;
                resolution.sourceButton.disabled = true;
                resolution.sourceButton.textContent = '処理中...';
                privateReplyResolveCancel.disabled = true;
                privateReplyResolveConfirm.disabled = true;
                privateReplyResolveConfirm.textContent = '処理中...';
                showPrivateReplyResolveStatus('対応済みにしています...');

                try {
                    const response = await fetch(resolution.resolveUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        throw new Error(payload.message || '対応済みにできませんでした。');
                    }

                    await pollPrivateReplyStatus();
                    privateReplyResolutionSubmitting = false;
                    closePrivateReplyResolveModal(false);
                    showPrivateReplyFeedback(payload.message || '通知を対応済みにしました。');
                } catch (error) {
                    if (resolution.sourceButton.isConnected) {
                        resolution.sourceButton.disabled = false;
                        resolution.sourceButton.textContent = '対応済みにする';
                    }
                    showPrivateReplyResolveStatus(error.message || '対応済みにできませんでした。', true);
                    await pollPrivateReplyStatus();
                } finally {
                    privateReplyResolutionSubmitting = false;
                    privateReplyResolveCancel.disabled = false;
                    privateReplyResolveConfirm.disabled = false;
                    privateReplyResolveConfirm.textContent = '対応済みにする';
                }
            });

            pollMailBadge();
            pollPrivateReplyStatus();
            window.setInterval(pollMailBadge, mailPollIntervalMs);
            window.setInterval(pollPrivateReplyStatus, privateReplyPollIntervalMs);
            window.addEventListener('focus', () => {
                pollMailBadge();
                pollPrivateReplyStatus();
            });
        })();
    </script>
</body>
</html>
