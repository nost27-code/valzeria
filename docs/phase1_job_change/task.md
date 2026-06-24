# 転職・能力引き継ぎシステム タスクリスト

## 1. データベース・モデルの改修
- `[x]` `job_classes` テーブルに `job_key` と `is_active` を追加するマイグレーションの作成
- `[x]` `job_change_logs` テーブルのマイグレーション作成
- `[x]` `JobChangeLog` モデルの作成（`Character`とのリレーション定義）
- `[x]` `JobClass` モデルの修正（`$guarded` の設定や `job_key` の扱い）

## 2. Seederの作成と実行
- `[x]` `JobSeeder` を作成し、初期4職業（戦士、魔法使い、僧侶、盗賊）と成長率を登録
- `[x]` 既存キャラクターに初期職業（僧侶）を割り当てる処理（Seeder内など）
- `[x]` `DatabaseSeeder` に `JobSeeder` を登録し、マイグレーションとシードを実行

## 3. サービスクラスの実装
- `[x]` `LevelService` の改修（Lv100上限でのEXP取得停止、職業ベースの成長率によるステータス上昇）
- `[x]` `CharacterJobChangeService` の作成
    - `[x]` `canChangeJob`
    - `[x]` `calculateInheritedStats` （基礎能力のみを対象とした引き継ぎ計算）
    - `[x]` `previewJobChange`
    - `[x]` `changeJob` （トランザクション、履歴保存、公開ログを含む）

## 4. UI・コントローラの実装
- `[x]` `JobChangeController` の作成（`index`, `confirm`, `change`）
- `[x]` ルーティングの設定（`web.php`）
- `[x]` `resources/views/jobs/index.blade.php` の作成（転職所トップ）
- `[x]` `resources/views/jobs/confirm.blade.php` の作成（転職確認）
- `[x]` `resources/views/jobs/completed.blade.php` の作成（転職完了）
- `[x]` `resources/views/components/layouts/app.blade.php` （またはサイドバー）に「転職回数」や「次の目標」メッセージを追加

## 5. 動作確認
- `[x]` 手動テスト（Lv100未満での制御、Lv100以上での転職実行、予測値と実際のステータスの合致など）
