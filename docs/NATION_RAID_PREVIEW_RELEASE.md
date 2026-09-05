# 国家レイド事前案内の限定配備

## 範囲と現在地

ユーザーは2026-09-05 22:00（日本時間）の本番事前公開を希望。対象はTOP・予定報酬・ランキング集計前案内。正式開催ではない。

原候補 `9477da38c2a6202fbcdfa3da16b28d191f17647c` は `fb39fadb8cb8a00e51653d03dcaeb7d609f72ccc` 基点で準備した。再開時に最新main・本番実体のSHA `fba6b4c581db1db18e1194f9fcfaaf115d69e4e4` を取得し、この最新mainを親に事前案内21ファイルだけを移植した。既存の専用キャラクター画像追加は保持し、管理更新概要の競合は両entryを残して解消した。ローカルの未公開レイド本体を含めない。

準備cloneの実在場所はリポジトリ内の `.codex-releases/raid-preview-isolated-20260905`。originは元workspaceへのローカルパスなのでpush先に使わず、確認済みGitHubリポジトリへ送る。配備用branchは `codex/nation-raid-advance-preview-release-20260905`。過去の22時予約は成立していない。公開済みかどうかは配備Actions・実体SHA・実効flagの読み戻しで判断する。

## 安全境界

- `NATION_COMPETITIVE_RAID_PREVIEW_ENABLED=false` が既定。ONにしても認証・キャラ選択済みのGETだけを公開する。
- レイドのengine/model/table/migration/開催Scheduler/出撃POST/受取POSTは本候補に存在しない。誤って正式公開のenvをONにしても、存在しない本体は開始されない。
- 正式版の `NATION_COMPETITIVE_RAID_ENABLED` / `ACTIVE` が既存envにあればfalseを維持する。本候補はこれらを参照せず、新規定義もしない。旧国家戦設定も変更しない。
- 既存の認証・キャラ選択middlewareによる最終アクセス等の通常処理は維持。案内処理自体はレイドDB参照・出撃・在庫/通貨・報酬権利更新をしない。
- 予定報酬は表示専用snapshot（source policy SHA-256: `b0a4b87e859f5737da207f8630f0712f73cf2f4a6ff8802a4abf8b02d1144e4b`）。有効出撃5回の参加報酬、その他15回、9段階の固定欠片など既存候補16件を数量・条件を変えず抽出。予定変更時は元カタログと再照合して差し替える。実配布の正本にはしない。
- 本候補を正式版へ統合するときは、既存ローカルの同名controller/routeと競合を確認し、正式gateと開催ごとの報酬snapshotへ戻す。案内限定版をそのまま「フラグ一つで正式開催可」と扱わない。

## 配備手順（通信可能な環境で再開）

1. 最新main・本番SHAを取得。基点との差を確認し、本候補以外の未公開差分を混ぜない。
2. 対象差分のテスト/構文/build/レビューを確認して対象commitを固定。
3. 同一SHAをstaging→productionへ `migration_mode=none` で配備。公開時刻に間に合わせるために検証を省略しない。`local_deploy.php`、migration、Seeder、レイド開催CLIは使わない。
4. 運用用接続で本番envの当該preview keyだけtrueにし、設定キャッシュを更新。正式gateと旧国家戦gateはOFFのまま読戻す。秘密値をログや文書へ出さない。
5. release SHA、配備ログ、health、認証済みTOP・予定報酬16件・ランキング案内を確認。PC/375pxで戦闘dialogの開閉とEscape、画像、横はみ出しを確認。入手フォーム/進捗がないこと、直接出撃/受取POSTが拒否されることを確認する。
6. 未配備や接続不可なら「公開済み」「予約済み」と報告しない。予約を別経路で勝手に作らない。

## 切り戻し

preview keyをfalseにして設定キャッシュを更新すれば、新規入口・案内ページを閉じられる。必要なら検証済み直前SHAへ戻す。データ更新がないのでレイドデータの削除・巻戻しは不要。

## 検証の注意

この基点のpackage.jsonには `verify` scriptがない（元dirty workspaceとは異なる）。変更PHP構文・PHPUnit・Vite buildを個別に実行して記録する。環境変数APP_KEYはテスト専用の仮値をプロセスだけに指定し、本番envをコピーしない。composer.lock/package-lock.jsonは元workspaceとSHA一致した依存を独立コピーし、候補側でautoloadを再生成した。元workspaceのテストPASSを本候補のPASSと取り違えない。

## 原候補9477da38の検証結果（履歴）

- 新規preview 6 tests / 169 assertions PASS。その後、所属国家TOPと全メニューの入口テストを追加し、国家関連と合わせ41 tests / 1,010 assertions PASS（追加後のpreviewは7テスト）。
- 変更PHP構文と `git diff --check` PASS。候補ディレクトリでVite build成功。最後のwrapper修正はCSSクラスを変えず、OFF時の空要素を除くBlade条件だけ。
- 全体は2,553 tests / 55,256 assertions、7 failures / 13 errorsで不合格。全体PASSとは扱わない。
- 失敗した6ファイルを本候補・変更前fb39fadbの独立監査worktreeで同条件再実行し、両方38 tests / 173 assertions、2 failures（FerdiaMaterialDropMaster / KatanaWeaponEvolutionMaster）・13 errors（TowerBattleServiceの解放条件）が完全一致。残り5 failures（Map/SubArea/TrainingGround）は両版の個別再実行で消え、順序依存/乱数等の原因は未確定。変更前の全体実行による最終比較は未実施。
- 元workspaceのカタログをDB query禁止listener下で再抽出し、本候補の表示snapshotとPHP配列全体がstrict一致。policy hashだけの比較ではない。exporterは配備対象外。
- 独立した読み取り専用レビューでblockerなし。OFF時に空wrapperが余白を増やす指摘を修正し再レビュー済み。
- 候補のBladeを合成テストデータから描画し、同候補のbuild/assetsでローカル静的表示確認。CSS幅375pxのTOP/報酬に横はみ出しなし、16報酬行、POSTフォーム0、読み込み済み画像エラー0。PC幅1280pxのランキング案内を確認。TOPはLivewire同梱JSを配信して戦闘dialogの表示・閉じる・Escapeを確認。これは本番認証セッションの実画面確認ではない。
- 通常プレイヤーの戦闘・HP/SP・探索力・在庫・報酬・DB構造は変更なし。元workspaceのローカル開催も変更なし。テスト用の表示serverは停止済み。

上記は原候補の検証履歴であり、今回配備候補の結果と区別する。最新main上の候補について、関連テスト・全体テストの親比較・構文・build・独立レビュー後、same-SHA staging検証・本番readbackを記録する。希望時刻だけを理由にこれらを省略しない。

## 最新mainへの移植後の検証（2026-09-05）

- 実装差分は21ファイル。親は本番と同じ `fba6b4c581db1db18e1194f9fcfaaf115d69e4e4`。database、Model、開催タスク、依存、workflow/配備スクリプトは親と同一。
- 関連57 tests / 1,326 assertions PASS。実際の正式出撃 `/nation-raid/events/1/battle` と試行戦闘 `/nation-raid/trial/battle` の拒否も追加後、preview 7 tests / 175 assertions PASS。変更PHP11件の構文・Vite build・diff check PASS。独立した読み取り専用レビューでblockerなし。レビュー後の変更はテスト2 URL追加とdocs記録だけ。
- 候補全体は2,554 tests / 55,282 assertions、7 failures / 13 errors。変更前の最新main全体でも同じ7 failures / 13 errorsが再現した。候補だけの失敗は0。Ferdia/Katana/Map/SubAreaの6 failuresとTowerの13 errorsはメッセージも一致。TrainingGroundは同じ上限10のassertionに対し親44・候補46で失敗する。全体PASSではなく、既存の失敗原因自体は未解決。
- 親の全体初回はVite生成物不足により追加20 failures / 1 errorがあり、総計2,547 tests / 54,952 assertions、27 failures / 14 errorsだった。親でもbuildした後、その追加21件を同じ親で再実行し21 tests / 251 assertions PASS。生成物不足による失敗をアプリの回帰や候補固有の失敗と扱わない。build後の親全体再実行はしていない。
- このSHAのpackage.jsonにverify scriptはないため、構文・PHPUnit・buildを個別実行した。DBテストは分離されたSQLite `:memory:`、APP_KEYはテスト専用仮値。運用DBのmigration/Seeder/プレイヤーデータ更新は実行していない。
- 配備前のstaging・production実体はともに `fba6b4c5`、healthは全6項目正常。preview/正式/activeの環境変数と実装は未定義で実効false、旧国家戦はfalse。本番の正式レイドengine/model/route/開催CLIは存在しない。
- 配備後のActions ID、同一SHA、実効flag、認証済み画面、直接POST拒否は今回の公開記録へ追記する。
