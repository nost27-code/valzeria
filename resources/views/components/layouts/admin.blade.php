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
    <title>Valzeria - 管理者ダッシュボード</title>
    @include('partials.ogp', ['ogTitle' => 'Valzeria - 管理者ダッシュボード'])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
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
                    ['route' => 'admin.growth-analytics', 'label' => '運営分析', 'abbr' => 'G'],
                    ['route' => 'admin.top-analytics', 'label' => 'TOPアクセス解析', 'abbr' => 'V'],
                ],
            ],
            [
                'key' => 'operations',
                'label' => '運用',
                'items' => [
                    ['route' => 'admin.players', 'label' => 'プレイヤー一覧', 'abbr' => 'P'],
                    ['route' => 'admin.user-investigation', 'label' => 'ユーザー調査', 'abbr' => 'U'],
                    ['route' => 'admin.player-controls', 'label' => 'プレイヤー調整', 'abbr' => 'C'],
                    ['route' => 'admin.action-logs', 'label' => '行動ログ', 'abbr' => 'L'],
                    ['route' => 'admin.chat', 'label' => '管理人チャット', 'abbr' => 'Q'],
                    ['route' => 'admin.private-chat-logs', 'label' => '個人チャットログ', 'abbr' => 'D'],
                    ['route' => 'admin.kiseki-purchases', 'label' => '課金監査', 'abbr' => 'K'],
                    ['route' => 'admin.reward-settings', 'label' => '運営・報酬設定', 'abbr' => 'R'],
                    ['route' => 'admin.top-updates', 'label' => 'TOP更新情報', 'abbr' => 'N'],
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
                    ['route' => 'admin.testers', 'label' => 'テストキャラ管理', 'abbr' => 'T'],
                ],
            ],
        ];

        $activeGroupKey = collect($navGroups)
            ->first(fn ($group) => collect($group['items'])->contains(fn ($item) => request()->routeIs($item['route'])))['key'] ?? 'overview';
        $mailNavActive = request()->routeIs($mailNavItem['route']);
    @endphp

    <div class="min-h-screen lg:flex">
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
                                $groupActive = collect($group['items'])->contains(fn ($item) => request()->routeIs($item['route']));
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
                                        @php $active = request()->routeIs($item['route']); @endphp
                                        <a href="{{ route($item['route']) }}" class="group flex items-center gap-3 rounded-md px-2.5 py-2.5 text-sm font-bold transition {{ $active ? 'bg-amber-300 text-slate-950 shadow-lg shadow-amber-950/20' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-[11px] font-black {{ $active ? 'bg-slate-950 text-amber-200' : 'bg-white/10 text-slate-200 group-hover:bg-white/15' }}">{{ $item['abbr'] }}</span>
                                            <span class="truncate">{{ $item['label'] }}</span>
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
                    <form action="{{ route('admin.logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="w-full rounded-md bg-white px-4 py-2.5 text-sm font-black text-slate-950 shadow hover:bg-amber-100">
                            ログアウト
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <div class="min-w-0 flex-1 lg:pl-72">
            <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 shadow-sm backdrop-blur lg:hidden">
                <div class="flex h-16 items-center justify-between px-4">
                    <a href="{{ route('admin.dashboard') }}" class="font-black tracking-[0.18em] text-slate-950">
                        <span class="text-amber-500">VALZERIA</span> ADMIN
                    </a>
                    <form action="{{ route('admin.logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700">
                            ログアウト
                        </button>
                    </form>
                </div>
                <nav class="flex gap-2 overflow-x-auto px-4 pb-3">
                    <a href="{{ route($mailNavItem['route']) }}" class="shrink-0 rounded-md px-3 py-2 text-xs font-bold {{ $mailNavActive ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-700' }}">
                        {{ $mailNavItem['label'] }}
                    </a>
                    @foreach($navGroups as $group)
                        @foreach($group['items'] as $item)
                            @php $active = request()->routeIs($item['route']); @endphp
                            <a href="{{ route($item['route']) }}" class="shrink-0 rounded-md px-3 py-2 text-xs font-bold {{ $active ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-700' }}">
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    @endforeach
                </nav>
            </header>

            <main class="min-h-screen bg-[radial-gradient(circle_at_top_right,_rgba(212,175,55,0.12),_transparent_34%),linear-gradient(180deg,_#f8fafc_0%,_#eef2f7_100%)]">
                {{ $slot }}
            </main>
        </div>
    </div>

    @livewireScripts
    <script>
        (() => {
            const badgeUrl = @js(route('admin.contact-messages.badge-count'));
            const baseTitle = document.title;
            const baseIconHref = @js(asset('images/favicon.webp') . '?v=2');
            const pollIntervalMs = 5 * 60 * 1000;
            let faviconLink = document.getElementById('admin-favicon');
            let objectUrl = null;

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

            const pollMailBadge = async () => {
                try {
                    const response = await fetch(badgeUrl, {
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
                    await drawBadge(payload.new_count || 0);
                } catch (e) {
                    // 管理画面の操作を妨げないため、ポーリング失敗は黙って次回へ回す。
                }
            };

            pollMailBadge();
            window.setInterval(pollMailBadge, pollIntervalMs);
            window.addEventListener('focus', pollMailBadge);
        })();
    </script>
</body>
</html>
