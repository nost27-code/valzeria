# 管理者向け 処理負荷・DB分析（2026-10-08）
- 探索回数別の比較: 新しいPOST battle.explore概要には、server結果から確定した要求回数/完了回数だけを任意exploration_countへ保存する。早期拒否・再送・旧記録には付かない。公開版に加え、要求/完了回数が同じ記録を比較する。既存の画面集計は従来どおり混合集計で、回数別集計は保存記録の読取で確認する。SQL詳細の抽出有無にかかわらず概要へ付く。

実装のみ。本番未公開。作業用チェックアウトは公開版マーカーと同じコミット61336e3eb88580d71283d43045fdde2febe6e02cから分離し、元のFFA作業ツリーの未コミット変更を保持した。公開時は最新mainと実際の公開コードとの差分を再確認する。

## 入口と目的

通常の管理者ログイン → 検証 → 処理負荷・DB分析。GET /admin/request-performance、route名 admin.request-performance。既存auth/adminガードを使い、未ログイン・一般プレイヤー・閲覧専用viewerには許可しない。参照のみで、SQL実行・EXPLAIN・索引作成・設定変更・計測データ消去は提供しない。

| 対策 | 見る指標 | 判断時の確認 |
| --- | --- | --- |
| ② 重複取得・計算の削減 | SQL平均、同条件の重複読取、SQL形式、代表呼出元 | 同じリクエストのSQLとbindingsが同じ読取を識別。書込前後や別接続の結果差に注意し、再利用の安全性は元コードで確認 |
| ③ 表示用キャッシュ | 呼出頻度、読取/構造確認割合、書込/セッション/キャッシュSQL、頻出SQL | 条件が違う同形式SQLと同条件の重複は別。表示・マスタに限り、無効化・鮮度・利用者差を確認。残高・報酬・戦闘判断のキャッシュ可否は確定しない |
| ④ 遅いSQL・索引 | DB合計/平均、最長SQL、詳細SQLの合計/最大時間、代表呼出元 | 元コードの値で別途EXPLAINする。SQL詳細は匿名化されており実行可能なSQLとして扱わない |
| ⑤ ロック競合 | 例外件数、チャンプ状態変更、競合段階、最長transaction、明示ロックSQL時間、HTTP 5xx | 再試行中に回復した競合も集計。純粋な待機時間・ロック保持時間の測定ではない。報酬・資産・クールダウンの原子性を保持する |

期間15分/1時間/6時間/24時間、終了時刻（日本時間）、公開SHAによる絞り込み、5種類の並び替え、操作別詳細を提供。直前の同じ長さの期間と回数・1回あたりDB時間・応答p95を比較する。公開版が混在する場合は版の絞り込みを使う。サンプルが少ないことやアクセス構成の差から効果を断定しない。

## 計測範囲

- グローバルHTTP middlewareの入口から、内側の処理が戻るまで。通常のDB認証・セッション保存も含む。収集結果の整理/保存とブラウザ通信時間は応答時間に含まない。
- 管理画面、管理Livewire更新、ヘルスチェック、Livewire配信アセット、CLI workerは除外。サーバー全体のThreads_connectedやDBユーザー別接続数はこの機能では収集しない。
- 通常HTTPはmethodとroute名（名前がなければrouteテンプレート）。URL値、クエリ文字列、Referer、ユーザーID/IPは保存しない。
- Livewire 4のバージョン付きURLは名前末尾livewire.updateで識別。既存App\Livewire\Componentに対応する名前と実在するpublicメソッドだけをラベルにする。未知名はother。複数componentは1通信としてまとめ、各componentに全SQLを重複配賦しない。
- QueryExecutedで成功SQLの本数と時間を計測。失敗SQLの所要時間は取得できず、DB時間には含めない。成功SQL時間にも通信/待機が含まれ、実CPU時間やロック待ちだけの時間ではない。
- transactionイベントは接続ごとに最外周のbeginからcommit/rollbackまでを記録。nested savepointでは二重計上しない。開始前から存在するtransactionは計測対象外。
- 既存exception reportとチャンプ戦catchに観測フックのみ追加。チャンプ戦の計算、報酬、ロック順序、retry回数は変更しない。同じ例外の重複報告を除く。全機能の内部で握りつぶされた例外を網羅するわけではない。

## 保存・負荷制限・個人情報

DB migration・テーブル追加なし。既存DB行への新規書込みなし。storage/app/private/request-performance の分単位JSONLへ、非ブロッキングflockで追記する。収集・保存失敗からDB/loggerへフォールバックしない。観測コードの障害をゲーム処理へ伝播させない。

概要は対象通信をすべて試みる。詳細の既定抽出率20%。SQLのliteral・コメント・数値は除去し、bindings/生SQL/例外メッセージ/stack引数を永続保存しない。同条件の読取判定用hashはリクエスト内だけに保持。文字列が閉じていない失敗SQLも末尾を除去する。

- 1リクエストの詳細は最大64形式、同条件読取の記憶最大512条件。時間/頻度/重複/失敗の各上位を統合し最大12形式を保存。SQLは500文字、代表呼出元はapp配下の相対パスと行。
- 1分128KiB、保存48時間（JSONLの理論上限約360MiB、gap marker等は別）。1時間ごとのbest-effort清掃。保存容量が近いと詳細を省略して概要を優先。それでも超過・保存競合時は欠測markerを残す。
- 各期間の読込は8MiBまたは20,000記録まで、最新分から。概要上位100操作、選択操作SQL詳細は最大256形式を集約し上位30表示。
- 容量超過・競合・破損・読込制限・詳細省略は管理ページに表示。欠測がある場合、回数/総時間は保存・読み取れた範囲の下限であり、全トラフィックや正確な時系列比較を意味しない。
- REQUEST_PERFORMANCE_ENABLED=falseで停止。REQUEST_PERFORMANCE_SAMPLE_RATEで詳細抽出率を0〜1に調整。保存先・上限はconfig/request_performance.php。共有storageが保持される配備が前提。管理ページで保存先未作成/権限・空データを確認する。
- SQL詳細に失敗SQLの記録が含まれるため、成功SQLの詳細件数・概要件数・抽出リクエスト数の分母は別。失敗SQLの0msは所要時間ゼロの証明ではない。

## 責務と対象ファイル

RequestPerformanceCollector（request単位収集/匿名化/label）、PerformanceQuery（SQL分類/匿名化/代表呼出元）、RequestPerformanceStore（保存/容量/清掃/読込）、Admin\RequestPerformanceReportService（集計/比較/対策候補）、RequestPerformanceController（GET条件/表示）、RequestPerformanceServiceProvider（event wiring）、RecordRequestPerformance（HTTP境界）。bootstrap/app.php、bootstrap/providers.php、routes/web.php、管理layoutへ最小追加。ChampBattleTransactionRunnerのcatchへ観測のみ1行追加。configとenv例、PHPUnitで既定OFF、Featureテストを追加。

## 確認と限界

確認記録はscratch/performance-qa/配下。SQLiteの隔離テストと合成記録のローカルブラウザ確認を、本番データ/実プレイヤー操作と混同しない。

確認済み：管理者認可、GET条件検証、個人情報除去、同条件と同形式SQLの区別、ON/OFF、nested transaction/競合重複除去、保存容量・破損・読込制限・障害時の処理継続、既存チャンプ戦・接続分散・cooldown・viewer・sidebarの回帰。CSSビルド。期間/並び順/公開版/終了時刻/詳細/リセット/戻る進む。320 CSS px相当幅で水平overflowなし。合成QAデータを使い本番数値とは扱わない。

関連48テスト/301 assertions成功（scratch/performance-qa/final-tests.jsonl）、変更PHP/Blade PHP 15ファイルの構文確認、Blade view:cache成功。ブラウザconsoleのwarning/errorなし。スクリーンショットはscratch/performance-qa/captures/。ローカルSQLiteで100リクエスト×50SQLの比較を2組行い、計測＋保存ONの中央値4.201〜4.518ms、OFF 0.433〜0.445ms。合成1万記録/10操作のレポート集計125.27ms、プロセス全体のpeak memory 60MiB。これらは本番MariaDBの性能保証ではない（benchmark.jsonl）。

未確認：本番MariaDBでの計測負荷、ピーク負荷/長時間運用、実機スマートフォン、本番での欠測率と保存容量。DB計測だけでキャッシュ安全性・索引不足・競合原因を確定しない。

該当なし：報酬・資産・戦績・育成仕様変更、満杯/育成上限のゲーム処理変更、新規画像。docs/dev-os/NAMELESS_WORKSHOP_RETROSPECTIVE_2026-10-07.md §6の性能・ON/OFF・狭幅/履歴・依存・記録の観点を適用した。

## 公開手順

最新mainの接続3ユーザー分散と競合再試行を保持して統合。同一SHAをstaging→productionへmigration_mode=noneで配備する。公開SHA・対象ソースhash・health・未認証拒否・private記録/集計・移行台帳・既存設定の読戻しで確認し、結果はActionsと公開検証記録へ残す。問題時は直前の公開先へ戻すかREQUEST_PERFORMANCE_ENABLED=falseで収集を停止する。DB migration/seedは行わない。

最新版main統合後：関連50テスト/385 assertions成功、追加の観測＋再試行3テスト/11 assertions成功。既存ChampBattleContentionTestの7失敗は今回の観測行を外したmainでも同じ例外で再現（latest-main-baseline-tests.jsonl）。現在のouter transaction例外伝播と初期ロック8回仕様に対して旧テストの前提が異なる。既存処理を変更せず、独立transactionでのrollback/再試行、8回上限、outer transactionへの同一例外伝播をRequestPerformanceRunnerTestで確認。
