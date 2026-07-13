# 実装指示書: 奥義フィードバック対応（ログ具体化・数値整合・回復スケール再設計）

> 出典: 2026-07 プレイヤーフィードバック（マスター済み職の奥義挙動レポート）。
> 原因調査は完了済み。本書は「決定済みの修正方針」であり、実装者は方針の再検討をせず本書の通りに実装すること。
> 判断に迷う差分が出た場合は実装を止めて報告する（勝手に仕様を発明しない）。

## 背景（要点のみ）

プレイヤー指摘24件を調査した結果、原因は5系統に分類された:

1. **継承倍率の説明不足**: 奥義はマスター職から継承すると `inherited_rate`(0.7〜0.85) が威力・効果量すべてに掛かる（`app/Services/JobArtService.php` availabilityFor()）。聖域展開20%→14%、ホーリーブレイド6%→4.2%、血潮の咆哮3%→2.55% は全て仕様通りだが、ゲーム内に説明が一切ない。
2. **memoとeffect_templateの乖離**: 説明文だけ豪華で実装が汎用テンプレートのまま（巨人断ちのHP比例、闘争本能のHP条件など）。
3. **エンジン実装バグ**: バリア軽減率のフォールバック計算に継承倍率が掛からない等。
4. **汎用ログが情報ゼロ**: 「戦闘力があがった」「守りが乱れた」ではどのステが何%変わったか分からない。
5. **回復量の下限バグ**: `applyJobArtHeal` の `max(80, power)` により、回復31相当〜78相当の全奥義が power=80 に均されている（回復奥義が横ばいな根本原因）。

## 対象ファイル

| ファイル | 修正内容 |
|---|---|
| `app/Services/BattleService.php` | ログ具体化・バリア計算・GUTS発動ログ・回復下限撤廃 |
| `app/Services/ChampBattleService.php` | 同上（同名メソッドの重複実装あり） |
| `app/Services/PvPBattleService.php` | 同上 |
| `app/Services/ArenaNpcBattleService.php` | 同上 |
| `app/Services/TowerBattleService.php` | takeDamage呼び出し箇所のGUTSログのみ |
| `app/Services/Battle/BattleActor.php` | GUTS発動フラグ追加 |
| `app/Services/Admin/SkillEffectPreviewService.php` | 回復下限撤廃・バリア計算をエンジンと同期 |
| `database/data/job_arts.json` | 個別データ修正（下表） |
| 奥義セット/一覧のBlade | 継承倍率の表示追加 |

> 注意: Champ/PvP/Arena/Preview の各サービスは BattleService とほぼ同じロジックをコピー実装している。**4〜5箇所全てに同じ修正を適用**しないと「探索では直ったがチャンプ戦では旧挙動」になる。修正後に `grep` で取り残しゼロを確認すること。

---

## Phase 1-A: 汎用ログの具体化

### applySelfBuff（BattleService.php:1085 ほか各サービス）

現状: 「{name} の戦闘力が高まった！」「{name} の魔力が高まった！」のみ。

変更: 実際に変動させたステータス名と%を表示する。`buffRate()` の戻り値(0.10/0.15/0.20)をそのまま%表示に使う。

```
物理側: 「{name} のATKが 15% / DEFが 7% 上昇した！」
魔法側: 「{name} のMAGが 15% / SPRが 7% 上昇した！」
```

- 副ステ（DEF/SPR）は rate/2 なので表示も半分（切り捨て、最低1%）。
- 上限1.5倍に到達して実際の上昇が0だった場合は「これ以上上がらない！」と表示する（無言で不発にしない）。
- 表記はプレイヤー向けステ名（ATK/DEF/MAG/SPR/SPD/LUK）を使う。STR/AGI等の内部名は使用禁止（CLAUDE.md準拠）。

### applyEnemyDebuff（BattleService.php:1100 ほか各サービス）

現状: 「{name} の守りが乱れた！」のみ。

変更: 「{name} のDEFが 15% / SPRが 7% 低下した！」形式。構造化デバフ（applyStructuredDebuffs）が既にこの形式なので文言・スタイルを揃える。

### applyTimeControl も同様に「SPDが n% 低下した！」へ。

---

## Phase 1-B: GUTS（踏みとどまり）発動時ログ

現状: 発動予告ログ（applyGuts）はあるが、実際に致死ダメージを耐えた瞬間（`BattleActor::takeDamage`、hp=1で耐えるパス）にログがない。

変更:
1. `BattleActor` に `public bool $gutsJustTriggered = false;` を追加し、takeDamage 内で耐えた時に true をセット。
2. 各バトルサービスで `takeDamage()` を呼んだ直後に以下を挟む共通処理を追加:
   ```
   if ($actor->gutsJustTriggered) {
       $state->addLog("<span class=\"text-orange-700 font-extrabold\">{$actor->name} は不屈の精神で致死ダメージを耐えた！（HP1）</span>");
       $actor->gutsJustTriggered = false;
   }
   ```
3. `grep -rn "->takeDamage(" app/Services` で全呼び出し箇所を洗い出し、プレイヤー/敵の別を問わず戦闘ログのある文脈すべてに適用する。

---

## Phase 1-C: バリア軽減率の計算統一（バグ修正）

### 問題

`jobArtGuardReduction`（BattleService.php:1124）:
- `damage_reduction_percent` 設定済み → `floor(configured × rate)` で**継承倍率が掛かる**
- 未設定 → `min(25, max(10, floor(power/10)))` で**継承倍率が掛からない**（rawのpowerを参照）

この非対称のせいで「金剛不壊（25%明記）は継承で17%に減るのに、大商隊の守護（明記なし、power255）は継承でも25%満額」という逆転が発生している。

### 修正

統一ルール: **基礎値を決めてから継承倍率を掛ける**。

```
基礎値 = damage_reduction_percent > 0 ? damage_reduction_percent
       : clamp(floor(power / 10), 10, 25)   // powerはskillのraw値
最終値 = min(25, max(1, floor(基礎値 × rate)))
```

- 同名ロジックが Champ/PvP/Arena/SkillEffectPreview にもある。**全箇所を同じ式に統一**すること。
- プレイヤー報告の「金剛不壊が10%と17%の2回発生」は、コンテキスト（探索/チャンプ等）でフォールバック計算が食い違っている可能性が高い。統一後、探索・チャンプ・PvP・塔の4文脈で同一スキル・同一rateなら同一%になることを確認する（受け入れ条件）。

---

## Phase 1-D: job_arts.json 個別データ修正

方針は TASK_F と同じ: 「①構造化フィールドで実装できる効果は実装」「②未実装システム（条件付き威力・ランダム弱体・追撃付与）への言及はmemoから削除し実効果に書き直す」。条件付き効果の実装自体は Phase 3（別タスク）へ送る。

デバフ標準値（TASK_F準拠）: ATK/MAG低下 ★1=6%/★5=10%/★9=14%、DEF低下 ★1=10%/★5=15%/★9=20%（SPRはDEFの半分・切り捨て）。ダメージ併発型の★9は攻撃も満額入るため DEF14%/SPR7% に減額する。

| job_id | ★ | 技名 | データ変更 | 新memo |
|---|---|---|---|---|
| 2 | 9 | 巨人断ち | なし | 単体特大ダメージ＋自身の戦闘力を上昇（戦闘中）。1戦1回 |
| 4 | 9 | 五月雨流星射ち | effect_template→MULTI_HIT（hit_count:5は既存） | 小ダメージの5回攻撃。1戦1回 |
| 8 | 9 | 大番振る舞い | damage_reduction_percent: 12 | 次の被ダメージを12%軽減。1戦1回 |
| 9 | 1 | 属性付与 | なし | 単体小ダメージ＋自身の戦闘力を小上昇（戦闘中） |
| 12 | 5 | 勝利の采配 | なし | 自身の戦闘力を中上昇（戦闘中） |
| 13 | 1 | 闘争本能 | なし | 自身の戦闘力を小上昇（戦闘中） |
| 13 | 5 | 闘技連斬 | effect_template→MULTI_HIT（hit_count:3は既存） | 3回攻撃 |
| 14 | 5 | 暴走撃 | self_damage_percent: 8 | 単体大ダメージ＋反動で最大HPの8%を消費 |
| 15 | 5 | ガーディアンブロウ | なし | 単体中ダメージ＋次の被ダメージを小軽減 |
| 16 | 9 | 傭兵団の総攻撃 | enemy_def_down_percent: 14, enemy_spr_down_percent: 7 | 単体大ダメージ＋敵DEF/SPR低下（戦闘中）。1戦1回 |
| 20 | 9 | 大商隊の守護 | damage_reduction_percent: 12 | 次の被ダメージを12%軽減。1戦1回 |
| 22 | 1 | 魔矢装填 | なし | 単体小魔法ダメージ |
| 22 | 5 | エレメントアロー | なし | 単体中ダメージ＋自身の戦闘力を小上昇（戦闘中） |
| 22 | 9 | 星霊連弓 | effect_template→DAMAGE_DEBUFF, hit_count: 3, enemy_def_down_percent: 14, enemy_spr_down_percent: 7 | 3回攻撃＋敵DEF/SPR低下（戦闘中）。1戦1回 |
| 23 | 5 | 勇気の旋律 | なし | 自身の戦闘力を中上昇（戦闘中） |
| 23 | 9 | 英雄譚の終章 | damage_reduction_percent: 12 | 次の被ダメージを12%軽減。1戦1回 |
| 26 | 1 | 錬成火花 | effect_template→MAGICAL_DAMAGE, enemy_def_down_percent: 10 | 単体小魔法ダメージ＋敵DEF小低下（戦闘中） |

補足:
- 「戦闘力を上昇」表記は TASK_F 共通ルール3に従いステ名を書かない（実ログ側が Phase 1-A で具体化されるため矛盾しない）。
- 星霊連弓は現状 ENEMY_DEBUFF でダメージ0（プレビュー検証で確認済み）。魔弓士は normal_attack_type=adaptive 済みなので、DAMAGE_DEBUFF でATK/MAG高い方に追従する。
- 錬成火花を MAGICAL_DAMAGE に固定するのは「小魔法ダメージ」というmemo通りにするため（DAMAGE_DEBUFF のままだと物理職に継承された際に物理化してしまう）。
- 反映は `php artisan db:seed --class=JobArtSeeder --force`（updateOrCreateで冪等。デプロイ時は自動実行）。

---

## Phase 1-E: 継承倍率のUI表示

1. 奥義セット画面・奥義一覧で、継承奥義（origin=inherited）に「継承効果 ×0.7」のようなバッジまたは注記を表示する。倍率は `inherited_rate` をそのまま表示（0.85なら×0.85）。
2. 画面のどこか1箇所に凡例: 「マスター職から継承した奥義は、威力・効果量が本来の70〜85%になります」。
3. 該当Bladeは `grep -rn "継承奥義\|inherited" resources/views` で特定すること。

---

## Phase 2: 回復奥義のスケール再設計

### 根本修正

`applyJobArtHeal`（BattleService.php:1135）の `max(80, (int)($skill->power ?: 100))` から **max(80, …) の下限を撤廃**し、`max(1, power)` にする。同じ下限が `SkillEffectPreviewService::templateHealAmount`（max(80, …)）と Champ/PvP/Arena の同等メソッドにもあるため全て撤廃。

### 新しい回復量基準（power_hint の張り替え）

回復量 = SPR × (power/100) × 継承rate。対象は effect_template が HEAL / HEAL_CLEANSE の全アーツ（`grep '"effect_template": "HEAL'` で全件列挙して漏れなく適用）。

| 区分 | power（=SPR依存%） | power_hint表記 |
|---|---|---|
| SP回復付き（mp_recover_percent > 0 の回復奥義） | 100 | 回復100相当 |
| ★1（Cost1）の純回復 | 80 | 回復80相当 |
| ★5（Cost2）の純回復 | 150 | 回復150相当 |
| ★9（Cost3・1戦1回）の純回復 | 250 | 回復250相当 |

- プレイヤー提案は Cost3=300% だったが、僧侶系はSPRが突出して伸びる職のため、まず250%で様子見とする（後から上げる調整は荒れない。下げる調整は荒れる）。
- DRAIN系（吸収）は対象外（drain_hp_rate による別計算のため変更しない）。
- heal_percent による「攻撃＋最大HP%回復」型（ホーリーブレイド等）も対象外（最大HP基準であり本件と無関係）。
- memoの「小回復/中回復/大回復」表記は据え置きでよいが、power_hint と矛盾する記述があれば「HPを回復（SPR依存）」系に直す。

---

## Phase 3（本タスクの対象外・着手禁止）

以下は条件付き効果エンジンが必要なため別タスクとする。**本タスクでは実装しない**（memo側を実挙動に合わせるのが Phase 1-D の対応）:

- 巨人断ち: 敵最大HP比例の威力上昇
- 闘争本能: HP半分以下で効果変化
- 傭兵団の総攻撃: 敵弱体時の威力上昇
- エレメントアロー: 弱点属性時の威力上昇
- 勝利の采配: 「低いステ2種」を選んで上昇
- 属性付与/魔矢装填: 「次の攻撃に追撃付与」システム
- 双極断: 物理Hit＋魔法Hitの2段演出（HYBRID_DAMAGE拡張）
- 大商隊の守護: LUK上昇＋連戦数スケール
- 英雄譚の終章: 全能力小上昇

---

## 受け入れ条件

1. `php -l` 全対象ファイル通過、`node -e "JSON.parse(...)"` で job_arts.json が valid。
2. SkillEffectLab（またはtinker経由のSkillEffectPreviewService）で:
   - 星霊連弓のダメージが0でない（3Hit）
   - 暴走撃で反動ダメージログが出る
   - 闘技連斬・五月雨流星射ちで「戦闘力があがった」「守りが乱れた」が出ない
   - 僧侶★1/★5/★9の回復量が明確に段階を持つ（80/150/250相当）
3. バフ/デバフのログにステ名と%が表示される（探索・チャンプ・PvPの3文脈で確認）。
4. 金剛不壊の軽減%が全戦闘文脈で一致する（継承時17%）。
5. 継承奥義のUIに倍率表示が出る。
6. 既存テストがあれば全通過。なければ tinker での動作確認ログを報告に添付。

## 検証コマンド例

```powershell
php artisan db:seed --class=JobArtSeeder --force
# プレビュー検証（例: 弓使い job_id=4）
# SkillEffectPreviewService::preview を tinker で叩き、turns の damage/label を確認する
```
