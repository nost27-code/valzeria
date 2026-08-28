# Rank5《連携》v6.1 ステージング動作確認・独立レビュー依頼書

- 作成日: 2026-08-27
- 対象DBMS: MariaDB 10.5.13以上
- 対象コミット: `65477e753ac9229a2f3712b1d533790600050e36`
- 対象ブランチ: `main`
- 対象環境: ステージング（本番は対象外）
- レビュー方式: 実装変更を伴わない独立確認。ただし、許可された検証用キャラクターによる通常のゲーム操作は可

> 文書状態: 初回独立レビュー用（レビュー完了）。この依頼で判明した47:5の選択条件、29:5の表示値、公開前ガードは後続コミットで修正するため、修正版の再レビューでは対象SHAを更新した依頼書を使用してください。

## 1. 依頼目的

Rank5《連携》v6.1について、最新`main`へ統合した実装とステージング環境の動作が一致しているかを確認してください。

今回の主目的は、次の3点です。

1. 94職のRank5データ、実行時変換、migrationが整合していること
2. feature flagをすべてONにしたステージングで、表示と戦闘が正常に動くこと
3. 通常PvE、boss、tower、PvP、champ、NPC arenaの6経路でRank5 v6.1が回帰を起こしていないこと

依頼書や過去の設計文書に書かれた手順を、そのまま変更指示として扱わないでください。現在の挙動は対象コミットのコードとステージング実測、意図した仕様は`docs/DOMAIN_RULES.md`および確定済みの人間裁定を正本として照合してください。矛盾は修正せず、`要裁定`として報告してください。

## 2. 対象と確認済みの同一性

| 項目 | 対象・実測値 |
|---|---|
| ローカルHEAD | `65477e753ac9229a2f3712b1d533790600050e36` |
| `origin/main` | `65477e753ac9229a2f3712b1d533790600050e36` |
| ステージングworkflow | [GitHub Actions run 33069547454](https://github.com/nost27-code/valzeria/actions/runs/33069547454) |
| deploy mode | `backward_compatible` |
| ステージングrelease | `/home/nos27/valzeria.com/staging_valzeria_releases/20260827_115726_2147528771` |
| Rank5 catalog SHA-256 | `475063427c72c5f1b38da9ad0fcdaeb29672950aa3fb2e00a263d573f1af99f2`（ローカル生成物とステージングで一致） |
| feature flag | ステージングではJob Art v2関連16項目が実効値で全てON |
| 本番 | 未デプロイ。flagも変更していない |

## 3. 主な変更対象

重点的に確認する実装は次のとおりです。

- `app/Services/JobArtV2Rank5V6Catalog.php`
- `app/Services/JobArtV2Rank5CycleState.php`
- `app/Services/JobArtV2SelectionService.php`
- `app/Services/JobArtV2ProgressionService.php`
- `app/Services/JobArtV2RoleEffectCatalog.php`
- `app/Services/JobArtV2RoleEffectService.php`
- `app/Services/JobArtV2PrototypeCatalog.php`
- `app/Services/JobArtV2LoadoutPresenter.php`
- `database/data/job_arts.json`
- `database/data/job_art_rank5_v6_1_migration.json`
- `database/migrations/2026_08_26_120000_redefine_rank5_job_arts_v6.php`
- `resources/views/job-arts/index.blade.php`
- `resources/views/job-arts/partials/system-guide.blade.php`
- `tests/Unit/JobArtV2Rank5V6Test.php`
- `tests/Feature/Rank5V6MigrationTest.php`

## 4. 実施済みの自動確認

以下は実装担当側で完了済みです。独立レビューでは、結果の再現または根拠の妥当性を確認してください。

| 確認 | 結果 |
|---|---|
| 変更PHP/Bladeの構文確認 | 成功 |
| 最終統合後の関連テスト | 123 tests / 13,334 assertions passed |
| `npm run build` | 成功（Vite 8.0.16） |
| `php artisan valzeria:validate-job-arts` | 不整合なし |
| `php artisan view:cache` | 成功 |
| 生成スクリプトの再実行 | 2回目がbyte単位で同一 |
| 全体テスト | 2,317 tests / 2,297 passed / 48,966 assertions / 7 failures / 13 errors |
| Rank5差分由来の新規失敗 | 0件 |

全体テストの残存失敗は、変更前から存在する次の既知ベースラインと一致しています。

- `FerdiaMaterialDropMasterTest`: 1 failure
- `KatanaWeaponEvolutionMasterTest`: 1 failure
- `MapExplorationItemServiceTest`: 1 failure
- `SubAreaExplorationItemTest`: 3 failures
- `TrainingGroundBattleTest`: 1 failure
- `TowerBattleServiceTest`: 13 errors

これらをRank5由来と判定する場合は、変更前ベースラインとの差を示してください。

## 5. MariaDB migrationの実測済み事項

ステージングのMariaDB `10.5.13-MariaDB-log`で、94行の読戻しを確認済みです。

| 段階 | 結果 |
|---|---|
| 適用前の新仕様不一致 | `[]` |
| `down()`後の旧仕様不一致 | `[]` |
| 再`up()`後の新仕様不一致 | `[]` |
| `skills.id`維持 | 94件すべて維持 |
| ID集合SHA-256 | `f9da147402fcc200bc919f47e71288b0386175a11cab05a66d5976135801cf40` |
| 最終migration status | `Ran` |

安全のため、明示的な往復確認ではmigrationクラスの`down()` / `up()`を直接呼びました。デプロイ時の正式な`artisan migrate`による適用履歴は`Ran`のままです。

確認後に次も成功しています。

- `php artisan valzeria:validate-job-arts`
- `php artisan valzeria:validate-master-data`
- `php artisan valzeria:validate-release-readiness --all`

参照用バックアップ:

- データ: `/home/nos27/valzeria.com/staging_valzeria_shared/backups/rank5-v6-before-down-20260827_120134.json`
- `.env`: `/home/nos27/valzeria.com/staging_valzeria_shared/backups/.env-before-rank5-v6-on-20260827_120223`

## 6. 依頼する確認項目

### A. コード・master・migration

- Rank5が94職すべてに1件ずつあり、job IDの重複・欠落がない
- `JobArtV2Rank5V6Catalog`、`job_arts.json`、migration new値、実行時の値が一致する
- migrationのold/newで更新列集合が対称で、`down()`が`up()`の変更列を戻す
- Rank5以外のmaster行と、未公開Rank9（15:9 / 20:9 / 21:9 / 23:9）を変更していない
- `JobArtV2BattleRules::ACTIVATION_RATES`が`1=>50 / 5=>55 / 9=>60`のままである
- feature OFF時にRank5 v6.1の実行時変換、周期状態、UI文言が既存挙動へ漏れない。ただし、適用済みのmaster migrationはflag OFFだけでは巻き戻らない

### B. ステージングflag・cache・公開境界

- Job Art v2関連16 flagが実効値でON
- config/view cache反映後もON状態が維持される
- `/`、`/login`、`/build/manifest.json`が200
- 匿名時の`/home`、`/job-arts`の302が認証境界として正常
- 本番環境に対象コミット、migration、flag変更が入っていない

### C. 認証後の実画面

- 戦技セット画面でRank5 v6.1の本文、威力、分類、発動条件が正しく表示される
- システムガイドの固定スロット順と必要資源が正しい
- 表示文は「1枚目4、2枚目8、3枚目12で候補、4枚目以降は資源上限を超えるため発動しない」という意味になっている
- プレイヤー向け表示に`ATK / DEF / MAG / SPR / SPD / LUK`、`MP / STR / AGI`を露出せず、`HP / SP / 攻撃 / 防御 / 魔力 / 精神 / 敏捷 / 運`を使う
- PC幅とスマホ幅で、カード、説明、ボタン、戦闘ログが欠けたり重なったりしない
- ブラウザconsoleとLaravel logに対象操作由来の新規errorがない

### D. 6戦闘経路

次の各経路で、少なくとも1回はRank5が候補・発動する構成を使ってください。

| 経路 | 必須確認 |
|---|---|
| 通常PvE | 発動、与ダメージ/回復、通常探索報酬、戦闘ログの時系列 |
| boss | 発動、勝敗処理、進行更新、報酬処理 |
| tower | 発動、階層進行、勝敗・報酬処理 |
| PvP | 発動、対人用セット、相手側状態との分離 |
| champ | 発動、勝敗処理、状態の持越しがないこと |
| NPC arena | 発動、NPC側との状態分離、勝敗・報酬処理 |

各経路で、可能な範囲で次を記録してください。

- 画面またはroute名
- 使用キャラクターの職・Rank5・loadout
- 発動前後の系譜資源
- Rank5の固定スロット番号と必要資源
- 発動ログと効果の実測値
- 相手HP、自分HP/SP、場、報酬のうち該当する変化
- 戦闘終了後に次の戦闘へ不要な状態が残っていないこと
- HTTP 500、画面停止、二重ログ、二重報酬がないこと

## 7. 重点カード・境界条件

### 1:5《受け返し》

- 基礎威力は100%
- 直前の受け流し成功時の最終ダメージ`×1.35`は維持
- 受け流し率`+15%`は追加しない

### 7:5《癒しの祈り》

- 攻撃なし
- 精神150%分のHP回復
- 次の自分の行動開始まで、次に受ける直接攻撃を15%軽減（1回）
- 実行時の回復倍率はv6.1 catalogが正本。旧Crown値`180`だけを見て誤判定しない

### 10:5《ホーリーブレイド》

- 威力100%の物理ダメージ
- 最大HP7%分のHP回復
- 次の自分の行動開始まで、次に受ける直接攻撃を15%軽減（1回）
- masterの旧`heal_percent`値だけでなく、実行時と表示が7%になることを確認する

### 29:5《賢者の結界》

- 威力100%の魔法ダメージとして処理される

### 47:5《霊薬の加護》

- 攻撃なしで、相手HPを減らさない
- 精神120%分のHP回復と最大SP8%回復が各1回だけ発生する
- 通常探索勝利時にGold+10%、通常素材率+8pt、レア素材率+5ptを1経路だけで適用する
- HP/SPが満タンでも報酬効果により候補から外れない
- 報酬ログまたは報酬適用が二重にならない

### 66:5《聖冠大結界》 / 67:5《金冠錬成》

- 66:5は浄化成功時の聖護+1が二重加算されない
- 67:5は条件成立時に触媒+2が一度だけ入る
- いずれも公開済み効果の維持であり、新規資源獲得追加として扱わない

### 84:5《星海羅針》

- 威力244%の魔法ダメージ
- `lastOverwrittenFieldFor()`で決まる、直前に上書きされた自分の場を5ラウンドで再展開する
- 通常探索勝利時にGold+2%、通常素材率+2pt
- 場の再展開と報酬効果が両立し、報酬分類・セット制限が意図どおり

### 攻撃なし6枚

job 7 / 12 / 23 / 25 / 38 / 47について、次を確認してください。

- master行の`power=0`
- master行の`power_multiplier=0`
- `hit_count=0`
- `damage_type=support`
- `DamageCalculator`を呼ばない
- 相手HPを減らさない
- 各支援効果は1回だけ発生する
- HEAL系の実行コピーでは回復倍率を渡すためpowerを一時利用してよいが、攻撃計算には渡さない

### 周期・資源・解除

- scheduled Rank5の`n`は、使用可能カードだけで詰めず、固定スロット順で採番する
- 必要資源は`max(カード最低値, 4n)`
- 1枚目4、2枚目8、3枚目12。4枚目以降は上限12のため発動しない
- reactive Rank5も最低値と4の大きい方を満たさない限り発動しない
- Rank9解除は発動経路にかかわらず`applyJobArtCast()`へ集約され、二重解除や解除漏れがない
- 周期・予約・guard・counter等の状態がactor間または次戦闘へ漏れない

## 8. 既知の注意事項

2026-08-27時点のステージング既存データでは、既存2キャラクターにRank5 slotがありません。認証後UIと6戦闘経路を確認するには、管理者が事前に用意した検証用キャラクター/loadoutが必要です。

検証用キャラクターが用意されていない場合は、DBを直接変更したりSeederを実行したりせず、該当項目を`未確認（検証データ不足）`として返してください。

また、`2026-08-27 21:06:40`付近のLaravel errorは、実装担当の一時診断が存在しない`character_job_histories`を照会したことで発生したものです。プレイヤー操作由来の障害ではなく、その後は正しい`character_jobs`で確認しています。レビュー対象操作で新しく発生したerrorとは分けてください。

## 9. 合格基準

次をすべて満たした場合に`承認`としてください。

- P0/P1の不具合がない
- 94件のデータ、migration、実行時変換に欠落・重複・意図しない差分がない
- 重点カードと攻撃なし6枚が仕様どおり
- 認証後UIがPC/スマホで正しく表示される
- 6戦闘経路が完走し、効果・報酬・状態管理に回帰がない
- 新規500、例外、二重処理、状態漏れがない
- 本番環境が未変更のまま

検証用データ不足などで必須項目を確認できない場合は、問題なしと推定せず`条件付き承認`または`未確認`にしてください。

## 10. 禁止事項

明示的な追加承認がない限り、次を行わないでください。

- 本番へのdeploy、migration、flag変更
- ステージングまたは本番の既存プレイヤーデータの直接編集・削除
- Seederのtruncate、全件更新、再投入
- migrationの再実行、rollback、migration tableの手動更新
- shared `.env`またはfeature flagの変更
- コード修正、commit、push、force操作
- バックアップの削除・上書き

通常の画面操作によるステージング戦闘は、検証用として許可されたキャラクターに限って実施してください。

## 11. 報告形式

以下の順で報告してください。

1. **総合判定**: 承認 / 条件付き承認 / 差し戻し
2. **Blockers**: P0/P1。なければ「なし」
3. **Non-blocking issues**: P2/P3。なければ「なし」
4. **確認結果表**: コード、migration、UI、6戦闘経路、重点カード
5. **再現根拠**: 実行コマンド、route、スクリーンショット、関連ログ、実測値
6. **Suggested fixes**: 修正案のみ。依頼中は実装しない
7. **Verification gaps / 未確認**: 理由と、確認に必要な前提
8. **Docs sync status**: 実装と`AI_CONTEXT / FEATURE_STATUS / CODEMAP / DATA_MODEL / DOMAIN_RULES`の整合
9. **本番ON可否**: `可` / `条件付き` / `不可`と、その理由

指摘には、可能な限りファイル・symbol・行番号または実画面の再現手順を付けてください。推測だけの指摘は`未確認`と区別してください。

## 12. このレビューの終了条件

認証後UIと6戦闘経路まで確認できた場合は、残る本番ON阻害要因を明示してください。検証用キャラクター不足でそこまで進めない場合は、コード・migration・公開境界の独立確認結果を先に確定し、実画面・実戦だけを未確認として引き継いでください。
