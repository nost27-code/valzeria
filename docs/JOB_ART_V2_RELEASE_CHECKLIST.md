# 戦技v2 Release Candidateチェックリスト

最終更新: 2026-08-15
対象起点: PR26 `2282b4ff682c846a0b0a754b4617957392e2ca48` 以降
公開判定: **コードのみ本番配置可 / 戦技v2機能公開は未承認**

## 2026-08-15 OFF本番配置

- 今回は戦技v2のコード・表示素材・テストを本番へ配置するが、`config/battle.php`の関連14 flagはすべてOFFのままにする
- deploymentは`migration_mode=none`で行う。migration、Seeder、`skills` master同期、既存slot/preset/プレイヤーデータ更新は実行しない
- flag OFF中の画面・選択・SP・戦闘はlegacyの3枠・Cost5を維持する。v2の5枠・Cost9・固定SP・循環cursor・複数系譜resource・対奥義は起動しない
- v2有効時の現行仕様は、全94職対応、現在職によるfallbackなし、主/副/出張なし、習得済み戦技の全効果100%、Rank1/5/9のCost1/2/3、奥義1枚、有効資源だけ共通獲得、同一戦技・同一行動・同一資源の重複禁止
- 本番有効化は別タスク。DB backup、必要migrationの個別確認、282件runtime master監査、6戦闘経路smoke、段階的flag切替を改めて実施する

以下のmigration・段階公開項目は、将来のv2有効化時に使うactivation checklistであり、今回のOFF本番配置では実行しない。

## 公開ブロッカー

- なし。63 星冠導師 Rank9の最終裁定・実装・回帰確認まで完了した。段階公開の手順は省略しない。

## 対応inventory

- current-job v2対応: 現行マスタ全94職
- current-job v2対象外: 0職。v2 flag ON時は転職先によるlegacy fallbackを設けない
- 戦技マスタ: 94職×Rank1/5/9=282件。`job_arts.json`・DB・lineage・prototype catalogの自然キー集合を一致させる
- 個別に凍結済みの追加効果は専用catalog/overrideを正とし、それ以外は各戦技の構造化master効果をv2共通resource・35/38/50・固定SP・5枠/Cost9で実行する。これは職業別の互換fallbackではなく、全職共通の効果経路である
- 63 星冠導師はRank1、Rank5、Rank9、resource、field/HUDまで個別効果対応。Rank9の上書き回数分岐は現在職63だけに適用する
- feature flag OFFだけを運用上の明示rollbackとし、存在しないJob IDや不完全masterはvalidator/testで停止する

以下のPR27監査CSVは40職対応時点の履歴であり、現行の対応範囲には使用しない。

監査CSV（リポジトリ外の検証成果物）:

- `C:\tmp\job-art-pr27\advanced_super_effect_inventory.csv`
- `C:\tmp\job-art-pr27\supported_current_jobs.csv`
- `C:\tmp\job-art-pr27\unsupported_current_jobs.csv`

## Job63の凍結済み範囲

- Rank1: `star_light -> melody -> sanctuary -> silence -> observation`の固定順で、現在の主場の次を展開する
- Rank1 resource: 基礎+4。既存場を実際に上書きした場合だけ追加+2
- 新規生成・延長・lock拒否では追加+2なし
- 行動開始時snapshotを使い、新しく展開した場を同じ行動へ自己適用しない
- Rank5: 上書きされた自分の旧場を1ラウンドだけechoとして保持
- echo: 既存の場補正を再利用し、追加のprimary/overlay slotを作らない。生成ラウンドは減算せず、次のround endで失効
- 同主系譜継承: 信頼済みRank1/5のfield/resource役割のみportable。current-job限定効果はportable化しない
- Rank9: 星印12pt、条件成立時優先。v2では旧masterのCT・1戦回数上限を使わない。行動開始時snapshotに主場がある場合だけ、本人の実`field_overwritten`回数0/1〜2/3〜4/5以上で基礎powerを1.00/1.05/1.10/1.15倍する
- Rank9 count: `field_created`、`field_refreshed`、`field_extended`、`field_expired`、副場eventは含めず、倍率上限は1.15
- Rank9 inheritance: 同主系譜継承は現在職の星印12ptを共有し、異系譜継承は独立した星印を元系譜Rank1等で12ptまで作って消費する。いずれもcurrent-job限定の上書き倍率は持ち込まず基礎damageのみ

Job63選択シミュレーション（seed 11/29/47、各6,000戦、normal、maxSP 800）:

- 戦技行動率平均: 36.61%
- resource上限到達ターン平均: 6.21
- Rank9初回平均: 8.19T
- Rank9初回p90: 12T
- Rank9到達率: 100%
- 終了SP平均: 31.40
- echo生成: 2.992回/戦
- 場上書き: 5.223回/戦
- resource範囲違反: 0

Job63 Rank9 targeted確認（seed 11/29/47、normal・決着型、実在敵6体、各1,500戦）:

- 境界倍率: overwrite 0/1〜2/3〜4/5以上で1.00/1.05/1.10/1.15、8回でも1.15 cap
- 自然到達した最初のRank9はoverwrite 0回が7.67〜28.90%、1〜2回が71.10〜92.33%。平均倍率は1.0299〜1.0416で、初回Rank9は平均6.57〜7.63T・p90 9〜11T
- 量産機械ゴーレムの平均TTKは基礎power 11.461Tから分岐あり11.388T（-0.64%）。他のRank9到達代表条件にも明確な破綻なし
- SP枯渇0、resource範囲違反0。3〜4回・5回以上の分岐は直接境界testで保証する

## DB migration

対象migration:

`database/migrations/2026_08_09_120000_add_condition_key_to_job_art_slots.php`

Up:

- `character_job_art_slots.condition_key`を`varchar(40) NOT NULL DEFAULT 'always'`で追加
- `job_art_preset_slots.condition_key`を同じ定義で追加
- 既存行は`always`になり、戦技ID・slot・発動方針を変更しない
- migrationは対象table/columnの存在を確認してから変更する

Down:

- 追加した2列だけを削除
- loadout/preset本体とプレイヤー設定行は削除しない
- rollback後はslot条件だけ失われ、legacyおよび条件なしv2は`always`として動作する

実施前:

- DB backupを取得し、復元手順を確認する
- 現在のmigration batchと2テーブルの代表行数を記録する
- すべての戦技v2 flagがOFFであることを確認する

実施後:

- 既存行が残り、両列が`always`であることを確認する
- normal/boss/pvpの保存・再読込とpreset保存・適用を確認する
- 未知conditionを一時fixtureで読み、`always`へ解決しつつDB値が書き換わらないことを確認する

## Feature flag matrix

全flagの既定値はOFF。環境変数追加だけで部分的なv2を起動しない。

| Flag | 依存 | 公開前の扱い |
|---|---|---|
| `BATTLE_JOB_ART_PVP_SET` | なし | 専用PvP setのUI/API/migration確認後にON |
| `BATTLE_JOB_ART_LOADOUT_V2` | 全94職catalog | 5枠/Cost9。単独ONでは戦闘v2を起動しない |
| `BATTLE_JOB_ART_LOADOUT_CARD_DETAILS` | loadout-v2 | 個別カード詳細UI。既定OFF |
| `BATTLE_JOB_ART_DYNAMIC_SINGLE` | 全94職catalog | 戦闘v2の基点 |
| `BATTLE_JOB_ART_NORMALIZED_SP` | dynamic | 階級×Rank固定SP表。dynamic未成立ならlegacy |
| `BATTLE_JOB_ART_HIT_RESOLUTION` | dynamic | 依存不成立ならlegacy |
| `BATTLE_JOB_ART_DAMAGE_APPLICATION` | dynamic + hit | 依存不成立ならlegacy |
| `BATTLE_JOB_ART_RESOURCES` | dynamic + hit + damage | 依存不成立なら無効 |
| `BATTLE_JOB_ART_FIELDS` | resources | 依存不成立なら無効 |
| `BATTLE_JOB_ART_PENETRATION` | resources | 対応metadataがない攻撃では無効 |
| `BATTLE_JOB_ART_PENETRATION_STANCE` | penetration | current job 62以外では無効 |
| `BATTLE_JOB_ART_PRESETS` | loadout-v2 + 対応current job | 戦闘処理はpreset tableを直接読まない |
| `BATTLE_JOB_ART_C_DESIGN_PROTOTYPE` | dynamic + resources | 主/副/出張を廃止した全効果・複数資源runtimeの共通gate |
| `BATTLE_JOB_ART_ULTIMATE_COUNTERPLAY` | c-design + battle path | 奥義/大技予告と10系譜の対処。既定OFF |

機械可読の確認表: `C:\tmp\job-art-pr27\release_flag_matrix.csv`

## 段階公開

RC READY後も一括ONにはしない。

1. DB backup後に必要migrationだけを適用し、全flag OFFでlegacy smoke
2. 内部検証characterだけに環境単位でUI系flagを有効化
3. `PVP_SET`、`LOADOUT_V2`、`PRESETS`の保存・再読込を確認
4. 戦闘依存を順に `DYNAMIC_SINGLE` -> `NORMALIZED_SP` -> `HIT_RESOLUTION` -> `DAMAGE_APPLICATION` -> `RESOURCES` まで有効化
5. `FIELDS`を有効化し、field/echo/HUDを確認
6. `PENETRATION`、`PENETRATION_STANCE`を有効化
7. normal/boss/tower/player PvP/champ/NPC arenaを各階層の代表職でsmoke
8. 小規模公開後、監視可能な指標と問い合わせを確認してから範囲を拡大

## 手動smoke

- flag OFF: 従来3枠/Cost5、legacy抽選、legacy SP、legacyログ
- flag ON、対応職: 5枠/Cost9、通常/ボス/PvP、condition保存・再読込
- preset: conditionを含めて保存し、別値へ変更後に適用して復元
- 転職: preset/slotを削除せず、元職へ戻ると同じconditionを読める
- Job63 Rank1: cycle順、上書き時だけ+6、それ以外+4、同一行動への自己適用なし
- Job63 Rank5: 直前に上書きされた自分の場だけ1ラウンドecho、主場/overlay数は不変
- Job63 Rank9: 主場ありで実上書き0/1〜2/3〜4/5+回が1.00/1.05/1.10/1.15倍、主場なし・同系譜継承は基礎damage
- Job63 HUD: 主場、echo、場上書き回数、resourceが戦闘結果と一致
- 全職coverage: 基本・中級・上級・超級・冠位・英雄・伝説・神話の代表職でv2 UI/runtimeを確認
- 6戦闘経路: normal、boss、tower、player PvP、champ、NPC arena
- 372px: condition、preset、field/echo HUDに横スクロールや操作不能なし

## 監視可能性

現在の永続telemetryで確認できるもの:

- `battle_logs.turn_count`等による戦闘ターン・勝敗・与被ダメージの集計
- 現在保存されているloadout/preset行数とcondition分布
- application error、HTTP 500、migration error

現在の永続telemetryだけでは確認できないもの:

- slot1〜5の選択率、Rank5/Rank9使用率、Rank9初回ターン
- current/same-lineage/cross-lineage別の戦技利用率
- resource獲得・消費・上限到達、SP不足、field/echo/stanceの発生率
- preset適用回数・戦闘での実使用率
- conditionごとの評価結果

本番公開前に、個人情報やログ本文解析へ依存しない集計event/columnの要否を別途裁定する。PR27では新しい永続telemetryを追加しない。

## Rollback

最優先rollback:

1. 14個の戦技v2 flagをすべてOFF
2. config cacheを対象環境だけ再構築
3. legacy UI・選択・SP・RNG・戦闘結果をsmoke

コードrollback:

- PR27コミットだけをrevertし、flag OFFを維持する
- migrationをdownする場合、condition値が失われることを事前告知しbackupを確保する
- loadout/preset行自体は削除しない

停止条件:

- migrationで既存slot/preset行が欠落または書換
- flag OFFのlegacy RNG/戦闘結果回帰
- 対象外54職でv2が起動
- unknown conditionで例外またはDB自動書換
- field echoが恒久残留、primary/overlay枠を増加、resourceが0..12を逸脱
- 既知baseline以外のtest failure/error

## 最終Go/No-Go

- コード・migration・自動テスト・Job63 R1/R5/R9・slot condition永続化: RC READY
- 段階公開開始判定: **READY**
- 未解決ブロッカー: なし
- `DOMAIN_RULES.md`更新、本番flag変更、push、PR作成、本番DB migrationはこのPRの範囲外
