# レアドロップ機能の実装計画

敵撃破時に一定確率で装備がドロップする機能を実装します。フェーズ1の仕様に則り、素材や鑑定などの要素を含まないシンプルなドロップ機能として実装します。

## Proposed Changes

### Database & Migrations
- `enemy_drops` テーブルを作成するマイグレーションの追加。
- `battle_logs` テーブルに `dropped_item_id`、`dropped_character_item_id` カラムを追加するマイグレーションの追加。

### Models
- `EnemyDrop` モデルの新規作成。
  - `enemy_id`, `item_id`, `drop_rate`, `min_character_level`, `max_character_level`, `is_active`
- `Enemy` モデル、`Item` モデルへのリレーション追加。

### Seeders
- `ItemSeeder.php` にドロップ専用装備（12種類）を追加。
  - `is_shop_item => false`
- `EnemyDropSeeder.php` を新規作成し、各エリアの敵とボスのドロップ設定を登録。

### Services
- `DropService.php` を新規作成。
  - 敵ごとのドロップ抽選処理、及び `character_items` への装備追加処理の実装。
- `ExplorationService.php` の更新。
  - 戦闘勝利時に `DropService` を呼び出し、結果を `BattleLogService` に渡す。
  - 獲得アイテムが `rare` 以上の場合は `PublicLogService` にログを記録。

### Views
- `battle/result.blade.php` の更新。
  - ドロップがあった場合の獲得表示の追加（レアリティ、ステータス補正値など）。
  - 「装備変更へ」などの導線追加。

## Verification Plan

### Automated Tests
- 各種マイグレーションとシードが正常に実行できるか `php artisan migrate:refresh --seed` を実行して確認。

### Manual Verification
- はじまりの草原、小鬼の森、古びた洞窟で探索を行い、以下を確認。
  - 敗北時にはドロップしないこと。
  - 勝利時に一定確率でドロップ装備を獲得できること。
  - ボス撃破時には確定で設定された装備がドロップすること。
  - 戦闘結果画面にドロップアイテムが正しく表示されること。
  - レア以上のドロップ時に公開ログ（全体チャット）に通知が流れること。
  - 獲得した装備が「装備変更」画面に表示され、装備できること。
