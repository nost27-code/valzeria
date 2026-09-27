# 定期監視で確認したロック競合・退会失敗の修正

基点: `642412edffca621bd672ce8d4caf139bf0cf986e`。独立worktreeで実装。本番DB更新・公開は未実施。

## 原因と対策

| 対象 | 根拠と変更 | 主なファイル |
|---|---|---|
| 所持品監視 | 全員分のupsertが、外部キー検査で探索中のCharacterロックを待つことをローカルMariaDBで再現。一人ずつNOWAITロックし、現在数・検知・基準値を同じ短いtransactionで確定。使用中なら基準値を残して次回へ回す。検知条件・閾値は変更しない。 | `CharacterBackgroundOperation`, `SecurityAnomalyDetectionService` |
| 最終アクセス | middlewareの通常UPDATEが探索中Characterを待っていた。NOWAITで取得できたときだけ日時を更新し、他の能力・残高・updated_atを変更しない。 | `CharacterPresenceService`, `CheckCharacterSelected` |
| レイド受付 | 全体coordinatorを保持したままCharacterを待っていた。参加行の作成前にCharacterをNOWAIT取得し、使用中なら受付をrollbackして案内する。出撃・消費・戦闘計算の再実行なし。 | `NationRaidSortieService` |
| 戦闘結果表示 | 保存済み結果の復元時のデッドロックに対し、DB読取だけ最大3試行。戦闘や報酬は再実行しない。 | `BattleController::showResult` |
| 退会 | 本番のRESTRICT参照5か所をread-only照会で確認。ユーザー裁定に従って所有地図を公開終了にし、人物参照をNULLにして探索・収益履歴を保持。精算待ちは退会transaction全体を中止。未公開登録を終了一覧へ露出させず、過去の終了日時も更新しない。 | `AccountDeletionService`, 地図・退会Blade, `2026_09_28_010000_preserve_map_history_after_account_deletion.php` |

現在のModelsはguarded=[]でnullable参照を受け入れ、belongsToは退会後nullを返す。追加のfillable/casts変更は不要。プレイヤーの参照先・表示を揃えた。

本番の全ロックエラーの同時刻の待機グラフは取得できていないため、すべてが監視upsert由来とは断定しない。再現できた競合経路を除去し、最終アクセス・レイド・結果表示にも個別の対策を入れた。

## 検証

- SQLite: 関連102テスト / 965 assertions成功（退会履歴保持・精算待ち・rollback保護、探索の消費/報酬/再送、国家・レイド、監視、最終アクセス）。その後の未公開地図の露出防止を含め、最終版の退会テスト2件 / 21 assertionsもMariaDBで成功。
- MariaDB 10.5.26: 同じ102テストの実行では99成功、既存のSQLite専用記述による失敗2、明示skip1。失敗は国家納品テストのSQL引用符比較とレイド計測テストのSQLite専用trigger。変更前の対象クラスを先に読み込んだ比較実行でも同じ2件が失敗したため、MariaDB全件成功とは扱わない。
- 専用ローカルMariaDB / REPEATABLE READ / port13328で18項目成功。旧全件upsertの外部キー待ちを対照として再現。修正後の在席更新・監視が使用中Characterを待たず別Characterを処理し、次回に保留した基準値を更新することを確認。実レイド受付が消費なしで戻り全体coordinatorを解放すること、実結果Controllerの読取が一度の競合後に成功し報酬を追加しないことを確認。NULL履歴がないDBでmigration down/upも成功。
- 再現スクリプト: `scripts/verify/background-lock-concurrency.php`。`APP_ENV=testing`、`DB_DATABASE=valzeria_lockcheck_<unique>`、専用ローカルMariaDB 10.5.26・port13328・migration済みの捨てられるDBが必要。試験用アカウントとレイドを作るため、本番・共用DBでは実行しない。NULL履歴があれば安全のためdownで停止する。
- Blade compile、PHP構文、git diff --checkを確認。画面はテキスト変更のみ。認証済み本番操作・スマホ実機・本番公開後の再発率は未確認。

NOWAITは[MariaDB公式仕様](https://mariadb.com/docs/server/reference/sql-statements/transactions/wait-and-nowait)に基づき、本番と同じバージョンで検証した。ロック待ち時の1205は最初のNOWAIT取得だけで判定し、任意のDBエラーや処理中の失敗を握りつぶさない。

## 公開時の注意

- DB変更あり。4テーブル・5参照をnullable / ON DELETE SET NULLへ変更する。レコード削除・既存マスタや報酬の更新はしない。
- 公開には別途本番公開指示が必要。ステージングを先に確認し、`migration_mode=maintenance_required`でmigrationと新コードへの切替を同じ停止区間で行う。旧コードのままnullable参照で退会を受け付ける時間を作らない。
- 退会でNULL参照が生成された後はmigrationのdownを拒否する。古いコードへの単純な切り戻しも避ける。バックアップと、履歴を失わない復旧方針を公開前に確認する。
