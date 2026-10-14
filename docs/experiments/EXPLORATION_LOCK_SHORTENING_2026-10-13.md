# 探索のロック保持時間短縮（2026-10-13準備、2026-10-14公開対象）

## 根拠と修正範囲

- 本番基準は `9146c19617330819bddc92da1e7b04d20668f1fc`。本番コードと保存済みの性能記録を読み取り専用で確認した。専用worktreeで修正し、既存の無関係な変更を取り込んでいない。
- 10/13 01:04:43の要求50・完了50の保存記録はtransaction 36.453秒、SQL 11,078件（DB計測7.597秒）。印の集計153回、開拓度502回、名もなき武具読取459回等の重複が確認できた。SQL形状の集計は値・テーブル名をまとめるものがあり、metadataの最初の呼出元を全件の呼出元とは扱わない。
- `BattleReadScope` は同期探索・チャンプ戦の明示した操作内で、印の恒久加算、名もなき武具本体の加算、開拓度だけを共有する。モデルや最終能力値は保持しない。所持値はtransaction外では共有しない。
- QueryExecutedによる依存テーブルのDML、rollback、最外transactionのcommit、操作終了で破棄する。途中の印取得・装備強化・開拓完了は直後の読取へ反映する。別の冒険者の結果を共有しない。
- `BattleSchemaMetadata` は同じ操作内のSchema::hasTable/hasColumn/hasColumns/getColumnListingを共有する。ほかのSchemaメソッドは元へ委譲してmetadataを破棄。終了・例外時に元のFacade/container状態へ戻し、後続接続を固定しない。
- 50回の探索結果・消費・報酬・再送記録の原子的確定を維持する。帰還・チャンプ戦のtimeoutや再試行回数は変更せず、競合元になり得る探索中の重複処理を減らす。DB構造・認証・経済・マスタ・UIの変更なし。

## 隔離MariaDB比較

MariaDB 10.5.13 / PHP 8.4、専用loopbackポート13339、新規 `valzeria_champcheck_20261013_read_scope` のみ使用。旧版の変更対象5ファイルをGit基準から取得し、autoloadを切り替えて比較。実ExplorationService/BattleServiceの50回を実行した。syntheticの高能力キャラクター、弱い敵、印128/512種類、名もなき武器・鎧を使用し、特殊イベントは試験用subclassで無効、otherworld到達済み、戦闘の乱数を各sampleで固定。各試験はsetup後の外側transaction内で実行して全体をrollbackした。本番プレイヤーの探索は実行していない。

| 所持印の種類数 | 各版の件数 | 旧版の実行時間中央値 | 修正版の中央値 | 短縮率 |
| --- | --- | --- | --- | --- |
| 128 | 3 | 3.733秒 | 2.824秒 | 24.3% |
| 512 | 3 | 4.454秒 | 2.839秒 | 36.3% |

- 全6組で要求50・完了50、Gold・EXP・勝利数・探索力・level・集計Goldと比較digestが一致。全所持品・全台帳を同一乱数で照合する性能試験ではなく、それらの安全性は関連回帰・並行操作検証で別に確認した。
- 印集計のSQLは150→1、名もなき武具読取は650→203。metadataは初回527→30、後続は506〜507→9〜10。残る装備読取は遺物等の更新があり共有対象外。SQL全体は各試験条件で削減したが、残る非固定抽選等によりseedが同じでもSQL数に小さな変動がある。
- この値は隔離環境の処理時間。HTTPの全経路・共有ホスト・本番の待機時間を再現したものではない。約37秒が同じ割合で短縮すると断定しない。競合3件の実際の保持接続とこの性能記録の同一性は未確認。

## 検証と限界

- 確認済み: 最終版の関連PHPUnit 121テスト・3,128 assertions。印/武具/開拓度の途中更新、別所有者、OFF、schema不在/DDL、例外、接続切替、rollback、nested/independent commit、成長後の能力、探索の停止・報酬、middlewareの失敗/再送を含む。
- 確認済み: 隔離MariaDBの既存champ/return/item concurrency verifier。並行挑戦、古いHPの再計算、FK保護、報酬と戦績の一度だけの確定、卵/帰還sessionの原子的更新、所持アイテムの二重消費防止、継続競合時の資産保持を確認。
- 既存失敗: ChampBattleContentionTest 9件のうち7件は旧版・修正版ともInjected database failure。RefreshDatabaseの外側transactionがあるため、現行runnerのouterLevel保護により再試行せず再throwする。修正版固有の失敗とせず、同じ競合経路は実MariaDBの別process verifierで確認した。
- 試験制約: 関連テストの一括実行はPHP128MB上限で途中停止したので、テストprocessだけ512MBへ変更して全件を実行した。本番設定は変更していない。初回champ concurrencyはretry barrier前に2秒の予算を超えて停止したが、旧版比較と修正版単独の再確認・最終版再確認は完了した。高負荷時も必ず再試行できる証明とは扱わない。
- 未確認: 本番配備、本番での再発率と最大transaction、長時間/ピーク負荷、認証済み実プレイヤー操作。UI・画像・build・有償取引・migration追加は該当なし。
- 比較script・6組のjsonl・PHPUnit結果・MariaDB verifier出力は専用worktreeの `scratch/`。配備後は同一release・operation・要求/完了回数を分け、30分単位の全範囲で件数と保存capacity/busy欠測を併記して比較する。

## 最新本番への公開前再検証（2026-10-14）

- 最新本番 `5383a9a7d322ae7ff561b957e35277d52d3bb662` へ修正本体を統合。取得した本番資材の変更対象5ファイルがGit基準と一致し、対象外の既存変更は保持。
- 独立vendorを使用し、同じ関連121テスト・3,128 assertionsを再実行して成功。loopback MariaDBのchamp/return/item並行操作検証も同じ候補で再実行する。
- 配備と配備後の本番性能・ログは別の公開記録で確認する。短時間の競合0件を完全解消とは扱わない。
