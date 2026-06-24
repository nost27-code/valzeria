<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>アイテム購入通知</title>
</head>
<body>
    <p>以下のアイテムが購入されました。</p>
    
    <ul>
        <li><strong>購入アイテム:</strong> {{ $pack['name'] }}</li>
        <li><strong>購入金額:</strong> {{ number_format($order->price_jpy) }}円</li>
        <li><strong>獲得輝石:</strong> {{ number_format($order->kiseki_amount) }} 個</li>
        <li><strong>キャラクター名:</strong> {{ $character->name }} (ID: {{ $character->id }})</li>
        <li><strong>注文番号:</strong> {{ $order->id }}</li>
        <li><strong>StripeセッションID:</strong> {{ $order->session_id }}</li>
        <li><strong>決済日時:</strong> {{ $order->fulfilled_at->format('Y-m-d H:i:s') }}</li>
    </ul>

</body>
</html>
