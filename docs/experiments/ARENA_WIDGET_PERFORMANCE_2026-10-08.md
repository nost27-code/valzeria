# ホーム闘技場ランキングウィジェットの負荷削減

2026-10-08。公開対象。実際の公開結果は同一SHAのActionsと読戻し記録を参照。比較時の本番SHAは040c42e5a6cad429961c32505aedb9a23cd52967。

## 現在の仕様・原因・影響範囲

ホーム共有ウィジェットはwire:initでloadArenaEntriesを呼び、rankingEntries(5)から闘技場上位5人の順位/名前/職業/戦力/画像を表示する。従来はcombinedEntriesで全公開参加者とactive NPCを読み込み、全員の装備/能力を生成してから5人に絞る。通常闘技場の候補3人を絞る経路とは別で、同じ軽量化が適用されていなかった。
2026-10-08 09:48 JSTの実記録は41回、SQL平均21,646本、DB平均12,894.15ms、応答中央値25,853.83ms、p95 30,168.35ms。詳細11標本。SQLには装備取得とschema照会の繰返しが確認された。保存欠測があるため全アクセスの正確な回数や代表性は保証しない。ロック競合/HTTP5xxは当該保存記録では0。

## 最小修正

正のlimitの場合、player/NPCを順位で各limit件まで取得、統合順位の上位limitだけ従来のmapPlayerEntries/mapNpcEntriesで生成。5人の計算中は既存NamelessSchemaService::withSnapshotを共有して最後に破棄する。永続キャッシュを追加せず次のリクエストで再取得する。0/負のlimitの既存挙動は維持。順位整合性の既存準備は残し、対戦/報酬/戦力式/認可/表示項目/Blade/DB schemaを変更しない。現在の本番callerは当該ウィジェットだけ（アイコン関連テストにも使用）。

## 読み取り比較

本番のSET SESSION TRANSACTION READ ONLYとREPEATABLE READ、同じtransaction/snapshotで候補と公開済み取得処理を各1回比較。候補はプロセス内の別クラスとして評価し、本番ファイルを書き換えない。CharacterStatusServiceのキャッシュは各比較前に破棄、最後にrollback。HTTPセッション/Livewire再描画/転送時間は含まない。

| 指標 | 公開済み | 候補 |
|---|---:|---:|
| SQL | 21,637 | 147 |
| 構造照会 | 8,275 | 34 |
| DB時間 | 11,985.81ms | 120.45ms |
| CLI処理時間 | 23,624.50ms | 378.22ms |
| 表示件数 | 5 | 5 |
| 書込 | 0 | 0 |

順位・種別・ID・名前・職業・level・戦力・画像/見せ場・frameのスカラー値hashは一致（e58a93e4c931d3bc2187656b5922230bd4ca36200cfd6db56e499b4e857f717e）。最小の修正で大きく削減できるが、時間は負荷・順序・cacheの影響がある1回の測定で、公開後p95の保証ではない。

## 検証と限界

追加回帰は500人のうち5人だけ能力計算、表示情報/戦力保持、widget二重呼出し時の再取得なし、player/NPC統合順位、非公開tester/非active NPC除外。関連47テスト/645 assertions成功（順位修復/rollback、アイコン見せ場、六英雄も含む）。既存HomeInitialLoadPerformanceTestの最初の静的文字列テストは、変更していない既存チャットタグに:key属性があるため旧期待の<livewire:chat-log />と不一致。テストfixtureのNPC初期化列不足はinactive NPCをfixtureへ用意して解消。その他のホーム回帰6テスト/22 assertions、schema snapshot3テスト/11 assertionsも成功。合計56テスト/678 assertions成功。変更PHP3ファイルの構文確認とgit diff --check成功。Blade/JS/CSS変更がないためビルドと狭幅再検証は該当なし（表示スカラー値一致、既存markup保持を根拠とする）。

§6適用：大量データSQL/時間/表示値と資産への書込0を確認、同期snapshot破棄は既存方式を再利用。UI/画像/JS依存/ゲームルール/報酬/満杯処理変更は該当なし。認証済み本番の操作・実機・ピーク/長時間の負荷・公開後の値は未確認。2026-10-08に本番反映の明示指示を受けたため、最新公開SHAへ統合しstaging→productionの同一SHAでmigration_mode=none、ファイル/health/計測/readbackを確認する。

元記録: scratch/performance-qa/arena-widget-live-before.json、arena-widget-comparison.json、arena-widget-tests.jsonl、arena-widget-home-tests.jsonl（ローカルのみ）。
