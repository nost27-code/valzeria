# レアドロップ機能実装 タスクリスト

- `[x]` `enemy_drops` テーブルのマイグレーション作成
- `[x]` `battle_logs` へのカラム追加（dropped_item_id, dropped_character_item_id）のマイグレーション作成
- `[x]` `EnemyDrop` モデル作成、リレーション定義
- `[x]` `Enemy`、`Item` モデルへのリレーション追加
- `[x]` `ItemSeeder` にドロップ専用装備を追加
- `[x]` `EnemyDropSeeder` を作成してドロップ率などを設定
- `[x]` `DatabaseSeeder` に `EnemyDropSeeder` を追加
- `[x]` `DropService` を作成
- `[x]` `ExplorationService` 内でドロップ抽選・付与とログ記録を実行するよう改修
- `[x]` `resources/views/battle/result.blade.php` にドロップ結果を表示するよう改修
- `[x]` マイグレーションとシードを実行し、動作確認
