# チャンプ戦の競合改善

基点は本番SHA `78ce78dedb7583f3dfb5cb6c8f19b6f7dc90e166`。独立worktree `champ-contention-20261004` で実装。本番反映は未実施。

## 確認した原因

- 旧実装は挑戦者をロックした後、共通の `champ_states` 行をNOWAIT取得し、戦闘計算・報酬保存まで保持する。同時挑戦は計算中でも即時拒否される。ローカルMariaDBでこの保持範囲を対照として再現した。
- 戦闘ログ・交代履歴の人物参照には現チャンプのCharacterへの外部キーがある。本人が探索等でCharacterを更新中だと、共通行を保持したまま後続保存が待ち得る。
- 本番の読取専用照会で `champ_states.appointed_at` が `ON UPDATE current_timestamp()` であると確認した。HP・防衛回数だけの更新でも就任日時が変わるため、別の挑戦や自動再試行を「交代」と誤判定する。同設定のMariaDBで再現した。
- 過去の警告はコード1205だけで、発生段階や時間を含まない。監視された全件の直接原因や、当時の待機時間までは特定できない。

## 変更

- 挑戦者のロックを保持して戦闘計算し、保存直前に共通チャンプ行を取得する。最新の全保存属性を読み、HP・SP・防衛回数・能力値・就任情報が変わっていれば、未確定の計算を破棄してtransaction全体から再試行する。
- 現チャンプCharacterの参照ロックを共通行より先に取得する。全SQLのInnoDBロック待ち設定を一時的に1秒へ制限し、終了時に元へ戻す。再試行は最大3試行、既に2秒以上経過した失敗では追加試行しない。SQLのタイムアウト判定粒度や計算時間があるため、要求全体の2秒保証ではない。
- 報酬・HP/SP・防衛数・交代履歴・ログ・クールダウンを同じtransactionで確定。再試行前にロールバックし、レベルアップにより作られた能力値キャッシュを破棄する。計測保存は成功後の1回だけ。
- 防衛および能力再計算では就任日時をUPDATEに明示し、自動変更を防ぐ。実際のチャンプ交代は従来どおり新しい就任情報を保存する。
- 解消しない競合は段階・試行数・経過時間・DBコードだけを警告に記録する。SQL・プレイヤーID・秘密情報は追加しない。競合以外のDBエラーを再試行案内で隠さない。

ゲームルール・報酬量・スキーマ変更なし。Controller/View/認証の変更なし。

## 検証

- SQLiteおよび専用ローカルMariaDB 10.5.13で、チャンプ・戦闘順・戦技ログ・報酬上限・結果保存の45テスト / 149 assertionsがそれぞれ成功。初期行がない場合の保護を加えた後、対象のSQLite 6テスト / 39 assertionsも成功。
- MariaDB / REPEATABLE READの別プロセス試験22項目成功。旧方式の計算中ロックを再現し、修正後の並行計算、最新HPからの再計算、同一人物の二重送信、実際の交代時の中止、就任日時の維持、初期行の復旧を確認。挑戦者・チャンプ本人・共通行の継続ロックでも部分保存なし、待機設定復元・transaction終了を確認した。チャンプ本人のロック試験は約2.13秒で案内へ戻った。
- 報酬付与・チャンプ更新後に故意にDB競合を起こすテストで、報酬と交代履歴の二重付与がないこと、継続失敗で経験値・素材・HP・クールダウンが残らないことを確認。
- 拡張検索で実行した93テストは92成功・1失敗。失敗は `HomeInitialLoadPerformanceTest::test_home_renders_status_champ_actions_cached_weekly_ranking_and_chat_immediately` の既存HTML文字列検査。テストと `resources/views/components/layouts/app.blade.php` は基点と同一で、期待する `<livewire:chat-log />` と現在のkey付き記述の不一致。今回の修正には含めない。
- PHP構文・差分の空白検査成功。認証済み本番操作、スマホ実機、本番反映後の競合率は未確認。

再現スクリプトは `scripts/verify/champ-lock-concurrency.php`。`APP_ENV=testing`、migration済みの使い捨てDB `valzeria_champcheck_<unique>`、専用ローカルポート13329が必須。テスト用アカウント・報酬・チャンプ状態を書き込むため本番・共有DBでは実行しない。

## 変更対象

- 本体: `app/Services/ChampBattleService.php`、`app/Services/ChampBattleTransactionRunner.php`、`app/Exceptions/ChampBattleStateChangedException.php`
- 検証: `tests/Feature/ChampBattleContentionTest.php`、`tests/Feature/ChampStatsRefreshTest.php`、`scripts/verify/champ-lock-concurrency.php`
- 文書: 本書、`docs/CODEMAP.md`、`docs/UPDATE_LOG.md` のUnreleased
