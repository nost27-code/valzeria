# 闘技場バトル画面のフルスクリーン化実装計画

ユーザーからの要望に基づき、闘技場でのバトル時も通常の敵（PvE）とのバトルと同じように、専用のバトル結果画面に切り替わるように変更します。また、闘技場らしい雰囲気（背景や装飾）を加えます。

## 目的
* 闘技場のバトル画面を「モーダル内表示」から「独立したフルスクリーンの画面」に変更する。
* PvPバトル特有の「順位の変動」をバトル結果画面に表示する。
* 闘技場らしさを演出する背景・デザインを適用する。

## 実装ステップ

### 1. ルーティングの追加
`routes/web.php` に以下のルートを追加します。
```php
Route::post('/battle/pvp/{targetCharacter}', [BattleController::class, 'pvp'])->name('battle.pvp');
```

### 2. コントローラーの改修
`app/Http/Controllers/BattleController.php` に `pvp` メソッドを追加します。
* `PvPBattleService` を用いてバトルを実行。
* バトル結果（配列）と、対戦相手のキャラクター情報、そして自分の順位変動情報を取得して View に渡します。

### 3. PvP用バトル結果Viewの作成
`resources/views/battle/pvp_result.blade.php` を新規作成します。
* 通常の `result.blade.php` のレイアウト（ステータス比較、VS表示、ログテキスト）を踏襲します。
* 背景画像に闘技場らしいもの（例: 城の背景 `bg-castle.webp` をベースにするか、少し色味を調整したもの）を使用します。
* レベルアップやアイテムドロップの代わりに、「勝利！ 順位が 10位 → 9位 に上がりました！」などの順位変動結果を専用の演出枠で表示します。
* 「もう一度探索する」ボタンの代わりに「闘技場へ戻る」ボタンを設置します。

### 4. 闘技場画面（Livewire）の修正
`resources/views/livewire/colosseum-screen.blade.php` と `app/Livewire/ColosseumScreen.php` を修正します。
* `wire:click="challenge(...)"` を廃止し、通常の `<form action="{{ route('battle.pvp', $target->character->id) }}" method="POST">` による画面遷移に変更します。
* Livewireコンポーネント内の `$battleResult` プロパティやモーダル表示のコードを削除してスッキリさせます。

## 【確認事項】
* 背景画像については、現在プロジェクト内にある `images/bg-castle.webp` などを使うか、あるいは単なる闘技場風の背景色（暖色系やレンガ色）を使う想定で進めてよろしいでしょうか？（後から画像を差し替えることも可能です）

上記の内容で実装を進めてよろしいでしょうか？ ご確認をお願いいたします。
