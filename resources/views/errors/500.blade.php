@php
    $isAdmin = auth()->check() && auth()->user()?->role === 'admin';
@endphp
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ひと休み | ヴァルゼリアの冒険者</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 20px; background: #020617; color: #e2e8f0; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        main { position: relative; width: min(100%, 960px); min-height: min(78vh, 640px); overflow: hidden; display: flex; align-items: center; border: 1px solid rgba(251,191,36,.35); border-radius: 20px; background: #0f172a; box-shadow: 0 28px 90px rgba(0,0,0,.55); }
        .scene { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: center; }
        .veil { position: absolute; inset: 0; background: linear-gradient(90deg, rgba(2,6,23,.98) 0%, rgba(2,6,23,.91) 36%, rgba(2,6,23,.48) 63%, rgba(2,6,23,.06) 100%); }
        .content { position: relative; z-index: 1; width: min(100%, 530px); padding: 38px; }
        .eyebrow { margin: 0 0 12px; color: #fcd34d; font-size: 12px; font-weight: 800; letter-spacing: .24em; }
        h1 { margin: 0; color: #f8fafc; font-size: clamp(28px, 5vw, 42px); line-height: 1.2; }
        p { margin: 18px 0 0; color: #cbd5e1; font-size: 16px; font-weight: 600; line-height: 1.9; }
        .note { color: #94a3b8; font-size: 13px; }
        a { display: inline-flex; min-height: 46px; align-items: center; justify-content: center; margin-top: 24px; padding: 12px 20px; border-radius: 9px; background: #fbbf24; color: #172033; font-size: 14px; font-weight: 800; text-decoration: none; box-shadow: 0 8px 22px rgba(251,191,36,.2); }
        .admin { margin-top: 26px; padding: 14px; border: 1px solid rgba(254,202,202,.35); border-radius: 9px; background: rgba(69,10,10,.82); color: #fee2e2; font-size: 12px; overflow-wrap: anywhere; }
        .admin code { display: block; margin-top: 8px; white-space: pre-wrap; }
        @media (max-width: 640px) { body { padding: 0; } main { min-height: 100vh; border: 0; border-radius: 0; } .scene { object-position: 66% center; } .veil { background: linear-gradient(180deg, rgba(2,6,23,.82), rgba(2,6,23,.95) 68%, rgba(2,6,23,.86)); } .content { align-self: end; padding: 28px 24px 42px; } }
    </style>
</head>
<body>
    <main>
        <img class="scene" src="{{ asset('images/errors/error-resting-room.webp') }}" alt="ランタンに照らされた冒険者の休憩部屋">
        <div class="veil"></div>
        <section class="content">
            <p class="eyebrow">ADVENTURE PAUSED</p>
            <h1>冒険の支度中です</h1>
            <p>酒場の灯りが落ち着くまで、少しだけお待ちください。<br>まもなく、また旅に戻れます。</p>
            <p class="note">何度も続く場合は、時間をおいてからお試しください。</p>
            <a href="{{ url('/') }}">街へ戻る</a>

            @if ($isAdmin && isset($exception))
                <section class="admin">
                    <strong>運営向けエラー情報</strong>
                    <code>{{ $exception->getMessage() }}
{{ $exception->getFile() }}:{{ $exception->getLine() }}</code>
                </section>
            @endif
        </section>
    </main>
</body>
</html>
