# 実装指示書: 技効果のデータ駆動化（説明文パース廃止）＋必殺技の乖離修正

> 実行順序: **TASK_E（本書）→ TASK_F → TASK_G** の順で実施すること。本書が前提エンジン。

## 目的
必殺技・奥義の効果分岐が説明文の `str_contains` に依存しており、文言と実効果の乖離が多発している。効果を構造化フィールド（DBカラム）駆動に変え、説明どおりに動かない6件の必殺技を修正する。

## 背景
- 効果テンプレの正: `app/Support/JobArtEffectCatalog.php`
- 説明文パースの現状: `BattleService` / `ChampBattleService` / `PvPBattleService` / `ArenaNpcBattleService` の4箇所に `str_contains($skill->description, 'LUKに応じて')` 等が複製されている（grep `str_contains` で特定可能）
- 乖離の全リストは TASK_F 参照

## 現状の問題（必殺技側の確定バグ）
1. 軍師「勝利の采配」・吟遊詩人「勇気の旋律」: `damage_type='support'` がダメージ分岐に無く、**説明にある攻撃が発動しない**（`BattleService::executeSkillAction` L781-793）
2. 賢商王「王者の秘薬」(`heal`)・古代錬成王「神代錬成」(`magical`): 報酬補正ブロックが `damage_type in ['gold','drop']` 限定のため、**素材/レア判定UPが発動しない**（L796-802）
3. 深淵歩き「深淵崩壊」: 説明は「最高火力依存」だがコードは文字列「高い方依存」を要求 → **平均値依存で動いている**
4. 時空王「クロノブレイク」: 追加攻撃判定がヒットループ内にあり、理論上無限連鎖する
5. 幻影王「夢幻殺」「敵命中低下」・魔盗士「消費SPの一部回復」: 実装に存在しない表現

## 実装対象

### 1. migration: skillsテーブルにカラム追加
```
enemy_atk_down_percent   unsignedInteger default 0
enemy_mag_down_percent   unsignedInteger default 0
rare_bonus_percent       unsignedInteger default 0
drain_hp_rate            decimal(4,2)    default 0    // 与ダメ吸収率 0.35等
extra_hit_chance_percent unsignedInteger default 0    // 追加攻撃確率
luk_power_rate           decimal(4,2)    default 0    // 威力へのLUK加算率
hybrid_scaling           string(16)      default 'average'  // 'average'|'max'
self_buff_percent        unsignedInteger default 0    // 使用時 自バフ%（specials用）
```
- `app/Models/Skill.php` の `$fillable` と `$casts` を同時更新（CLAUDE.mdルール）
- down() でカラムdrop可能にする

### 2. `BattleService::executeSkillAction`（必殺技）の書き換え
以下の `str_contains` を全廃し、構造化フィールドに置換:

| 現行（削除） | 置換後 |
|---|---|
| `str_contains(description,'LUKに応じて')` → +luk*0.5 | `luk_power_rate > 0` → `skillPowerInt += (int)($attacker->luk * $skill->luk_power_rate)` |
| `str_contains(description,'確率で追加')`（ループ内） | `extra_hit_chance_percent > 0` → **ループ開始前に1回だけ**判定し、成功時 `$hitCount++`（連鎖廃止） |
| `str_contains(description,'高い方依存')` | `hybrid_scaling === 'max'` → `max($attacker->str, $attacker->mag)` |
| `str_contains(description,'レア判定UP')` | `rare_bonus_percent > 0` → `$state->rareBonusPercent = max(現値, rare_bonus_percent)` |
| `damage_type==='support' && str_contains(description,'上昇')` の固定5%バフ | `self_buff_percent > 0` → str/mag に `base * pct/100` 加算（上限1.5倍は現行踏襲） |

さらに:
- **ダメージ分岐の physical リストに `'support'` を追加**（`['physical','gold','drop','support']`）→ 軍師・吟遊詩人の攻撃が発動するようになる
- **報酬ブロックの `damage_type` ゲートを撤廃**: `gold_bonus_percent>0` / `drop_bonus_percent>0` / `rare_bonus_percent>0` をそれぞれ独立に適用 → 賢商王・古代錬成王が直る

### 3. 同一修正を残り3サービスへミラー
- `ChampBattleService`（L602, 636, 706, 735 付近）
- `PvPBattleService`（L422, 427, 465, 540, 565 付近）
- `ArenaNpcBattleService`（L285, 319, 370, 393 付近）

`canActivateRecoveryArt`（`BattleService` L546 と `JobArtBattleSupportService` L152）の `DRAIN && str_contains('HP')` は `drain_hp_rate > 0` に置換。

### 4. 奥義実行の汎用サイドエフェクトパイプライン（`executeJobArtAction` 系）
テンプレ実行後に以下を順に適用する共通処理を追加（4サービス共通。PvE は `BattleService`、他は各サービスの `applyJobArtTemplateEffects` 相当へ）:

1. `heal_percent > 0` → `maxHp * pct/100` 回復（HEALテンプレのSPR回復とは併用しない。HEALテンプレ時はスキップ）
2. `mp_recover_percent > 0` → 既存 `recoverJobArtSp` を全テンプレに一般化
3. `self_damage_percent > 0` → `maxHp * pct/100` 自傷（ログ「反動により〜」は必殺技側の文言を流用）
4. `damage_reduction_percent > 0` → `damageReductionRate = max(現値, 値)`（上限25）
5. 構造化デバフ: `enemy_atk/mag/def/spr/spd_down_percent > 0` → 対象ステータスを `base * pct/100` 減算（下限1）。**ボス(`is_boss`)は効果×0.5**（必殺技側と統一。既存奥義のボス戦が実質弱体化する点は承認済み前提）。継承奥義（origin=inherited）は効果値に `jobArtRates`（0.85等）を乗算し床関数、最低1%
6. DRAINテンプレ: `drain_hp_rate > 0` → 与ダメ×rate×継承rate を回復（`str_contains('HP')` 廃止）
7. `applyEnemyDebuff` のフォールバック（DEF/SPR固定低下）は**構造化フィールドが全て0の場合のみ**動作させる（TASK_F完了までの互換）
8. `applyTimeControl`: `enemy_spd_down_percent` が設定されていればその値、なければ現行 `buffRate`

### 5. ダメージ系テンプレのhit_count対応
- 対象: `PHYSICAL_DAMAGE / MAGICAL_DAMAGE / HYBRID_DAMAGE / MULTI_HIT / DAMAGE_BUFF / MAGICAL_DAMAGE_BUFF / DAMAGE_DEBUFF / DAMAGE_GUARD_BARRIER / PHYSICAL_DAMAGE_REWARD / MAGICAL_DAMAGE_REWARD`
- `hits = max(1, (int)$skill->hit_count)`（DB値優先。0ならカタログ既定）
- 1Hitあたり威力 = `max(60, round(power / hits))`、Hitごとに命中判定、敵死亡でbreak（既存 `executeMultiHitJobArt` を一般化して置換）
- `def_ignore_percent > 0` → `overrideDef = def*(1-pct/100)`（魔法はSPR側）を計算式に渡す。`executePhysicalAttack/executeMagicalAttack` にオプション引数を追加するか、専用パスで実装

### 6. Seeder更新
- `database/seeders/JobArtSeeder.php`: json行の `hit_count / heal_percent / self_damage_percent / damage_reduction_percent / enemy_atk_down_percent / enemy_mag_down_percent / enemy_def_down_percent / enemy_spr_down_percent / enemy_spd_down_percent / drain_hp_rate / def_ignore_percent / rare_bonus_percent` をマッピング（未指定は現行どおりカタログ既定/0）。**`heal_percent` を0固定にしている行を撤去**
- `database/seeders/SkillSeeder.php`: `luk_power_rate / extra_hit_chance_percent / hybrid_scaling / rare_bonus_percent / self_buff_percent` をマッピング

### 7. 必殺技マスタ `database/data/job_special_skills.php` の変更（数値確定・変えない）

| job_key | 追加フィールド | description変更 |
|---|---|---|
| thief（不意打ち） | `luk_power_rate: 0.5` | なし |
| samurai（居合斬り） | `luk_power_rate: 0.5` | なし |
| sword_master（無双一閃） | `luk_power_rate: 0.5` | なし |
| abyss_walker（深淵崩壊） | `hybrid_scaling: 'max'` | なし（「最高火力依存」が真になる） |
| time_space_king（クロノブレイク） | `extra_hit_chance_percent: 30` | なし |
| golden_merchant（ゴールドラッシュ） | `rare_bonus_percent: 8` | なし |
| merchant_sage_king（王者の秘薬） | `rare_bonus_percent: 8` | なし |
| ancient_alchemist_king（神代錬成） | `rare_bonus_percent: 8` | なし |
| bard（勇気の旋律） | `self_buff_percent: 5` | なし |
| phantom_king（夢幻殺） | なし | 「1.90倍攻撃。敵SPDを10%低下」（命中の文言を削除） |
| magic_thief（スピリットスティール） | なし | 「1.45倍魔法攻撃。最大SPの5%回復」 |

## 実装対象外（重要）
- job_arts.json のデータ・memo変更（TASK_Fで実施）
- ターン制バフ/デバフ管理・DoT・反撃・状態異常/解除システムの新規実装
- ダメージ計算式（`DamageCalculator`）の変更
- 依頼範囲外のリファクタリング・文言変更

## 変更範囲
- 想定: migration 1本、`app/Models/Skill.php`、`app/Services/{Battle,ChampBattle,PvPBattle,ArenaNpcBattle}Service.php`、`app/Services/JobArtBattleSupportService.php`、`database/seeders/{SkillSeeder,JobArtSeeder}.php`、`database/data/job_special_skills.php`
- 想定しない: `DamageCalculator.php`、ドロップ/Gold付与処理本体、Livewire/Blade

## 既存仕様への影響
- 軍師・吟遊詩人の必殺技が説明どおりダメージを与えるようになる（バフ方向・説明との一致化）
- 賢商王・古代錬成王・黄金商人の報酬UPが実際に効く
- 奥義デバフにボス半減が入る（ボス戦は現行よりわずかに討伐が遅くなる）
- 時空王の追加攻撃は連鎖しなくなる（最大+1Hit）

## DB変更
- [x] あり → 上記カラム追加。既存データはdefault 0/average で挙動不変。ロールバックは down() でカラムdrop

## テスト観点
- `tests/Unit/JobArtEffectCatalogTest.php` が通ること
- 新規Unit: support型必殺技がダメージを与える / heal型でもdrop・rare補正がstateに乗る / hybrid_scaling=max で max(str,mag) / extra_hit は1回のみ / 構造化デバフのボス半減 / hit_count=3 のダメージテンプレが3回攻撃する / drain_hp_rate>0 のみ吸収
- 既存 `php artisan test` 全通過

## 手動確認手順
1. 軍師キャラで戦闘 → 勝利の采配のログにダメージ行が出る
2. 賢商王で必殺技発動→勝利 → ドロップ判定に補正が乗る（`DropService` へ渡る `dropBonusPercent`/`rareBonusPercent` をログ/デバッガで確認）
3. ボス戦で奥義デバフ → ログの低下%が半分になっている

## ロールバック方針
- migration:rollback + 本タスクのコミットrevertで旧挙動（説明文パース）に完全復帰

## 完了条件
- [ ] 4サービスから技効果に関する `str_contains($skill->description, ...)` が全て消えている
- [ ] 上記6件の必殺技バグが解消（テストで担保）
- [ ] php -l 通過、`php artisan test` 通過
- [ ] docs同期: DOMAIN_RULES.md に「技効果は構造化フィールド駆動（説明文は表示専用）」を追記

## 更新情報サマリ案
- category: fixed
- title: 一部必殺技の効果が説明と異なる問題を修正
- detail: 軍師・吟遊詩人の必殺技で攻撃が発動しない、賢商王・古代錬成王の報酬アップが効かない等、説明文と実際の効果のズレを修正しました。
