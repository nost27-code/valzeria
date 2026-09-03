# 戦技v2 水平検証指摘修正 確認依頼書

作成日: 2026-09-03

対象: ヴァルゼリアの冒険者 / 戦技v2

状態: **修正・独立レビュー済み／本番公開後の再確認に使用**

---

## 0. 依頼範囲

水平検証で要裁定となった次の2項目について、確定後の実装がカード正本どおりであり、既存の実行値上書き対策へ回帰を起こしていないか確認してください。

1. 封式の場で奥義を「基礎効果だけ」発動する時も、旧DB master値ではなくL列正本値を使う
2. `62:9 竜冠天穿槍`は、同一戦闘中に系譜を問わず任意のRank5連携を1回以上使用済みなら威力470%とする

今回は調査・報告のみです。コード、テスト、docs、DB、migration、Seeder、デプロイ、作業ツリーの既存変更へ手を加えないでください。

---

## 1. 正本と変更境界

- 現在動作の正本: 実コード
- 期待仕様の正本: `docs/DOMAIN_RULES.md`とカード本文
- 戦技静的値の正本: `JobArtV2CardDescriptionCatalog`と`JobArtV2CrownBalanceCatalog`が示すL列値
- 母集団: `database/data/job_arts.json`の282戦技（Rank5は94件）
- DB、migration、Seeder、`job_arts.json`の値は今回変更していない
- `job_art_rate`の旧継承率経路は、現行v2が全効果100%で到達不能のため変更していない

仕様差を新たに見つけた場合は、修正せず「要裁定」として分離してください。

---

## 2. 修正A: 封式の場の基礎効果

### 処理順

1. source masterをcloneする
2. cloneへ`JobArtV2CrownBalanceCatalog::applyToExistingExecution()`を適用する
3. 正本powerから`power_multiplier`を同期する
4. 通常のDamage／Effect Semantics、Role／Progression、prepared、fieldなどの固有追加効果は抑止したまま返す

### 期待値

| 戦技 | 旧値を模したsource | 封式の場での実行値 |
|---|---|---|
| `66:9 聖冠アイギスロード` | 威力333%、軽減0% | 威力355%、軽減45% |
| `16:9 傭兵団の総攻撃` | 威力89%、持続1、低下14%／7% | 威力255%、持続4、低下20%／20% |

次も確認してください。

- PvP、チャンプ戦、NPC闘技場で同じ
- source masterの全属性snapshotが戦闘前後で同じ
- 正本の静的値だけを復元し、V2 Guard、prepared effect、progression、field効果を誤って発動しない
- 封式の場がない通常実行と、field/counterplay flag OFFの経路を変えない

---

## 3. 修正B: 竜冠天穿槍の連携条件

### 期待値

| 条件 | 実行時威力 |
|---|---:|
| 同一戦闘でRank5未使用 | 355% |
| 任意のRank5連携を1回以上使用済み | 470% |
| 必要feature flag OFF | 355% |

「使用済み」は次を問いません。

- Rank5の所属系譜
- 現在職、同系譜継承、異系譜継承
- `JobArtV2ProgressionCatalog`の個別metadata登録有無
- Rank5の結果がHIT、MISS、EVADEのいずれか

特に、進行metadata未登録の実カード`1:5 受け返し`でも条件が成立することを確認してください。`job_arts.json`のRank5全94件を母集団にし、各カード使用後の履歴、竜冠天穿槍470%、source master不変を照合してください。

### 処理順

1. `completeJobArtCast()`が、行動者のv2 resources有効を確認する
2. Job Art Rank5なら、個別進行metadataを調べる前に戦闘内履歴を立てる
3. Rank9実行時に`JobArtV2PowerResolver`が355%／470%を解決する
4. CrownBalance再適用後、`JobArtV2RoleEffectService`が条件成立時470%を保持する
5. SP出力連動が有効なら、470%を基礎に一度だけスケールする

---

## 4. 比較する実行経路

最低限、次を比較してください。

- `BattleService`の通常PvE、ボス、星樹の塔、冒険者訓練所
- `JobArtBattleSupportService::skillForExecution()`を使うPvP、チャンプ戦、NPC闘技場、対人模擬戦
- 国家戦・国家レイドは戦技v2接続済みなら対象、未接続なら「対象外」、証拠不足なら「未確認」

各経路で条件未成立／成立、現在職／同系譜／異系譜継承、flag OFF、source master不変を記録してください。

---

## 5. 重点ファイル

- `app/Services/JobArtV2UltimateCounterplayService.php`
- `app/Services/JobArtV2ProgressionService.php`
- `app/Services/JobArtV2ProgressionState.php`
- `app/Services/JobArtV2PowerResolver.php`
- `app/Services/JobArtV2RoleEffectService.php`
- `app/Services/JobArtV2CrownBalanceCatalog.php`
- `app/Services/BattleService.php`
- `app/Services/JobArtBattleSupportService.php`
- `tests/Unit/JobArtV2UltimateCounterplayTest.php`
- `tests/Unit/JobArtV2PowerBalanceTest.php`
- `tests/Unit/JobArtV2RuntimeOverwriteRegressionTest.php`

---

## 6. 推奨コマンド

```powershell
php -l app/Services/JobArtV2UltimateCounterplayService.php
php -l app/Services/JobArtV2ProgressionService.php
php -l app/Services/JobArtV2PowerResolver.php
php -l app/Services/JobArtV2RoleEffectService.php

php artisan test tests/Unit/JobArtV2UltimateCounterplayTest.php
php artisan test tests/Unit/JobArtV2PowerBalanceTest.php
php artisan test tests/Unit/JobArtV2RuntimeOverwriteRegressionTest.php
```

全suite成功だけで問題なしとせず、修正前に失敗する再現テストであることと、各処理段階の`Skill`属性を確認してください。

---

## 7. 必須報告形式

### 結論

- 承認可能／修正後に再レビュー／Blockerあり
- Blocker、Non-blocking、要裁定、未確認の件数
- 影響する戦闘経路

### 回帰表

| 確認項目 | PvE系 | 対人系 | 現在職 | 同系譜継承 | 異系譜継承 | flag OFF | 判定 |
|---|---|---|---|---|---|---|---|
| 封式の場でL列正本値 | | | | | | | |
| 固有追加効果の抑止 | | | | | | | |
| Rank5全94件の履歴 | | | | | | | |
| 竜冠355%／470% | | | | | | | |
| source master不変 | | | | | | | |

### Findings

各項目にfile／symbol、再現条件、期待値、実値、影響経路、最小修正案、DB変更要否を記載してください。

### 実行確認

- 実行コマンド
- tests／assertions
- 失敗内容
- 実行できなかった確認と理由
- 作業ツリーへ変更を加えていないこと

---

## 8. 完了条件

- [ ] 封式の場の3対人経路でL列正本値と固有効果抑止を確認した
- [ ] Rank5全94件をHIT／MISS／EVADEへ分散して確認した
- [ ] 竜冠天穿槍の355%／470%と後段再適用後の保持を確認した
- [ ] BattleServiceとSupport系の両入口を確認した
- [ ] 現在職・同系譜・異系譜継承・flag OFFを確認した
- [ ] source masterの全属性不変を確認した
- [ ] DB／migration／Seeder／masterデータ変更なしを確認した
- [ ] Blocker、要裁定、未確認を分離して報告した
- [ ] 修正やデプロイを行わず、報告だけで終了した
