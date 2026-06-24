<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ご購入ありがとうございます</title>
</head>
<body>
    <p>この度はヴァルゼリアで「{{ $pack['name'] }}」をご購入いただき、誠にありがとうございます。</p>
    
    <p>以下の内容で購入が完了し、キャラクターに反映されました。</p>

    <ul>
        <li><strong>購入アイテム:</strong> {{ $pack['name'] }}</li>
        <li><strong>購入金額:</strong> {{ number_format($order->price_jpy) }}円</li>
        <li><strong>獲得輝石:</strong> {{ number_format($order->kiseki_amount) }} 個</li>
        <li><strong>注文番号:</strong> {{ $order->id }}</li>
        <li><strong>決済日時:</strong> {{ $order->fulfilled_at->format('Y-m-d H:i:s') }}</li>
    </ul>

    <p>引き続き、ヴァルゼリアをお楽しみください。</p>

    <hr>
    <p><small>※このメールは送信専用アドレスから送信されています。ご不明な点がございましたら、公式サイトのお問い合わせよりご連絡ください。</small></p>
</body>
</html>
