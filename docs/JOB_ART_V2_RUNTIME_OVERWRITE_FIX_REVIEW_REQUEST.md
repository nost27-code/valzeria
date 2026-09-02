# 戦技v2 実行clone分離・聖冠軽減重複修正 確認依頼書

作成日: 2026-09-03

対象: ヴァルゼリアの冒険者 / 戦技v2

状態: **修正済み・独立レビュー合格／本番公開後の再確認に使用**

---

## 0. レビュー担当者への依頼

今回の修正が、次の2不具合を最小差分で解消し、既存の条件付き威力、継承、feature flag OFF、ほかの軽減戦技へ副作用を出していないか確認してください。

1. `BattleService`でCrownBalance個別値のない戦技が、実行時の意味解決により正本`Skill`ごと変更される
2. 聖冠系3戦技で、旧`damageReductionRate`とV2の1回Guardが同じ率を二重適用する

問題を発見しても、この依頼では修正せず、file／symbol、再現条件、期待値、実値、最小修正案を報告してください。

---

## 1. 権限と禁止境界

許可するのは、コードレビュー、読み取り、ローカルテスト実行、報告までです。

- コード、テスト、docs、マスタの変更禁止
- コミット、push、PR作成禁止
- DB更新、migration／Seeder実行禁止
- デプロイ禁止
- dirty worktreeの既存変更を復元、整形、stage、削除しない
- 未裁定のカード条件を拡張しない

---

## 2. 変更対象

### 実装

- `app/Services/BattleService.php`
  - `executeJobArtAction()`の先頭で、CrownBalance metadataの有無にかかわらず実行用cloneを作る
  - cloneへ`applyToExistingExecution()`を適用する
  - 後続のDamage／Effect Semantics、Role、power、報酬処理は実行用cloneだけを変更する

- `app/Services/JobArtV2EffectSemanticsResolver.php`
  - 聖冠系Rank1／5／9でV2の構造化Guardを選んだ場合、実行用`damage_reduction_percent`を0にする
  - `JobArtV2DefenseService`の25%／35%／45%・1回Guardは維持する

- `app/Services/JobArtV2RoleEffectService.php`
  - Effect Semanticsが確定した0を、後段の`applyToExistingExecution()`が25／35／45へ戻さないよう保持する
  - 竜冠天穿槍の470%保持と同じく、動的に解決済みの実行値を静的再適用より優先する

### 回帰テスト

- `tests/Unit/JobArtV2RuntimeOverwriteRegressionTest.php`
  - CrownBalance metadataなしの正本不変
  - 聖冠3Rank×現職／異系譜継承
  - `BattleService`と`JobArtBattleSupportService`の両実行入口
  - feature flag OFFのfail-closed

- `tests/Unit/JobArtV2UltimateCounterplayTest.php`
  - 静寂による系譜抑止時に、聖冠Rank9の旧45%軽減もV2 Guardも復活しないことを固定

### 記録・依頼書

- `docs/UPDATE_LOG.md`
- `config/admin_update_summaries.php`
- `docs/JOB_ART_V2_POST_FIX_HORIZONTAL_VERIFICATION_REQUEST.md`
- 本書

DB、migration、Seeder、`database/data/job_arts.json`の変更はありません。

---

## 3. 不具合A: 正本`Skill`への実行値混入

### 修正前の処理順

1. `BattleService`がsourceとexecutionへ同じ`Skill`インスタンスを受け取る
2. `JobArtV2CrownBalanceCatalog::applyToExecution()`は個別metadataがない場合、同じインスタンスを返す
3. Effect／Role Semanticsが`effect_template`、`power`、報酬値などを実行用として書き換える
4. 書き換えが正本`Skill`にも残る

### 再現代表

`20:5 掘り出し物`では、修正前に通常PvEを1回実行すると、正本へ少なくとも次が混入しました。

| 属性 | source master | 修正前の戦闘後source | 修正後の期待 |
|---|---|---|---|
| `effect_template` | `REWARD_MIXED` | `V2_ROLE_EFFECT_ONLY` | `REWARD_MIXED` |
| `power` | 0 | 100 | 0 |
| `power_multiplier` | 0 | 1 | 0 |
| `reward_scope` | `normal_exploration_win_only` | `none` | `normal_exploration_win_only` |

加えて、複数の回復・弱体・報酬属性の0が正本へ追加されていました。

### 受入条件

- CrownBalance metadataあり／なしの両方で、sourceとexecutionのobject identityが異なる
- 戦闘前後でsourceの`getAttributes()`が完全一致する
- 実行用cloneには、カード仕様に必要なSemantics、Role、Progression、CDesign変更が入る
- `JobArtBattleSupportService::skillForExecution()`と同じ分離方針になる
- feature flag OFFでも値は従来どおりで、object分離だけが安全側に増える

---

## 4. 不具合B: 聖冠系軽減の二重適用

### 修正前の処理順

1. CrownBalanceが実行用`damage_reduction_percent`へ25／35／45を設定する
2. Effect Semanticsが旧`MAGICAL_DAMAGE_BUFF`を`MAGICAL_DAMAGE`へ置換する
3. `JobArtV2DefenseService`が同じ25／35／45を1回Guardとして登録する
4. RoleEffect内の静的再適用が、0にした旧軽減値を25／35／45へ戻す
5. 各戦闘サービスが旧`damageReductionRate`にも同じ率を登録する
6. DamageCalculatorの旧軽減後、DamageApplicationのV2 Guardでもう一度軽減する

### 修正後の期待値

| Rank | 戦技名 | executionの旧軽減 | V2 Guard | 1,000入力の期待 |
|---:|---|---:|---:|---:|
| 1 | 聖冠加護 | 0% | 25%・1回 | 750 |
| 5 | 聖冠大結界 | 0% | 35%・1回 | 650 |
| 9 | 聖冠アイギスロード | 0% | 45%・1回 | 550 |

丸めを伴う実戦値は既存処理に従ってください。重要なのは、旧`damageReductionRate`が0、`JobArtV2GuardState::$rate`だけが0.25／0.35／0.45になることです。

### 受入条件

- Rank1／5／9すべてで一回だけ軽減する
- 通常PvE、ボス、塔、訓練所、PvP、チャンプ、NPC闘技場、対人模擬戦で同じ
- 現職、同系譜、異系譜継承で同じカード効果を実行する
- source masterへ0や25／35／45を書き戻さない
- flag OFFは`MAGICAL_DAMAGE_BUFF`と旧25／35／45を維持し、V2 Guardを作らない
- 他職の`GUARD_BARRIER`、`DAMAGE_GUARD_BARRIER`、prepared guard、counterplay guardへ影響しない
- ultimate counterplayが系譜効果を抑止した場合、旧軽減だけが復活しない

---

## 5. 既知回帰として維持すべき項目

### 竜冠天穿槍

- 条件未成立: 355%
- 同一戦闘中に「竜冠穿槍」を使用済み: 470%
- RoleEffect内のCrownBalance再適用後も470%
- source masterは355%のまま
- 「別の連携」へ条件を広げない

### 既存の攻撃意味・防御意味

- job61の魔力／精神参照
- multi-hitのaction-total分割
- 貫通率と貫通構え
- prepared effect、field、counterplay
- SP出力連動
- 継承率の一回適用

今回の修正に便乗して、これらの数値、対象、条件、丸め順を変えていないことを確認してください。

---

## 6. 推奨確認コマンド

環境に合うPHP実行ファイルへ読み替えて構いません。

```powershell
php -l app/Services/BattleService.php
php -l app/Services/JobArtV2EffectSemanticsResolver.php
php -l app/Services/JobArtV2RoleEffectService.php
php -l tests/Unit/JobArtV2RuntimeOverwriteRegressionTest.php

php artisan test tests/Unit/JobArtV2RuntimeOverwriteRegressionTest.php

php artisan test `
  tests/Unit/JobArtV2RuntimeOverwriteRegressionTest.php `
  tests/Unit/JobArtV2PowerBalanceTest.php `
  tests/Unit/JobArtV2CounterGuardServiceTest.php `
  tests/Unit/JobArtV2DamageSemanticsResolverTest.php `
  tests/Unit/JobArtV2RoleEffectServiceTest.php `
  tests/Unit/JobArtV2CrownUltimateInteractionTest.php `
  tests/Unit/JobArtV2UltimateCounterplayTest.php

npm run build
php artisan test
git diff --check
```

実装者側の修正直後参考値:

- 新規回帰: 3 tests / 62 assertions
- 関連7ファイル: 147 tests / 2,587 assertions

参考値と一致しない場合は、現在のテスト件数、差分、失敗内容をそのまま報告してください。全suiteに既存失敗がある場合、今回差分による失敗とbaseline失敗を分離してください。

---

## 7. レビュー観点

### Blocker

- source masterがいずれかの経路で変更される
- 聖冠の旧軽減が0回または2回適用される
- V2有効／無効でfail-closed境界が崩れる
- 竜冠470%が355%へ戻る
- 他戦技の回復、吸収、弱体、報酬、field、prepared、counterplayが変わる

### Non-blocking

- コメント、テスト名、依頼書の不足
- 追加すると有効な代表ケース
- 重複をさらに減らせる将来のリファクタ案

将来案は今回の最小修正へ混ぜず、別提案として分けてください。

---

## 8. 回答形式

### 結論

- `承認可能 / 修正後に再レビュー / Blockerあり`
- Blocker件数
- Non-blocking件数
- 未確認件数

### Findings

重大度順に、各項目へ次を記載してください。

- file／symbol
- 再現条件
- 期待値
- 実値
- 影響経路
- 最小修正案

### 回帰表

| 確認項目 | 現職 | 異系譜継承 | flag OFF | BattleService | Support系 | 判定 |
|---|---|---|---|---|---|---|
| source master不変 | | | | | | |
| 聖冠Rank1 25%一回 | | | | | | |
| 聖冠Rank5 35%一回 | | | | | | |
| 聖冠Rank9 45%一回 | | | | | | |
| 竜冠355%／470% | | | | | | |

### 実行確認

- 実行コマンド
- tests／assertions
- 失敗内容
- 実行できなかった確認と理由
- DB変更なしの確認
- 作業ツリーへ変更を加えていないこと

---

## 9. 完了条件

- [ ] 3つの実装ファイルを処理順までレビューした
- [ ] 新規回帰テストが修正前の2不具合を実際に検出できることを確認した
- [ ] 現職・異系譜継承・Rank1／5／9・flag OFFを確認した
- [ ] `BattleService`とSupport系の両入口を確認した
- [ ] source masterの全属性不変を確認した
- [ ] 聖冠軽減が1回だけであることを確認した
- [ ] 竜冠470%回帰を確認した
- [ ] DB／migration／Seeder変更がないことを確認した
- [ ] Blocker、Non-blocking、未確認を分離して報告した
