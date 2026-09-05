# 国家対抗レイド 正式開始

## 承認範囲

2026-09-05、本タスクで正式開催・出撃・報酬受取、本体の対象限定配備、専用6 migrationと必要な保存が承認された。Seeder・既存データ一括補正は含まない。初回のみ予告を短縮し、実時刻から168時間開催する。さらに、全20再臨・総HP6億・出撃回数無制限・探索力10について、参加量による討伐時間・報酬供給量が未検証であることを開示し「初回は現行値で開催する」の裁定を得た。旧5回/日simulationを1,000 seed合格や無制限参加量の検証済み根拠にしない。

## 対象とDB変更

基点は公開済みpreview `c9c463d02be91fe2517d71a582ce080d30cf77d5`。元workspaceの無関係な変更を含めず独立worktreeへレイド専用ファイルと共有部の最小接続だけを移植する。現行の戦技SP出力・Rank5・威力精度等の公開済み変更は維持する。

次の6本だけを適用する。旧previewは新規テーブルを参照しないため、新規空テーブルへの追加と拡張は `migration_mode=backward_compatible` とする。配備前に実DBのpending一覧を照合する。

- `2026_08_31_120000_create_nation_raid_battle_telemetry`
- `2026_09_03_120000_create_nation_raid_event_foundation`
- `2026_09_04_230000_add_nation_raid_finalization_snapshots`
- `2026_09_04_230100_add_nation_raid_honor_titles`
- `2026_09_04_235000_harden_nation_raid_history_and_refund_counts`
- `2026_09_05_120000_widen_nation_raid_sortie_counts`

新規11テーブル、snapshot/index、無損失の回数幅拡張、能力を持たない称号4件。既存master IDは変更しない。通常の出撃で探索力・レイド戦果、報酬受取で無償輝石/合計/台帳・素材・消耗品・称号・国家資材/台帳/実績が更新される。既存の有償輝石を増加させない。

## 検証と配備

候補上でPHP構文、関連/全体テスト、Vite build、frontend timer test、独立レビューを実施する。基点のpackage.jsonにverify scriptはないため個別実行する。全体テストの既存failure/errorと候補差を比較し、部分PASSを全体PASSとしない。MariaDB 10.5.13の隔離CIでPhase 3/4の独立接続・競合・報酬境界を確認する。実行結果は配備記録へ記載する。

同じSHAをmain経由でstaging→productionへActions配備する。正式flag OFFのまま6 migration適用、health/master/readinessを確認してから対象flagを変更する。stagingでは既存の確認用アカウントでPC/375pxのTOP・導線・出撃・保存結果を確認する。認証情報を記録しない。

## 初回の開始

`NATION_COMPETITIVE_RAID_ENABLED=true`、previewはONを維持。`NATION_WAR_ENABLED=false`、`NATION_RAID_STRATEGY_ENABLED=false`。旧`NATION_COMPETITIVE_RAID_ACTIVE`は未使用の互換keyで切替不要。正式出撃と受取の入口は正式flagで開くが、受取は終了・確定後の権利だけを認める。

検証済みSHAとruleset/reward policy hash、実在管理者を読み戻し、専用CLI `nation-raid:start-initial` に `--admin-id`、`--approval-reference`、`--ruleset-hash`、`--reward-policy-hash`、`--confirm-initial-launch` を指定する。公開flagはCLIでは変更しない。event keyは `valgreid-inaugural` 固定。作成・承認・実時刻予告・開始は一括transactionで、失敗時はdraftも残さない。同じ承認の再実行は期間や所属を作り直さない。イベントID/開始/終了/参加snapshot件数を別接続で読む。

## 終了と報酬

毎分のlifecycleで期限後にfinalizingへ進む。回収・日次系譜の確定後、運営が `nation-raid:finalize <event-id> --confirm-rewards` を実行して順位/報酬権利を確定する。個人報酬は本人受取であり、開催開始直後には受取可能な報酬はない。正式コードのまま公開OFFでも未確定出撃の回収を継続できる。

## 停止・切り戻し

まず対象eventの出撃を管理画面で停止し、必要なら正式flagをOFF、config cacheを更新して実効値を読む。開始後にpreviewだけの旧SHAへ即時戻すと回収jobを失うため、未確定/未返却の出撃を確認し、現行正式コードを保持したforward修正を優先する。開始前で履歴がなければ公開済みpreview SHAへ `migration_mode=none` で戻せる。どちらも新規テーブル/称号/戦果/台帳を残し、migration down・データ削除・Seederを実行しない。
