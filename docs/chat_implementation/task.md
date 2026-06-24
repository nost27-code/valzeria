# チャット（全体・個人）実装タスク

- [ ] 1. データベース改修
  - [ ] `public_logs` テーブルに `receiver_id` (nullable) を追加するマイグレーション作成・実行
  - [ ] `PublicLog` モデルに `$fillable` とリレーション（`receiver`）を追加
- [ ] 2. ログ取得ロジックの改修 (`PublicLogService`)
  - [ ] `addLog` メソッドに `receiver_id` 引数を追加
  - [ ] `getRecentLogs` 取得時に、プライベートチャットの場合は「自分宛」または「自分が送信したもの」のみにフィルタリングするクエリを追加
- [ ] 3. Livewireコンポーネント改修 (`ChatLog.php`)
  - [ ] プロパティ追加 (`$message`, `$chatTarget`, `$receiverId`)
  - [ ] 宛先キャラクターの一覧（全キャラクター等）を取得するプロパティを用意
  - [ ] `sendMessage` メソッドの実装（全体チャットと個人チャットの振り分け）
- [ ] 4. Bladeビュー改修 (`chat-log.blade.php`)
  - [ ] 「全体/個人」の宛先セレクトボックスの追加 (`wire:model="chatTarget"`)
  - [ ] 個人宛の場合に出現する「キャラクター選択」セレクトボックスの追加
  - [ ] 入力フォーム・送信ボタンのバインディング
  - [ ] チャットタブに「個人（手紙）」を追加
  - [ ] `wire:poll="3s"` での自動更新対応
