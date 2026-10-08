# 通常連続探索・操作内の最新状態と保存集約（第3段階）

2026-10-08。基準は第2段階のローカルcommit `3ec18c69366ebaac3e8807120098a2bf02b907cd`。第3段階もローカル実装・既定OFF・本番未反映。50戦を順番に判定し、通常戦闘の冒険者・職業・探索進行の最新値を操作内で管理する。50戦を10戦へ置き換えたり、報酬を倍率で代用したりしない。

## 適用条件と最小境界

`EXPLORATION_BATCH_STATE_ENABLED=false` を追加。第1・第2段階の切替もON、NAM機能ON、通常エリア、複数回、標準runnerに限定する。さらにPOST、CommitExplorationRequestが設定するserver属性 `committed_exploration_token` のUUID、外側の確定transactionを要求する。client入力だけでは有効にならない。直接serviceを呼ぶCLI等は第2段階までを使う。

このHTTP経路は、確定middlewareのtransaction開始後、最初に冒険者行をFOR UPDATEし、その後に再送記録をSELECTする。MariaDB REPEATABLE READで所有者ロックより前の古いsnapshotを持ちうる直接呼出元まで対象を広げない。job/state全体への範囲ロックは追加しない。現在のmiddlewareとbootstrapの順序をソースで確認したが、MariaDB上の並行実行試験は未実施。別のtransaction middlewareを追加する際はこの条件を再検証する。

`ExplorationBatchStateContext` はrequest-scopedの値配列を持ち、Eloquentのsave/refreshを全体で上書きしない。対象serviceの明示helperだけを置換する。モデルは毎回別インスタンスで返し、dirty列だけを最新値へ合成する。同じ冒険者・同じDB接続・同じtransactionに限定する。

- 集約対象：既存Characterの戦績・Gold残高・成長・HP/SP・探索力、CharacterJobのEXP/段階/マスター、CharacterExplorationStateの探索進行。許可列を明示する。身份・所属・職業変更・銀行・有償/無償輝石は対象外。
- 個別保存：各BattleLogの実ID、Gold増減ごとの台帳とbalance_after、素材・装備・印・卵、国家貢献、支援消費、公開ログ、称号等。IDを仮に置き換えない。
- 成長計算：各戦闘で実行し、次戦の能力・HP警告・停止判定は最新値を使う。職業EXPだけの変更は能力cacheを維持し、段階/マスター変更は失効する。所持品・報酬・NAM書込前のfresh安全検査は維持する。
- 通常以外：特殊イベントが決まった時点でpendingをflushし、その操作の残りを既存保存経路へ戻す。全特殊イベントの集約は対象外。`batch_state` は操作で方式を選択した記録であり、全戦が集約された保証ではない。
- 新規行とobserver：新規job/stateは元のINSERTで実IDを確保。許可外の列変更や既存のsaving/saved/updating/updated listenerがあればflush後に即時保存。pending後にlistenerが新設された場合は成功と推測せず例外でrollbackする。
- 通常のSQLからの参照：DB接続のbeforeExecutingで対象tableのSELECT/JOIN/DMLより先にpendingを保存。DML後は該当snapshotを破棄。DDL・親削除等は全体flush/破棄。未移植の既存読取にも最新値を返すための保守的な境界であり、SQLが完全に消えるとは扱わない。
- 確定/例外：図鑑flush→状態flush→外側の操作UUID/暗号化結果の保存→commitの順。途中commitや自動再実行を導入しない。正常停止は完了分を保存、例外はpendingと既存の個別記録を同じtransactionでrollback。savepoint開始時の配列を保持し、内側commit後の親rollbackも戻す。updated_atはflush時刻でなく論理保存時刻を維持する。

Gold台帳は各戦闘の残高を保持し、Characterの残高UPDATEだけをまとめる。直接残高を読む既存SQLがあれば先に保存する。Stripe、輝石台帳、決済、補償は変更しない。DB構造・migration・seed・マスタIDの変更はない。

## 固定条件の隔離比較

第2段階ONを基準に第3段階OFF/ON各3標本。実際のBattleControllerとCommitExplorationRequestを使うローカルSQLite試験。固定時刻とPHP/SQLite/Collectionの固定乱数、架空冒険者、所持印527種類、NAM武具/遺物、通常戦闘を使用。認証/入場のfixture代替あり。本番HTTPの計測ではない。各標本をrollbackし、SQLite複製は終了時に削除。query-log計測は前後とも同じ。

| 50回条件 | SQL平均 前→後 | DB平均 前→後 | 処理時間中央値 前→後 | 対象3表のUPDATE 前→後 |
|---|---:|---:|---:|---:|
| 成長あり | 8,976→5,529 | 448.3→293.3ms | 1,801.8→1,440.7ms | 202→3 |
| 最大レベル・職業マスター済み | 6,137→5,179 | 490.9→381.3ms | 2,213.2→1,693.5ms | 153→2 |
| 成長＋毎勝利Gold | 9,076→5,579 | 523.4→271.2ms | 2,170.2→1,330.9ms | 252→3 |
| Lv254/職業9から上限へ | 6,646→5,277 | 348.8→328.1ms | 1,530.6→1,674.7ms | 155→3 |

SQL削減は約15.6〜38.5%。上限到達条件の処理時間は悪化した。DB実行時間とDB以外を含む処理時間は別指標であり、SQL削減だけで本番の応答改善率は断定しない。前段資料とは別時点の計測なので応答時間を跨いで比較しない。

4fixture×1/10/50回の12条件、各旧/新3標本で結果と保存状態hashが一致。比較にはCharacter全属性、job、所持品、素材、印、BattleLog、Gold台帳、遺物、NAM武具、探索state、卵、図鑑、初戦/初勝利、公開ログ、称号を含む。Gold fixtureは試験専用BattleServiceで毎勝利7G、50回で350Gと台帳50行を保持。上限fixtureも試験専用EXP付与でLv255/職業10へ達する。運用の抽選・報酬ルールは変えない。

別の特殊イベントfixtureは2戦目にイベントを発生させ、1/10/50回の3条件も旧/新各3標本でhash一致。50回の対象UPDATEは201→197で、fallback後は逐次保存へ戻る。合計15条件・90標本は隔離fixtureの回数であり、本番のアクセス数ではない。全特殊イベント・所持構成・通知・国家状況の網羅試験ではない。

生データは `scratch/performance-qa/batch-state-{growth,max,gold,master,special}-{off,on}.json`、集計は `batch-state-comparison.json`。秘密情報・実プレイヤーID・SQLリテラルを成果物に含めない。

## 確認と残作業

確認済み：逐次成長/マスター/上限、毎戦のGold残高台帳・BattleLog、50回完了、HP警告/探索力不足/敗北/timeout/イベント停止、初回不足、操作UUID再送、保存失敗とセッション復元、対象3表のflush失敗による図鑑/ログ/台帳の全rollback、raw SQLの最新値、別所有者、許可外列、observer、新規job/stateの実ID、savepoint rollback、client tokenのみ/GET/直接呼出元の適用除外。

確認済み：追加/関連48テスト648 assertions（既定OFFの確認とテスト内のON/OFF比較）、全切替ONの既存回帰196テスト1,712 assertions、確定middleware経路の全切替ON追加確認11テスト59 assertions。HTTP限定条件追加後も実controller比較9標本で結果/保存状態/SQL本数/方式が既存計測と一致。変更PHP27ファイルの構文、Blade compile、diff check成功。方式`batch_state`の保存/絞込と500時の成功回数除外も確認済み。旧記録や再送の方式欠落を旧処理と推測しない。

NAMELESS_WORKSHOP_RETROSPECTIVE §6/QA_CHECKLIST：台帳・報酬・保有値・所有者・順次成長・停止・再送・例外・ON/OFFは隔離自動確認済み。MariaDBの並行操作/deadlock、staging/本番、実アカウントの基本ループ、スマホ実機、長時間負荷は未確認。JS/CSS/Bladeのソース変更なしのためfrontend build再実行は該当なし。migration、補償、課金、画像制作、公開smokeは対象外。

公開は別の明示指示後。まずOFF配備、同一SHAのstagingで対象経路ON、成長/停止/再送/意図的失敗/並行操作を確認する。その後、公開版・要求/完了回数・処理方式を揃えて性能を観察する。詳細抽出率、保存容量/件数上限、欠測・省略を明記し、保存件数を全アクセスと扱わない。切戻しは状態切替OFFで第2段階、図鑑切替OFFで第1段階、読取切替OFFで元の経路。データ変換やDB巻き戻しは不要。

残る約5千本のSQLには各戦闘の報酬・所持品・安全確認・監査等を含む。これらの一括化は本変更の範囲外。次の削減は同じ安全境界で上位SQLのcallerを確認してから判断する。
