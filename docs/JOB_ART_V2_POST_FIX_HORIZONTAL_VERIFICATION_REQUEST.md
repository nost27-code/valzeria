# 戦技v2 実行値上書き不具合 修正後水平検証依頼書

作成日: 2026-09-03

対象: ヴァルゼリアの冒険者 / 戦技v2

状態: **修正済み・独立レビュー合格／本番公開後の再検証に使用**

---

## 0. 依頼範囲と禁止事項

戦技v2で、条件成立後に算出した実行時の値が後段の静的値で上書きされる問題、同じ効果が複数経路で重複適用される問題、実行時変更が正本`Skill`へ混入する問題を、全戦技・全戦闘経路で水平検証してください。

今回は**調査・報告のみ**です。次は行わないでください。

- コード、テスト、ドキュメント、マスタデータの修正
- コミット、push、PR作成
- DB更新、migration／Seeder実行
- ステージング・本番デプロイ
- 作業ツリーの既存変更の破棄、整形、復元、stage
- カード条件や適用対象の独自拡張

調査用の一時コードが必要な場合は、追跡対象外の安全な一時領域だけを使い、終了時に残存物を報告してください。

---

## 1. 目的

次の4点を独立に確認してください。

1. 「竜冠天穿槍」の条件成立時470%が、後段のCrownBalance再適用後も維持される
2. `BattleService`が、CrownBalance個別値のない戦技でも正本`Skill`を変更せず、実行用cloneだけを変更する
3. 聖冠系3戦技の25%／35%／45%軽減が、旧軽減とV2 Guardの二重適用にならず、1回だけ適用される
4. 同種の静的上書き、二重適用、適用漏れ、正本混入が、ほかの戦技に残っていない

既存テストの全成功だけで「問題なし」としないでください。条件成立・未成立の中間値と、最終実行用`Skill`の属性を比較してください。

---

## 2. 修正後の既知基準

### 2-1. 竜冠天穿槍

| 条件 | 期待する実行時威力 |
|---|---:|
| 条件未成立 | 355% |
| 同一戦闘中に「竜冠穿槍」を使用済み | 470% |

- `JobArtV2PowerResolver`で成立した470%を、RoleEffect内の静的な基礎値再適用後も保持すること
- 正本`Skill`の基礎威力355%は変更されないこと
- カード本文は「連携を1回以上使用済み」と読める一方、現行コードは「竜冠穿槍を使用済み」の場合だけフラグを立てる
- 別の連携も対象にするかは**要裁定**であり、今回の検証で条件を拡張しないこと

### 2-2. 実行用cloneの分離

`BattleService::executeJobArtAction()`は、CrownBalanceの個別metadataの有無にかかわらず、最初に実行用cloneを作成することが期待値です。

少なくとも、先行調査で書き込み衝突候補になった次の10戦技を確認してください。

| job_id | Rank | 戦技名 |
|---:|---:|---|
| 8 | 5 | 幸運の一手 |
| 8 | 9 | 大番振る舞い |
| 15 | 1 | 不屈の誓い |
| 20 | 5 | 掘り出し物 |
| 23 | 5 | 勇気の旋律 |
| 28 | 1 | 剣気集中 |
| 38 | 1 | 商聖の助言 |
| 47 | 1 | 聖薬散布 |
| 70 | 5 | 暁光ブレイク |
| 79 | 5 | 白銀王盾 |

各戦技で、正本`Skill`の全属性snapshotと、実行用`Skill`の全属性snapshotを別々に取得してください。戦闘後に正本の`effect_template`、`damage_type`、`power`、報酬値、回復値、弱体値などが変わっていないことを確認してください。

### 2-3. 聖冠系の一回軽減

| job_id | Rank | 戦技名 | V2 Guard期待値 | 実行用`damage_reduction_percent`期待値 |
|---:|---:|---|---:|---:|
| 66 | 1 | 聖冠加護 | 25%・次の直接攻撃1回 | 0 |
| 66 | 5 | 聖冠大結界 | 35%・次の直接攻撃1回 | 0 |
| 66 | 9 | 聖冠アイギスロード | 45%・次の直接攻撃1回 | 0 |

V2有効時は、`JobArtV2DefenseService`の構造化Guardだけを適用し、各戦闘サービスの旧`damageReductionRate`へ同じ率を再登録しないことが期待値です。

固定入力1,000ダメージの一次確認では、丸め前の期待は次です。

| 戦技 | 一回軽減後 | 二重適用時（不具合） |
|---|---:|---:|
| 聖冠加護 25% | 750 | 562.5相当 |
| 聖冠大結界 35% | 650 | 422.5相当 |
| 聖冠アイギスロード 45% | 550 | 302.5相当 |

実ダメージ確認では既存の丸め位置を記録し、まず`Skill`属性、`BattleActor::$damageReductionRate`、`JobArtV2GuardState::$rate`を比較してください。

feature flag OFFではfail-closedとして旧テンプレートと25%／35%／45%値を維持し、V2 Guardを作らないことを確認してください。

---

## 3. 正本と判定原則

- 現在の動作: 実コードを正とする
- 本来の仕様: `docs/DOMAIN_RULES.md`とカード本文を正とする
- コードと仕様が食い違う場合: 修正せず「要裁定」とする
- 単に後段で値が変わるだけでは不具合としない
- カード仕様上、先に算出した動的値を保持すべきか確認して判定する
- 証拠が取れない項目は「未確認」とする
- `database/data/job_arts.json`の件数が282でない場合、282と決め打ちせず、実件数と差分を報告する

最低限、次を読んでください。

- `AGENTS.md`
- `docs/AI_CONTEXT.md`
- `docs/CODEMAP.md`
- `docs/FEATURE_STATUS.md`
- `docs/DOMAIN_RULES.md`
- `docs/dev-os/QA_CHECKLIST.md`

---

## 4. 母集団と対象ファイル

`database/data/job_arts.json`の全282戦技（94職×Rank1／5／9）を母集団として棚卸ししてください。

### 正本・カタログ

- `app/Services/JobArtV2CardDescriptionCatalog.php`
- `app/Services/JobArtV2CrownBalanceCatalog.php`
- `app/Services/JobArtV2PowerCatalog.php`
- `app/Services/JobArtV2RoleEffectCatalog.php`
- `app/Services/JobArtV2ProgressionCatalog.php`
- `app/Services/JobArtV2CDesignEffectCatalog.php`
- `database/data/job_arts.json`

### 実行処理

- `app/Services/BattleService.php`
- `app/Services/JobArtBattleSupportService.php`
- `app/Services/JobArtV2PowerResolver.php`
- `app/Services/JobArtV2RoleEffectService.php`
- `app/Services/JobArtV2ProgressionService.php`
- `app/Services/JobArtV2DamageSemanticsResolver.php`
- `app/Services/JobArtV2EffectSemanticsResolver.php`
- `app/Services/JobArtV2UltimateCounterplayService.php`
- `app/Services/JobArtV2SpPowerScalingService.php`
- `app/Services/JobArtV2PenetrationService.php`
- `app/Services/JobArtV2FieldService.php`
- `app/Services/JobArtV2DefenseService.php`

---

## 5. 重点検証

### 5-1. 静的値による動的値の上書き

次の値が条件判定、系譜処理、prepared effect、field、counterplayで変更された後、静的な基礎値へ戻っていないか確認してください。

- `power`、`power_multiplier`、`hit_count`、`duration_turns`
- `effect_template`、`damage_type`
- 攻撃／魔力の参照経路、防御／精神の参照経路
- 防御・精神の無視率、対象別最終ダメージ倍率
- HP回復率、SP回復率、HP吸収率、ダメージ軽減率
- 攻撃・防御・魔力・精神・敏捷低下率
- 報酬倍率
- prepared effect、field、counterplayの効果

特に次の2メソッドが再設定する値と、その直前直後を比較してください。

- `JobArtV2CrownBalanceCatalog::applyToExistingExecution()`
- `JobArtV2CrownBalanceCatalog::reapplyCoreExecutionValues()`

### 5-2. 二重適用・適用漏れ

次が0回または2回以上適用されていないか確認してください。

- 条件付きダメージ倍率
- 系譜進行倍率
- prepared effect
- 対象種族倍率
- SP出力連動
- 多段分割
- 貫通
- counterplay
- field補正
- 継承率
- 1回Guard／旧`damageReductionRate`

多段攻撃では、action-total威力への補正とHit分割後の補正を区別してください。

### 5-3. 優先候補

- `JobArtV2PowerCatalog::OVERRIDES`
- `RoleEffectCatalog`の`execution_power`
- `conditional_damage_multiplier`
- `conditional_target_multiplier`
- `prepared_effect.damage_multiplier`
- `damage_stat_route`
- `adaptive_route`
- `structured_debuff`
- 動的なHit数・攻撃種別変更
- 奥義counterplayによる威力・貫通変更
- fieldの展開・上書き回数による最終倍率
- SP残量による威力補正
- Rank1→Rank5→Rank9の戦闘中進行条件

---

## 6. 戦闘経路と装備条件

最低限、次を比較してください。

- 通常PvE
- ボス戦
- 星樹の塔
- 冒険者訓練所
- PvP
- チャンプ戦
- NPC闘技場
- 対人模擬戦

国家戦・国家レイドなど`BattleService`派生経路は、戦技が接続済みなら対象へ含め、未接続なら「対象外」、コードだけで追えなければ「未確認」としてください。

特に次の二系統で、同じ条件の最終実行値が一致するか確認してください。

- `BattleService`
- `JobArtBattleSupportService::skillForExecution()`

各候補について次を比較してください。

- 習得職を現在職にした場合
- 同系譜で使用した場合
- 異系譜へ継承した場合
- 条件未成立
- 条件成立
- feature flag OFF時のfail-closed経路

カード本文は使用職・系譜を問わず全効果を実行する方針です。コードが異なる場合は勝手に直さず「要裁定」としてください。

---

## 7. 記録する処理段階

条件付き戦技は、最終ダメージだけでなく次の値を記録してください。

1. source master
2. CrownBalance適用後
3. Damage／Effect Semantics適用後
4. PowerResolver適用後
5. Progression／Role／CDesign適用後
6. CrownBalance再適用後
7. Final modifier／Counterplay適用後
8. SP出力連動後
9. Hit分割直前
10. 最終ダメージ計算時

条件成立フラグが`true`でも最終値が変わっていない場合は、途中値を必ず比較してください。

乱数による誤判定を避けるため、まず実行用`Skill`の属性を比較し、ダメージ比較ではseedまたは既存の固定乱数源を使用してください。

---

## 8. 必須報告形式

### 8-1. 結論

- 確定不具合件数
- 要裁定件数
- 未確認件数
- 影響する戦闘経路
- 今回の3修正点と竜冠470%回帰の合否

### 8-2. 検証網羅表

| 分類 | 対象数 | 検証済み | 問題あり | 要裁定 | 未確認 |
|---|---:|---:|---:|---:|---:|
| 全戦技 | | | | | |
| 条件付き威力・倍率 | | | | | |
| Hit数変更 | | | | | |
| 攻撃種別・参照能力変更 | | | | | |
| デバフ・持続時間 | | | | | |
| 回復・吸収・軽減 | | | | | |
| field・prepared・counterplay | | | | | |

### 8-3. 戦技別結果

| job_id | Rank | 戦技名 | 条件 | 期待値 | 中間値 | 最終値 | 経路 | 判定 |
|---:|---:|---|---|---|---|---|---|---|

### 8-4. 確定不具合ごとの詳細

- 現在の仕様
- カード上の仕様
- 原因となる処理順
- 上書き前後の具体的な値
- 再現条件
- 影響する現在職・継承・戦闘モード
- 最小修正案
- 追加すべき回帰テスト
- DB変更の有無
- 既存仕様へのリスク

### 8-5. 問題なしと判断した根拠

代表例だけでなく、どの属性衝突、条件分岐、書き込み元、再適用先を確認したか示してください。

### 8-6. 実行した確認

- 実行コマンド
- テスト件数・アサーション数
- 失敗内容
- 実行できなかった確認と理由
- 作業ツリーへ変更を加えていないこと

---

## 9. 完了条件

- [ ] 全282戦技を母集団として棚卸しした
- [ ] 静的値と動的値の書き込み衝突を列挙した
- [ ] 条件成立・未成立の両方を確認した
- [ ] PvE系と対人系の実行順を比較した
- [ ] 現在職・同系譜・異系譜継承を比較した
- [ ] feature flag OFFのfail-closedを確認した
- [ ] 元の`Skill`マスタが不変で、実行用cloneだけが変化することを確認した
- [ ] 聖冠3戦技が25%／35%／45%を一回だけ適用することを確認した
- [ ] 竜冠天穿槍の355%／470%分岐と正本355%不変を確認した
- [ ] 「確定不具合」「要裁定」「未確認」を分離した
- [ ] 修正、DB更新、デプロイを行わず、最小修正案までの報告に留めた
