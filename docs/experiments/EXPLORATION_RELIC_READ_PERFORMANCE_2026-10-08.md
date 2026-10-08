# 探索50回の遺物読取・エリア取得の軽量化

2026-10-08。公開対象、公開結果は同一SHAのActionsと独立読戻し記録を参照。比較元は公開版 `f792763ab5a3c98fc40ae9f888488cd84b9b333d`。DB構造・設定変更なし。

## 現在の仕様、原因、再現条件、影響範囲

11:39の最新公開版の保存計測では、探索50回完了9件、SQL平均6,571.4本、応答中央値7,753.2ms、p95 28,835.5ms。要求/完了回数が揃わない旧計測の4.38秒とは直接比較しない。保存上限等による欠測とSQL詳細の省略あり。詳細2標本ではNamelessSchemaServiceの列/索引/制約検査、エリアの同条件読取が繰り返されていた。

戦闘効果のattachからactiveRelics、ordinarySchemaReadyへ入る際、正式NAM構造検査が3回走る。activeRelics単独も2回走る。探索では一戦のAreaを取得済みでもEnemyへ載せず、敵能力と危険度/報酬の計算から同じAreaを取り直していた。

## 最小修正

- NamelessRelicBattleService::attachとNamelessRelicEquipmentService::activeRelicsを既存NamelessSchemaService::withSnapshotで包む。共有は同期した読み取りメソッド内だけ。attach内のactiveRelicsはnested scopeになり正式検査1回となる。次の操作・例外終了後は破棄。装備/遺物データ、設定ON/OFFは各呼出で確認し、書込前のready/assertAvailableは既存どおりfresh。型・制約・移行履歴の検査を省略しない。
- ExplorationServiceの敵加工後、targetEnemy.area_idが今回のArea.idと一致する場合だけ、読み込み済みAreaをrelationへ設定。一戦ごとにAreaを取得し、別のsource areaを持つ地域ダンジョン等へ上書きしない。敵の抽選・能力式・報酬・成長・停止・再送防止・ロックとtransactionは維持。
- 遺物読取はPvE/PvP/遺跡/レイドの共通経路にも影響する。Area共有の入口は通常/ボスのExplorationService。地図/亜域は今回のArea共有対象外。

## 同条件のローカルPOST経路比較

SQLite、527種類の所持印、固定時刻/RNG、通常戦闘固定。ControllerとCommitExplorationRequest、実戦/報酬/保存を通す。入場/authはfixtureで代替、通信なし。NAM ONは装備中武器と装着済み遺物を追加。各条件3標本、外側transaction rollbackで同じ初期状態へ戻す。fixture作成は計測外。旧/候補は別プロセスで順番に測定。SQLiteの構造照会数はMariaDBと異なる。

| 条件 | SQL平均 旧→改善 | 処理時間中央値 旧→改善 | 保存状態・結果 |
|---|---:|---:|---|
| NAM ON・1回完了 | 362 → 278本 | 69.71 → 69.01ms | 一致 |
| NAM ON・10回完了 | 3,654 → 2,814本 | 499.09 → 466.21ms | 一致 |
| NAM ON・50回完了 | 18,042 → 13,838本 | 2,648.32 → 2,463.22ms | 一致 |
| NAM OFF・1回完了 | 151 → 143本 | 55.52 → 48.06ms | 一致 |
| NAM OFF・10回完了 | 1,084 → 1,004本 | 300.97 → 286.43ms | 一致 |
| NAM OFF・50回完了 | 5,192 → 4,788本 | 1,526.91 → 1,310.33ms | 一致 |

ONの50回でSQL23.3%減、処理時間7.0%減。保存状態は冒険者全属性・探索状態全属性・印数量合計・無銘武具/遺物全属性、結果は勝敗/EXP/Gold/職業EXP/成長/連続探索結果のhash。全条件・全標本で一致。勝利数50、探索力消費50も一致。ローカル値を本番6,571本/7.75秒への改善率と読み替えない。

## 本番データの読み取り専用比較

11:47:24、公開版f792763a。READ ONLY + REPEATABLE READの同一snapshotで、所持遺物のある冒険者の戦闘効果読取/有効遺物読取を各50回×旧/候補3標本比較。計測順は標本ごとに交互。候補classはCLIプロセス内のみ。実戦・資産更新・通知・本番ファイル変更なし、書込0、最後にrollback。

| 読取処理50回 | SQL 旧→改善 | 処理時間中央値 旧→改善 | 結果 |
|---|---:|---:|---|
| 戦闘効果読み込み | 1,450 → 650本 | 1,218.49 → 551.09ms | 全hash一致 |
| 有効遺物の取得 | 1,050 → 650本 | 962.64 → 561.02ms | 全hash一致 |

戦闘効果読取はSQL55.2%減、処理時間54.8%減。これらは重なる処理なので削減数を足して見積もらない。HTTP50回探索全体の改善値は公開後の同一要求/完了回数・公開SHAの計測で確認する。

## QAと振り返り§6の適用結果

- 確認済み: 主回帰234テスト/6338 assertions、追加の無銘実戦・通常装備遺物・国家レイド35テスト/259 assertions成功。計269テスト/6597 assertions。新規検査は正式NAM検査1回、遺物rank/装備ON/OFF変更の反映、未準備で閉じる、nested rollback、設定OFF、効果読取例外後の破棄、通常/ボスのArea共有と異なるsource areaの保持。
- 確認済み: 既存の50回完了/途中停止/敗北/時間切れ/状態更新/成長/報酬/支援/地域ダンジョン/無銘武具/遺物育成/破棄回復/PvP/レイドの該当回帰。ローカルPOST処理の保存値/結果一致と、本番読取結果一致・書込0。
- 確認済み: 変更PHP構文、git diff --check。後述根拠へ記録。
- 該当なし: DB構造/移行/seed/補填、画面・素材・公開assetsの変更、課金や通貨仕様変更、バランス数値変更。DOMAIN_RULESとDATA_MODELの変更不要。
- 未確認: 本番公開後のHTTP値/p95、認証済み本番実戦、実機スマホ、同時操作/ピーク/長時間負荷。公開結果と初期計測は同一SHAのActionsと独立読戻し記録を参照。本番利用者を使った50回探索は実行していない。
- Docs: AI_CONTEXT、CODEMAP、FEATURE_STATUS、UPDATE_LOGを同期し、管理者更新概要を追加。

根拠: `scratch/performance-qa/relic-read-http-equipped-{baseline,candidate}-{on,off}.json`、`relic-read-comparison-summary.json`、`relic-read-live-comparison.json`、`relic-read-regression-tests.txt`、`relic-read-adjacent-tests.txt`、`relic-read-syntax.txt`。旧本番基準は`next-priority-top5-20261008.json`と`next-priority-exploration-20261008.json`。private/local scratchは公開commitへ含めない。
