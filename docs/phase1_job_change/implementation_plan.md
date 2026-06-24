# 転職・能力引き継ぎ 実装計画

## Goal

冒険都市ヴァルゼリアにおける「転職システム」と「能力引き継ぎ仕様」を実装する。
レベル100に到達したキャラクターが、ステータスのインフレを抑えつつ成長を実感できるバランスで転職できるようにする。

## User Review Required

> [!WARNING]
> カラム名の調整について
> 現在の `characters` テーブルや `job_classes` テーブルでは、ステータスの名称が `attack_base`, `defense_base`, `speed_base` などの名称になっています（仕様書では `str`, `def`, `agi` と表記）。
> 本実装では、DB設計の大幅な変更を避けるため、**データベース上のカラム名は既存の `attack_base` や `attack_growth_min` 等のまま維持**し、UI表示や計算ロジック内で仕様書の `STR`, `DEF`, `AGI` と読み替えて実装する方針としたいですが、よろしいでしょうか？

> [!NOTE]
> レベルアップ上限について
> 仕様書に「Lv100到達後もEXPは増えない」とあります。これは `LevelService` 側で「レベルが100以上の場合はEXPを加算しない」という処理を追加する方針で進めます。

## Proposed Changes

### 1. データベース・モデルの改修

#### [MODIFY] `c:\Users\yuta\tool\tool\ffa\database\migrations\2026_06_05_012201_create_job_classes_table.php`
- 現在の `job_classes` に `job_key` カラムを追加（既にマイグレーションが存在するため、今回は新しいマイグレーション `add_job_key_to_job_classes_table` を作成して対応します）。
- `is_active` カラムを追加。

#### [NEW] `c:\Users\yuta\tool\tool\ffa\database\migrations\YYYY_MM_DD_HHMMSS_create_job_change_logs_table.php`
- `job_change_logs` テーブルを作成し、転職履歴（変更前後の能力やレベル、職業IDなど）を保存できるようにします。

#### [NEW] `c:\Users\yuta\tool\tool\ffa\app\Models\JobChangeLog.php`
- `job_change_logs` に対応するモデルを作成し、`Character` とのリレーションを定義します。

### 2. Seeder の作成

#### [NEW] `c:\Users\yuta\tool\tool\ffa\database\seeders\JobSeeder.php`
- 戦士、魔法使い、僧侶、盗賊の4職のデータを `job_classes` に登録します。
- 成長率は仕様書に基づく値（例: 戦士のHPは3〜5、攻撃力は2〜4等）を設定します。

#### [MODIFY] `c:\Users\yuta\tool\tool\ffa\database\seeders\DatabaseSeeder.php`
- `JobSeeder` を登録し、初期データの投入が行えるようにします。

### 3. サービスクラスの実装

#### [MODIFY] `c:\Users\yuta\tool\tool\ffa\app\Services\LevelService.php`
- 現在のハードコードされた僧侶ベースの成長値を削除し、`$character->jobClass`（現在の職業）に設定された `growth_min` / `growth_max` を使用して成長するように変更します。
- レベル100以上の場合、EXPの加算を行わない（あるいはレベルアップを打ち止める）処理を追加します。

#### [NEW] `c:\Users\yuta\tool\tool\ffa\app\Services\CharacterJobChangeService.php`
- `canChangeJob`, `calculateInheritedStats`, `previewJobChange`, `changeJob` メソッドを実装します。
- `calculateInheritedStats` にて、仕様通り「HPは成長分の10%、その他は15%」を引き継ぐ計算ロジック（装備補正を除外した基礎能力ベース）を実装します。

### 4. コントローラとルーティング

#### [NEW] `c:\Users\yuta\tool\tool\ffa\app\Http\Controllers\JobChangeController.php`
- `index`（転職所トップ）、`confirm`（転職確認）、`change`（転職実行）のアクションを実装します。

#### [MODIFY] `c:\Users\yuta\tool\tool\ffa\routes\web.php`
- `/jobs`, `/jobs/{job}/confirm`, `/jobs/{job}/change` などのルーティングを追加します。

### 5. UI（ビュー）の作成・修正

#### [NEW] `c:\Users\yuta\tool\tool\ffa\resources\views\jobs\index.blade.php`
- 転職所のトップ画面。現在の職業とレベル、転職回数を表示し、Lv100未満の場合は転職できない旨を表示します。Lv100以上の場合は職業ごとの「転職する」ボタンを表示します。

#### [NEW] `c:\Users\yuta\tool\tool\ffa\resources\views\jobs\confirm.blade.php`
- 転職確認画面。転職前後のステータス予測値（`CharacterJobChangeService`で計算）を表示し、最終確認を行います。

#### [NEW] `c:\Users\yuta\tool\tool\ffa\resources\views\jobs\completed.blade.php`
- 転職完了画面。引き継いだ能力や、Lv1に戻ったことなどを表示します。

#### [MODIFY] `c:\Users\yuta\tool\tool\ffa\resources\views\components\layouts\app.blade.php` (または左カラム部分)
- 左カラムに「転職：〇回」の表示を追加します。
- 次の目標のメッセージを「Lv100到達後」や「転職直後」に応じて切り替えられるようにします。

## Verification Plan

### Automated Tests
- 今回は自動テストの作成指示がないため省略しますが、手動テストを重視します。

### Manual Verification
- コマンドラインやTinker等で意図的にキャラクターのレベルを100にし、転職所で転職が可能になることを確認する。
- 転職時にステータスが正しく「成長分の10%・15%」になっているか、確認画面の予測と実際の能力が一致しているかを確認する。
- レベル100を超えて経験値が取得されないこと、およびレベル1の状態で各職業の成長率に沿ってステータスが上昇することを確認する。
