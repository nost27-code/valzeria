<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>メンテナンス中 | ヴァルゼリアの冒険者</title>
    <style>
        html,
        body {
            margin: 0;
            min-height: 100%;
            background: #07111f;
            color: #f8fafc;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 16px;
            box-sizing: border-box;
        }

        .maintenance-image {
            width: min(100%, 640px);
            height: auto;
            display: block;
            border-radius: 8px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .45);
        }
    </style>
</head>
<body>
    <img
        class="maintenance-image"
        src="/images/mente/image.webp"
        alt="ヴァルゼリアの冒険者 データ更新中です、ほんの少しお待ちください。"
    >
    <script>
        (function () {
            var seconds = 5;
            var timer = setInterval(function () {
                seconds -= 1;
                if (seconds <= 0) {
                    clearInterval(timer);
                    location.reload();
                }
            }, 1000);
        })();
    </script>
</body>
</html>
