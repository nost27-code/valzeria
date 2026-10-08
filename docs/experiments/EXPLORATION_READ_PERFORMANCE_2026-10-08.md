# 探索の読み取り負荷調査と最小修正

2026-10-08。公開対象、公開結果は同一SHAのActionsと読戻し記録を参照。比較時の本番SHAは574740fbb2afcc3657826522b30c44b1be64f416。

## 現在の仕様・原因・再現条件・影響範囲

POST battle.exploreは1回探索と最大50回探索を同じ操作名で集計している。10:06の保存記録は19リクエスト、平均4,981.5 SQL/回、平均DB2,840.6ms、応答中央値6,385.8ms、p95 19,717.7ms、詳細5標本、同条件読取平均2,258.4本/標本。記録の保存欠測・SQL詳細省略がある。SQL条件が同じでも途中更新後の値は異なり得るため、重複数だけで削除・cache可能とは判断しない。
直近30分のゲーム統計は通常探索1回25件、10回15件、2回1件、21回1件、50回42件（各公開版を跨ぐ別集計、管理計測19件との一対一対応はない）。50回は計1,613回完了し途中停止もある。探索1回と50回を同じ処理時間の目標にしない。
代表SQL詳細はDiscovery/StorageCapacityの列確認、NAM構造検査、所持印からのEnemy取得、ExplorationStateの再読取。1つのSQL形式の呼出元は代表例で全呼出元ではない。DB時間より長い応答にはPHP等の処理時間もあり、印を多く持つデータの関連モデル構築も重い。

## 修正

- DiscoveryService/StorageCapacityService/MonsterMarkAlchemyServiceの存在確認を既存SchemaStateServiceへ委譲。同じHTTP request内だけテーブル・列情報を再利用し、次requestで新しいscopedインスタンスを使う。NAMの型/制約/移行台帳検査と正式な準備・runtime readinessは変更しない。
- MonsterMarkService::permanentBonusesの所持印・MonsterMark・Enemy・Areaの多段eager loadを、所持印/印マスタ/敵の判定項目のJOINへ変更。毎回最新の所持quantityを読む。従来のPHP signature/閾値/能力式を使い、同エリア/同名合算、inactive/boss/ダンジョン主除外、最小active印IDの能力採用を維持する。SQLでname集計しないためcollationによる別名の合算も増やさない。
- 通常/ボス/地図/亜域の探索回数、停止条件、資産/報酬/戦績、actor lock・transaction、再送の確定・rollbackは変更しない。印の永続ボーナスは共通最終能力で使うため、探索以外の戦闘・表示も読取方法の影響範囲。Blade/JS/画像/DB schema/seed変更なし。

## 本番データの読み取り比較

全てREAD ONLY＋REPEATABLE READ、同じsnapshotでold/candidateを各1回比較。候補はプロセス内の別クラスとして評価し、本番ファイルや設定を変更しない。終了rollback。個人ID・能力値は出さずhashで一致判定。実探索・報酬・通知は実行しない。

| 比較範囲 | 旧 | 候補 | 結果 |
|---|---:|---:|---|
| 構造guard/所持枠/印錬成の1回読取SQL | 14（metadata10） | 17（metadata13） | 判定/所持枠/bonus hash一致。初回は既存hasColumn helperのtable確認分が3SQL増える |
| 同10回SQL | 140（metadata100） | 53（metadata13） | hash一致 |
| 同50回SQL | 700（metadata500） | 213（metadata13） | hash一致、DB302.99→38.43ms、全体350.19→58.02ms |
| 所持印527行の永続印ボーナス1回 | 4SQL、89.49ms | 1SQL、36.60ms | 全8能力のhash一致 |
| 同10回 | 40SQL、942.45ms | 10SQL、314.65ms | hash一致 |
| 同50回 | 200SQL、4,153.72ms | 50SQL、1,110.02ms | hash一致、DB684.43→204.82ms |
| 同データの最終能力50回 | 1,650SQL、4,715.76ms | 1,353SQL、2,668.96ms | 全最終能力hash一致、metadata550→403、DB1,199.87→1,027.71ms |

書込はいずれも0。最終能力は各回でCharacterStatusService cacheを破棄。NAM inspectorは各能力計算で従来どおりfresh。部分の測定を足して探索全体の短縮時間に読み替えない。順序/サーバー負荷/cacheに左右される1回の比較である。

## 検証と限界

ExplorationSchemaReadReuseTestで50回のschema照会数が初回から増えず、途中の発見進行・遺物取得・印錬成の変化を最新読取で反映、機能OFFの所有枠、owner列不足と次request相当のschema再確認を検証。MonsterMarkServiceTestで同名印の統合/別エリア分離/保有増加/inactive/boss/ダンジョン主除外を検証。既存の連続探索・低HP/探索力停止・敗北/時間切れ・再送/rollback・共有倉庫満杯・印錬成・能力表示・NAM snapshot・チャンプ/闘技場回帰を含む70テスト/632 assertionsが成功。変更PHP7ファイルの構文、git diff --checkも確認済み。Blade/JS/CSS変更がないためビルド・狭幅再確認は該当なし。

本番POSTの実行はしていない。全探索のHTTP応答改善、ピーク/並行操作・長時間負荷、実機・認証済み本番の戦闘は未確認。正式なNAM gateの長期cacheや探索状態の再利用は導入しない。§6の資産保護/途中停止/再送/rollback/ON-OFF/大量データ性能を適用し、UIや画像・満杯仕様変更は該当なし。

公開の明示指示を受領。staging→productionの同じSHAでmigration_mode=noneを指定し、コード/設定/台帳/healthを読戻す。実操作の性能は新しい公開版の保存計測で別途評価する。本番でテスト戦闘や既存資産の更新・補填は行わない。

元記録: scratch/performance-qa/exploration-live-before.json、exploration-count-distribution.json、exploration-schema-comparison.json、exploration-mark-final-comparison.json、exploration-stats-comparison.json、exploration-final-tests.jsonl（ローカル）。
