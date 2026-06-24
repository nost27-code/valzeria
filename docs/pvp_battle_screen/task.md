# 闘技場バトル画面のフルスクリーン化 タスクリスト

- [x] 1. ルーティングの追加
  - [x] `routes/web.php` に `/battle/pvp/{targetCharacter}` (POST) を追加
- [x] 2. コントローラーの改修
  - [x] `BattleController@pvp` の実装（PvPBattleServiceの呼び出しと結果データの用意）
- [x] 3. PvP用バトル結果Viewの作成
  - [x] `resources/views/battle/pvp_result.blade.php` の作成
  - [x] PvP専用の画面デザイン（背景・順位変動の表示など）の実装
- [x] 4. 闘技場画面（Livewire）の修正
  - [x] `colosseum-screen.blade.php` の「挑む」ボタンを form(POST) に変更
  - [x] `ColosseumScreen.php` から `$battleResult` 関連のコードを削除
