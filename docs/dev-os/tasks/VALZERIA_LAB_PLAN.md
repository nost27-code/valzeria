# 実装指示書: 管理者専用 Valzeria Lab MVP

Status: MVP完了・本番公開済み（2026-09-02）

## 目的

既存のヴァルゼリア実装と現在のマスタデータを正本として、戦闘の再現、世界データの参照関係確認、非永続の仮想プレイを一か所で検証できる管理者用 Lab を作る。

## 背景

- 戦闘確認には `BattleService`、非永続実行の先例には `TrainingGroundBattleService` がある。
- 世界データは City / Area / Enemy / Item / Material / JobClass / Title と、各リレーション・発見リンク・設定・カタログに分散している。
- 既存の管理画面には個別の戦闘検証機能があるが、入力状態の持ち運び、根拠付きの世界参照、複数行動の仮想プレイを横断して確認する場所はない。
- プレイヤー向け正本ラベルは `HP / SP / 攻撃 / 防御 / 魔力 / 精神 / 敏捷 / 運` とする。

## 追加裁定（2026-09-02 本番公開）

- ユーザーの明示承認により、管理者専用のまま staging / production でも利用可能にする。
- コード上のfeature flag既定値はfalseを維持し、各環境の共有設定で明示的にONにした場合だけ公開する。
- DB migration、マスタ変更、プレイヤーデータ更新は行わず、`migration_mode=none` で公開する。
- 最終releaseは同一SHAをstagingで確認してからproductionへ反映する。

## 現状の問題

- 実在キャラクターの戦闘条件を、個人情報を含めず同一 seed で再実行できない。
- 世界データの参照元・参照先と、欠落・未使用・到達不能の「確認候補」をまとめて追えない。
- 現在の戦闘・成長マスタを使い、DBを更新せず複数行動の流れを観察する仕組みがない。

## 実装対象

### 1. 共通基盤

- `/admin/valzeria-lab/*` を `auth`、`admin`、Lab専用gateで保護する。
- `VALZERIA_LAB_ENABLED` は常に既定 `false` とし、`local` / `testing` / `staging` / `production` の許可環境でも明示ON時だけ有効にする。
- 管理画面にflag有効時だけ見える導線と、3画面共通のタブを設ける。
- 各Livewireコンポーネントの `mount` でも管理者・環境・flagを再確認し、ルート外からの直接起動を拒否する。

### 2. 再現

- 実在または `tester_%@valzeria.local` のテスト用 Character、Enemy、通常戦 / ボス戦を選択する。
- `CharacterStatusService`、装備計算、戦技ロード、Enemy実効能力を使って開始状態をホワイトリスト形式へ変換する。
- JSONスキーマ `valzeria-lab-battle-snapshot/v1` は個人ID、ユーザーID、メール、認証情報、キャラクター名を含めない。キャラクター表示名は固定の匿名名に置換する。
- JSONの保存・読込はブラウザ上のダウンロードとテキスト入力で行い、サーバーやDBへ保存しない。
- 読込時はサイズ・schema・必須キー・値域を検証し、メモリ上の Character / Enemy / JobClass / Skill として復元する。
- `BattleService` の現在の戦闘ループを使い、`persist_character_state=false`、`exploration_support_enabled=false`、`auto_unequip_invalid_items=false` で実行する。報酬は結果へ計算して表示するが、報酬付与サービスへ渡さない。
- seed付き `Randomizer` を DamageCalculator、BattleService、戦技の乱数源で同じスコープに共有する。通常実行時は従来の乱数源へフォールバックする。
- 同じスナップショットとseedで結果・ターン数・HP/SP・ログが一致する自動テストを置く。
- v1の戦闘種別は Character 対 Enemy の通常戦 / ボス戦とする。対人戦、国家戦、六英雄戦は対象外。

### 3. 世界グラフ

- ノード種別: 街、エリア、敵、装備、アイテム、素材、職業、称号。
- 明示参照を `confirmed`、論理キー・JSON・設定・カタログ由来を `declared`、欠落や経路判定を `candidate` として分離する。
- 各辺に source（テーブル / カラム / config / catalog）と説明を持たせ、根拠のない辺は生成しない。
- 検索、種別絞り込み、40件単位のページング、選択ノードの入辺 / 出辺、根拠一覧を提供する。
- 明示参照切れ、入手経路なし候補、使用経路なし候補、進行リンク上の到達不能候補を検出する。
- 配置関係と進行解放関係を区別する。未公開・意図的な空データを自動的に不具合と断定しない。
- 1回の読込で必要な列と関連だけを取得し、UIは縦リストと折返し可能な表を中心にする。

### 4. 仮想冒険者

- `beginner`（安全優先）、`efficiency`（成長効率優先）、`collector`（登録ドロップ経路優先）の3方針を用意する。
- 初期街、初期職、初期能力、実在マスタを使ってメモリ上の Character 状態を組み立てる。個人レコードは作らない。
- 行動上限は入力1〜100、既定30とする。上限、戦闘不能、資金不足、進行先なし等を停止理由として残す。
- 街、探索、戦闘、宿屋、装備更新、転職判定、ボス挑戦の判断履歴を時系列で返す。
- 戦闘は再現サービス、必要経験値は `LevelService`、宿代は `InnService::fee()`、装備実効値は `CharacterStatusService::equipmentStatsForItem()`、職業要件は JobRequirement / 現行マスタを使う。
- DB更新を伴う `LevelService` の報酬付与、`InnService::rest()`、`GoldService`、探索確定、転職確定は呼ばない。
- 行動選択、探索進行の仮置き、装備購入タイミング、メモリ上の成長反映は「Lab簡略モデル」として画面・結果に明示する。実ゲーム仕様とは断定しない。
- seed、方針、行動数が同じ場合は同じタイムラインを返す。

## 実装対象外（重要）

- DB migration、Seeder、マスタID変更、既存データの補正。
- キャラクター、所持品、Gold、経験値、職業経験値、進行、戦績、ランキング、battle/public/audit logへの書込み。
- Stripe、輝石、課金、認証方式の変更。
- PvP、国家戦、六英雄戦、イベント固有戦闘のスナップショット再現。
- 一般プレイヤーへの公開、管理者以外への権限付与。
- 既存の戦闘・成長・経済バランス値の変更。
- 依頼範囲外のリファクタリング・文言変更。

## 変更範囲

- 想定ファイル:
  - `app/Http/Middleware/EnsureValzeriaLabAvailable.php`
  - `app/Livewire/Admin/ValzeriaLab*.php`
  - `app/Services/Admin/ValzeriaLab*.php`
  - `app/Services/Battle/ScopedBattleRandomizer.php`
  - 既存BattleServiceと戦技乱数源への限定的な非永続入力・乱数フック
  - `resources/views/livewire/admin/valzeria-lab/*`
  - `routes/web.php`、`config/features.php`、管理レイアウト
  - focused tests、関連docs、管理者更新サマリ
- 想定しない範囲:
  - Controller、通常プレイヤー画面、通常戦闘の永続化経路
  - DB schema / model fillable / casts の変更

## 既存仕様への影響

- 戦闘・探索（`docs/dev-os/IMPACT_MAP.md`）: BattleServiceの既定パスは変えず、Labが明示した非永続オプションでのみ匿名入力を使う。通常戦闘、ボス撃破、報酬、探索進行を回帰確認する。
- 職業・戦技: 通常時の乱数源は従来どおり暗号学的乱数へフォールバックする。Labスコープ終了時は必ず解除する。
- 管理画面: 許可環境かつflag有効時だけ新しい導線を表示する。
- データ参照: 世界グラフと仮想冒険者はSELECTのみ。判定結果をDBへ保存しない。

## DB変更

- [x] なし
- migration、既存行更新、Seeder実行は行わない。

## 画面/UI変更

- 対象URL:
  - `/admin/valzeria-lab/replay`
  - `/admin/valzeria-lab/world`
  - `/admin/valzeria-lab/adventurer`
- 390pxでは1列、操作ボタンは44px相当以上、長いJSON・根拠・ログは横幅を越えず折返しまたは内部スクロールにする。
- 1280pxでは入力と結果を2列化できるが、読む順番はDOM上で上から下を維持する。
- 色付きカードを増やさず、見出し、余白、罫線、現在タブの控えめな色で区切る。

## バックエンド変更

- Livewireは入力検証と表示状態に限定し、スナップショット、戦闘、グラフ、仮想行動はService層へ置く。
- すべての実行は1リクエスト内のメモリで完結させる。
- 読取トランザクションやロックは不要。書込みAPIは呼ばない。
- 同名エンティティは内部のマスタIDで選択し、表示には所属街・エリアも併記する。

## 管理者機能への影響

- [x] あり — 管理者専用の「Valzeria Lab」導線を検証グループへ追加する。
- 非管理者、guest、flag無効、許可外環境は利用不可にする。

## ログ・更新履歴への影響

- 公開ログ: 流さない。
- `battle_logs` / `gold_transactions` / 監査ログ: 記録しない。
- `config/admin_update_summaries.php`: `internal` として管理者検証基盤追加を追記する。
- docs同期: `AI_CONTEXT.md`、`FEATURE_STATUS.md`、`CODEMAP.md`、`UPDATE_LOG.md` を必要箇所だけ更新する。schemaとゲームルールを変えないため `DATA_MODEL.md`、`DOMAIN_RULES.md` は原則更新しない。

## エラーハンドリング

- 不正・過大JSON、未知schema、欠落マスタ、範囲外seed / 行動数は入力エラーとして画面へ返す。
- 戦闘実行中はボタンを無効化し、例外時も乱数スコープを `finally` で解除する。
- グラフの欠落参照は画面全体を500にせず、個別の検出候補として表示する。
- 仮想プレイは100行動で必ず打ち切り、停止理由を返す。

## セキュリティ注意点

- ルートmiddlewareとLivewire `mount` の二重gate。
- JSON出力はホワイトリストで組み立て、キー名も再帰検査する。`id` はマスタ参照以外に使わず、個人IDは含めない。
- Character検索は管理者画面内の表示だけとし、メールは返さない。
- Bladeへ出す戦闘ログは既存のHTMLログであるため、スナップショット入力から任意HTMLを受け入れず、マスタ名も通常のBladeエスケープ対象にする。

## パフォーマンス注意点

- Character / Enemy候補は検索語あり・件数上限ありで取得する。
- 世界ノードは必要列と関連をまとめて取得し、表示は40件単位にする。
- 選択ノードの入出辺だけを表示し、全辺をDOMへ描画しない。
- 仮想プレイは100行動、戦闘は現行BattleServiceの最大ターン上限内で打ち切る。

## テスト観点

- guest / 非管理者 / flag無効 / 許可外環境を拒否し、許可環境＋管理者だけ3画面を表示できる。
- 同じsnapshot＋seedの結果、HP/SP、報酬、戦闘ログが一致する。別seedは実行可能である。
- JSONへ禁止キー・個人値が入らず、改ざん・サイズ超過を拒否する。
- 実在Characterを元に再現した前後で、Character属性、所持品、戦績、進行、Gold取引、各戦闘ログ、ランキング件数が変わらない。
- 世界グラフが明示参照と根拠を返し、明示値の欠落は確認結果、入手・使用・到達経路は `candidate` として分離し、推測を `confirmed` にしない。
- 3方針が実行でき、行動数が100以下で、停止理由と時系列があり、DBスナップショットが不変である。
- 通常戦闘のfocused regression、PHP構文、Blade cache、frontend build、`npm run verify` を順番に実施する。

## 手動確認手順

1. 確認対象環境の共有 `.env` で `VALZERIA_LAB_ENABLED=true` にし、config/view cacheを更新する。
2. `https://ffa.test/admin/valzeria-lab/replay` を管理者で開き、実在CharacterとEnemyを選びseed付きで実行する。
3. JSONを保存し、入力を変えた後に読込み、同じseedで同じ結果・ログになることを確認する。
4. 世界画面で8種別を検索・絞込みし、街→エリア→敵→drop、職業要件等の入辺・出辺・根拠を確認する。
5. 整合性一覧で各項目が「確認済み欠落」または「確認候補」として区別されることを確認する。
6. 仮想冒険者を3方針で実行し、判断、HP/SP、Lv、職業、Gold、装備、戦闘結果、停止理由を確認する。
7. 上記をviewport 390pxと1280pxで操作し、横溢れ、ボタン、スクロール、ログ順を確認する。
8. 実行前後の代表テーブル件数と対象Character属性を自動テストで比較する。

## ロールバック方針

- 最短停止は `VALZERIA_LAB_ENABLED=false` または環境変数削除とconfig cache再生成。
- コード上はLab専用route、middleware、components、services、views、tests、docsの追加と、限定フックを戻す。migration rollbackは不要。
- 緊急停止時は共有 `.env` をバックアップから戻すかflagをfalseにし、config cacheを再生成する。DB rollbackは不要。

## 完了条件

- [x] 3画面が `ffa.test` で表示・操作できる。
- [x] 各画面に代表的な実行可能シナリオがある。
- [x] 同じ入力とseedで戦闘結果を再現できる。
- [x] JSONに個人ID・メール・認証情報が含まれない。
- [x] 永続データが変化しないことを自動テストで証明できる。
- [x] 根拠付き世界参照と4種の整合性候補を確認できる。
- [x] 3方針・最大100行動の仮想タイムラインを確認できる。
- [x] focused tests、適用可能なQA、PHP構文、Blade、buildが通る。
- [x] `npm run verify` の結果を記録する。
- [x] 390px / 1280pxのブラウザ確認を完了する。
- [x] docs同期、管理者更新サマリ、未確認事項を報告する。

## 更新情報サマリ案

- category: `internal`
- title: `管理者向けValzeria Labを公開`
- detail: `戦闘再現、世界データの参照確認、非永続の仮想プレイを管理画面から実行できる検証機能を追加しました。既定では無効で、運用が明示的に有効化した環境だけで利用できます。`

## チェックポイント

### CP0 調査・設計

- [x] 正本docs、現行コード、dirty worktreeを確認。
- [x] 非永続戦闘先例、現在の世界マスタ構造、管理者gate、QA基準を確認。
- [x] DB変更・ゲームルール変更・本番作業なしで実装可能と判断。
- 検証: 読取調査のみ。コード・DB変更なし。
- 未確認: 実装後の実画面と全乱数経路。

### CP1 共通基盤

- [x] feature flag、環境gate、管理者gate、3route、共通ナビ。
- [x] access focused tests（5 tests / 17 assertions）。
- 検証: `ffa.test` の管理者sidebarからLab導線を確認。guestはログインへ302、管理者ログイン後だけ表示できる。

### CP2 再現

- [x] 匿名snapshot capture/import/export、seed付き現行戦闘、結果UI。
- [x] 再現性・匿名性・非永続性 focused tests。
- 対象境界: PvE通常戦 / ボス戦のみ。
- 検証: CP1＋再現＋既存訓練所 21 tests / 133 assertions。戦技ありでも同一結果を確認。
- 検証: 実ブラウザで匿名JSONを生成し、テキスト読込とファイル選択読込から同一seed・同一結果を再実行。個人キーなしも確認。保存ボタン操作時にブラウザエラーはなし。
- 未確認: 自動ブラウザではBlobダウンロードイベントとOS上の保存先を観測できないため、生成ファイルそのものの着地だけ未確認。

### CP3 世界グラフ

- [x] 8種node、根拠付きedge、検索・filter・paging・詳細。
- [x] 欠落 / 入手経路 / 使用経路 / 到達不能の確認候補。
- [x] graph focused tests（6 tests / 41 assertions）。
- 検証: ローカル全マスタ 2,148 node / 5,196 edgeを走査。Ferdia configの宣言キーも実レコードへ解決し、実行SQLがSELECT系だけであることを自動確認。
- 検証: 実ブラウザで「スライム」49件への検索、敵ノードの参照元1件・参照先8件と根拠、明示参照の確認結果表示を確認。

### CP4 仮想冒険者

- [x] 3方針、1〜100行動、現行戦闘、簡略判断、timeline、停止理由。
- [x] 決定性・上限・非永続性 focused tests（6 tests / 57 assertions）。
- 検証: ローカル実データで3方針を各30行動実行。効率 / 収集方針はボス撃破と次エリア遷移を含む。CP1〜4＋既存訓練所回帰は33 tests / 233 assertions。
- 検証: 実ブラウザで3方針を切替え、効率型は100行動で街・探索・戦闘・宿屋・装備・転職判定・ボスの全判断種別を確認。

### CP5 統合QA・同期

- [x] 独立レビュー、docs sync、admin update summary。
- [x] focused tests、`npm run verify`、view cache、build。
- [x] `ffa.test` 390px / 1280px実操作。
- 検証: 独立レビューで、読込JSONの連撃数上限、参照切れの確定/候補表示、世界グラフの取得列と検索実行頻度を指摘し、修正・回帰確認した。
- 検証: focused 33 tests / 233 assertions、Blade cache、Vite buildが最終差分で成功。最初の標準全体検証も2,408 tests / 51,207 assertionsで成功した。
- 検証: 最終 `npm run verify` 再実行はPHP構文2,123ファイルを通過後、対象外の未追跡国家レイドテスト1失敗・2エラーで停止（2,405 / 2,408 tests、51,193 assertions）。該当2ファイルだけの再実行は9 tests / 33 assertionsで成功したため、対象外差分の全体順序依存として分離した。
- 検証: 1280x900 / 390x844で3画面を操作。横溢れなし、主要タップ領域44px、ブラウザerror logなし。
- 未確認: 自動ブラウザがBlobダウンロードイベントを公開しないため、JSON保存ファイルのOS上の着地のみ未確認。ここまでの記録はCP5完了時点のローカル確認結果。

### CP6 本番公開

- [x] 本番公開の明示承認、現行本番SHA、共有flag、同一SHAデプロイ経路を確認。
- [x] コード既定OFFを維持し、staging / productionでも明示ON時だけ管理者へ公開するgateと回帰testを追加。
- [x] focused QA、独立review、`npm run verify` を最終差分で完了する。
- [x] 同一SHAをstaging → productionへ `migration_mode=none` で反映する。
- [x] flag、route、HTTP、管理者画面、代表シナリオ、非永続性を本番相当で再確認する。
- 検証: SHA `3e133bdd7a12d4daaea5a86c8662b4cb8caa7bf3` をstaging run `33634929369`、production run `33635943149` の順で反映し、両runの成功とrelease marker一致を確認。migrationは実行していない。
- 検証: 本番共有flagはバックアップ後に明示ONとし、config readback、4 route、migration status、公開TOP 200、未認証3画面302を確認した。
- 検証: 本番の管理者セッションで3画面を表示。再現は同一seedのJSONが同じSHA-256となり、匿名禁止key・Character名・HTML角括弧を含まないことを確認。世界グラフは街検索・種別絞り込み・前後参照・根拠・候補表示、仮想冒険者は効率型30行動の成長・Gold・勝敗・装備・転職判断・停止理由を確認した。
- 検証: 390x844 / 1280x900で3画面に横溢れなし。自動testでは再現・世界グラフ・仮想冒険者がread queryだけを実行し、player-owned tableを保持することを確認済み。本番ログにはLab・SQL例外・fatal参照なし。
- 未確認: JSON保存ファイルのOS上の着地。本番ログには同時刻帯の対象外エラーとして、確認コマンドの未対応optionと既存探索地図の通常モンスター不足が各1件あり、Lab由来ではないため変更対象外とした。
- 現在の阻害事項: なし。

## 進捗ログ

- 2026-09-02 CP6完了: SHA `3e133bdd` をstagingからproductionへ同一SHA・`migration_mode=none`で公開。release marker、明示flag ON、4 route、DB status、HTTP、管理者3画面の代表シナリオ、seed再現性、390px / 1280px表示を読戻した。DB schema・player-owned data・通貨・報酬・ランキングは変更していない。残件はブラウザ自動化で取得できないJSON保存ファイルのOS着地確認のみ。
- 2026-09-02 CP6公開前QA完了: 本番許可gateを含むfocused 41件283 assertion、独立レビュー（認可・匿名化・非永続化）指摘なし、PHP構文scan、Vite buildが成功。最終 `npm run verify` の全PHPUnitは2,519件54,574 assertionを実行し、今回追加した呼出元固定testを修正後、対象差分由来の失敗は0件。残った7失敗・14エラーは、変更前SHAでも再現するFerdia / 刀 / 塔の15件と、単独再実行では成功する探索item / 訓練所 / 経験値護符の6件に分離した。次は同一SHAのstaging確認とproduction公開。
- 2026-09-02 CP6着手: 本番公開の明示承認を受領。`origin/main` の現行本番SHAからクリーンな分離作業ツリーを作り、無関係なdirty差分を除外した。コード既定OFF・管理者gate・DB変更なしを維持したまま、staging / productionの明示ONを許可する実装へ更新。focused 36件246 assertionとVite buildが成功。次は全体QAと独立レビュー。
- 2026-09-02 CP0完了: 正本docsと実装を調査。`TrainingGroundBattleService` の非永続option、`BattleService` / `DamageCalculator` の乱数境界、管理者middleware、主要マスタと発見リンクを確認。DB変更・仕様裁定・本番作業は不要。次はCP1。
- 2026-09-02 CP1完了: local/testingかつ明示flag ON、さらに管理者であることを二重gateにした3routeと共通タブを追加。PHP構文、diff check、access test 5件17 assertionが成功。ローカル `.env` のみON、本番既定はOFF。次はCP2。
- 2026-09-02 CP2完了: 実在Characterからホワイトリスト形式の匿名JSONを作成し、seed付きRandomizerをBattleService・DamageCalculator・戦技選択/命中/受流しへ同一スコープで適用。読込値のschema・サイズ・値域・未知キー・HTML記号を検証し、メモリ上のactorから通常戦/ボス戦を実行するUIを追加。個人情報なし、同一seed完全一致、代表永続テーブル不変、既存訓練所回帰を21 tests / 133 assertionsで確認。次はCP3。
- 2026-09-02 CP3完了: 街・エリア・敵・装備・アイテム・素材・職業・称号を既存テーブルとFerdia設定から読取り、明示参照 / 宣言参照の根拠、入辺 / 出辺、検索・絞込み・ページングを実装。参照切れと3種の確認候補を断定表現と分離し、SELECT限定を含むgraph test 6件37 assertion、CP1〜3合計16件87 assertionが成功。次はCP4。
- 2026-09-02 CP4完了: CharacterServiceの初期値をメモリ上に複製し、初心者 / 効率 / 収集の方針別に街・探索・戦闘・宿屋・装備・転職判定・ボス挑戦を時系列化。戦闘、必要EXP、職業EXP、宿代、装備性能、各マスタを再利用し、簡略判断と未モデル化範囲を画面で分離。同一入力完全一致、1〜100制約、7種の代表行動、SELECT限定・永続テーブル不変を6 tests / 57 assertionsで確認。次はCP5。
- 2026-09-02 CP5完了: 独立レビューの3指摘を修正し、docsと管理者更新概要を同期。focused 33件233 assertion、初回の標準全体検証2,408件51,207 assertion、最終Blade cache、Vite buildが成功。最終 `npm run verify` は途中追加された対象外・未追跡の国家レイド3件だけで停止し、該当ファイル単独9件33 assertionは成功。`ffa.test` を1280x900 / 390x844で操作し、再現・世界参照・3方針と100行動上限、横溢れなし、44px操作領域、ブラウザerrorなしを確認。本番公開・migration・プレイヤーデータ更新は実施していない。
