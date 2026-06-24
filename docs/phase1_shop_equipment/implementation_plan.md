# フェーズ1 武具屋・装備 実装計画

## 目標
`valzeria_phase1_shop_equipment_spec.md` に基づき、キャラクターが戦闘で得たGOLDを使って武器・防具・装飾品を購入し、それを装備してステータスを強化（戦闘での与ダメージや被ダメージに反映）する育成ループを実装します。

## User Review Required
> [!IMPORTANT]
> 既存の「木の剣」「布の服」「魔除けの護符」を、作成済みの全テストキャラクターに一斉配布する初期装備付与コマンド (`php artisan valzeria:grant-initial-equipment`) を作成・実行します。
> また、戦闘時のダメージ計算（`BattleService`）において、キャラクターの素のステータス（str, def等）ではなく、**装備補正が加算されたステータス**をベースにするようロジックを差し替えます。

## Proposed Changes

---

### Database & Models

#### [NEW] `database/migrations/xxxx_create_items_table.php`
- `items` テーブルを作成（id, name, type, rarity, price, 各種ボーナス値, required_level, is_shop_item 等）。

#### [NEW] `database/migrations/xxxx_create_character_items_table.php`
- `character_items` テーブルを作成（character_id, item_id, is_equipped, equipped_slot 等）。

#### [NEW] `app/Models/Item.php`, `app/Models/CharacterItem.php`
- リレーション等の定義。

#### [NEW] `database/seeders/ItemSeeder.php`
- 木の剣、布の服、鉄の剣など仕様書に指定された初期装備14種を登録。

---

### Services

#### [NEW] `app/Services/CharacterStatusService.php`
- `character_items` を参照し、各スロットの装備品補正値（STR, DEF等）を基礎ステータスに加算した「最終ステータス」を算出する。

#### [NEW] `app/Services/ShopService.php`
- GOLD不足・レベル不足等のチェックを行い、購入処理をトランザクション内で実行する。

#### [NEW] `app/Services/EquipmentService.php`
- キャラクターの装備変更を処理。同スロットの既存装備を外し、新たな装備をセットする。最大HP変動時の現在HP丸め処理も担当。

#### [MODIFY] `app/Services/BattleService.php`
- 戦闘時、直接キャラクターのステータス（`$character->str`等）を参照せず、`CharacterStatusService` 経由で取得した補正込みステータスを使用するように修正。

---

### Controllers & Routes

#### [MODIFY] `routes/web.php`
- `/shop`, `/shop/weapons`, `/shop/armors`, `/shop/accessories`, `/shop/items/{item}/buy` などを追加。
- `/equipment`, `/equipment/{characterItem}/equip` を追加。

#### [NEW] `app/Http/Controllers/ShopController.php`
- 武具屋トップ、各カテゴリ一覧、購入実行処理。

#### [NEW] `app/Http/Controllers/EquipmentController.php`
- 装備画面の表示と装備実行処理。

---

### Views & UI

#### [MODIFY] `app/Livewire/MainScreen.php`
- 左カラムの「現在の装備」表示と、装備補正込みのステータス表示をサポート。

#### [NEW] `resources/views/shop/index.blade.php`, `list.blade.php`
- ショップの施設案内および各カテゴリの装備一覧（購入ボタンつき）UIを作成。

#### [NEW] `resources/views/equipment/index.blade.php`
- 所持している装備一覧と、装備変更用ボタンのUIを作成。

---

### CLI Command

#### [NEW] `app/Console/Commands/GrantInitialEquipment.php`
- 既存の全キャラクターに対して、初期装備（木の剣、布の服、魔除けの護符）を付与し装備状態にする一括コマンド。

## Verification Plan

### Automated Tests / Commands
- マイグレーションと `ItemSeeder` の実行。
- `php artisan valzeria:grant-initial-equipment` の実行。

### Manual Verification
1. 既存のキャラクターで画面を開いた際、初期装備がセットされており、ステータスに反映されているか。
2. 敵と戦い、与えるダメージが初期装備分（木の剣のSTR+3など）増えているか。
3. 戦闘でGOLDを稼いだ後、武具屋で新しい装備（例：鉄の剣）を購入できるか（GOLDが足りない・レベルが足りない場合はエラーとなるか）。
4. 装備画面で購入した鉄の剣に変更し、ステータスが上昇し、木の剣が外れるか。
5. 新しい装備で戦い、戦闘結果のダメージが上がっているか。
