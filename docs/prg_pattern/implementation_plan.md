# 戦闘処理への PRG（Post/Redirect/Get）パターン導入計画

## 目的
戦闘結果画面でブラウザの「更新（F5・リロード）」ボタンを押した際に、再度戦闘処理（ドロップ抽選や経験値付与）が実行されてしまう二重処理バグを防止します。

## 背景と課題
現在、戦闘処理は POST リクエストを受け取った後、そのまま View（結果画面）を返しています。この状態では、ユーザーが結果画面でリロードを行うとブラウザが「フォームの再送信」を行い、もう一度 POST リクエストが走るため、同一の戦闘が複数回実行されてしまう可能性があります（今回の「親分の腕輪が2つ手に入った」原因）。

これを防ぐため、状態の更新（DBの書き換え）を伴う POST 処理のあとは必ず GET リクエストへリダイレクトさせる **PRGパターン** を導入します。

## Proposed Changes

### `app/Http/Controllers/BattleController.php`
- `explore` メソッド
  - 既存の `return view(...)` を `return redirect()->route('battle.result')->with('battleData', $data)` に変更。
- `boss` メソッド
  - 同様に `return redirect()->route('battle.result')->with('battleData', $data)` に変更。
- `pvp` メソッド
  - 同様に `return redirect()->route('battle.pvp_result')->with('battleData', $data)` に変更。
- 新規 `showResult` メソッドの追加
  - セッションから `battleData` を取得して `view('battle.result')` を返す。データが無い場合はホームへリダイレクト。
- 新規 `showPvpResult` メソッドの追加
  - セッションから `battleData` を取得して `view('battle.pvp_result')` を返す。データが無い場合はホームへリダイレクト。

### `routes/web.php`
- 結果表示用の GET ルートを新規追加。
  - `Route::get('/battle/result', [BattleController::class, 'showResult'])->name('battle.result');`
  - `Route::get('/battle/pvp-result', [BattleController::class, 'showPvpResult'])->name('battle.pvp_result');`

### プロジェクト知識への追記
- 継続的改善（Lessons Learned）として、「状態更新を伴うリクエストでは必ず PRG パターンを用いること」を追記し、今後の類似バグを防止。
