<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>@yield('title') | Valzeria dot閲覧画面</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #172c3b; background: #f1f5f8; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        a { color: #126078; text-underline-offset: 3px; }
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible { outline: 3px solid #d79621; outline-offset: 3px; }
        header { background: #173443; color: #fff; padding: 20px max(16px, calc((100vw - 1080px) / 2)); }
        header p { margin: 0; font-size: 14px; color: #c8e0e9; }
        .brand { font-size: 19px; font-weight: 700; margin-bottom: 8px; }
        .badge { display: inline-block; border: 1px solid #91c9ce; border-radius: 6px; padding: 2px 8px; font-size: 12px; margin-left: 8px; }
        main { max-width: 1080px; margin: 24px auto; padding: 0 16px 40px; }
        h1 { font-size: 24px; margin: 0 0 10px; }
        h2 { font-size: 18px; margin: 0 0 8px; }
        p { line-height: 1.7; }
        .muted { color: #526979; font-size: 14px; }
        .panel, .card { background: #fff; border: 1px solid #d4e0e7; border-radius: 10px; padding: 20px; margin-bottom: 14px; min-width: 0; }
        .notice { background: #e4f1f1; border-left: 4px solid #2c7a7e; padding: 12px 16px; font-size: 14px; margin: 16px 0; }
        nav { display: flex; flex-wrap: wrap; gap: 8px; margin: 20px 0; }
        nav a, .button { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 10px 14px; border: 1px solid #c0d3dd; border-radius: 6px; background: #fff; text-decoration: none; font: inherit; cursor: pointer; }
        nav a[aria-current="page"], .primary { background: #185c6b; color: #fff; border-color: #185c6b; }
        .toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); align-items: end; gap: 12px; }
        label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; }
        input, select { width: 100%; min-height: 44px; border: 1px solid #a6bdca; border-radius: 6px; padding: 10px; background: #fff; color: inherit; font: inherit; }
        .filters .button { width: 100%; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; }
        .value { font-size: 32px; font-weight: 700; margin: 10px 0; }
        .meta { display: flex; flex-wrap: wrap; gap: 8px 16px; color: #526979; font-size: 13px; margin-bottom: 12px; }
        .body { white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.8; }
        .error { color: #9c2525; background: #fff0ef; padding: 12px; border-radius: 6px; }
        .login { max-width: 480px; margin: 48px auto; }
        .login .button { width: 100%; margin-top: 18px; }
        .pager { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-top: 20px; }
        .card h2, .meta span { overflow-wrap: anywhere; }
        @media (max-width: 480px) { main { margin-top: 18px; } .panel, .card { padding: 16px; } h1 { font-size: 22px; } .filters { grid-template-columns: 1fr; } .grid { grid-template-columns: 1fr 1fr; } .grid .card { padding: 12px; } .grid h2 { font-size: 15px; } nav a { flex: 1 1 40%; font-size: 14px; } }
    </style>
</head>
<body>
    <header>
        <div class="brand">ヴァルゼリアの冒険者 <span class="badge">閲覧専用</span></div>
        <p>dotの運用確認画面</p>
    </header>
    <main>@yield('content')</main>
</body>
</html>
