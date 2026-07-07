# ヴァルゼリアの冒険者 — AI向け総合概要

## 1. プロジェクト概要

**名称**: ヴァルゼリアの冒険者  
**種別**: ブラウザRPG（昔流行したCGIゲーム「FFA」の遊び心地を現代Webアプリとして再構築）  
**デプロイ先**: `valzeria.com`（Xserver）

### コアループ

```
ログイン → キャラ作成 → 戦闘 → 経験値・Gold獲得 → レベルアップ
→ 装備購入 → 転職 → より強いエリアへ → ランキング上位を目指す
```

### 技術スタック

| 層 | 技術 |
|---|---|
| バックエンド | Laravel 11（PHP） |
| フロントエンド | Livewire v3 + Blade + Alpine.js + Tailwind CSS |
| DB | MySQL |
| 認証 | Google OAuth（1アカウント1キャラ原則） + メール認証 |
| 決済 | Stripe（輝石・課金システム） |
| デプロイ | `php local_deploy.php`（ZIP → サーバーPOST → 自動展開） |

---

## 2. 画面・ルート構成

### 認証・キャラクター

| URL | 機能 |
|---|---|
| `GET /` | トップページ（ゲーム紹介、オンライン人数、チャンプ情報、公開ログ） |
| `GET /login`, `POST /login` | メールログイン |
| `GET /register`, `POST /register` | メール登録 |
| `GET /auth/google`, `GET /auth/google/callback` | Google OAuth |
| `POST /auth/guest-login` | ゲストログイン |
| `GET /character/select` | キャラクター選択（Livewire） |
| `GET /character/create` | キャラクター作成（Livewire） |

### メイン画面

`GET /home` — `MainScreen`（Livewire）が担うホーム画面。4つのタブを持つ。

| タブ | 内容 |
|---|---|
| `town` | 街の施設（武器屋・防具屋・アクセサリー屋・転職所・鍛冶屋・合成屋・素材交換所・宿屋・冒険者協会・タバーン・ランキング・案内所） |
| `dungeon` | エリア一覧（探索度・危険度・連戦数表示、探索ボタン・ボス戦ボタン） |
| `colosseum` | チャンプ情報・挑戦ボタン・闘技場ランキング・PvP対戦相手選択 |
| `move` | 都市間移動 |

左パネルにキャラクターステータス・装備確認。右パネルに施設・アクション・戦闘ログ・メッセージ。

### 戦闘・探索

```
POST /battle/areas/{area}/explore   通常探索（POST → PRGパターンで GET /battle/result へ）
POST /battle/areas/{area}/boss      ボス戦
GET  /battle/result                 戦闘結果表示（session から battleData 取得）
POST /battle/return                 街へ帰還
POST /battle/pvp-random             闘技場ランダムマッチ
POST /battle/pvp/{targetCharacter}  指定プレイヤーへ挑戦
GET  /battle/pvp-result             PvP結果
```

### 装備・ショップ・インベントリ

```
GET  /shop/equipment, /shop/weapons, /shop/armors, /shop/accessories  各ショップ
POST /shop/items/{item}/buy         購入
GET  /equipment                     装備変更画面
POST /equipment/{characterItem}/equip / unequip / lock / store / unstore
GET  /inventory                     持ち物（装備・素材・消耗品）
POST /inventory/sell                売却
DELETE /inventory/materials/{characterMaterial}   素材破棄
```

### クラフト・素材

```
GET  /blacksmith                    鍛冶屋（装備強化）
POST /blacksmith/{characterItem}/enhance
GET  /smith                         合成屋
POST /smith/craft
GET  /smith/disassemble             分解屋
POST /smith/disassemble/{characterItem}
GET  /material-exchange             素材交換所
POST /material-exchange, /material-exchange/bulk
```

### 職業・スキル・成長

```
GET  /jobs                          転職所（Livewire: JobChange）
GET  /bonus-points                  能力割振り
POST /bonus-points/allocate
GET  /titles                        称号一覧（Livewire: TitleList）
GET  /monster-marks                 印図鑑
```

### ヴァルモン（ペットシステム）

```
GET  /valmons/starter               スターター選択
POST /valmons/starter
GET  /valmons                       所持ヴァルモン一覧
POST /valmons/{valmon}/partner      相棒設定
POST /valmons/{valmon}/feed/materials/{characterMaterial}   素材で給餌
POST /valmons/{valmon}/feed/equipment/{characterItem}       装備で給餌（オーブ化）
POST /valmons/{valmon}/feed/equipment-bulk                  一括給餌
```

### チャンプ戦・施設

```
GET  /champ/confirm                 チャンプ挑戦確認
POST /champ/challenge
GET  /champ/result
GET  /inn （POST /inn/rest）        宿屋（HP/MP回復）
GET  /tavern                        タバーン（NPC会話）
GET  /tavern/npcs/{npc}/talk
GET  /association （POST /association/donate）  冒険者協会（ギルド寄付）
GET  /ranking                       ランキング
GET  /message                       メールボックス（Livewire: MessageBox）
GET  /guide                         案内所
```

### 輝石（課金）システム

```
GET  /kiseki/shop                   輝石ショップ
GET  /kiseki/support                サポート・レスキュー保険
POST /kiseki/checkout               Stripe決済セッション生成
POST /kiseki/support/purchase       サポート商品購入
GET  /kiseki/success, /kiseki/cancel  決済結果
POST /stripe/webhook                Stripe Webhook受信
```

### 管理画面

`/admin` 配下にLivewireベースの管理コンソール（マスターデータ管理・プレイヤー操作・ログ閲覧・戦闘シミュレーター等）。

---

## 3. データモデル（主要テーブル）

### ユーザー・キャラクター

| モデル | 主なカラム | 備考 |
|---|---|---|
| `User` | id, email, google_id, ... | Google OAuth / メール認証 |
| `Character` | id, user_id, name, level, exp, money, hp_base, attack_base, defense_base, magic_base, spirit_base, speed_base, luck_base, current_hp, current_mp, current_city_id, last_battle_at, bonus_points | **`money`カラム**（`gold`ではない）。基礎値のみDB保存 |

`User` は `hasMany` `Character`。取得は必ず `Auth::user()->characters()->first()` を使う（`->character` は不可）。

### 職業関連

| モデル | 主なカラム |
|---|---|
| `JobClass` | id, name, hp_rate, atk_rate, def_rate, mag_rate, spr_rate, spd_rate, luk_rate, bonus_hp, bonus_mp, bonus_str, ... |
| `CharacterJob` | character_id, job_class_id, job_level, job_exp, is_mastered, mastered_at |
| `JobRequirement` | job_id, required_job_id, required_level |
| `JobExpTable` | job_id, level, required_exp |
| `JobMasterBonus` | job_id, character_level_threshold, hp_bonus, str_bonus, def_bonus, ... |
| `Skill` | job_id, name, skill_type, effect_type, power, sp_cost_rate, activation_rate, element |
| `JobSkill` | job_id, skill_id |

### エリア・敵

| モデル | 主なカラム |
|---|---|
| `City` | id, name, order, unlock_condition |
| `Area` | id, city_id, name, recommended_level, boss_enemy_id |
| `Enemy` | id, area_id, name, hp, attack, defense, exp, money, appearance_weight, type_name |
| `EnemyDrop` | enemy_id, item_id, drop_rate, rarity |
| `CharacterAreaProgress` | character_id, area_id, boss_cleared, exploration_point |

### 装備・アイテム

| モデル | 主なカラム |
|---|---|
| `Item` | id, name, type（weapon/armor/accessory）, rarity（normal/rare/epic/legend）, required_level, required_job_id, hp_bonus, str_bonus, def_bonus, agi_bonus, mag_bonus, spr_bonus, luk_bonus, price |
| `CharacterItem` | character_id, item_id, is_equipped, equipped_slot, enhance_level, is_stored, is_locked |
| `Material` | id, name, description |
| `CharacterMaterial` | character_id, material_id, quantity |
| `Recipe` | id, result_item_id, required_materials（JSON） |

### 探索・戦闘ログ

| モデル | 主なカラム |
|---|---|
| `CharacterExplorationState` | character_id, area_id, exploration_point, chain_count, danger_rate, started_at |
| `BattleLog` | character_id, area_id, result（win/lose）, exp_gained, money_gained |
| `PublicLog` | type, message, character_id |
| `ArenaRanking` | character_id, rank, score, wins, losses |
| `ArenaLog` | attacker_id, defender_id, result, score_change |
| `ChampState` | character_id, player_name, level, job_name, max_hp, ... |
| `ChampBattleLog` | challenger_id, result, ... |

### ヴァルモン

| モデル | 主なカラム |
|---|---|
| `ValmonMaster` | id, name, base_stats, evolution_stages |
| `PlayerValmon` | character_id, master_id, level, exp, affection, evolution_stage, is_partner |
| `PlayerValmonEgg` | character_id, master_id, found_at |
| `ValmonSpawnRegion` | area_id, master_id, spawn_rate |
| `ValmonFeedLog` | valmon_id, feed_type, amount |

### システム・課金

| モデル | 主なカラム |
|---|---|
| `Character`（関連） | kiseki, paid_kiseki, free_kiseki |
| `StripeOrder` | character_id, stripe_session_id, amount, status |
| `KisekiTransaction` | character_id, amount, type（purchase/drop/use） |
| `GameSetting` | key, value |
| `TopUpdate` | title, body |
| `ContactMessage` | user_id, subject, body, replied_at |

### モンスターマーク（印図鑑）

| モデル | 主なカラム |
|---|---|
| `MonsterMark` | id, enemy_type, name, bonus_stat, bonus_per_level |
| `CharacterMonsterMark` | character_id, mark_id, quantity, unlocked_level |

### 称号

| モデル | 主なカラム |
|---|---|
| `Title` | id, name, condition_key, condition_value |
| `CharacterTitle` | character_id, title_id, earned_at |

---

## 4. サービス層（45クラス）

### 戦闘・探索

| サービス | 責務 |
|---|---|
| `BattleService` | 自動ターン制戦闘の全処理（ダメージ計算・スキル発動・勝敗判定） |
| `ExplorationService` | 探索メイン（敵抽選→戦闘→ドロップ→成長→イベント処理）、結果をsessionに保存しredirect |
| `ExplorationStateService` | 探索状態（危険度・連戦数・探索度）の読み書き |
| `BattleLogService` | 戦闘ログ保存 |
| `ExplorationItemService` | 探索アイテム（トラップ対策等）の使用処理 |
| `PvPBattleService` | PvP戦闘実行・ELOレーティング更新 |
| `ChampBattleService` | チャンプ戦専用（10分クールタイム、チャンプ交代処理） |

### キャラクター成長

| サービス | 責務 |
|---|---|
| `CharacterStatusService` | 最終ステータス計算（基礎値＋職業補正＋装備補正＋印ボーナス） |
| `LevelService` | EXP付与・レベルアップ処理・ボーナスポイント付与 |
| `JobService` | 転職可否判定・ジョブEXP付与・ジョブレベルアップ・マスター判定 |
| `CharacterJobChangeService` | 転職実行（装備チェック・自動解除） |
| `CharacterService` | キャラクター作成・基本情報管理 |
| `BonusPointService` | ボーナスポイント割振り |

### 装備・アイテム

| サービス | 責務 |
|---|---|
| `EquipmentService` | 装備変更・解除・一覧取得 |
| `EquipmentEnhancementService` | 装備強化（強化レベル上昇） |
| `EquipmentEvolutionService` | 装備進化（素材消費で上位版へ） |
| `EquipmentDecompositionService` | 装備分解（素材化） |
| `EquipmentAutoUnequipService` | 転職時等に不適切な装備を自動解除 |
| `EquipmentPermissionService` | 装備適性チェック（必要レベル・職業制限） |
| `DropService` | ドロップ抽選・付与（装備・素材・輝石） |
| `KisekiDropService` | 輝石ドロップ処理 |
| `ShopService` | ショップ購入・売却・在庫管理 |
| `MaterialExchangeService` | 素材交換（種別変換・スロット管理） |
| `DailySupplyService` | 日程サプライ（毎日の無料配布） |

### 特殊システム

| サービス | 責務 |
|---|---|
| `MonsterMarkService` | 印ドロップ判定・図鑑管理・永続ボーナス計算 |
| `ValmonService` | ヴァルモン管理（卵発見・孵化・給餌・進化・相棒） |
| `TitleUnlockService` | 称号アンロック判定 |
| `TitleService` | 称号管理・表示 |
| `BeginnerMissionService` | 初心者ミッション進捗・報酬付与 |
| `SecretRealmService` | 秘密の領域（特殊エリア） |
| `AdventureSupportService` | 冒険サポート・レスキュー保険発動 |

### 施設・UI補助

| サービス | 責務 |
|---|---|
| `AreaService` | エリア管理（解放条件・推奨レベル） |
| `InnService` | 宿屋（HP/MP回復） |
| `GuildService` | 冒険者協会（ギルド寄付） |
| `TavernNpcService` | タバーンNPC会話 |
| `PublicLogService` | 全体ログ作成・表示 |
| `CityThemeService` | 都市テーマ・背景画像管理 |
| `StorageCapacityService` | 倉庫容量管理 |
| `CharacterGoalService` | 次の目標表示 |
| `CharacterProfileService` | プロフィール表示 |

### システム・管理

| サービス | 責務 |
|---|---|
| `AuthService` | Google OAuth・ユーザー作成 |
| `GameSettingService` | ゲーム設定フラグ管理 |
| `AccountDeletionService` | アカウント削除 |
| `ContactMailboxImportService` | お問い合わせメール取り込み |
| `ContactMailReplyService` | お問い合わせ返信 |

---

## 5. 戦闘システム詳細

### 全体フロー（`BattleService::executeBattle`）

```
1. EquipmentAutoUnequipService で不適切装備を事前解除
2. BattleActor（DTO）を生成
   - プレイヤー: CharacterStatusService::getFinalStats() の最終値
   - 敵: 基本値 + 危険度補正（最大25%強化）
3. 先攻判定: プレイヤーAGI + rand(0,5) vs 敵AGI + rand(0,5)
4. ターンループ（最大20ターン）:
   a. 攻撃側がスキル発動を試みる（MP確認 + 確率判定）
   b. DamageCalculator でダメージ算出
   c. 回避判定
   d. ダメージ適用（BattleActor::takeDamage）
   e. 勝敗判定（isDead チェック）
5. 結果処理:
   - 勝利: EXP・ジョブEXP・ドロップ授与
   - 敗北: HP30%回復・MP10%回復（ゴールド・装備喪失なし）
   - 時間切れ: 20ターン超過で双方疲弊扱い
```

### ダメージ計算（`DamageCalculator`）

```
物理ダメージ = max(1, (STR - DEF × 0.5) × 乱数(0.8〜1.2)) × クリティカル倍率
魔法ダメージ = max(1, (MAG × スキル倍率 - SPR × 0.2)) × クリティカル倍率

会心率     = 5% + LUK × 0.2%（上限25%）、会心倍率 = 1.5×
回避率     = 5% + (防御側AGI - 攻撃側AGI) × 0.5%（下限3%・上限20%）
```

### 敵AI（`type_name` で分岐）

| type_name | 行動パターン |
|---|---|
| 魔法型 | 70%魔法・30%物理 |
| 耐久型/重装型 | 25%防御・75%物理 |
| 高速型 | 20%連続攻撃・80%通常 |
| アンデッド型 | 30%吸収攻撃・70%通常 |
| 竜型 | 30%ドラゴンブレス・70%強物理 |
| 物理型/標準型 | 100%物理 |

ボスはさらに15%の確率で大技（1.8倍ダメージ）。

### 戦闘関連クラス構成（`app/Services/Battle/`）

```
BattleActor       プレイヤー・敵の戦闘時DTO（hp, mp, str, def, agi, mag, spr, luk）
BattleState       ターン数・ログ・ドロップボーナス率を管理
BattleResult      戦闘結果（勝敗・ログ・獲得物）
DamageCalculator  ダメージ・会心・回避の計算ロジック
BattleTypeAffinity 属性相性
```

---

## 6. ステータス計算システム（`CharacterStatusService`）

### 計算パイプライン

```
最終ステータス = 基礎値 + 職業補正 + 装備補正 + 印図鑑ボーナス

① 基礎値（DBのCharacterカラム）
   Character.hp_base, attack_base, defense_base, magic_base, spirit_base, speed_base, luck_base

② 職業補正（JobService::calculateFinalStats）
   現在ジョブのレート × ジョブレベル
   + 過去マスター済み全職の JobMasterBonus（永続累積）

③ 装備補正
   装備中CharacterItem × Item.{hp/str/def/agi/mag/spr/luk}_bonus
   + 強化レベルに応じた追加補正
   ※ sub_type が ['印','刻印','王印','神印'] の旧装備は除外（印図鑑に統合済み）

④ 印図鑑ボーナス（MonsterMarkService::permanentBonuses）
   所持量に応じた unlocked_level（1/3/7/15個で段階解放）× bonus_per_level
```

---

## 7. 探索・危険度システム（`ExplorationService` / `ExplorationStateService`）

### 探索フロー

```
POST /battle/areas/{area}/explore
  ↓
ExplorationService::explore(Character, areaId, isBossBattle)
  1. クールタイム確認（last_battle_at で3秒）
  2. HP確認（0の場合は探索不可）
  3. 敵抽選（appearance_weight ベースの重みつき乱択）
  4. 特別イベント判定（宝物・ダンジョンロード・隠し要素）
  5. BattleService::executeBattle
  6. 勝利時:
     - LevelService で EXP・ジョブEXP付与（レベルアップ処理含む）
     - DropService・KisekiDropService・MaterialDropService でドロップ抽選
     - MonsterMarkService で印判定
     - ValmonService で卵発見判定（探索度依存）
     - TitleUnlockService で称号チェック
     - ボス戦の場合: CharacterAreaProgress.boss_cleared = true → 次エリア解放
  7. 敗北時:
     - HP/MP回復（30%/10%）
     - 一部素材ペナルティ
     - AdventureSupportService でレスキュー保険チェック
  8. session('battleData') に結果保存 → redirect('/battle/result')
```

### 危険度・探索度

```
危険度(danger_rate):
  連続戦闘ごとに上昇 → 敵ステータス最大25%強化
  100未満: 通常 / 100〜249: 危険 / 250〜399: 非常に危険 / 400+: 極度に危険

探索度(exploration_point):
  敵撃破ごとに5〜15pt加算
  一定値到達でヴァルモン卵発見率UP・宝物イベントトリガー

連戦数(chain_count):
  同エリア内での連続勝利数（ドロップボーナスに影響）
```

---

## 8. 職業システム

### 職業一覧と転職条件

**基本職（4種・初期選択可）**

| 職業 | 特徴 |
|---|---|
| 戦士 | HP・STR・DEF重視 |
| 魔法使い | MAG重視 |
| 僧侶 | SPR重視・回復安定型 |
| 盗賊 | AGI・LUK重視 |

**上位職（転職条件あり）**

| 職業 | 条件 |
|---|---|
| ナイト | 戦士マスター |
| 賢者 | 魔法使い + 僧侶マスター |
| アサシン | 盗賊マスター |
| 魔法剣士 | 戦士 + 魔法使いマスター |

上位職のさらに上に拡張職・伝説職も実装済み（条件は JobRequirement テーブルで管理）。

### ジョブレベルアップ・マスター

```
ジョブEXP付与
  → job_level が jobMax に達したら is_mastered = true, mastered_at 記録
  → JobMasterBonus が以後のステータス計算で永続加算される
  → 転職条件の required_job_id が解放される
```

転職時: キャラレベル・所持金・装備・全ジョブ経験値は維持。EquipmentAutoUnequipServiceが不適合装備を自動解除。

### スキル発動（`Skill`）

```
発動率 = base_rate + ジョブレベル補正
MP確認（sp_cost_rate × MAX_MP が現在MPを超える場合は不発）
→ 発動: effect_type に応じてDamageCalculatorが計算（damage/heal/defense等）
```

---

## 9. 装備システム

### 装備スロット

| スロット | 種別 |
|---|---|
| `weapon` | 武器（Item.type = 'weapon'） |
| `armor` | 防具（Item.type = 'armor'） |
| `accessory` | アクセサリー（Item.type = 'accessory'） |

同スロットに1つのみ装備可能。装備変更時は旧装備が自動解除される。

### 装備適性チェック（`EquipmentPermissionService`）

```
required_level  > character.level  → 装備不可
required_job_id != 現在のjob_class_id → 装備不可
is_locked = true → 売却・分解不可（意図的なロック保護）
```

### 強化・進化・分解

| 操作 | 処理 | 記録 |
|---|---|---|
| 強化（Enhance） | enhance_level++ / 費用（金or素材）消費 / 成功率判定 | EquipmentEvolutionLog |
| 進化（Evolve） | 素材消費 → evolution_stage 更新 → 新CharacterItem作成・旧削除 | EquipmentEvolutionLog |
| 分解（Decompose） | CharacterItem削除 → CharacterMaterial × 複数個追加 | EquipmentDecompositionLog |

---

## 10. ドロップシステム

各ドロップ枠は独立して抽選される（複合ドロップ）。

### 抽選枠

| 枠 | サービス | 条件・備考 |
|---|---|---|
| 装備 | `DropService` | EnemyDropテーブルのdrop_rate × レアリティ別ボーナス |
| 素材 | `MaterialDropService` | 素材ドロップテーブルを参照 |
| 輝石 | `KisekiDropService` | エリアLv依存の確率 |
| モンスターマーク（印） | `MonsterMarkService` | 8〜20%で印ドロップ |
| ヴァルモン卵 | `ValmonService` | 探索度依存の確率 |

### ドロップ率目安

| レアリティ | ドロップ率 |
|---|---|
| normal | 5〜10% |
| rare | 1〜3% |
| epic | 0.3〜1% |
| legend | 0.05〜0.2% |

---

## 11. モンスターマーク（印図鑑）システム

### フロー

```
敵撃破 → MonsterMarkService::rollAndGrant(Character, Enemy)
  ↓
印が存在するか確認（markForEnemy）
  ↓（存在する場合）
8〜20%の確率でドロップ判定
  ↓（当選）
CharacterMonsterMark.quantity++
  → unlocked_level を段階更新（1/3/7/15個で1/2/3/4段階）
  → 段階ごとに bonus_stat のボーナス値が上昇
```

### 永続ボーナス計算

```
MonsterMarkService::permanentBonuses(Character)
  → 全所持印の quantity × unlocked_level × bonus_per_level を集計
  → 結果: { 'hp' => 50, 'str' => 30, ... }
  → CharacterStatusService::getFinalStats() 内で加算
```

---

## 12. ヴァルモンシステム

### ヴァルモンの生涯

```
1. スターター選択
   ValmonService::chooseStarter(Character, masterId)
   → PlayerValmon 作成（is_partner=true）

2. 卵の発見
   ValmonService::tryFindEgg(Character, Area, ExplorationState)
   → 探索度に基づく発見率 → PlayerValmonEgg 作成

3. 自動孵化
   街タブへ戻ったタイミング（MainScreen::changeLocation）
   → PlayerValmonEgg → PlayerValmon に変換

4. 給餌・成長
   feedMaterial: 素材で給餌 → exp + affection 増加
   feedEquipment: 装備をオーブ化して給餌 → 高効率
   → evolution_stage 進化判定（child → middle → adult → ...）

5. 相棒（is_partner）
   相棒のみが素材発見・探索ボーナスを付与
   給餌時に +20% EXPブースト
```

---

## 13. チャンプシステム

### チャンプの定義

`ChampState` にランキング1位プレイヤーの情報（name, level, job, ステータスのスナップショット）を常時保持。誰でも挑戦可能（10分クールタイム）。

### 挑戦フロー

```
POST /champ/challenge
  ↓
ChampBattleService::executeChallenge(challenger)
  1. 10分クールタイムチェック
  2. BattleService で戦闘実行
  3. 敗北時: EXP × 0.7 × ランク差補正 + ジョブEXP
  4. 勝利時: EXP × 1.5 × ランク差補正 + 大量素材 → ChampState 更新
  5. ChampBattleLog 記録 → PublicLog「チャンプ誕生」
```

---

## 14. PvP（闘技場）システム

### マッチング

ランダムPvPは自分より1〜3ランク上の相手を選定。指定プレイヤーへの挑戦も可能。

### 結果処理

```
PvPBattleService::executeBattle(attacker, defender)
  → 両者のステータスをスナップショット取得
  → BattleService と同等の戦闘処理
  → ArenaRanking を ELOライク計算で更新
  → ArenaLog に記録
```

---

## 15. 輝石（課金）システム

### 輝石の種類

```php
Character.kiseki       // 総保有量
Character.paid_kiseki  // 購入分（課金）
Character.free_kiseki  // 無料分（ドロップ・配布）
```

### Stripe決済フロー

```
1. KisekiShopController::createCheckout()
   → Stripe Checkout セッション生成 → ユーザーをStripeページへリダイレクト

2. Stripe決済完了

3. StripeWebhookController::handle()
   → charge.succeeded イベント受信
   → StripeOrder 作成
   → KisekiTransaction 作成
   → Character.paid_kiseki 加算
```

### 主な輝石消費先

- サポート商品（レスキュー保険・アドベンチャーサポート）
- 輝石ショップでの特別アイテム購入
- 牧場背景変更（ヴァルモン牧場カスタマイズ）
- 将来実装: 装備スロット拡張等

---

## 16. その他の実装済みシステム

### 初心者ミッション（`BeginnerMissionService`）

チュートリアル段階的ミッション（初戦闘・初レベルアップ・初転職など）。完了フラグを `Character.beginner_mission_completed_keys`（JSON配列）で管理。完了時に金・装備・素材を報酬付与。

### 称号システム（`TitleUnlockService`）

`Title` テーブルの `condition_key/condition_value` に基づいて解放判定。`CharacterTitle` に記録。称号一覧は `TitleList`（Livewire）で閲覧可能。

### 冒険者協会（ギルド寄付）

`GuildService` が寄付処理を担当。寄付累計に応じたランク・特典管理。

### タバーン（NPC会話）

`NpcMaster` テーブルにNPC定義。`TavernNpcService` で会話進行・`PlayerNpcEncounter` で初回遭遇フラグ管理。

### 公開ログ（`PublicLogService`）

全プレイヤーに見えるリアルタイムログ。種別: `rare_drop`（レアドロップ）/ `growth`（Lv10/50/100到達）/ `job_change`（上位職転職）/ `monster_mark`（印コンプリート）/ `valmon`（ヴァルモン発見）/ `champ`（チャンプ誕生）。

### 宿屋（`InnService`）

`POST /inn/rest` でHP/MPを全回復（金消費）。

### 素材交換所（`MaterialExchangeService`）

素材を別種素材へ変換。レート・スロット数はテーブル管理。一括交換も対応。

---

## 17. 設計パターン・重要規約

### PRGパターン（二重送信防止）

```php
// 全戦闘・フォームPOSTに適用
$result = $service->execute(...)
session()->flash('battleData', $result)
return redirect()->route('battle.result')

// GET側
$data = session('battleData')
return view('battle.result', compact('data'))
```

### ステータス計算の三層分離

```
DBには基礎値のみ保存（Character.hp_base 等）
計算時に合算: 基礎値 + 職業補正(ジョブLv依存) + 装備補正 + 印ボーナス
→ CharacterStatusService::getFinalStats() が唯一の真実
```

### DBトランザクション・楽観的ロック

```php
DB::transaction(function () {
    $character = Character::lockForUpdate()->findOrFail($id)
    // 更新処理
})
```

### 命名規則

| 対象 | 規則 |
|---|---|
| Eloquentモデル（DBカラム） | スネークケース（`max_hp`, `current_mp`） |
| DTO・Battle系クラス | キャメルケース（`maxHp`, `currentMp`） |
| `money`カラム | `Character.money`（`gold`ではない） |

### キャラクター取得

```php
// ❌ 不可: User は hasMany Character
Auth::user()->character

// ✅ 正しい
Auth::user()->characters()->first()
Auth::user()->currentCharacter()  // ヘルパーが存在する場合
```

### 同名エンティティの特定

同名の敵・素材が別ダンジョンに存在することがある。`name` だけで検索するとバグになるため、`area_id`（`dungeon_id`）との複合条件で一意特定する。

### クールタイム

`Character.last_battle_at` で3秒クールタイムチェック（探索・ボス戦・PvP共通）。チャンプ戦は10分クールタイム。

### Livewireイベント連携

```php
#[On('character-updated')]
public function refreshData() {
    $this->character = Auth::user()->currentCharacter()
}
```

---

## 18. UI・デザイン規則

| 規則 | 詳細 |
|---|---|
| 施設画面 | `<x-layouts.facility>` で全画面表示に統一 |
| 施設ヘッダー | 背景画像 + 暗いオーバーレイ(`bg-slate-900/60`) + 白テキスト |
| 「街へ戻る」ボタン | レイアウト共通部品に配置（各ビューに個別配置しない） |
| Tailwind注意 | `min-h-[...]` や `flex-wrap` はカードの縦幅不均一を招くので注意 |
| PC施設グリッド | `md:` ブレークポイントで2列表示 |

---

## 19. デプロイ手順

```powershell
php local_deploy.php
```

1. `npm run build` でアセットビルド
2. プロジェクトをZIP圧縮（`.git` / `vendor` / `node_modules` / `storage` / `.env` / `backups/` を除外）
3. `valzeria.com/server_deploy_api.php` へPOST送信
4. サーバー側で展開 → マイグレーション自動実行 → Seeder自動実行

**注意**: マイグレーションファイルをリネームした場合、旧ファイルがサーバーに残り二重実行エラーになる。旧ファイル名で空の `up()`/`down()` を持つダミーを作成して上書きデプロイすること。

---

## 20. 主要ドキュメント一覧

| ファイル | 内容 |
|---|---|
| `docs/base.md` | ゲーム仕様書（初期設計の原典） |
| `docs/project_knowledge.md` | 過去の教訓・バグ事例集 |
| `docs/ai_development_rules.md` | 統合済みリダイレクトスタブ（実体は AGENTS.md + docs/dev-os/ + docs/DOMAIN_RULES.md） |
| `docs/valzeria_battle_system_implementation.md` | 戦闘システム詳細 |
| `docs/valzeria_job_change_system_implementation.md` | 職業システム詳細 |
| `docs/valzeria_equipment_job_restriction.md` | 装備適性ルール |
| `docs/valzeria_tavern_npc_system_implementation.md` | NPC会話システム |
| `docs/valzeria_chain_battle_exploration_system.md` | 連戦・探索システム |
| `app/Livewire/MainScreen.php` | 施設・タブ構成の定義（コード） |
| `app/Services/BattleService.php` | 戦闘ロジック中枢（コード） |
| `app/Services/CharacterStatusService.php` | 最終ステータス計算（コード） |
