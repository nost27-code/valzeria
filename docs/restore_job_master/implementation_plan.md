# 職業マスタの更新およびシーダー修正計画

ユーザー様からご提示いただいた新しい職業マスタ [jobs_data.tsv](file:///c:/Users/yuta/tool/tool/ffa/jobs_data.tsv) に基づいて、データベースのシード処理および移行ロジックを修正します。

## 新旧マスタの違いの整理

提示いただいたマスタと、これまでのマスタには以下の違いがあります：

1. **ヘッダー名の日本語化**:
   - `name` → `職業名`
   - `max_job_level` → `最大Lv`
   - `hp_rate`〜`luck_rate` → `HP`〜`LUK`
   - `is_hidden` → `隠し職` (`FALSE`/`TRUE` 表記)
   - `bonus_hp`〜`bonus_luk` → `HPボーナス`〜`LUKボーナス`
   - `bonus_gold_rate` → `GOLD獲得%`
   - `bonus_drop_rate` → `ドロップ率%`
   - `bonus_critical_rate` → `必殺率%`
   - `special_skill_rate` → `必殺技率` (`1%` や `3%` などのパーセント表記)
   - `description` → `実装メモ`

2. **転職条件カラムの構造変化**:
   - カンマ区切りの `requirements` カラムから、`必要職1`, `必要職2`, `必要職3` の個別列へと分割されました。

3. **ステータス・ボーナス値の変更（これが本来意図された値です）**:
   - 例：剣士のボーナス値が `HPボーナス=40`, `ATKボーナス=10`, `DEFボーナス=6`, `SPDボーナス=4` など、本来の設計値に変更されています。

---

## 計画内容

### 1. `JobSystemSeeder.php` の修正
新しい TSV フォーマット（日本語ヘッダー、複数カラム化された必要職、パーセント表記の必殺技率など）を正しく読み込み、型変換してデータベースに流し込めるようにシーダーを修正します。

- **数値変換のロバスト化**: `必殺技率`（`3%`）や `隠し職`（`FALSE`/`TRUE`）などの文字列を適切に数値・booleanにキャストします。
- **必要職の抽出**: `必要職1`〜`必要職3` を走査し、空でない職業名を解放条件として登録します。

### 2. ローカルDBでのシード検証
ローカル環境で `php artisan db:seed --class=JobSystemSeeder` を実行し、Tinker でデータが意図通りにインポートされたか（ズレや 0 がないか）を確認します。

### 3. 本番デプロイとデータ移行
修正した Seeder と TSV ファイルを本番にデプロイし、自動シードを実行します。
（前回の移行ルートにより職業IDマッピングはすでに調整されているため、再度マスタを更新してもデータ整合性は保たれます）。

---

## 提案する変更

### 職業データコンポーネント

#### [MODIFY] [JobSystemSeeder.php](file:///c:/Users/yuta/tool/tool/ffa/database/seeders/JobSystemSeeder.php)
新マスタ（日本語ヘッダー・カラム分割）に対応したパース処理に修正します。

---

## 検証計画

### 自動/コマンド検証
- `php artisan db:seed --class=JobSystemSeeder`
- Tinkerによるデータ検証:
  `php artisan tinker --execute="print_r(\App\Models\JobClass::where('key', 'swordsman')->first()->toArray())"`
  （HPボーナスが 40、ATKボーナスが 10、必殺技率が 1 などの値になっているかを確認）
