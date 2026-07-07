\# 職業別装備制限 実装仕様書



\## 目的



武器・防具に「装備カテゴリ」を追加し、職業ごとに装備可能な武器・防具を制御できるようにする。



これにより、職業ごとの個性を強化する。



例：



\* 剣士は剣・短剣・槍を装備できる

\* 魔法使いは杖・魔導具・短剣を装備できる

\* 剣聖は剣・刀に特化する

\* 大賢者は杖・魔導具に特化する

\* 勇者や伝説職は幅広く装備できる



職業の違いを、ステータス倍率だけでなく「装備選択」にも反映する。



\---



\## 実装方針



既存の武器・防具マスタに、職業制限用のカテゴリを追加する。



ただし、いきなり既存装備を装備不可にするとプレイヤー体験を壊す可能性があるため、段階導入する。



導入順は以下。



1\. 武器・防具マスタにカテゴリカラムを追加

2\. 職業ごとの装備可能カテゴリテーブルを追加

3\. 装備可否判定サービスを作成

4\. 装備変更画面・ショップ画面に装備可否表示を追加

5\. 転職時に装備不可チェックを追加

6\. 問題なければ装備制限を本適用



\---



\## 追加する武器カテゴリ



| category\_key | 表示名 | 説明           |

| ------------ | --- | ------------ |

| sword        | 剣   | 標準的な片手剣・長剣   |

| axe          | 斧   | 戦士・狂戦士向けの重武器 |

| dagger       | 短剣  | 盗賊・忍者・軽量職向け  |

| bow          | 弓   | 弓使い・狙撃手向け    |

| staff        | 杖   | 魔法使い・僧侶向け    |

| magic\_device | 魔導具 | 魔法職・機工系向け    |

| gun          | 銃   | 狙撃手・機工系向け    |

| spear        | 槍   | 騎士・竜騎士向け     |

| fist         | 拳甲  | 格闘家・武神向け     |

| katana       | 刀   | 侍・剣聖向け       |



\---



\## 追加する防具カテゴリ



| category\_key | 表示名    | 説明            |

| ------------ | ------ | ------------- |

| clothes      | 服・旅装   | 軽装。多くの職業が装備可能 |

| robe         | ローブ・法衣 | 魔法職・僧侶職向け     |

| cloak        | 外套・マント | 軽量職・魔法職向け     |

| light\_armor  | 革鎧・軽鎧  | 前衛・軽量職向け      |

| heavy\_armor  | 鎧・重鎧   | 戦士・騎士系向け      |



\---



\## DB変更



\### 武器マスタへの追加カラム



既存の武器マスタに以下を追加する。



```sql

ALTER TABLE weapons

ADD COLUMN weapon\_category VARCHAR(50) NULL AFTER type,

ADD COLUMN weapon\_hand\_type VARCHAR(20) NULL AFTER weapon\_category,

ADD COLUMN weapon\_role VARCHAR(20) NULL AFTER weapon\_hand\_type;

```



\### カラム説明



| カラム              | 例        | 説明          |

| ---------------- | -------- | ----------- |

| weapon\_category  | sword    | 武器カテゴリ      |

| weapon\_hand\_type | one\_hand | 将来用。片手・両手など |

| weapon\_role      | physical | 物理・魔法・複合など  |



`weapon\_category` は装備可否判定に使用する。

`weapon\_hand\_type` と `weapon\_role` は将来拡張用のため、初期実装では必須ではない。



\---



\### 防具マスタへの追加カラム



既存の防具マスタに以下を追加する。



```sql

ALTER TABLE armors

ADD COLUMN armor\_category VARCHAR(50) NULL AFTER type,

ADD COLUMN armor\_weight VARCHAR(20) NULL AFTER armor\_category,

ADD COLUMN armor\_role VARCHAR(20) NULL AFTER armor\_weight;

```



\### カラム説明



| カラム            | 例           | 説明             |

| -------------- | ----------- | -------------- |

| armor\_category | light\_armor | 防具カテゴリ         |

| armor\_weight   | light       | 将来用。軽量・中量・重量など |

| armor\_role     | physical    | 物理・魔法・複合など     |



`armor\_category` は装備可否判定に使用する。

`armor\_weight` と `armor\_role` は将来拡張用のため、初期実装では必須ではない。



\---



\## カテゴリマスタ



\### weapon\_categories



```sql

CREATE TABLE weapon\_categories (

&#x20;   id BIGINT UNSIGNED AUTO\_INCREMENT PRIMARY KEY,

&#x20;   category\_key VARCHAR(50) NOT NULL UNIQUE,

&#x20;   name VARCHAR(100) NOT NULL,

&#x20;   description TEXT NULL,

&#x20;   sort\_order INT NOT NULL DEFAULT 0,

&#x20;   created\_at TIMESTAMP NULL,

&#x20;   updated\_at TIMESTAMP NULL

);

```



\### 初期データ



```sql

INSERT INTO weapon\_categories (category\_key, name, description, sort\_order, created\_at, updated\_at) VALUES

('sword', '剣', '標準的な剣。剣士・騎士系が得意とする。', 10, NOW(), NOW()),

('axe', '斧', '高威力の重武器。戦士・狂戦士系が得意とする。', 20, NOW(), NOW()),

('dagger', '短剣', '軽量で扱いやすい武器。盗賊・忍者系が得意とする。', 30, NOW(), NOW()),

('bow', '弓', '遠距離武器。弓使い・狙撃手系が得意とする。', 40, NOW(), NOW()),

('staff', '杖', '魔法や回復に適した武器。魔法使い・僧侶系が得意とする。', 50, NOW(), NOW()),

('magic\_device', '魔導具', '魔力を増幅する装置。魔法職・機工系が扱う。', 60, NOW(), NOW()),

('gun', '銃', '機械式の遠距離武器。狙撃手・機工系が扱う。', 70, NOW(), NOW()),

('spear', '槍', 'リーチに優れた武器。騎士・竜騎士系が得意とする。', 80, NOW(), NOW()),

('fist', '拳甲', '拳を強化する武器。格闘家・武神系が得意とする。', 90, NOW(), NOW()),

('katana', '刀', '技量を要する刃。侍・剣聖系が得意とする。', 100, NOW(), NOW());

```



\---



\### armor\_categories



```sql

CREATE TABLE armor\_categories (

&#x20;   id BIGINT UNSIGNED AUTO\_INCREMENT PRIMARY KEY,

&#x20;   category\_key VARCHAR(50) NOT NULL UNIQUE,

&#x20;   name VARCHAR(100) NOT NULL,

&#x20;   description TEXT NULL,

&#x20;   sort\_order INT NOT NULL DEFAULT 0,

&#x20;   created\_at TIMESTAMP NULL,

&#x20;   updated\_at TIMESTAMP NULL

);

```



\### 初期データ



```sql

INSERT INTO armor\_categories (category\_key, name, description, sort\_order, created\_at, updated\_at) VALUES

('clothes', '服・旅装', '軽く扱いやすい防具。多くの職業が装備できる。', 10, NOW(), NOW()),

('robe', 'ローブ・法衣', '魔法や精神力に優れた防具。魔法職・僧侶職向け。', 20, NOW(), NOW()),

('cloak', '外套・マント', '身軽さと防御を両立する防具。軽量職・魔法職向け。', 30, NOW(), NOW()),

('light\_armor', '革鎧・軽鎧', '動きやすさを残した鎧。前衛・軽量職向け。', 40, NOW(), NOW()),

('heavy\_armor', '鎧・重鎧', '防御力に優れた重装備。戦士・騎士系向け。', 50, NOW(), NOW());

```



\---



\## 職業別装備可能テーブル



\### job\_weapon\_permissions



```sql

CREATE TABLE job\_weapon\_permissions (

&#x20;   id BIGINT UNSIGNED AUTO\_INCREMENT PRIMARY KEY,

&#x20;   job\_id BIGINT UNSIGNED NOT NULL,

&#x20;   weapon\_category VARCHAR(50) NOT NULL,

&#x20;   created\_at TIMESTAMP NULL,

&#x20;   updated\_at TIMESTAMP NULL,

&#x20;   UNIQUE KEY uq\_job\_weapon\_category (job\_id, weapon\_category),

&#x20;   INDEX idx\_job\_weapon\_permissions\_job\_id (job\_id),

&#x20;   INDEX idx\_job\_weapon\_permissions\_category (weapon\_category)

);

```



\### job\_armor\_permissions



```sql

CREATE TABLE job\_armor\_permissions (

&#x20;   id BIGINT UNSIGNED AUTO\_INCREMENT PRIMARY KEY,

&#x20;   job\_id BIGINT UNSIGNED NOT NULL,

&#x20;   armor\_category VARCHAR(50) NOT NULL,

&#x20;   created\_at TIMESTAMP NULL,

&#x20;   updated\_at TIMESTAMP NULL,

&#x20;   UNIQUE KEY uq\_job\_armor\_category (job\_id, armor\_category),

&#x20;   INDEX idx\_job\_armor\_permissions\_job\_id (job\_id),

&#x20;   INDEX idx\_job\_armor\_permissions\_category (armor\_category)

);

```



\---



\## 職業別 武器装備可能表



職業IDは既存の職業マスタに合わせること。

以下は `job\_key` または職業名ベースで登録する想定。



| 職業        | 装備可能武器                                                                 |

| --------- | ---------------------------------------------------------------------- |

| 剣士        | sword, dagger, spear                                                   |

| 戦士        | sword, axe, spear, fist                                                |

| 盗賊        | dagger, sword, gun                                                     |

| 弓使い       | bow, dagger, gun                                                       |

| 格闘家       | fist, dagger                                                           |

| 魔法使い      | staff, magic\_device, dagger                                            |

| 僧侶        | staff, sword, magic\_device                                             |

| 商人        | dagger, gun, staff                                                     |

| 魔法剣士      | sword, dagger, staff, magic\_device                                     |

| 聖騎士       | sword, spear, staff                                                    |

| 侍         | katana, sword, dagger                                                  |

| 軍師        | sword, staff, magic\_device, gun                                        |

| 剣闘士       | sword, axe, spear, fist                                                |

| 狂戦士       | axe, sword, fist                                                       |

| 守護騎士      | sword, spear, axe                                                      |

| 傭兵        | sword, axe, dagger, bow, spear, gun                                    |

| 忍者        | dagger, katana, fist, gun                                              |

| 狙撃手       | bow, gun, dagger                                                       |

| 魔盗士       | dagger, staff, magic\_device, gun                                       |

| 旅商人       | dagger, gun, staff, bow                                                |

| モンク       | fist, staff                                                            |

| 魔弓士       | bow, staff, magic\_device, dagger                                       |

| 吟遊詩人      | bow, dagger, staff                                                     |

| 司祭        | staff, magic\_device                                                    |

| 薬師        | staff, dagger, magic\_device                                            |

| 錬金術師      | magic\_device, staff, gun                                               |

| 勇者        | sword, katana, spear, staff, bow                                       |

| 剣聖        | sword, katana, dagger                                                  |

| 大賢者       | staff, magic\_device                                                    |

| 暗黒騎士      | sword, axe, spear, magic\_device                                        |

| 黄金商人      | dagger, gun, staff, magic\_device                                       |

| 竜騎士       | spear, sword, axe                                                      |

| 武神        | fist, axe, spear                                                       |

| 幻影王       | dagger, katana, staff, magic\_device                                    |

| 機工王       | gun, magic\_device, axe, spear                                          |

| 神官戦士      | sword, spear, staff, magic\_device                                      |

| 影狩人       | bow, gun, dagger, katana                                               |

| 賢商王       | staff, magic\_device, gun, dagger                                       |

| ヴァルゼリアの救世主 | sword, axe, dagger, bow, staff, magic\_device, gun, spear, fist, katana |

| 深淵歩き      | sword, axe, dagger, katana, magic\_device                               |

| 古代錬成王     | magic\_device, gun, staff, axe                                          |

| 蒼竜王        | spear, sword, axe, fist                                                |

| 時空王       | staff, magic\_device, dagger, katana, gun                               |



\---



\## 職業別 防具装備可能表



| 職業        | 装備可能防具                                         |

| --------- | ---------------------------------------------- |

| 剣士        | clothes, cloak, light\_armor, heavy\_armor       |

| 戦士        | clothes, light\_armor, heavy\_armor              |

| 盗賊        | clothes, cloak, light\_armor                    |

| 弓使い       | clothes, cloak, light\_armor                    |

| 格闘家       | clothes, cloak, light\_armor                    |

| 魔法使い      | clothes, robe, cloak                           |

| 僧侶        | clothes, robe, cloak, light\_armor              |

| 商人        | clothes, cloak, light\_armor                    |

| 魔法剣士      | clothes, robe, cloak, light\_armor, heavy\_armor |

| 聖騎士       | clothes, robe, light\_armor, heavy\_armor        |

| 侍         | clothes, cloak, light\_armor, heavy\_armor       |

| 軍師        | clothes, robe, cloak, light\_armor              |

| 剣闘士       | clothes, light\_armor, heavy\_armor              |

| 狂戦士       | clothes, light\_armor, heavy\_armor              |

| 守護騎士      | light\_armor, heavy\_armor                       |

| 傭兵        | clothes, cloak, light\_armor, heavy\_armor       |

| 忍者        | clothes, cloak, light\_armor                    |

| 狙撃手       | clothes, cloak, light\_armor                    |

| 魔盗士       | clothes, robe, cloak, light\_armor              |

| 旅商人       | clothes, cloak, light\_armor                    |

| モンク       | clothes, robe, cloak, light\_armor              |

| 魔弓士       | clothes, robe, cloak, light\_armor              |

| 吟遊詩人      | clothes, robe, cloak                           |

| 司祭        | clothes, robe, cloak                           |

| 薬師        | clothes, robe, cloak, light\_armor              |

| 錬金術師      | clothes, robe, cloak, light\_armor              |

| 勇者        | clothes, robe, cloak, light\_armor, heavy\_armor |

| 剣聖        | clothes, cloak, light\_armor, heavy\_armor       |

| 大賢者       | clothes, robe, cloak                           |

| 暗黒騎士      | cloak, light\_armor, heavy\_armor                |

| 黄金商人      | clothes, robe, cloak, light\_armor              |

| 竜騎士       | light\_armor, heavy\_armor                       |

| 武神        | clothes, cloak, light\_armor, heavy\_armor       |

| 幻影王       | clothes, robe, cloak, light\_armor              |

| 機工王       | clothes, cloak, light\_armor, heavy\_armor       |

| 神官戦士      | robe, cloak, light\_armor, heavy\_armor          |

| 影狩人       | clothes, cloak, light\_armor                    |

| 賢商王       | clothes, robe, cloak, light\_armor              |

| ヴァルゼリアの救世主 | clothes, robe, cloak, light\_armor, heavy\_armor |

| 深淵歩き      | robe, cloak, light\_armor, heavy\_armor          |

| 古代錬成王     | clothes, robe, cloak, light\_armor, heavy\_armor |

| 蒼竜王        | clothes, light\_armor, heavy\_armor              |

| 時空王       | clothes, robe, cloak, light\_armor              |



\---



\## 装備可否判定サービス



\### サービス名



`EquipmentPermissionService`



\### 役割



現在の職業が、指定された武器・防具を装備できるか判定する。



\### メソッド案



```php

class EquipmentPermissionService

{

&#x20;   public function canEquipWeapon(int $jobId, string $weaponCategory): bool

&#x20;   {

&#x20;       return JobWeaponPermission::where('job\_id', $jobId)

&#x20;           ->where('weapon\_category', $weaponCategory)

&#x20;           ->exists();

&#x20;   }



&#x20;   public function canEquipArmor(int $jobId, string $armorCategory): bool

&#x20;   {

&#x20;       return JobArmorPermission::where('job\_id', $jobId)

&#x20;           ->where('armor\_category', $armorCategory)

&#x20;           ->exists();

&#x20;   }



&#x20;   public function validateWeaponEquip(Player $player, Weapon $weapon): bool

&#x20;   {

&#x20;       if (empty($weapon->weapon\_category)) {

&#x20;           return true;

&#x20;       }



&#x20;       return $this->canEquipWeapon(

&#x20;           $player->current\_job\_id,

&#x20;           $weapon->weapon\_category

&#x20;       );

&#x20;   }



&#x20;   public function validateArmorEquip(Player $player, Armor $armor): bool

&#x20;   {

&#x20;       if (empty($armor->armor\_category)) {

&#x20;           return true;

&#x20;       }



&#x20;       return $this->canEquipArmor(

&#x20;           $player->current\_job\_id,

&#x20;           $armor->armor\_category

&#x20;       );

&#x20;   }

}

```



\---



\## 未分類装備の扱い



初期移行中は、`weapon\_category` または `armor\_category` が未設定の装備が残る可能性がある。



そのため、初期実装では以下の扱いにする。



```text

カテゴリ未設定の装備は、暫定的に装備可能として扱う。

```



理由：



\* 既存データ移行中の事故を防ぐ

\* 実装直後にプレイヤー装備が壊れるのを防ぐ

\* マスタ更新漏れによる致命的な不具合を避ける



ただし、カテゴリ移行完了後は、未設定装備を管理画面やログで検出できるようにする。



\---



\## 装備変更画面の仕様



装備変更画面では、現在職業で装備できない武器・防具をグレーアウトする。



\### 表示例



```text

黒鉄の大斧

ATK +120

カテゴリ：斧

現在の職業では装備できません

```



\### ボタン制御



| 状態      | 表示               |

| ------- | ---------------- |

| 装備可能    | 「装備する」ボタンを表示     |

| 装備不可    | 「装備不可」表示。ボタンは非活性 |

| カテゴリ未設定 | 暫定的に装備可能         |



\---



\## ショップ画面の仕様



ショップでは、現在職業で装備できない装備も購入可能にする。



理由：



\* 将来の転職後に使うために先買いできる

\* レア装備・高額装備の購入目標を残せる

\* 装備制限によるストレスを減らせる



ただし、装備不可であることは明示する。



\### 表示例



```text

黒鉄の大斧

価格：32,000G

ATK +120

カテゴリ：斧



現在の職業では装備できません。

戦士、狂戦士、暗黒騎士などで装備可能です。

```



\### ボタン制御



| 状態    | 購入 | 装備 |

| ----- | -- | -- |

| 装備可能  | 可能 | 可能 |

| 装備不可  | 可能 | 不可 |

| 所持金不足 | 不可 | 不可 |



\---



\## ドロップ入手時の仕様



敵からドロップした装備は、現在の職業で装備できなくても入手可能。



ドロップ後の表示に、装備可否を追加する。



\### 表示例



```text

黒鉄の大斧を手に入れた！



現在の職業では装備できません。

戦士系の職業で装備可能です。

```



\---



\## 転職時の仕様



転職後、現在装備している武器・防具が新しい職業で装備不可になる可能性がある。



その場合は、自動的に装備を外す。



\### 処理方針



転職処理の最後に、現在装備の可否を確認する。



\* 新職業で武器が装備不可なら、武器を外す

\* 新職業で防具が装備不可なら、防具を外す

\* 外した装備は所持品に戻す

\* プレイヤーにメッセージを表示する



\### 表示例



```text

魔法使いに転職しました。



現在の職業では「黒鉄の大斧」を装備できないため、装備から外しました。

```



\### 疑似コード



```php

public function changeJob(Player $player, int $newJobId): void

{

&#x20;   DB::transaction(function () use ($player, $newJobId) {

&#x20;       $player->current\_job\_id = $newJobId;

&#x20;       $player->save();



&#x20;       $this->equipmentAutoUnequipService->unequipInvalidItems($player);

&#x20;   });

}

```



\---



\## 装備不可自動解除サービス



\### サービス名



`EquipmentAutoUnequipService`



\### メソッド案



```php

class EquipmentAutoUnequipService

{

&#x20;   public function \_\_construct(

&#x20;       private EquipmentPermissionService $permissionService

&#x20;   ) {}



&#x20;   public function unequipInvalidItems(Player $player): array

&#x20;   {

&#x20;       $messages = \[];



&#x20;       $weapon = $player->equippedWeapon;

&#x20;       if ($weapon \&\& !$this->permissionService->validateWeaponEquip($player, $weapon)) {

&#x20;           $player->weapon\_id = null;

&#x20;           $messages\[] = "現在の職業では「{$weapon->name}」を装備できないため、装備から外しました。";

&#x20;       }



&#x20;       $armor = $player->equippedArmor;

&#x20;       if ($armor \&\& !$this->permissionService->validateArmorEquip($player, $armor)) {

&#x20;           $player->armor\_id = null;

&#x20;           $messages\[] = "現在の職業では「{$armor->name}」を装備できないため、装備から外しました。";

&#x20;       }



&#x20;       if (!empty($messages)) {

&#x20;           $player->save();

&#x20;       }



&#x20;       return $messages;

&#x20;   }

}

```



\---



\## 戦闘前チェック



万が一、装備不可の装備が残っていた場合に備え、戦闘開始前にもチェックする。



\### 方針



戦闘開始前に、装備不可の武器・防具を自動で外す。



理由：



\* 古いデータや移行漏れによる不正状態を防ぐ

\* 装備不可品のステータス補正が戦闘に乗る事故を防ぐ



\### 実装箇所



戦闘開始処理の直前。



```php

$this->equipmentAutoUnequipService->unequipInvalidItems($player);

```



\---



\## 管理・マスタメンテナンス用チェック



カテゴリ未設定の装備を検出できるようにする。



\### 武器カテゴリ未設定チェック



```sql

SELECT id, name

FROM weapons

WHERE weapon\_category IS NULL

&#x20;  OR weapon\_category = '';

```



\### 防具カテゴリ未設定チェック



```sql

SELECT id, name

FROM armors

WHERE armor\_category IS NULL

&#x20;  OR armor\_category = '';

```



\### 不正カテゴリチェック



```sql

SELECT w.id, w.name, w.weapon\_category

FROM weapons w

LEFT JOIN weapon\_categories c

&#x20;   ON w.weapon\_category = c.category\_key

WHERE w.weapon\_category IS NOT NULL

&#x20; AND w.weapon\_category <> ''

&#x20; AND c.id IS NULL;

```



```sql

SELECT a.id, a.name, a.armor\_category

FROM armors a

LEFT JOIN armor\_categories c

&#x20;   ON a.armor\_category = c.category\_key

WHERE a.armor\_category IS NOT NULL

&#x20; AND a.armor\_category <> ''

&#x20; AND c.id IS NULL;

```



\---



\## UI表示ルール



\### 装備カテゴリ表示



武器・防具の詳細欄にカテゴリを表示する。



```text

カテゴリ：剣

```



```text

カテゴリ：ローブ

```



\### 装備可否表示



現在職業で装備できる場合。



```text

現在の職業で装備可能

```



現在職業で装備できない場合。



```text

現在の職業では装備できません

```



\### 装備可能職業表示



可能であれば、その装備カテゴリを装備できる代表職業を表示する。



```text

装備可能職業：剣士、魔法剣士、聖騎士、勇者 など

```



全職業を表示すると長くなりすぎるため、画面上は代表職業のみでよい。



\---



\## 装備推奨表示



将来的には、装備可能なだけでなく、職業に合っているかを表示してもよい。



例：



\* 剣士に剣：得意装備

\* 剣士に槍：装備可能

\* 魔法使いに短剣：装備可能だが非推奨

\* 魔法使いに杖：得意装備



ただし、今回の実装では必須ではない。



今回の対象は「装備できる / 装備できない」のみ。



\---



\## 実装フェーズ



\### Phase 1：DBとマスタ追加



\* weapons に `weapon\_category` を追加

\* armors に `armor\_category` を追加

\* weapon\_categories テーブル作成

\* armor\_categories テーブル作成

\* job\_weapon\_permissions テーブル作成

\* job\_armor\_permissions テーブル作成

\* 初期データ投入



この時点では、まだ装備制限を有効化しない。



\---



\### Phase 2：既存装備へのカテゴリ付与



既存の武器・防具マスタにカテゴリを設定する。



例：



```text

王都兵の剣 → sword

王都兵の斧 → axe

王都兵の短剣 → dagger

王都兵の弓 → bow

王都見習いの杖 → staff

王都式魔導具 → magic\_device

王都式短銃 → gun

王都兵の槍 → spear

王都兵の拳甲 → fist

侍刀 → katana

```



防具例：



```text

旅人の服 → clothes

魔法のローブ → robe

影の外套 → cloak

革鎧 → light\_armor

騎士の鎧 → heavy\_armor

```



\---



\### Phase 3：装備可否表示



装備変更画面・ショップ画面・ドロップ結果画面に、装備可否を表示する。



この段階では、まだ装備不可でも装備できる設定にしてよい。



目的は、プレイヤーと開発者が表示内容を確認すること。



\---



\### Phase 4：装備制限の有効化



装備変更時に、装備不可の装備を選べないようにする。



\* 装備変更画面ではボタン非活性

\* API側でも装備不可ならエラー

\* ショップでは購入可能

\* ドロップでは入手可能



\---



\### Phase 5：転職時の自動解除



転職後の職業で装備できない装備を自動的に外す。



外した装備は所持品に戻す。



\---



\### Phase 6：戦闘前チェック



戦闘開始前に装備不可品が残っていないか確認する。



残っていた場合は自動解除する。



\---



\## API・コントローラー側の注意点



装備変更処理では、フロント側のボタン制御だけに頼らない。



必ずサーバー側でも判定する。



\### 武器装備時



```php

if (!$equipmentPermissionService->validateWeaponEquip($player, $weapon)) {

&#x20;   return back()->with('error', '現在の職業ではこの武器を装備できません。');

}

```



\### 防具装備時



```php

if (!$equipmentPermissionService->validateArmorEquip($player, $armor)) {

&#x20;   return back()->with('error', '現在の職業ではこの防具を装備できません。');

}

```



\---



\## エラーメッセージ



\### 武器装備不可



```text

現在の職業ではこの武器を装備できません。

```



\### 防具装備不可



```text

現在の職業ではこの防具を装備できません。

```



\### 転職時の自動解除



```text

転職後の職業では装備できないため、一部の装備を外しました。

```



\---



\## バランス上の注意点



装備制限を入れると、職業ごとの個性は強くなるが、プレイヤーの自由度は下がる。



そのため、以下の点に注意する。



\### 基本職は不自由にしすぎない



序盤の基本職は、装備候補をある程度広くする。



例：



\* 剣士：剣、短剣、槍

\* 戦士：剣、斧、槍、拳甲

\* 盗賊：短剣、剣、銃

\* 魔法使い：杖、魔導具、短剣



\### 中級職は個性を出す



中級職は、組み合わせ元の職業に応じて装備カテゴリを引き継ぐ。



例：



\* 魔法剣士：剣、短剣、杖、魔導具

\* 忍者：短剣、刀、拳甲、銃

\* 狙撃手：弓、銃、短剣



\### 上級職は専門性を強める



上級職は、強い代わりに装備範囲を絞ってもよい。



例：



\* 剣聖：剣、刀、短剣

\* 大賢者：杖、魔導具

\* 竜騎士：槍、剣、斧



\### 伝説職は到達報酬として広くする



伝説職は到達難度が高いため、装備可能範囲を広くしてよい。



例：



\* ヴァルゼリアの救世主：全武器・全防具

\* 蒼竜王：槍、剣、斧、拳甲

\* 時空王：杖、魔導具、短剣、刀、銃



\---



\## テスト観点



\### DB



\* weapon\_categories が作成されている

\* armor\_categories が作成されている

\* job\_weapon\_permissions が作成されている

\* job\_armor\_permissions が作成されている

\* weapons に weapon\_category が追加されている

\* armors に armor\_category が追加されている



\### マスタ



\* すべての武器に weapon\_category が設定されている

\* すべての防具に armor\_category が設定されている

\* 存在しないカテゴリが設定されていない

\* 職業ごとの装備可能カテゴリが登録されている



\### 装備変更



\* 装備可能な武器を装備できる

\* 装備不可の武器を装備できない

\* 装備可能な防具を装備できる

\* 装備不可の防具を装備できない

\* カテゴリ未設定装備は暫定的に装備可能



\### ショップ



\* 装備可能品は通常表示される

\* 装備不可品は警告付きで表示される

\* 装備不可品も購入できる

\* 所持金不足の場合は購入できない



\### ドロップ



\* 装備不可品も入手できる

\* 入手後に装備不可表示が出る

\* 所持品には正常に入る



\### 転職



\* 転職後も装備可能なら装備を維持する

\* 転職後に装備不可なら自動で外れる

\* 外れた装備が消えない

\* 自動解除メッセージが表示される



\### 戦闘



\* 装備不可品の補正が戦闘に乗らない

\* 戦闘前チェックで不正装備が解除される

\* 解除後のステータスで戦闘が行われる



\---



\## 受け入れ条件



以下を満たせば実装完了とする。



\* 武器・防具にカテゴリを設定できる

\* 職業ごとに装備可能カテゴリを定義できる

\* 装備変更画面で装備可否が判定される

\* 装備不可品は装備できない

\* ショップでは装備不可品も購入できる

\* 転職時に装備不可品が自動解除される

\* 戦闘時に装備不可品の補正が乗らない

\* 既存装備が消えない

\* カテゴリ未設定装備によるエラーが発生しない



\---



\## 実装上の最重要ポイント



この改修で最も重要なのは、既存プレイヤーの装備状態を壊さないこと。



そのため、最初から制限を強制せず、以下の順番で導入する。



```text

カテゴリ追加

↓

マスタ設定

↓

画面表示

↓

装備変更時の制限

↓

転職時の自動解除

↓

戦闘前チェック

```



この順番で進めれば、既存データへの影響を抑えながら、職業ごとの装備制限を安全に導入できる。





