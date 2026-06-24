# 敵マスター・ボス戦・エリア解放 補完実装タスク

- [x] `app/Services/CharacterGoalService.php` の新規作成
- [x] `app/Livewire/MainScreen.php` の改修
  - `CharacterGoalService` を組み込んで「次の目標」を動的に生成
  - エリアごとの `boss_defeated` の状態に応じて `boss_action` の有無を切り替える
- [x] `app/Http/Controllers/BattleController.php` の改修
  - `boss()` メソッドや探索結果から「今回がボス戦だったか(`isBoss`)」「次エリアが解放されたか」をViewへ渡す
- [x] `resources/views/battle/result.blade.php` の改修
  - ボス戦に勝利した場合、「〇〇が解放されました！」のメッセージとリンクを追加する
- [x] ブラウザでの動作検証
  - 目標文言が正しく表示されるか
  - ボスに挑んで撃破した際に結果画面が正常に表示されるか
  - 撃破後にボス挑戦ボタンが消え、新しいエリアが解放されるか
