# 連続探索の追加軽量化と比較測定

2026-10-08。公開対象。公開結果は同一SHAのActionsと独立読戻し記録を参照。比較の基準は本番/HEAD 475039658e6e8577445b7a6ba222dc53827014e5。

## 現在の仕様・原因・再現条件・影響範囲

10:45の公開版別保存計測はPOST battle.explore 35件、SQL平均6162.3本、DB平均3014.7ms、応答中央値4378.6ms、p95 27996.5ms。1回/50回/途中停止を混ぜるため、これを50回固定の候補測定に直接比較しない。metadataはSQLの約24%。NAM正式検査・一般的なテーブル/列確認、探索状態、所持印の永続ボーナスが詳細で繰返される。DB時間以外の処理も長い。

## 最小実装

- JobArtService、RegionDepthDungeonService、EnemyDiscoveryService、EquipmentDiscoveryService、ExplorationMapDropServiceの一般的な存在確認を既存request-scoped SchemaStateServiceへ寄せる。設定・戦技・所持品や図鑑のデータ自体は毎回読む。次requestでは再確認。
- ExplorationStateServiceをscopedにし、ExplorationService::exploreで冒険者行ロック取得後、一戦だけwithLockedStateを使う。状態をclone返却し未保存の変更を共有しない。QueryExecutedの対象テーブルDML（Eloquent/Query Builderとも）とTransactionRolledBackで読み取りを失効する。終了/例外時に破棄し、他キャラクターとtransaction外は共有しない。getOrStartの初回作成・別エリア初期化を維持。
- MonsterMarkService::permanentBonusesは必要列の最新JOINをraw取得。PHP signatureで同エリア/同名を合算し、最小active印ID、数量閾値/最大level、HP/SP倍率と8能力の式を維持。無効・ボス・ダンジョン主の除外を維持。SQL文字列collationでの名前集計や所持数量のcacheは導入しない。
- RequestPerformanceCollectorへ任意exploration_count要求/完了回数を追加。serverの保存対象結果だけを採用し1..50と完了0..要求を検証。旧記録/再送/早期拒否は回数不明のまま。個人ID/本文/結果全文は追加しない。
- 通常/ボス/地図/亜域、停止条件、報酬/成長/探索力、actor lock、commit/rollback、操作UUID再送、正式NAM schema readinessのfresh検査は維持。DB schema/migration/seed、Blade/JS/CSS/画像変更なし。状態共有の実装入口は通常/ボスexplore。一部の亜域/地図には適用せず一般schemaと共通印計算だけが影響する。

## 同条件のローカルPOST処理経路比較

SQLiteの隔離fixture、所持印527種類、固定された通常敵、固定時刻とseed。各1/10/50回で旧/候補3標本ずつ。ControllerとCommitExplorationRequest、実際の戦闘・報酬・能力・状態・保存を通し、50回を途中停止せず完了させるためspecial-event抽選だけを通常固定、入場可否/authはfixtureで代替。完了後に外側transactionをrollbackし同じ初期状態へ戻す。HTTP通信・認証画面・本番DBの測定ではない。初期fixture作成は計測外。旧classは基準HEADのsourceをプロセス内に読込む。

| 条件 | 旧/候補標本 | SQL平均 | 処理時間中央値 | 保存後状態hash |
|---|---:|---:|---:|---|
| 1回完了 | 3 / 3 | 169 → 151本 | 90.47 → 50.56ms | 一致 |
| 10回完了 | 3 / 3 | 1299 → 1084本 | 823.49 → 323.18ms | 一致 |
| 50回完了 | 3 / 3 | 6285 → 5192本 | 4311.93 → 1540.91ms | 一致 |

50回のSQL約17.4%減、処理時間約64.3%減。全標本で完了数、冒険者全属性・探索状態全属性・所持印合計hashが一致。これを本番35件の4.38秒やp95への改善率と読み替えない。3標本で長時間/ピークを保証しない。

## 本番データの読取専用比較

11:07の本番475039、READ ONLY + REPEATABLE READの同じsnapshot内でold/candidateを比較。候補はCLI内の別classで本番ファイル/設定を変更しない。527所持印、8能力と全最終能力をhash照合、書込0、終了rollback。実探索・報酬・通知なし。

- 永続印ボーナス50回: 50 SQL → 50 SQL、942.72ms → 73.10ms（約92.2%減）。DB55.41 → 35.99ms。
- 最終能力50回: 1353 SQL → 1353 SQL、2012.76ms → 1112.19ms（約44.7%減）。DB646.49 → 589.42ms。
- 両方の結果hash一致。SQL数が同じでも、取得項目とEloquent model/Collectionの組立を省くことでPHP処理を減らした。1回ずつの順序付き測定で負荷/cacheの影響があり、HTTP全体とは別に扱う。

## 検証・§6とQAの適用結果

- 確認済み: 関連123テスト/1043 assertions成功。構造確認50回固定＋途中strategy更新、状態50回1読取、未保存変更の遮断、Model/Query Builder更新、削除/作成/エリア切替、nested rollback/例外/他人の状態隔離、印重複/別エリア/最新数量/無効/boss/ダンジョン主除外。
- 確認済み: 既存連続探索の50回完了・低HP/探索力停止・敗北/時間切れ・commit再送/結果保存rollback、戦技構成/strategy gate、支援装備/地域ダンジョン、印錬成/表示、NAM fresh準備、チャンプ/闘技場の回帰。集計に探索回数を記録しても再送/失敗/個人情報を混ぜない。
- 確認済み: 本番READ ONLYで全能力一致/書込0。ローカルPOST処理経路1/10/50回の同条件比較と保存状態一致。
- 該当なし: DB変更/移行/補填、UI/狭幅/画像/公開素材変更、戦闘・報酬数値の変更。
- 公開結果は同一SHAのリリース記録を参照。未確認: 公開後HTTP応答/p95、認証済み本番の実戦、実機、ピーク/並行操作・長時間負荷。本番公開後は要求/完了回数と公開SHAで保存計測を分けて収集する。旧記録には回数がないため不明として保持する。
- 初期失敗: 新規状態テストのarea=1固定でFK違反、比較fixtureの旧stat列名や途中深度停止を修正。最終123件と固定比較は成功。これらを本番障害と扱わない。

根拠（ローカル）: scratch/performance-qa/exploration-batch-union-tests.txt、exploration-http-before/after/comparison.json、exploration-http-pipeline-benchmark.php、exploration-real-before/after-fixed.json、exploration-batch-mark-live-comparison.json、top5-live-readonly.json。管理者更新概要とAI_CONTEXT/CODEMAP/FEATURE_STATUS/DATA_MODEL/ADMIN_REQUEST_PERFORMANCE/UPDATE_LOGを同期。
