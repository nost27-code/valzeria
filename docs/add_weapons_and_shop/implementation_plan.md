# 武器マスターの登録とショップ販売の実装計画

提供いただいた武器マスタデータ（TSV形式、計81件）をデータベースに登録し、ショップで販売できるように実装を行います。

## User Review Required

> [!IMPORTANT]
> **テーブル構造（アイテム属性）の拡張について**
> 現在の `items` テーブルには、「MPのステータス補正値（mp_bonus）」「売却価格（sell_price）」「装備のサブタイプ（剣、斧、杖など）」「属性（無、魔など）」を保存するカラムが存在しません。
> 武器だけでなく今後の防具や装飾品の実装にも備え、`items` テーブルに以下のカラムを追加する設計で進めてよろしいでしょうか？
> 
> * `mp_bonus`: MP補正値
> * `sell_price`: 売却価格
> * `sub_type`: サブタイプ（剣、斧など）
> * `element`: 属性（無、魔など）

> [!NOTE]
> **推奨Lvと購入条件について**
> 既存の `items` テーブルには `required_level` (装備要求レベル / 購入条件レベル) があります。
> ご提供いただいたTSVの「購入条件」の「Lv20到達で購入可能」などを解釈し、この `required_level` に 1, 20, 40 などの数値をセットし、ショップ画面でレベルごとの表示・購入制限を行う実装といたします。

## Proposed Changes

### Database Migrations

#### [NEW] database/migrations/YYYY_MM_DD_HHMMSS_add_columns_to_items_table.php
`items` テーブルに以下のカラムを追加します。
* `mp_bonus` (integer, default 0)
* `sell_price` (integer, default 0)
* `sub_type` (string, nullable) : 「剣」「斧」「杖」などを保存
* `element` (string, nullable) : 「無」「魔」などを保存

### Master Data / Seeder

#### [NEW] database/seeders/WeaponsSeeder.php または更新スクリプト
* いただいたTSVデータをパースし、`items` テーブルに `type = 'weapon'` として全81件を一括で Insert/Update（`updateOrCreate`）するスクリプトを作成します。
* `str_bonus` に ATK、`agi_bonus` に SPD など、既存のカラム名に合わせたマッピングを行います。

### Controllers & Services

#### [MODIFY] app/Http/Controllers/ShopController.php
* 武器屋の表示処理（`weapons()`）において、`required_level` を基に、プレイヤーの現在レベルに応じた表示制限（または購入可能フラグ）を持たせてViewに渡すようにします。（既存は単純なリスト取得のみのため）

### Views

#### [MODIFY] resources/views/shop/list.blade.php (存在する場合) 
* 新しく追加された `mp_bonus` や `spr_bonus`、`attribute(属性)`、`sub_type(武器種)` を表示するように見た目を整えます。
* 購入条件レベル（`required_level`）を満たしていない場合は購入ボタンを非活性（Disabled）にするか、「LvXXで解放」と表示するようにします。

## Verification Plan

### Automated Tests
* なし

### Manual Verification
1. ローカル環境でTSV読み込みスクリプトを実行し、`items` テーブルに81件の武器が正しく登録されていることを確認する。
2. キャラクター（様々なレベル）でログインし、ショップ画面にアクセスする。
3. 自分のレベル以下の武器は購入可能であり、レベルが足りない武器は表示されない（または購入できない）ことを確認する。
4. 武器を購入し、ステータス画面や戦闘でのボーナス（MP、SPR等）が正しく反映されることを確認する。
