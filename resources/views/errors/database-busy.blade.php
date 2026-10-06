<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>混み合っています | ヴァルゼリアの冒険者</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #07111f; color: #f8fafc; font-family: system-ui, sans-serif; }
        main { box-sizing: border-box; width: min(100%, 560px); padding: 24px; line-height: 1.8; overflow-wrap: anywhere; }
        h1 { font-size: 1.25rem; }
        a { display: inline-flex; align-items: center; min-height: 44px; color: #f6d998; }
    </style>
</head>
<body>
    <main>
        <h1>ただいま混み合っています</h1>
        <p>{{ \App\Support\DatabaseContention::MESSAGE }}</p>
        <a href="/home">ホームを開く</a>
    </main>
</body>
</html>
