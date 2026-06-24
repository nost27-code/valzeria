# 職業マスタボーナスと全職業データの実装計画

ご提示いただいた詳細な「職業マスタデータ（TSV）」をもとに、システムにマスターボーナスと必殺技発動率などの概念を組み込み、全43職業のデータを一括でデータベース（シーダー）に投入します。

## Proposed Changes

### 1. データベースの変更 (Migration)
マスターボーナスは職業ごとに固定で決まっているため、別テーブル（`job_master_bonuses`）で管理するよりも、`job_classes` テーブル自体にカラムを持たせる方がパフォーマンス（N+1問題の回避）と管理の面で優れています。

#### [NEW] `database/migrations/xxxx_xx_xx_xxxxxx_add_bonus_columns_to_job_classes_table.php`
`job_classes` テーブルに以下のカラムを追加します。
- **ステータス永続加算ボーナス**
  - `bonus_hp`, `bonus_mp`, `bonus_str`, `bonus_def`, `bonus_mag`, `bonus_spr`, `bonus_spd`, `bonus_luk` (integer, default: 0)
- **特殊永続パッシブボーナス**
  - `bonus_gold_rate` (integer, default: 0) ... GOLD獲得率ボーナス(%)
  - `bonus_drop_rate` (integer, default: 0) ... ドロップ率ボーナス(%)
  - `bonus_critical_rate` (integer, default: 0) ... 会心率ボーナス(%)
- **職業基本パラメータ**
  - `special_skill_rate` (integer, default: 0) ... 必殺技発動率(%)

### 2. モデルの変更
#### [MODIFY] `app/Models/JobClass.php`
- 上記の追加カラムを `$fillable` またはキャスト等で適切に扱えるように整備します。

### 3. マスターデータの更新
#### [MODIFY] `database/seeders/JobSystemSeeder.php`
- ご提示いただいたTSVデータの全43職業（倍率、マスターボーナス、解放条件、役割など）を解析し、`JobSystemSeeder.php` の初期データ配列として完全に移植します。
- `php artisan db:seed --class=JobSystemSeeder` を実行するだけで、最新のバランス調整がデータベースに反映されるようにします。

## User Review Required

- 以前作成されていた `job_master_bonuses` というテーブル（enumで `hp_rate` などを指定する方式）は、今回の「固定値加算方式（`job_classes`へのカラム統合）」によって不要になるため、システムから除外（または放置）する方針でよろしいでしょうか？（この方が圧倒的に処理が軽くなり、管理も簡単です）

## Verification Plan

### Manual Verification
1. マイグレーションを実行し、`job_classes` テーブルにボーナスカラムが追加されることを確認。
2. `php artisan db:seed --class=JobSystemSeeder` を実行。
3. データベース上で、全43職業の倍率とマスターボーナス（剣士のATK+6など）、GOLD獲得ボーナス（旅商人の+2%など）が正しく登録されているか確認。
