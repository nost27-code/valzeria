# レシピデータ (recipe_master) のインポート計画

## 概要
`docs/recipe_master` に記載された武具・装飾品の合成レシピデータをシステムに取り込み、ゲーム内で活用できる状態にする。

## 実装方針

### 1. データベース設計 (recipes テーブル)
新しい `recipes` テーブルを作成し、以下の情報を管理する。
- `recipe_code`: レシピのユニークコード (例: REC0001)
- `name`: レシピ名
- `item_type`: 装備の種類 (WEAPON, ARMOR, ACCESSORY)
- `result_item_id`: 合成によって完成するアイテムのID（`items` テーブルと連携）
- `result_item_name`: 完成アイテム名
- `city_name` / `area_id` / `area_name` / `required_level` / `element`: エリアと属性情報
- `cost`: 合成費用
- `success_rate`: 成功確率
- `unlock_condition_type` / `unlock_condition_value`: レシピ解放条件
- `materials`: 必要な素材とその個数を格納するJSONカラム
- `key_material_id` / `key_material_name`: 必須となるキー素材情報
- `consume_key_material`: キー素材を消費するかどうか (boolean)
- `is_active`: 有効状態
- `notes`: メモ

### 2. データインポートスクリプトの作成
文字コード起因の文字化け（Shift-JIS/UTF-8問題）を防ぐため、PHPで専用のインポートコマンド（`app/Console/Commands/ImportRecipes.php` 等）を作成し、`docs/recipe_master` ファイルを読み込んでデータベースに安全に登録する。

### 3. モデルの作成
`app/Models/Recipe.php` を作成し、JSONキャストの設定や `Item` モデルとのリレーションを定義する。
