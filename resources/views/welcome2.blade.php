<!DOCTYPE html>
<html lang="ja">
<head>
    {{-- PWA --}}
    <link rel="manifest" href="{{ asset('manifest.json') }}?v=3">
    <meta name="theme-color" content="#070d1a">
    <link rel="apple-touch-icon" href="{{ asset('images/icon-192x192.png') }}?v=3">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js?v=3').then(r => {}).catch(e => {});
            });
        }
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ヴァルゼリアの冒険者 - 探索・育成・ランキングで進むブラウザファンタジーRPG</title>
    <link rel="icon" href="{{ asset('images/favicon.webp') }}?v=2" type="image/webp">
    @include('partials.ogp', ['ogUrl' => 'https://valzeria.com/'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;700;900&family=Shippori+Mincho+B1:wght@700;800&display=swap" rel="stylesheet">
    {{-- PWAインストールバナー等のTailwindユーティリティクラスを有効化するために必要 --}}
    @vite(['resources/css/app.css'])
    <style>
        :root {
            --navy-deep: #070d1a;
            --navy: #0a1628;
            --navy-card: #101c33;
            --navy-line: #21304d;
            --gold: #d4af37;
            --gold-soft: #e7c96a;
            --gold-pale: #f0dc9c;
            --purple: #392a63;
            --ink: #e9e4d6;
            --ink-sub: #aab4ca;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Noto Sans JP','Hiragino Kaku Gothic ProN',sans-serif;
            background: var(--navy-deep);
            color: var(--ink);
            line-height: 1.7;
            -webkit-font-smoothing: antialiased;
        }
        img { max-width: 100%; display: block; }
        a { text-decoration: none; color: inherit; }
        .serif { font-family: 'Shippori Mincho B1','Noto Serif JP',serif; }
        .wrap { max-width: 1080px; margin: 0 auto; padding: 0 20px; }

        /* ===== 共通セクション見出し ===== */
        .sec { padding: 72px 0; position: relative; }
        .sec-kicker {
            display: flex; align-items: center; justify-content: center; gap: 14px;
            font-size: 12px; font-weight: 900; letter-spacing: .3em;
            color: var(--gold); text-transform: uppercase; margin-bottom: 14px;
        }
        .sec-kicker::before, .sec-kicker::after {
            content: ''; display: block; width: 48px; height: 1px;
            background: linear-gradient(90deg, transparent, var(--gold));
        }
        .sec-kicker::after { background: linear-gradient(90deg, var(--gold), transparent); }
        .sec-title {
            text-align: center; font-size: clamp(24px, 4.6vw, 34px); font-weight: 800;
            color: var(--ink); letter-spacing: .06em; margin-bottom: 14px;
        }
        .sec-lead { text-align: center; font-size: 15px; color: var(--ink-sub); max-width: 640px; margin: 0 auto 44px; }

        /* ===== βバナー ===== */
        .beta-bar {
            background: linear-gradient(90deg, #131f38 0%, #0a1628 50%, #131f38 100%);
            border-bottom: 1px solid rgba(212,175,55,.35);
            padding: 9px 16px; text-align: center;
            font-size: 12px; font-weight: 700; color: var(--gold-soft); letter-spacing: .06em;
        }
        .beta-bar b {
            display: inline-block; background: rgba(212,175,55,.16); border: 1px solid rgba(212,175,55,.45);
            border-radius: 4px; padding: 1px 8px; margin-right: 8px; font-size: 11px; letter-spacing: .12em;
        }

        /* ===== HERO ===== */
        .hero {
            position: relative; overflow: hidden; text-align: center;
            padding: clamp(64px, 11vw, 120px) 20px clamp(56px, 9vw, 96px);
            background:
                linear-gradient(180deg, rgba(7,13,26,.82) 0%, rgba(10,22,40,.62) 45%, rgba(7,13,26,.96) 100%),
                url('{{ asset('images/top/top_bg1.webp') }}') center 22% / cover no-repeat;
            border-bottom: 1px solid rgba(212,175,55,.3);
        }
        .hero::before, .hero::after {
            content: ''; position: absolute; pointer-events: none;
        }
        .hero::before { /* 上部の紫のオーラ */
            inset: -30% -10% auto; height: 70%;
            background: radial-gradient(ellipse at 50% 0%, rgba(109,91,208,.22), transparent 65%);
        }
        .hero::after { /* 下部の金の残光 */
            inset: auto -10% -40%; height: 70%;
            background: radial-gradient(ellipse at 50% 100%, rgba(212,175,55,.14), transparent 60%);
        }
        .hero > * { position: relative; z-index: 1; }
        .hero-kicker {
            display: inline-flex; align-items: center; gap: 12px;
            font-size: 12px; font-weight: 900; letter-spacing: .34em; color: var(--gold-pale);
            margin-bottom: 26px; text-transform: uppercase;
        }
        .hero-kicker::before, .hero-kicker::after { content: '◆'; font-size: 8px; color: var(--gold); }
        .hero-logo {
            width: min(460px, 86vw); margin: 0 auto 8px;
            filter: drop-shadow(0 6px 30px rgba(212,175,55,.35)) drop-shadow(0 2px 8px rgba(0,0,0,.6));
        }
        .hero-sub {
            font-size: clamp(15px, 2.6vw, 19px); font-weight: 800; color: var(--gold-pale);
            letter-spacing: .08em; margin: 18px 0 20px;
        }
        .hero-copy { font-size: clamp(14px, 2.2vw, 16px); color: var(--ink-sub); font-weight: 700; }
        .hero-copy strong { color: var(--ink); }
        .hero-cta { display: flex; flex-direction: column; align-items: center; gap: 12px; margin-top: 34px; }
        .hero-register-note { font-size: 12.5px; color: var(--ink-sub); margin: -2px 0 2px; }
        .hero-register-note a { color: var(--gold-soft); font-weight: 800; border-bottom: 1px solid rgba(212,175,55,.5); }
        .hero-register-note a:hover { color: var(--gold-pale); }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            border-radius: 12px; font-weight: 900; letter-spacing: .05em; cursor: pointer;
            transition: transform .15s ease, box-shadow .2s ease, filter .2s ease;
            font-family: inherit; border: none;
        }
        .btn:active { transform: scale(.97); }
        .btn-primary {
            width: min(360px, 100%); padding: 17px 28px; font-size: 17px; color: #1a1204;
            background: linear-gradient(180deg, #f0dc9c 0%, #d4af37 45%, #b8860b 100%);
            border: 1px solid #f5e6ae;
            box-shadow: 0 4px 24px rgba(212,175,55,.45), inset 0 1px 0 rgba(255,255,255,.5);
            text-shadow: 0 1px 0 rgba(255,255,255,.35);
        }
        .btn-primary:hover { box-shadow: 0 6px 34px rgba(212,175,55,.65), inset 0 1px 0 rgba(255,255,255,.5); filter: brightness(1.05); }
        .btn-secondary {
            width: min(360px, 100%); padding: 14px 28px; font-size: 15px; color: var(--gold-pale);
            background: rgba(16,28,51,.82); border: 1.5px solid rgba(212,175,55,.55);
            box-shadow: 0 2px 14px rgba(0,0,0,.4);
        }
        .btn-secondary:hover { background: rgba(26,40,68,.92); border-color: var(--gold); box-shadow: 0 2px 18px rgba(212,175,55,.25); }
        .btn-ghost { padding: 10px 20px; font-size: 13px; color: var(--ink-sub); font-weight: 700; }
        .btn-ghost:hover { color: var(--gold-pale); }
        .btn-ghost::after { content: '▼'; font-size: 9px; margin-left: 4px; }

        /* ===== 実績ストリップ ===== */
        .stats {
            background: linear-gradient(180deg, #0c1830, #0a1628);
            border-bottom: 1px solid var(--navy-line);
            padding: 20px 16px;
        }
        .stats-inner {
            max-width: 720px; margin: 0 auto;
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; text-align: center;
        }
        .stat-num { font-size: clamp(20px, 4vw, 28px); font-weight: 800; color: var(--gold-soft); letter-spacing: .04em; }
        .stat-num small { font-size: 12px; color: var(--ink-sub); font-weight: 700; margin-left: 2px; }
        .stat-label { font-size: 11px; font-weight: 700; color: var(--ink-sub); letter-spacing: .1em; }

        /* ===== 街にいる冒険者 ===== */
        .online-band { background: var(--navy); border-bottom: 1px solid var(--navy-line); padding: 18px 16px 22px; }
        .online-inner { max-width: 720px; margin: 0 auto; }
        .online-title {
            display: flex; align-items: center; justify-content: center; gap: 7px;
            font-size: 12px; font-weight: 800; letter-spacing: .1em; color: var(--ink-sub); margin-bottom: 12px;
        }
        .online-dot { width: 8px; height: 8px; border-radius: 50%; background: #4ade80; box-shadow: 0 0 0 3px rgba(74,222,128,.2); flex-shrink: 0; }
        .online-dot.is-empty { background: #64748b; box-shadow: none; }
        .online-chips { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; }
        .online-chip {
            font-size: 12.5px; font-weight: 700; color: var(--ink);
            background: rgba(212,175,55,.08); border: 1px solid rgba(212,175,55,.28);
            border-radius: 999px; padding: 5px 14px;
        }
        .online-empty { text-align: center; font-size: 12.5px; color: #7e89a3; }

        /* ===== 特徴カード ===== */
        .features-grid { display: grid; grid-template-columns: 1fr; gap: 16px; }
        @media (min-width: 640px) { .features-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 960px) {
            .features-grid { grid-template-columns: repeat(6, 1fr); }
            .feature-card { grid-column: span 2; }
            .feature-card:nth-child(4) { grid-column: 2 / span 2; }
            .feature-card:nth-child(5) { grid-column: 4 / span 2; }
        }
        .feature-card {
            position: relative; overflow: hidden;
            background: linear-gradient(160deg, #131f38 0%, var(--navy-card) 60%, #0d1730 100%);
            border: 1px solid var(--navy-line); border-radius: 14px; padding: 26px 22px;
            transition: transform .2s ease, border-color .2s ease, box-shadow .25s ease;
        }
        .feature-card::before { /* 上辺の金ライン */
            content: ''; position: absolute; top: 0; left: 12%; right: 12%; height: 1px;
            background: linear-gradient(90deg, transparent, rgba(212,175,55,.7), transparent);
        }
        .feature-card:hover {
            transform: translateY(-3px); border-color: rgba(212,175,55,.45);
            box-shadow: 0 10px 34px rgba(0,0,0,.5), 0 0 22px rgba(212,175,55,.12);
        }
        .feature-icon {
            width: 56px; height: 56px; border-radius: 13px; margin-bottom: 16px;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(160deg, rgba(212,175,55,.2), rgba(212,175,55,.05));
            border: 1px solid rgba(212,175,55,.4); color: var(--gold-soft);
        }
        .feature-icon svg { width: 26px; height: 26px; }
        .feature-icon img { width: 46px; height: 46px; object-fit: contain; filter: drop-shadow(0 2px 6px rgba(0,0,0,.5)); }
        .feature-title { font-size: 17px; font-weight: 800; color: var(--gold-pale); letter-spacing: .06em; margin-bottom: 8px; }
        .feature-text { font-size: 13.5px; color: var(--ink-sub); font-weight: 500; }

        /* ===== 冒険の流れ ===== */
        .loop-band { background: linear-gradient(180deg, var(--navy) 0%, #0d1226 100%); border-top: 1px solid var(--navy-line); border-bottom: 1px solid var(--navy-line); }
        .loop-grid { display: grid; grid-template-columns: 1fr; gap: 0; max-width: 520px; margin: 0 auto; }
        .loop-step { display: flex; gap: 16px; align-items: flex-start; position: relative; padding-bottom: 26px; }
        .loop-step:last-child { padding-bottom: 0; }
        .loop-step::before { /* 縦ライン */
            content: ''; position: absolute; left: 25px; top: 54px; bottom: 2px; width: 2px;
            background: linear-gradient(180deg, rgba(212,175,55,.5), rgba(212,175,55,.12));
        }
        .loop-step:last-child::before { display: none; }
        .loop-no {
            flex-shrink: 0; width: 52px; height: 52px; border-radius: 50%; position: relative;
            display: flex; align-items: center; justify-content: center;
            background: radial-gradient(circle at 35% 30%, #1c2c4e, #0d1730);
            border: 1.5px solid var(--gold);
            box-shadow: 0 0 16px rgba(212,175,55,.28);
        }
        .loop-no img { width: 34px; height: 34px; object-fit: contain; filter: drop-shadow(0 2px 4px rgba(0,0,0,.55)); }
        .loop-no i {
            position: absolute; top: -6px; left: -6px; font-style: normal;
            width: 20px; height: 20px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: var(--gold); color: #1a1204; font-weight: 900; font-size: 11px;
            box-shadow: 0 1px 4px rgba(0,0,0,.6);
        }
        .loop-body b { display: block; font-size: 16px; color: var(--ink); letter-spacing: .05em; }
        .loop-body span { font-size: 13px; color: var(--ink-sub); }
        @media (min-width: 960px) {
            .loop-grid { max-width: none; grid-template-columns: repeat(6, 1fr); gap: 8px; }
            .loop-step { flex-direction: column; align-items: center; text-align: center; padding-bottom: 0; gap: 12px; }
            .loop-step::before { left: calc(50% + 34px); right: auto; top: 25px; bottom: auto; width: calc(100% - 68px); height: 2px;
                background: linear-gradient(90deg, rgba(212,175,55,.5), rgba(212,175,55,.12)); }
        }

        /* ===== 世界観（都市） ===== */
        .world-note { text-align: center; font-size: 13px; color: var(--ink-sub); margin-bottom: 30px; }
        .world-note em { font-style: normal; color: var(--gold-soft); font-weight: 800; }
        .city-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
        @media (min-width: 640px) { .city-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 960px) { .city-grid { grid-template-columns: repeat(5, 1fr); } }
        .city-card {
            position: relative; border-radius: 12px; overflow: hidden; aspect-ratio: 4 / 5;
            border: 1px solid var(--navy-line);
            transition: transform .25s ease, border-color .25s ease, box-shadow .25s ease;
        }
        .city-card:hover { transform: translateY(-3px); border-color: rgba(212,175,55,.55); box-shadow: 0 8px 26px rgba(0,0,0,.55), 0 0 18px rgba(212,175,55,.12); }
        .city-card img { width: 100%; height: 100%; object-fit: cover; transition: transform .4s ease; }
        .city-card:hover img { transform: scale(1.06); }
        .city-card .shade {
            position: absolute; inset: 0;
            background: linear-gradient(180deg, rgba(7,13,26,.05) 35%, rgba(7,13,26,.86) 100%);
        }
        .city-card .name {
            position: absolute; left: 0; right: 0; bottom: 0; padding: 10px 10px 12px; text-align: center;
        }
        .city-card .name b { display: block; font-size: 13.5px; font-weight: 800; color: var(--ink); letter-spacing: .04em; text-shadow: 0 1px 4px rgba(0,0,0,.8); }
        .city-card .name span { font-size: 10px; color: var(--gold-soft); font-weight: 700; letter-spacing: .12em; }
        .city-badge {
            position: absolute; top: 8px; left: 8px; z-index: 1;
            font-size: 10px; font-weight: 900; letter-spacing: .08em; padding: 3px 9px; border-radius: 5px;
        }
        .city-badge.start { background: rgba(212,175,55,.92); color: #1a1204; }
        .city-badge.goal { background: rgba(57,42,99,.94); color: #d9ccff; border: 1px solid rgba(150,120,255,.5); }

        /* ===== スクリーンショット ===== */
        .ss-band { background: linear-gradient(180deg, #0d1226, var(--navy) 60%); border-top: 1px solid var(--navy-line); border-bottom: 1px solid var(--navy-line); }
        .ss-scroll {
            display: flex; gap: 16px; overflow-x: auto; padding: 6px 20px 22px;
            scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch;
            max-width: 1080px; margin: 0 auto;
        }
        .ss-scroll::-webkit-scrollbar { height: 6px; }
        .ss-scroll::-webkit-scrollbar-thumb { background: rgba(212,175,55,.4); border-radius: 3px; }
        .ss-scroll::-webkit-scrollbar-track { background: rgba(255,255,255,.05); border-radius: 3px; }
        .ss-frame {
            flex: 0 0 auto; width: min(255px, 72vw); scroll-snap-align: center;
            border-radius: 16px; overflow: hidden;
            border: 1.5px solid rgba(212,175,55,.45);
            background: #05080f; padding: 6px;
            box-shadow: 0 8px 30px rgba(0,0,0,.6), 0 0 18px rgba(212,175,55,.1);
        }
        .ss-frame img { border-radius: 11px; width: 100%; }
        .ss-hint { text-align: center; font-size: 11px; color: var(--ink-sub); letter-spacing: .1em; margin-top: 4px; }

        /* ===== β版セクション ===== */
        .beta-card {
            max-width: 720px; margin: 0 auto; text-align: center;
            background: linear-gradient(160deg, #131f38, #0d1730);
            border: 1px solid rgba(212,175,55,.35); border-radius: 16px; padding: 34px 24px;
            box-shadow: 0 0 34px rgba(212,175,55,.07);
        }
        .beta-card .tag {
            display: inline-block; font-size: 11px; font-weight: 900; letter-spacing: .18em; color: var(--gold);
            border: 1px solid rgba(212,175,55,.5); border-radius: 5px; padding: 3px 12px; margin-bottom: 14px;
        }
        .beta-card p { font-size: 14px; color: var(--ink-sub); }
        .beta-card p strong { color: var(--ink); }
        .beta-card .small { font-size: 12px; margin-top: 12px; color: #7e89a3; }
        .valmon-parade { display: flex; justify-content: center; align-items: flex-end; gap: 6px; margin-bottom: 18px; }
        .valmon-parade img {
            width: 44px; height: 44px; object-fit: contain;
            filter: drop-shadow(0 3px 6px rgba(0,0,0,.5));
            transition: transform .2s ease;
        }
        .valmon-parade img:nth-child(odd) { transform: translateY(3px) rotate(-4deg); }
        .valmon-parade img:nth-child(even) { transform: translateY(-2px) rotate(4deg); }
        .valmon-parade img:hover { transform: translateY(-6px) scale(1.15) rotate(0deg); }

        /* ===== 最終CTA ===== */
        .final-cta {
            position: relative; overflow: hidden; text-align: center;
            padding: clamp(72px, 11vw, 120px) 20px;
            background:
                linear-gradient(180deg, rgba(7,13,26,.9) 0%, rgba(10,22,40,.72) 50%, rgba(7,13,26,.94) 100%),
                url('{{ asset('images/map/map01.webp') }}') center 30% / cover no-repeat;
            border-top: 1px solid rgba(212,175,55,.3);
        }
        .final-cta::after {
            content: ''; position: absolute; inset: auto -10% -50%; height: 80%; pointer-events: none;
            background: radial-gradient(ellipse at 50% 100%, rgba(212,175,55,.16), transparent 60%);
        }
        .final-cta > * { position: relative; z-index: 1; }
        .final-title {
            font-size: clamp(22px, 4.8vw, 34px); font-weight: 800; color: var(--ink);
            letter-spacing: .08em; margin-bottom: 12px; line-height: 1.6;
            text-shadow: 0 2px 14px rgba(0,0,0,.8);
        }
        .final-title em { font-style: normal; color: var(--gold-pale); }
        .final-sub { font-size: 14px; color: var(--ink-sub); margin-bottom: 34px; }
        .final-buttons { display: flex; flex-direction: column; align-items: center; gap: 12px; }
        .guest-form { width: min(360px, 100%); }
        .btn-guest {
            width: 100%; padding: 13px 20px; font-size: 14px; color: var(--ink-sub);
            background: rgba(10,18,34,.7); border: 1px dashed rgba(170,180,202,.45); border-radius: 12px;
        }
        .btn-guest:hover { color: var(--ink); border-color: var(--gold-soft); background: rgba(16,28,51,.85); }
        .register-note { font-size: 12.5px; color: var(--ink-sub); margin-top: 16px; }
        .register-note a { color: var(--gold-soft); font-weight: 800; border-bottom: 1px solid rgba(212,175,55,.5); }
        .register-note a:hover { color: var(--gold-pale); }

        /* ===== フッター ===== */
        footer {
            background: #05080f; border-top: 1px solid var(--navy-line);
            padding: 36px 20px 46px; text-align: center;
        }
        .foot-links { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px 22px; margin-bottom: 18px; }
        .foot-links a { font-size: 12.5px; color: #7e89a3; font-weight: 700; }
        .foot-links a:hover { color: var(--gold-soft); }
        .foot-copy { font-size: 11px; color: #4c566d; letter-spacing: .08em; }
        .foot-emblem { width: 44px; margin: 0 auto 16px; opacity: .8; }
    </style>
</head>
<body>
    <x-pwa-install-banner />

    {{-- βバナー --}}
    <div class="beta-bar"><b>β版</b>現在ベータ版として公開中。冒険世界は今も拡張を続けています。</div>

    {{-- ① HERO --}}
    <header class="hero">
        <div class="hero-kicker">Browser Fantasy RPG</div>
        <img class="hero-logo" src="{{ asset('images/title_logo.webp') }}" alt="ヴァルゼリアの冒険者" width="460" height="230">
        <p class="hero-sub serif">探索・育成・ランキングで進む、ブラウザファンタジーRPG</p>
        <p class="hero-copy">
            <strong>王都アークレアから始まる冒険。</strong><br>
            探索で素材を集め、職業を極め、装備を鍛え、まだ見ぬ深層へ進め。
        </p>
        <div class="hero-cta">
            <a class="btn btn-primary" href="{{ route('auth.google') }}"
               data-top-event="{{ $registrationOpen ? 'google_start_click' : 'google_login_click' }}"
               data-top-label="{{ $registrationOpen ? '冒険を始める' : 'Googleでログイン' }}">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor"><path d="M12.24 10.285V14.4h6.806c-.275 1.765-2.056 5.174-6.806 5.174-4.095 0-7.439-3.389-7.439-7.574s3.345-7.574 7.439-7.574c2.33 0 3.891.989 4.785 1.849l3.254-3.138C18.189 1.186 15.479 0 12.24 0c-6.635 0-12 5.365-12 12s5.365 12 12 12c6.926 0 11.52-4.869 11.52-11.726 0-.788-.085-1.39-.189-1.989H12.24z"/></svg>
                {{ $registrationOpen ? '冒険を始める' : 'Googleでログイン' }}
            </a>
            <a class="btn btn-secondary" href="{{ route('auth.email.login') }}" data-top-event="email_login_click" data-top-label="ログイン">ログイン</a>
            @if($registrationOpen)
                <p class="hero-register-note">メールアドレスで登録する場合は <a href="{{ route('auth.email.register') }}" data-top-event="email_register_click" data-top-label="メールで新規登録">こちら</a></p>
            @endif
            <a class="btn btn-ghost" href="#features">ゲーム紹介を見る</a>
        </div>
    </header>

    {{-- 実績ストリップ --}}
    <div class="stats">
        <div class="stats-inner">
            <div>
                <div class="stat-num">{{ number_format($totalCharacters) }}<small>人</small></div>
                <div class="stat-label">登録冒険者</div>
            </div>
            <div>
                <div class="stat-num">{{ number_format($onlineCount) }}<small>人</small></div>
                <div class="stat-label">いま街にいる冒険者</div>
            </div>
            <div>
                <div class="stat-num">10<small>都市</small></div>
                <div class="stat-label">広がる冒険世界</div>
            </div>
        </div>
    </div>

    {{-- いま街にいる冒険者 --}}
    <div class="online-band">
        <div class="online-inner">
            @if($onlineCharacters->isNotEmpty())
                <div class="online-title"><span class="online-dot"></span>いま街にいる冒険者（{{ $onlineCharacters->count() }}人）</div>
                <div class="online-chips">
                    @foreach($onlineCharacters as $onlineCharacter)
                        <span class="online-chip">{{ $onlineCharacter->name }}</span>
                    @endforeach
                </div>
            @else
                <div class="online-title"><span class="online-dot is-empty"></span>いま街にいる冒険者</div>
                <p class="online-empty">現在、街に冒険者はいません。最初の冒険者になりましょう！</p>
            @endif
        </div>
    </div>

    {{-- ② ゲーム特徴 --}}
    <section class="sec" id="features">
        <div class="wrap">
            <div class="sec-kicker">Features</div>
            <h2 class="sec-title serif">冒険を支える、5つの柱</h2>
            <p class="sec-lead">シンプルな操作で、奥深い育成。ブラウザだけで、じっくり強くなる。</p>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="{{ asset('images/icon/icon_004.webp') }}" alt="" loading="lazy">
                    </div>
                    <div class="feature-title">探索</div>
                    <p class="feature-text">ダンジョンを探索して、素材・装備・経験値を手に入れる。探索を重ねるほど奥へ進め、まだ誰も見ぬ深層への入口が姿を現す。</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="{{ asset('images/icon/icon_042.webp') }}" alt="" loading="lazy">
                    </div>
                    <div class="feature-title">職業育成</div>
                    <p class="feature-text">剣士・盗賊・魔法使い・僧侶から始まり、職業を極めるほど中級職・上級職、そして伝説職への道が開く。転職を重ねて、自分だけのビルドを。</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="{{ asset('images/icon/icon_006.webp') }}" alt="" loading="lazy">
                    </div>
                    <div class="feature-title">装備強化</div>
                    <p class="feature-text">集めた素材で武器・防具を鍛え、進化分岐でさらに強く。どの系統に育てるかは、君の戦い方しだい。</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="{{ asset('images/valmon/valmon01.webp') }}" alt="" loading="lazy">
                    </div>
                    <div class="feature-title">ヴァルモン</div>
                    <p class="feature-text">冒険に寄り添う小さな相棒。ともに探索し、卵から新しい仲間を育てよう。頼れる相棒は、旅の景色を少し変えてくれる。</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="{{ asset('images/icon/icon_010.webp') }}" alt="" loading="lazy">
                    </div>
                    <div class="feature-title">ランキング・チャンプ戦</div>
                    <p class="feature-text">育てた冒険者でチャンプに挑み、ランキングを駆け上がれ。他の冒険者とゆるく競い合える、もうひとつの戦場。</p>
                </div>
            </div>
        </div>
    </section>

    {{-- ③ 冒険の流れ --}}
    <section class="sec loop-band">
        <div class="wrap">
            <div class="sec-kicker">Game Cycle</div>
            <h2 class="sec-title serif">冒険の流れ</h2>
            <p class="sec-lead">このループを回すたび、冒険者は確実に強くなる。</p>
            <div class="loop-grid">
                <div class="loop-step"><div class="loop-no"><i>1</i><img src="{{ asset('images/icon/icon_004.webp') }}" alt="" loading="lazy"></div><div class="loop-body"><b>探索する</b><span>ダンジョンへ足を踏み入れる</span></div></div>
                <div class="loop-step"><div class="loop-no"><i>2</i><img src="{{ asset('images/icon/icon_025.webp') }}" alt="" loading="lazy"></div><div class="loop-body"><b>素材を集める</b><span>戦利品と経験値を持ち帰る</span></div></div>
                <div class="loop-step"><div class="loop-no"><i>3</i><img src="{{ asset('images/icon/icon_006.webp') }}" alt="" loading="lazy"></div><div class="loop-body"><b>装備を鍛える</b><span>武器・防具を強化・進化</span></div></div>
                <div class="loop-step"><div class="loop-no"><i>4</i><img src="{{ asset('images/icon/icon_042.webp') }}" alt="" loading="lazy"></div><div class="loop-body"><b>職業を育てる</b><span>職業を極め、上位職へ転職</span></div></div>
                <div class="loop-step"><div class="loop-no"><i>5</i><img src="{{ asset('images/icon/icon_005.webp') }}" alt="" loading="lazy"></div><div class="loop-body"><b>強敵に挑む</b><span>都市のボスへ挑戦する</span></div></div>
                <div class="loop-step"><div class="loop-no"><i>6</i><img src="{{ asset('images/icon/icon_003.webp') }}" alt="" loading="lazy"></div><div class="loop-body"><b>新たな街へ</b><span>冒険の舞台が広がっていく</span></div></div>
            </div>
        </div>
    </section>

    {{-- ④ 世界観 --}}
    <section class="sec">
        <div class="wrap">
            <div class="sec-kicker">World of Valzeria</div>
            <h2 class="sec-title serif">世界は、街をめぐるほど広がる</h2>
            <p class="world-note">はじまりの王都から、雪原、砂漠、天空へ——。都市のボスを打ち破るたび、<em>次の街への道</em>が開かれる。</p>
            @php
                $worldCities = [
                    ['name' => '王都アークレア',   'en' => 'ARCLEA',     'img' => 'city01', 'badge' => 'start'],
                    ['name' => '港町マリネス',     'en' => 'MARINES',    'img' => 'city02', 'badge' => null],
                    ['name' => '精霊の森エルフィア', 'en' => 'ELFIA',      'img' => 'city03', 'badge' => null],
                    ['name' => '鍛冶街グランベルグ', 'en' => 'GRANBERG',   'img' => 'city04', 'badge' => null],
                    ['name' => '雪原の町フロストリア', 'en' => 'FROSTRIA',  'img' => 'city05', 'badge' => null],
                    ['name' => '砂漠の宿場サンドラ', 'en' => 'SANDRA',     'img' => 'city06', 'badge' => null],
                    ['name' => '魔導学院ルミナス',  'en' => 'LUMINAS',    'img' => 'city07', 'badge' => null],
                    ['name' => '死霊街ネクロム',   'en' => 'NECROM',     'img' => 'city08', 'badge' => null],
                    ['name' => '天空神殿セレスティア', 'en' => 'CELESTIA', 'img' => 'city09', 'badge' => null],
                    ['name' => '魔王城ヴァルゼリア', 'en' => 'VALZERIA',   'img' => 'city10', 'badge' => 'goal'],
                ];
            @endphp
            <div class="city-grid">
                @foreach($worldCities as $city)
                    <div class="city-card">
                        @if($city['badge'] === 'start')
                            <span class="city-badge start">はじまりの街</span>
                        @elseif($city['badge'] === 'goal')
                            <span class="city-badge goal">最深の地</span>
                        @endif
                        <img src="{{ asset('images/cities/' . $city['img'] . '.webp') }}" alt="{{ $city['name'] }}" loading="lazy">
                        <div class="shade"></div>
                        <div class="name">
                            <span>{{ $city['en'] }}</span>
                            <b>{{ $city['name'] }}</b>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ⑤ スクリーンショット --}}
    <section class="sec ss-band">
        <div class="sec-kicker">Screenshots</div>
        <h2 class="sec-title serif">実際のゲーム画面</h2>
        <p class="sec-lead">インストール不要。ブラウザを開けば、そこはもうヴァルゼリア。</p>
        <div class="ss-scroll">
            @foreach(['screemshot01', 'screemshot02', 'screemshot03', 'screemshot04', 'screemshot05', 'screemshot06'] as $ss)
                <div class="ss-frame">
                    <img src="{{ asset('images/top/' . $ss . '.webp') }}" alt="ゲーム画面スクリーンショット" loading="lazy">
                </div>
            @endforeach
        </div>
        <p class="ss-hint">◀ スワイプで他の画面もチェック ▶</p>
    </section>

    {{-- ⑥ β版・開発中 --}}
    <section class="sec">
        <div class="wrap">
            <div class="beta-card">
                <div class="valmon-parade">
                    @foreach(['valmon02', 'valmon05', 'valmon01', 'valmon08', 'valmon11'] as $vm)
                        <img src="{{ asset('images/valmon/' . $vm . '.webp') }}" alt="" loading="lazy">
                    @endforeach
                </div>
                <span class="tag">NOW IN BETA</span>
                <p>
                    <strong>現在ベータ版として公開中です。</strong><br>
                    不具合修正・バランス調整・新コンテンツ追加を行いながら、冒険世界を拡張しています。<br>
                    新しい職業、新しい深層、新しい相棒——世界は、これからもっと広がります。
                </p>
                <p class="small">※ ベータ期間中は、ゲームバランス調整により一部仕様や数値が変更される場合があります。</p>
            </div>
        </div>
    </section>

    {{-- ⑦ 最終CTA --}}
    <section class="final-cta">
        <h2 class="final-title serif">君の冒険は、<em>王都アークレア</em>から始まる。</h2>
        <p class="final-sub">登録無料・インストール不要。ブラウザだけで、今すぐ冒険へ。</p>
        <div class="final-buttons">
            <a class="btn btn-primary" href="{{ route('auth.google') }}"
               data-top-event="{{ $registrationOpen ? 'google_start_click' : 'google_login_click' }}"
               data-top-label="{{ $registrationOpen ? '今すぐ冒険を始める' : 'Googleでログイン' }}">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor"><path d="M12.24 10.285V14.4h6.806c-.275 1.765-2.056 5.174-6.806 5.174-4.095 0-7.439-3.389-7.439-7.574s3.345-7.574 7.439-7.574c2.33 0 3.891.989 4.785 1.849l3.254-3.138C18.189 1.186 15.479 0 12.24 0c-6.635 0-12 5.365-12 12s5.365 12 12 12c6.926 0 11.52-4.869 11.52-11.726 0-.788-.085-1.39-.189-1.989H12.24z"/></svg>
                {{ $registrationOpen ? '今すぐ冒険を始める' : 'Googleでログイン' }}
            </a>
            <a class="btn btn-secondary" href="{{ route('auth.email.login') }}" data-top-event="email_login_click" data-top-label="ログインする">ログインする</a>
            @if($registrationOpen)
                <form class="guest-form" action="{{ route('auth.guest') }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-guest" data-top-event="guest_start_click" data-top-label="アカウント連携せずにプレイ">アカウント連携せずにプレイしてみる</button>
                </form>
                <p class="register-note">メールアドレスで登録する場合は <a href="{{ route('auth.email.register') }}" data-top-event="email_register_click" data-top-label="メールで新規登録">こちら</a></p>
            @else
                <p class="register-note">現在、新規登録の受付を停止しています。既存アカウントでログインできます。</p>
            @endif
        </div>
    </section>

    {{-- フッター --}}
    <footer>
        <img class="foot-emblem" src="{{ asset('images/emblem.webp') }}" alt="" loading="lazy">
        <div class="foot-links">
            <a href="{{ route('help') }}">ヘルプ</a>
            <a href="{{ route('legal.terms') }}">利用規約</a>
            <a href="{{ route('legal.privacy') }}">プライバシーポリシー</a>
            <a href="{{ route('legal.contact') }}">お問い合わせ</a>
            <a href="{{ route('legal.operator') }}">特定商取引法に基づく表記</a>
            <a href="https://x.com/valzeria_dev" target="_blank" rel="noopener noreferrer">X（開発者）</a>
        </div>
        <p class="foot-copy">&copy; {{ date('Y') }} ヴァルゼリアの冒険者</p>
    </footer>

    <script>
        window.valzeriaTopAnalytics = {
            visitUuid: @js($topPageVisit?->visit_uuid ?? null),
            endpoint: @js(route('top.analytics.event')),
            csrf: @js(csrf_token()),
            startedAt: Date.now(),
            sentDwell: false,
        };

        function sendTopAnalytics(eventName, metadata) {
            var analytics = window.valzeriaTopAnalytics || {};
            if (!analytics.visitUuid || !analytics.endpoint) return;

            var payload = new FormData();
            payload.append('_token', analytics.csrf || '');
            payload.append('visit_uuid', analytics.visitUuid);
            payload.append('event_name', eventName);

            Object.keys(metadata || {}).forEach(function(key) {
                if (metadata[key] !== undefined && metadata[key] !== null) {
                    payload.append(key, metadata[key]);
                }
            });

            if (navigator.sendBeacon) {
                navigator.sendBeacon(analytics.endpoint, payload);
                return;
            }

            fetch(analytics.endpoint, {
                method: 'POST',
                body: payload,
                keepalive: true,
                credentials: 'same-origin'
            }).catch(function() {});
        }

        document.addEventListener('click', function(event) {
            var target = event.target.closest('[data-top-event]');
            if (!target) return;

            sendTopAnalytics(target.dataset.topEvent, {
                label: target.dataset.topLabel || target.textContent.trim(),
                href: target.href || ''
            });
        });

        function sendTopDwell() {
            var analytics = window.valzeriaTopAnalytics || {};
            if (analytics.sentDwell) return;
            analytics.sentDwell = true;
            var duration = Math.max(1, Math.round((Date.now() - analytics.startedAt) / 1000));
            sendTopAnalytics('page_dwell', { duration_seconds: duration });
        }

        window.addEventListener('pagehide', sendTopDwell);
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') sendTopDwell();
        });
    </script>

</body>
</html>
